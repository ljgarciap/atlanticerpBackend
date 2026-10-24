<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\FacturacionElectronica\Adapters\Msl;

use App\Modules\AdminCont\FacturacionElectronica\Exceptions\DocumentoAunPendienteException;
use App\Modules\AdminCont\FacturacionElectronica\Services\FiscalCertificationService;
use App\Modules\AdminCont\FacturacionElectronica\Services\PacProviderFactory;
use App\Modules\AdminCont\Models\AdminContCreditNote;
use App\Modules\AdminCont\Models\AdminContInvoice;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * SCRUM-943 tarea 3 — polling para un documento en estado PENDIENTE (202) — GUIA_ILUMINATION.md
 * §6.1 pide reintentar `GET /v1/documentos/{serie}/{numero}` cada 1-2 min hasta CERTIFICADO/
 * RECHAZADO. `tries`/`backoff` reusan el mecanismo normal de reintento de la cola en vez de
 * reimplementar un loop propio — mismo patrón de resiliencia que App\Modules\AI\Jobs\BaseAiJob
 * (de donde también viene el patrón de restaurar el tenant a mano dentro de `handle()`: un worker
 * de Horizon no tiene ningún tenant activo por default).
 *
 * Agendado únicamente por `FiscalCertificationService::persistirResultado()` cuando la
 * certificación inicial (o un polling previo, nunca debería pasar) resuelve `pendiente` — nunca se
 * despacha a mano desde ningún controller.
 *
 * Busca el modelo por `(serie, numero)` — únicos por tipo de documento desde el hallazgo #2 del
 * threat model (`invoices_fe_serie_numero_unique`/`credit_notes_fe_serie_numero_unique`) — en vez
 * de serializar el id: si el job queda en la cola con delay y algo reemplaza la fila entre tanto
 * (no debería pasar, pero es más barato buscar por la clave real que confiar en un id capturado
 * antes de que exista la certeza de que esa fila es la definitiva).
 */
final class PollMslDocumentEstadoJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 20; // ~20 * 90s ≈ 30 min de ventana total, ver backoff()

    public function __construct(
        public readonly string $serie,
        public readonly string $numero, // folio interno AtlanticERP (ej. "F-0013") — clave real de (serie, numero) en `invoices`/`credit_notes`, usado SOLO para encontrar el modelo acá abajo.
        public readonly string $tipoDocumento, // factura | nota_credito
        public readonly string $tenantSlug,
        public readonly string $numeroFiscal, // SCRUM-987 — numero puramente numérico que de verdad se le mandó al PAC (ver FiscalDocumentBuilder::numeroFiscal()); el que hay que usar para consultar MSL, nunca el folio interno.
    ) {
    }

    public function backoff(): int
    {
        return 90;
    }

    public function handle(PacProviderFactory $providerFactory, FiscalCertificationService $certification): void
    {
        $tenant = Tenant::where('slug', $this->tenantSlug)->first();
        if ($tenant === null) {
            Log::error('MSL: polling sin tenant válido, se descarta', ['tenant_slug' => $this->tenantSlug, 'serie' => $this->serie, 'numero' => $this->numero]);

            return;
        }
        $tenant->makeCurrent();

        $modelo = $this->tipoDocumento === 'factura'
            ? AdminContInvoice::where('serie', $this->serie)->where('numero', $this->numero)->first()
            : AdminContCreditNote::where('serie', $this->serie)->where('numero', $this->numero)->first();

        if ($modelo === null) {
            Log::error('MSL: polling no encontró el documento por (serie, numero) — se descarta', [
                'tipo' => $this->tipoDocumento, 'serie' => $this->serie, 'numero' => $this->numero,
            ]);

            return;
        }

        $resultado = $providerFactory->make()->consultarEstado($this->serie, $this->numeroFiscal);

        // SCRUM-987 — constancia de CADA consulta, también las que siguen pendientes o fallan.
        $certification->registrarTransmision($modelo, $this->tipoDocumento, $resultado, 'polling', $this->serie, $this->numeroFiscal);

        if ($resultado->estado === 'pendiente') {
            // errorTransporte distingue "MSL encoló el documento, hay algo que esperar" (202 real)
            // de "no pudimos ni contactar a MSL" (circuito abierto/timeout) — el segundo caso no
            // tiene nada nuevo que un polling vaya a encontrar, ver Senior Review 2026-09-17,
            // finding #6. Misma cadencia de reintento en ambos casos por ahora (tries=20/backoff
            // 90s ya acotan el costo) — esto solo deja rastro distinto en logs para una alerta
            // futura ("documentos pendientes por mucho tiempo") que sí necesite diferenciarlos.
            if ($resultado->errorTransporte) {
                Log::warning('MSL: no se pudo consultar el estado del documento (circuito abierto/timeout de transporte) — reintentando', [
                    'serie' => $this->serie,
                    'numero' => $this->numero,
                ]);
            }

            throw new DocumentoAunPendienteException($this->serie, $this->numero);
        }

        if ($resultado->errorConfiguracion()) {
            // No es una resolución: MSL nunca llegó a evaluar el documento por un problema
            // nuestro (ej. API key invalidada a mitad de la ventana de polling). Senior Review
            // 2026-09-20, finding #1 — reintentar con la misma configuración rota no cambia nada,
            // así que no se relanza como `pendiente`, pero tampoco puede quedar logueado como
            // "resuelto": requiere intervención manual, el estado fiscal real del documento sigue
            // sin confirmarse. A propósito NO se persiste acá — dejar el `fe_estado: pendiente`
            // original intacto es más honesto que sobreescribirlo con un estado que tampoco resuelve nada.
            Log::error('MSL: error de configuración detectado durante el polling — requiere intervención manual, no es una resolución', [
                'serie' => $this->serie,
                'numero' => $this->numero,
                'errores' => $resultado->errores,
            ]);

            return;
        }

        $certification->persistirResultado($modelo, $this->tipoDocumento, $resultado, numeroFiscal: $this->numeroFiscal);

        Log::info('MSL: documento resuelto tras polling', [
            'serie' => $this->serie,
            'numero' => $this->numero,
            'estado' => $resultado->estado,
            'cufe' => $resultado->cufe,
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        Log::warning('MSL: documento sigue pendiente tras agotar reintentos de polling', [
            'serie' => $this->serie,
            'numero' => $this->numero,
            'exception' => $exception->getMessage(),
        ]);
    }
}
