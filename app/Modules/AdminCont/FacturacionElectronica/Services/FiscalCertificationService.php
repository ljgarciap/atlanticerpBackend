<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\FacturacionElectronica\Services;

use App\Modules\AdminCont\FacturacionElectronica\Adapters\Msl\PollMslDocumentEstadoJob;
use App\Modules\AdminCont\FacturacionElectronica\DTOs\FiscalDocument;
use App\Modules\AdminCont\FacturacionElectronica\DTOs\FiscalDocumentResult;
use App\Modules\AdminCont\FacturacionElectronica\DTOs\ReceptorFiscalSnapshot;
use App\Modules\AdminCont\Events\InvoiceCreated;
use App\Modules\AdminCont\FacturacionElectronica\Exceptions\RecertificacionNoPermitidaException;
use App\Modules\AdminCont\Models\AdminContCreditNote;
use App\Modules\AdminCont\Models\AdminContFeTransmision;
use App\Modules\AdminCont\Models\AdminContFiscalSettings;
use App\Modules\AdminCont\Models\AdminContInvoice;
use App\Modules\Bodega\Models\Order;
use App\Shared\Multitenancy\Tenant;
use App\Shared\Services\AuditService;
use App\Shared\Support\CircuitBreaker;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * SCRUM-943 — wiring real: ensambla el `FiscalDocument` (`FiscalDocumentBuilder`), lo certifica
 * ante el PAC activo (`PacProviderFactory`) y persiste el resultado en `invoices`/`credit_notes`.
 * Único punto que escribe `serie`/`fe_estado`/`cufe`/`qr_content`/`xml_firmado_path`/
 * `pac_provider_usado`/`fe_certificado_at` — nadie más en la capa `FacturacionElectronica` toca
 * esas columnas.
 *
 * Regla dura: un problema de facturación electrónica NUNCA revierte ni bloquea la factura/NC ya
 * creada en AtlanticERP — por eso todo error (de ensamblado, de config, de red) se atrapa acá y se
 * persiste como `error_configuracion`, nunca se deja escapar al caller. Esto es justamente lo que
 * permite el modo `apagado`/`sandbox`/`producción`: la generación del documento en AtlanticERP es
 * incondicional, la certificación ante el PAC es un efecto secundario best-effort.
 *
 * Un resultado `pendiente` agenda `PollMslDocumentEstadoJob` (90s de delay, ver GUIA_ILUMINATION.md
 * §6.1) — el job resuelve por su cuenta (mismo circuito de reintento de la cola) y llama de vuelta
 * a `persistirResultado()` cuando el documento se resuelve, sin duplicar la lógica de persistencia.
 * El motivo textual de un `rechazado`/`error_configuracion` se persiste en `fe_detalle` (batch de
 * endurecimiento FE/MSL, 2026-09-29) además de loguearse — texto de un tercero: truncado y sin
 * credenciales, y solo se expone a quien ve Configuración Fiscal (ver los controllers de detalle).
 */
final class FiscalCertificationService
{
    private const DISK = 's3-private';

    /** SCRUM-1007 — acciones fiscales manuales que la UI puede ofrecer (solo Mark/superadmin). */
    public const ACCION_REINTENTAR         = 'reintentar';
    public const ACCION_ACTUALIZAR_ESTADO  = 'actualizar_estado';

    /** SCRUM-1019 — estados desde los que se puede "Reconciliar con MSL". */
    private const ESTADOS_RECONCILIABLES = ['rechazado', 'error_configuracion', 'pendiente'];

    /**
     * SCRUM-1009 — `fe_estado` de la factura de REEMPLAZO de una "Corrección de datos" mientras su nota
     * de anulación no esté certificada. No es un rechazo ni un envío: el reemplazo todavía no se mandó.
     */
    public const ESTADO_EN_ESPERA_NC = 'en_espera_nc';

    public function __construct(
        private readonly FiscalDocumentBuilder $builder,
        private readonly PacProviderFactory $providerFactory,
        private readonly FeTransmisionRecorder $transmisiones,
        private readonly AuditService $audit,
        private readonly ReceptorFiscalGuard $receptorGuard,
        private readonly FiscalTotalAligner $alineador,
        private readonly FeRechazoNotifier $notificador,
    ) {
    }

    public function certificarFactura(AdminContInvoice $invoice): void
    {
        $settings = AdminContFiscalSettings::current();

        if (! $this->debeCertificar($settings, $settings->pac_doc_factura_habilitado)) {
            $invoice->forceFill(['fe_estado' => 'no_aplica'])->save();

            return;
        }

        // SCRUM-1140 (A1) — una factura creada con el PAC apagado congeló el receptor SIN validar: se revalida antes de enviar.
        if ($invoice->receptor_tipo === ReceptorFiscalSnapshot::TIPO_CONTRIBUYENTE && $invoice->receptor_validado_at === null) {
            $this->refrescarReceptorCongelado($invoice);
        }

        $this->certificar(
            modelo: $invoice,
            settings: $settings,
            tipoDocumento: 'factura',
            armar: fn (): FiscalDocument => $this->builder->paraFactura($invoice),
        );
    }

    public function certificarNotaCredito(AdminContCreditNote $creditNote): void
    {
        $settings = AdminContFiscalSettings::current();

        if (! $this->debeCertificar($settings, $settings->pac_doc_nota_credito_habilitado)) {
            $creditNote->forceFill(['fe_estado' => 'no_aplica'])->save();

            return;
        }

        // SCRUM-1009 (Senior Review B1) — la NC de anulación de una "Corrección de datos" sobre una factura que NUNCA
        // se certificó (rechazada, con error de configuración, o emitida con la FE apagada) no tiene nada que anular
        // ante la DGI: no existe CUFE que referenciar y el builder fallaría. La anulación es solo interna de AtlanticERP
        // (`no_aplica`), y el reemplazo se certifica normalmente — no hay doble facturación posible porque el original
        // nunca existió fiscalmente. Sin esto el reemplazo quedaba retenido para siempre. Las NC que NO son una
        // corrección conservan el comportamiento de siempre (error visible y accionable si falta el CUFE).
        if ($creditNote->subtipo_anulacion === AdminContCreditNote::SUBTIPO_CORRECCION && $creditNote->facturaOrigen?->cufe === null) {
            $creditNote->forceFill(['fe_estado' => 'no_aplica'])->save();

            return;
        }

        $this->certificar(
            modelo: $creditNote,
            settings: $settings,
            tipoDocumento: 'nota_credito',
            armar: fn (): FiscalDocument => $this->builder->paraNotaCredito($creditNote),
        );
    }

    // ─── SCRUM-1007 — acciones fiscales manuales (Mark/superadmin) ───────────────────────────

    /**
     * Qué acción manual está disponible AHORA para el documento (o null): `reintentar` para
     * `rechazado`/`error_configuracion`, `actualizar_estado` para `pendiente`. Ninguna si el documento
     * está anulado, la NC no está aplicada, la FE está apagada o el ambiente cambió desde su último envío.
     * Deny-by-default: la UI solo ofrece el botón si esto devuelve algo (y el controlador de detalle además
     * exige Mark/superadmin).
     */
    public function accionDisponible(AdminContInvoice|AdminContCreditNote $modelo, string $tipoDocumento): ?string
    {
        if ($this->bloqueoFiscal($modelo, $tipoDocumento) !== null) {
            return null;
        }

        return match ($modelo->fe_estado) {
            'rechazado', 'error_configuracion' => self::ACCION_REINTENTAR,
            'pendiente'                        => self::ACCION_ACTUALIZAR_ESTADO,
            default                            => null,
        };
    }

    /**
     * Reenvía a MSL un documento `rechazado` o con `error_configuracion`. Entra por `enviar()` (mismo
     * ensamblado, mismo proveedor, misma transmisión registrada con `origen = reintento_manual`) y NO por
     * `certificarFactura()/certificarNotaCredito()`: esos, con la FE apagada, sobrescriben `fe_estado` a
     * `no_aplica` y borrarían la evidencia de un rechazo.
     *
     * @return array{accion: string, fe_estado: ?string, fe_detalle: ?string, cufe: ?string, mensaje: string, codigo: ?string}
     * @throws RecertificacionNoPermitidaException
     */
    public function recertificar(AdminContInvoice|AdminContCreditNote $modelo, string $tipoDocumento): array
    {
        return $this->conBloqueo($modelo, $tipoDocumento, function () use ($modelo, $tipoDocumento): array {
            $modelo->refresh(); // otro usuario pudo resolverlo mientras esperábamos el lock

            if (! in_array($modelo->fe_estado, ['rechazado', 'error_configuracion'], true)) {
                throw new RecertificacionNoPermitidaException(
                    RecertificacionNoPermitidaException::ESTADO_NO_REINTENTABLE,
                    'Solo se puede reintentar un documento rechazado o con error de configuración.',
                );
            }
            $this->exigirDisponible($modelo, $tipoDocumento);

            $anterior = $modelo->fe_estado;
            [$desenlace, $httpStatus] = $this->reenviar($modelo, $tipoDocumento, 'reintento_manual');
            $modelo->refresh();

            $this->auditar(AuditService::ADMIN_CONT_FE_DOCUMENTO_RECERTIFICADO, $modelo, $tipoDocumento, $anterior, ['desenlace' => $desenlace, 'http_status' => $httpStatus]);

            return $this->resumenTrasReenvio($modelo, $desenlace);
        });
    }

    /**
     * Consulta a MSL el estado de un documento `pendiente` (sin reenviar) y resuelve según lo que responda.
     * Hallazgo del spike (2026-09-30): `GET /v1/documentos/{serie}/{numero}` solo conoce lo que pasó por la
     * cola de MSL; responde 404 "no encontrado en la cola" tanto para un documento nunca recibido como para uno
     * ya certificado por el camino directo. Por eso ese 404 se resuelve reenviando (el POST responde de forma
     * definitiva: 200 certificado, 422 rechazado, o 9022 si MSL ya lo tenía: en ese caso NO se cambia el estado).
     *
     * @return array{accion: string, fe_estado: ?string, fe_detalle: ?string, cufe: ?string, mensaje: string, codigo: ?string}
     * @throws RecertificacionNoPermitidaException
     */
    public function actualizarEstado(AdminContInvoice|AdminContCreditNote $modelo, string $tipoDocumento): array
    {
        return $this->conBloqueo($modelo, $tipoDocumento, function () use ($modelo, $tipoDocumento): array {
            $modelo->refresh();

            if ($modelo->fe_estado !== 'pendiente') {
                throw new RecertificacionNoPermitidaException(
                    RecertificacionNoPermitidaException::ESTADO_NO_ACTUALIZABLE,
                    'Solo se puede actualizar el estado de un documento pendiente.',
                );
            }
            $this->exigirDisponible($modelo, $tipoDocumento);

            return $this->resolverContraMsl($modelo, $tipoDocumento, 'pendiente', AuditService::ADMIN_CONT_FE_DOCUMENTO_ESTADO_ACTUALIZADO, 'reintento_manual');
        });
    }

    /**
     * SCRUM-1019 — "Reconciliar con MSL" (solo Mark/superadmin): recupera el CUFE de un documento que MSL YA tiene pero que
     * AtlanticERP figura como `rechazado`/`error_configuracion`/`pendiente` (p. ej. se certificó y la respuesta se perdió, o MSL
     * respondió 9022). Primero consulta `GET /v1/documentos/{serie}/{numero}`; si MSL no lo tiene en su cola reenvía la MISMA
     * serie+número (MSL es idempotente: si ya lo certificó responde 200 con el CUFE original, ver `MslPacAdapter`). Al certificar una
     * NC de anulación, `persistirResultado()` libera sola la factura de reemplazo retenida en `en_espera_nc` (reemplaza la
     * "liberación manual" de SCRUM-1009 a). El reenvío respeta la ventana de `fe_reintento_max_horas`.
     *
     * @return array{accion: string, fe_estado: ?string, fe_detalle: ?string, cufe: ?string, mensaje: string, codigo: ?string}
     * @throws RecertificacionNoPermitidaException
     */
    public function reconciliar(AdminContInvoice|AdminContCreditNote $modelo, string $tipoDocumento): array
    {
        return $this->conBloqueo($modelo, $tipoDocumento, function () use ($modelo, $tipoDocumento): array {
            $modelo->refresh();

            if (! in_array($modelo->fe_estado, self::ESTADOS_RECONCILIABLES, true)) {
                throw new RecertificacionNoPermitidaException(
                    RecertificacionNoPermitidaException::ESTADO_NO_RECONCILIABLE,
                    'Solo se puede reconciliar un documento rechazado, con error de configuración o pendiente.',
                );
            }
            $this->exigirDisponible($modelo, $tipoDocumento);

            $anterior = (string) $modelo->fe_estado;
            $resumen  = $this->resolverContraMsl($modelo, $tipoDocumento, $anterior, AuditService::ADMIN_CONT_FE_DOCUMENTO_RECONCILIADO, 'reconciliacion');

            if ($modelo->fe_estado === 'certificado' && in_array($resumen['accion'], ['resuelto', 'reenviado'], true)) {
                $resumen['mensaje'] = 'Documento reconciliado con MSL: se recuperó el CUFE y quedó certificado.';
            }

            return $resumen;
        });
    }

    /** ¿Se ofrece "Reconciliar con MSL" para este documento ahora? (misma regla de bloqueo que reintentar/actualizar). */
    public function reconciliacionDisponible(AdminContInvoice|AdminContCreditNote $modelo, string $tipoDocumento): bool
    {
        return $this->bloqueoFiscal($modelo, $tipoDocumento) === null
            && in_array($modelo->fe_estado, self::ESTADOS_RECONCILIABLES, true);
    }

    /**
     * SCRUM-1009 (b) / SCRUM-1019 — solo lectura: ¿MSL ya tiene esta factura aunque AtlanticERP la muestre rechazada / con error?
     * Lo sabe por (1) un `GET /v1/documentos/{serie}/{numero}` que la encuentra certificada o pendiente en su cola, o (2) un
     * envío anterior que MSL contestó con 9022 ("ya enviada a certificar"). NO modifica nada. Falla cerrado: si no se puede
     * consultar a MSL devuelve `null` (desconocido) y el caller debe tratarlo como "no se puede asegurar".
     *
     * @return bool|null true = MSL ya la tiene; false = no; null = no se pudo verificar ahora
     */
    public function mslYaTieneLaFactura(AdminContInvoice $factura): ?bool
    {
        $fueEnviada = AdminContFeTransmision::query()
            ->where('documento_tipo', 'factura')
            ->where('documento_id', $factura->id)
            ->where('operacion', 'certificar')
            ->orderByDesc('id')
            ->get(['response_body', 'resultado'])
            ->contains(fn (AdminContFeTransmision $t): bool => $this->respuestaEs9022(is_array($t->response_body) ? $t->response_body : []));
        if ($fueEnviada) {
            return true;
        }

        $serie = trim((string) $factura->serie);
        if ($serie === '') {
            return false; // nunca se llegó a enviar con serie: MSL no puede tenerla
        }

        try {
            $resultado = $this->providerFactory->make()->consultarEstado($serie, $this->builder->numeroFiscal($factura->numero));
        } catch (Throwable) {
            return null;
        }
        if ($resultado->errorTransporte) {
            return null;
        }
        if ($resultado->errorConfiguracion()) {
            return $this->noEstaEnCola($resultado) ? false : null;
        }

        return in_array($resultado->estado, ['certificado', 'pendiente'], true);
    }

    /** @param array<string, mixed> $body */
    private function respuestaEs9022(array $body): bool
    {
        $patron = '/^\s*9022\s*(?:-|$)/m';
        $textos = array_filter(array_merge(
            is_array($body['detalles'] ?? null) ? $body['detalles'] : [],
            [$body['error'] ?? null],
        ), 'is_string');
        foreach ($textos as $t) {
            if (preg_match($patron, $t) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Cuerpo común de "Actualizar estado" (SCRUM-1007) y "Reconciliar" (SCRUM-1019): consulta a MSL y resuelve según
     * lo que responda; si MSL no lo tiene en cola, reenvía la misma serie+número (`$origenReenvio`).
     *
     * @return array{accion: string, fe_estado: ?string, fe_detalle: ?string, cufe: ?string, mensaje: string, codigo: ?string}
     */
    private function resolverContraMsl(AdminContInvoice|AdminContCreditNote $modelo, string $tipoDocumento, string $estadoAnterior, string $auditAccion, string $origenReenvio): array
    {
        // Igual que el job de polling: serie persistida + número fiscal derivado del folio (sin ensamblar el documento).
        $serie        = trim((string) $modelo->serie);
        $numeroFiscal = $this->builder->numeroFiscal($modelo->numero);

        if ($serie !== '') {
            try {
                $resultado = $this->providerFactory->make()->consultarEstado($serie, $numeroFiscal);
            } catch (Throwable $e) {
                Log::error("Facturación electrónica: no se pudo consultar el estado de {$tipoDocumento}", [
                    'id' => $modelo->id,
                    'exception_class' => $e::class,
                ]);

                return $this->resumen($modelo, 'sin_cambios', 'No se pudo consultar a MSL en este momento; el documento no cambió.');
            }

            $this->registrarTransmision($modelo, $tipoDocumento, $resultado, 'consulta_manual', $serie, $numeroFiscal);

            if ($resultado->errorTransporte) {
                return $this->resumen($modelo, 'sin_cambios', 'MSL no está disponible ahora; inténtalo de nuevo en unos minutos. El documento no cambió.');
            }

            if (! $resultado->errorConfiguracion()) {
                if ($resultado->estado === 'pendiente') {
                    // Sigue en la cola de MSL: no se persiste nada (no se encadena otro polling; el historial ya tiene la consulta).
                    return $this->resumen($modelo, 'sigue_en_cola', 'El documento sigue en la cola de MSL. Vuelve a actualizar en unos minutos.');
                }

                $this->persistirResultado($modelo, $tipoDocumento, $resultado, numeroFiscal: $numeroFiscal);
                $modelo->refresh();
                $this->auditar($auditAccion, $modelo, $tipoDocumento, $estadoAnterior, ['desenlace' => 'resuelto', 'http_status' => $resultado->httpStatus]);

                return $this->resumen($modelo, 'resuelto', $this->mensajeTrasReenvio($modelo));
            }

            // Error de configuración: solo el 404 "no encontrado en la cola" significa "MSL no lo tiene en cola". Un 401/403 o
            // un 404 sin ese cuerpo (p. ej. base_url mal configurada) NO se resuelve reenviando.
            if (! $this->noEstaEnCola($resultado)) {
                return $this->resumen($modelo, 'sin_cambios', 'MSL rechazó la consulta por un problema de configuración; revisa las credenciales y la URL del servicio.');
            }
        }

        [$desenlace, $httpStatus] = $this->reenviar($modelo, $tipoDocumento, $origenReenvio);
        $modelo->refresh();
        $this->auditar($auditAccion, $modelo, $tipoDocumento, $estadoAnterior, ['desenlace' => $desenlace, 'reenviado_tras_404' => true, 'http_status' => $httpStatus]);

        return $this->resumenTrasReenvio($modelo, $desenlace);
    }

    /** Motivo por el que NO se puede actuar sobre el documento, independiente de su `fe_estado`. */
    private function bloqueoFiscal(AdminContInvoice|AdminContCreditNote $modelo, string $tipoDocumento): ?RecertificacionNoPermitidaException
    {
        if ($modelo instanceof AdminContInvoice && $modelo->anulada_at !== null) {
            return new RecertificacionNoPermitidaException(RecertificacionNoPermitidaException::DOCUMENTO_ANULADO, 'La factura está anulada: no se puede certificar.');
        }
        if ($modelo instanceof AdminContCreditNote && $modelo->estado !== AdminContCreditNote::ESTADO_APLICADA) {
            return new RecertificacionNoPermitidaException(RecertificacionNoPermitidaException::NOTA_NO_APLICADA, 'Solo se puede certificar una nota de crédito aplicada.');
        }

        $settings   = AdminContFiscalSettings::current();
        $habilitado = $tipoDocumento === 'factura' ? $settings->pac_doc_factura_habilitado : $settings->pac_doc_nota_credito_habilitado;
        if (! $this->debeCertificar($settings, $habilitado) || $settings->pac_provider !== 'msl') {
            return new RecertificacionNoPermitidaException(RecertificacionNoPermitidaException::FE_APAGADA, 'La facturación electrónica está apagada o el proveedor no está activo: no se enviará nada a la DGI.');
        }

        // Si se cambió de ambiente (pruebas <-> producción) desde el último envío, reenviar sería una emisión en el ambiente
        // equivocado (p. ej. un documento del sandbox convertido en una emisión fiscal real). Se bloquea.
        $ambienteActual  = $settings->pac_ambiente === 'production' ? 'produccion' : 'pruebas';
        $ambienteUltimo  = AdminContFeTransmision::query()
            ->where('documento_tipo', $tipoDocumento)
            ->where('documento_id', $modelo->id)
            ->orderByDesc('id')
            ->value('ambiente');
        if (is_string($ambienteUltimo) && $ambienteUltimo !== $ambienteActual) {
            return new RecertificacionNoPermitidaException(
                RecertificacionNoPermitidaException::AMBIENTE_DISTINTO,
                "El documento se envió antes al ambiente de {$ambienteUltimo} y ahora la facturación electrónica está en {$ambienteActual}: no se reenvía para no emitir en el ambiente equivocado.",
            );
        }

        return null;
    }

    private function exigirDisponible(AdminContInvoice|AdminContCreditNote $modelo, string $tipoDocumento): void
    {
        $bloqueo = $this->bloqueoFiscal($modelo, $tipoDocumento);
        if ($bloqueo !== null) {
            throw $bloqueo;
        }
    }

    /**
     * Reenvío manual. Persiste el resultado SOLO cuando refleja el estado fiscal real:
     *  - 9022 ("referencia interna ya enviada a certificar") significa que MSL YA tiene ese número: NO es un rechazo
     *    (un rechazo no consume número). Persistirlo como `rechazado` dejaría un estado fiscal falso, ofrecería
     *    "Reintentar" en bucle y en una corrección de datos dejaría el reemplazo retenido para siempre. Se registra
     *    la transmisión pero el estado no cambia.
     *  - una falla de transporte (circuito abierto / timeout) tampoco cambia el estado ni agenda un polling huérfano.
     *
     * @return array{0: string, 1: ?int} [desenlace, http_status] — desenlace: persistido | ya_enviado_9022 | sin_cambios_transporte | error_ensamblado
     */
    private function reenviar(AdminContInvoice|AdminContCreditNote $modelo, string $tipoDocumento, string $origen): array
    {
        if ($modelo instanceof AdminContInvoice) {
            $this->refrescarReceptorCongelado($modelo);
        }

        $enviado = $this->enviar(
            $modelo,
            AdminContFiscalSettings::current(),
            $tipoDocumento,
            fn (): FiscalDocument => $this->documentoFiscal($modelo, $tipoDocumento),
            $origen,
        );
        if ($enviado === null) {
            return [$this->ventanaVencida($modelo, AdminContFiscalSettings::current()) ? 'ventana_vencida' : 'error_ensamblado', null];
        }

        [$documento, $resultado] = $enviado;
        if ($this->esNumeroYaEnviado($resultado)) {
            return ['ya_enviado_9022', $resultado->httpStatus];
        }
        if ($resultado->errorTransporte) {
            return ['sin_cambios_transporte', null];
        }

        $this->persistirResultado($modelo, $documento->tipoDocumento, $resultado, serie: $documento->serie, numeroFiscal: $documento->numero);

        return ['persistido', $resultado->httpStatus];
    }

    /**
     * SCRUM-1140 (hallazgo A1 de fe-panama) — el receptor congelado de una factura puede reemplazarse mientras NO esté
     * certificada ni pendiente: tras un rechazo por RUC/DV/razón social (9032) o un error de configuración, el reintento debe
     * enviar el receptor ya corregido del cliente y no repetir el error con la copia vieja. Se revalida contra la DGI.
     *  - Sin receptor congelado (anteriores) o Consumidor Final (decisión de quien factura): no se toca.
     *  - Si la guarda bloquea hoy (el RUC sigue sin validar), se deja el congelado: el PAC lo rechaza con causa legible.
     */
    private function refrescarReceptorCongelado(AdminContInvoice $invoice): void
    {
        if (in_array($invoice->fe_estado, ['certificado', 'pendiente'], true)) {
            return;
        }

        $congelado = ReceptorFiscalSnapshot::desdeFactura($invoice);
        if ($congelado === null || $congelado->esConsumidorFinal()) {
            return;
        }

        // R-01 (re-verificación de SCRUM-1140): la factura de reemplazo de una "Corrección de datos" hereda el receptor de la
        // original certificada para igualar al de su NC de anulación (la DGI rechaza, 9026, si difieren): no se refresca.
        if (AdminContCreditNote::query()->where('factura_nueva_id', $invoice->id)->exists()) {
            return;
        }

        $subClient = Order::find($invoice->family_root_id)?->pipelineCard?->subClient;
        if ($subClient === null) {
            return;
        }

        $evaluacion = $this->receptorGuard->evaluar($subClient);
        if ($evaluacion['bloquea'] === true) {
            return;
        }

        $nuevo = $this->receptorGuard->receptorResuelto($evaluacion);
        if ($nuevo === null || $nuevo->esConsumidorFinal()) {
            return; // el cliente pasó a Consumidor Final después: esta factura no cambia de receptor
        }

        $anterior = $invoice->only(['receptor_tipo', 'receptor_ruc', 'receptor_dv', 'receptor_nombre']);
        $invoice->forceFill($nuevo->comoAtributosDeFactura());
        $cambio = $invoice->isDirty(['receptor_tipo', 'receptor_ruc', 'receptor_dv', 'receptor_nombre']);
        $invoice->save();

        // Rastro solo si el receptor cambió de verdad (un reintento que revalida los mismos datos no deja evento).
        if ($cambio) {
            $this->audit->log(
                action:     AuditService::ADMIN_CONT_FE_RECEPTOR_REFRESCADO,
                entityType: 'invoice',
                entityId:   $invoice->id,
                oldValues:  $anterior,
                newValues:  $invoice->only(['receptor_tipo', 'receptor_ruc', 'receptor_dv', 'receptor_nombre']),
                metadata:   ['fe_estado' => $invoice->fe_estado],
            );
        }
    }

    /**
     * 9022 en cualquier forma en que MSL lo devuelva (422 con `estado`, 400 sin `estado`, texto largo en `error`).
     * Se exige el FORMATO DE CÓDIGO de MSL — una línea que EMPIECE con `9022` seguida de guion o fin de línea
     * (`9022 - La referencia INTERNA…`) — y no cualquier aparición del número: un RUC (`8-9022-…`) o un monto
     * (`9022.50`) dentro del motivo de un rechazo real no deben confundirse con este código.
     */
    private function esNumeroYaEnviado(FiscalDocumentResult $resultado): bool
    {
        $patron = '/^\s*9022\s*(?:-|$)/m';

        foreach ($resultado->errores as $error) {
            if (preg_match($patron, $error) === 1) {
                return true;
            }
        }
        $crudo = $resultado->rawResponse['error'] ?? null;

        return is_string($crudo) && preg_match($patron, $crudo) === 1;
    }

    /** El 404 "Documento no encontrado en la cola" de `GET /v1/documentos/...` (no confundir con una URL mal configurada). */
    private function noEstaEnCola(FiscalDocumentResult $resultado): bool
    {
        $crudo = $resultado->rawResponse['error'] ?? null;

        return $resultado->httpStatus === 404 && is_string($crudo) && stripos($crudo, 'no encontrado') !== false;
    }

    private function documentoFiscal(AdminContInvoice|AdminContCreditNote $modelo, string $tipoDocumento): FiscalDocument
    {
        return $modelo instanceof AdminContInvoice && $tipoDocumento === 'factura'
            ? $this->builder->paraFactura($modelo)
            : $this->builder->paraNotaCredito($modelo instanceof AdminContCreditNote ? $modelo : throw new \LogicException('Tipo de documento inconsistente.'));
    }

    /**
     * Un solo reintento a la vez por documento (doble clic, dos usuarios): lock en la caché (Redis en los EC2) con TTL
     * y la llave por tenant, sin estados intermedios en BD (un `pendiente` transitorio dispararía el polling y quedaría
     * huérfano si el proceso muere). El TTL de 90 s cubre GET (15 s) + POST (15 s) + almacenamiento del XML.
     *
     * @template T
     * @param \Closure(): T $accion
     * @return T
     */
    private function conBloqueo(AdminContInvoice|AdminContCreditNote $modelo, string $tipoDocumento, \Closure $accion): mixed
    {
        $tenant = Tenant::current()->slug ?? 'sin-tenant';
        $lock   = Cache::lock("fe-recertificar:{$tenant}:{$tipoDocumento}:{$modelo->id}", 90);
        if (! $lock->get()) {
            throw new RecertificacionNoPermitidaException(RecertificacionNoPermitidaException::EN_CURSO, 'Ya hay una acción en curso para este documento; espera unos segundos.');
        }

        try {
            return $accion();
        } finally {
            $lock->release();
        }
    }

    private function mensajeTrasReenvio(AdminContInvoice|AdminContCreditNote $modelo): string
    {
        return match ($modelo->fe_estado) {
            'certificado'         => 'Documento certificado ante la DGI.',
            'rechazado'           => 'La DGI rechazó el documento; revisa el motivo.',
            'error_configuracion' => 'No se pudo certificar por un problema de configuración; no es un rechazo de la DGI.',
            'pendiente'           => 'MSL encoló el documento; se consultará automáticamente.',
            default               => 'Operación completada.',
        };
    }

    /** @return array{accion: string, fe_estado: ?string, fe_detalle: ?string, cufe: ?string, mensaje: string, codigo: ?string} */
    private function resumenTrasReenvio(AdminContInvoice|AdminContCreditNote $modelo, string $desenlace): array
    {
        return match ($desenlace) {
            'ya_enviado_9022' => $this->resumen(
                $modelo,
                'reenviado',
                'MSL indica que ese número ya fue enviado a certificar (9022): el documento probablemente ya existe ante la DGI, pero el CUFE no se puede recuperar por API. No se modificó el estado del documento; contacta a MSL para confirmarlo.',
                'ya_certificado_9022',
            ),
            'sin_cambios_transporte' => $this->resumen($modelo, 'sin_cambios', 'MSL no está disponible ahora; el documento no cambió. Inténtalo de nuevo en unos minutos.'),
            'ventana_vencida' => $this->resumen($modelo, 'sin_cambios', (string) $modelo->fe_detalle, 'ventana_vencida'),
            default => $this->resumen($modelo, 'reenviado', $this->mensajeTrasReenvio($modelo)),
        };
    }

    /** @return array{accion: string, fe_estado: ?string, fe_detalle: ?string, cufe: ?string, mensaje: string, codigo: ?string} */
    private function resumen(AdminContInvoice|AdminContCreditNote $modelo, string $accion, string $mensaje, ?string $codigo = null): array
    {
        return [
            'accion'     => $accion,
            'fe_estado'  => $modelo->fe_estado,
            'fe_detalle' => $modelo->fe_detalle,
            'cufe'       => $modelo->cufe,
            'mensaje'    => $mensaje,
            'codigo'     => $codigo,
        ];
    }

    /** @param array<string, mixed> $extra */
    private function auditar(string $accion, AdminContInvoice|AdminContCreditNote $modelo, string $tipoDocumento, ?string $estadoAnterior, array $extra = []): void
    {
        $this->audit->log(
            action: $accion,
            entityType: $tipoDocumento === 'factura' ? 'invoice' : 'credit_note',
            entityId: $modelo->id,
            oldValues: ['fe_estado' => $estadoAnterior],
            newValues: ['fe_estado' => $modelo->fe_estado],
            metadata: ['tipo_documento' => $tipoDocumento] + $extra,
        );
    }

    /** ¿Ya pasó la ventana de reenvío desde la primera emisión? (0 horas = sin límite). */
    private function ventanaVencida(AdminContInvoice|AdminContCreditNote $modelo, AdminContFiscalSettings $settings): bool
    {
        $horas = (int) $settings->fe_reintento_max_horas;

        return $horas > 0 && $modelo->fe_emision_at !== null && $modelo->fe_emision_at->lt(now()->subHours($horas));
    }

    private function debeCertificar(AdminContFiscalSettings $settings, bool $habilitadoParaEsteTipo): bool
    {
        return $settings->pac_ambiente !== 'apagado' && $habilitadoParaEsteTipo;
    }

    private function certificar(
        AdminContInvoice|AdminContCreditNote $modelo,
        AdminContFiscalSettings $settings,
        string $tipoDocumento,
        \Closure $armar,
        string $origen = 'certificacion',
    ): void {
        $enviado = $this->enviar($modelo, $settings, $tipoDocumento, $armar, $origen);
        if ($enviado === null) {
            return;
        }

        [$documento, $resultado] = $enviado;
        $this->persistirResultado($modelo, $documento->tipoDocumento, $resultado, serie: $documento->serie, numeroFiscal: $documento->numero);
    }

    /**
     * Ensambla, envía al PAC y deja constancia de la transmisión, SIN persistir el resultado en el documento
     * (eso lo decide el caller: `certificar()` siempre; el reintento manual no cuando el resultado no cambia el
     * estado fiscal real, ver `reenviar()`). Devuelve `null` si el ensamblado/envío lanzó una excepción — en ese
     * caso ya quedó persistido `error_configuracion`.
     *
     * @return array{0: FiscalDocument, 1: FiscalDocumentResult}|null
     */
    private function enviar(
        AdminContInvoice|AdminContCreditNote $modelo,
        AdminContFiscalSettings $settings,
        string $tipoDocumento,
        \Closure $armar,
        string $origen,
    ): ?array {
        // SCRUM-1019 — la fecha de emisión se fija UNA vez, en el primer intento, y nunca se regenera: los reintentos
        // reutilizan `fe_emision_at`. Pasada la ventana (`fiscal_settings.fe_reintento_max_horas`, 24 h por defecto) NO se
        // reenvía: la DGI puede rechazar una fecha tan atrasada y regenerarla cambiaría el documento fiscal; queda en
        // `error_configuracion` con un mensaje claro para acción manual.
        if ($modelo->fe_emision_at === null) {
            $modelo->forceFill(['fe_emision_at' => now()])->save();
        } elseif ($this->ventanaVencida($modelo, $settings)) {
            $horas = $settings->fe_reintento_max_horas;
            Log::warning("Facturación electrónica: {$tipoDocumento} fuera de la ventana de reenvío, no se envía", [
                'id' => $modelo->id,
                'fe_emision_at' => $modelo->fe_emision_at->toIso8601String(),
                'ventana_horas' => $horas,
            ]);
            $modelo->forceFill([
                'fe_estado' => 'error_configuracion',
                'pac_provider_usado' => $settings->pac_provider,
                'fe_detalle' => "No se reenvió: pasaron más de {$horas} horas desde la primera emisión ({$modelo->fe_emision_at->toDateTimeString()}) y la DGI puede rechazar una fecha tan atrasada. "
                    .'Requiere acción manual: usa «Reconciliar con MSL» por si MSL ya lo tiene, o contacta a Administración.',
            ])->save();

            return null;
        }

        // SCRUM-1185 — antes de armar el documento, el monto/las líneas de una factura aún sin certificar y sin cobros se alinean al total
        // fiscal si hubo que balancear centavos. Nunca bloquea el envío.
        if ($modelo instanceof AdminContInvoice && $tipoDocumento === 'factura') {
            $this->alinearTotalFiscal($modelo);
        }

        try {
            $documento = $armar();
            $provider  = $this->providerFactory->make();
            $resultado = $tipoDocumento === 'factura'
                ? $provider->certificarFactura($documento)
                : $provider->certificarNotaCredito($documento);
        } catch (Throwable $e) {
            Log::error("Facturación electrónica: error ensamblando/certificando {$tipoDocumento}", [
                'id' => $modelo->id,
                'exception' => $e->getMessage(),
                'exception_class' => $e::class,
            ]);
            $modelo->forceFill([
                'fe_estado' => 'error_configuracion',
                'pac_provider_usado' => $settings->pac_provider,
                'fe_detalle' => $this->sanitizarDetalle($e->getMessage()),
            ])->save();

            return null;
        }

        $this->registrarTransmision($modelo, $documento->tipoDocumento, $resultado, $origen, $documento->serie, $documento->numero);

        return [$documento, $resultado];
    }

    /**
     * SCRUM-1185 — ver `FiscalTotalAligner`. Una falla acá se registra y el envío sigue: `FiscalDocumentItemsBuilder` balancea los mismos netos
     * de forma idéntica, así que el documento que viaja al PAC es el mismo con o sin alineación; solo cambiaría el `monto` de AtlanticERP.
     */
    private function alinearTotalFiscal(AdminContInvoice $invoice): void
    {
        try {
            $resultado = $this->alineador->alinear($invoice);
            if ($resultado['alineada']) {
                Log::info('Facturación electrónica: monto y líneas de la factura alineados al total fiscal', ['id' => $invoice->id, 'monto' => (float) $invoice->monto]);
            }
        } catch (Throwable $e) {
            Log::warning('Facturación electrónica: no se pudo alinear el total de la factura; se envía balanceada sin cambiar su monto', [
                'id' => $invoice->id, 'exception' => $e->getMessage(), 'exception_class' => $e::class,
            ]);
        }
    }

    /**
     * SCRUM-987 — constancia de un intento de transmisión (payload + respuesta). La llama también
     * `PollMslDocumentEstadoJob` en cada consulta de estado, resuelva o no.
     */
    public function registrarTransmision(
        AdminContInvoice|AdminContCreditNote $modelo,
        string $tipoDocumento,
        FiscalDocumentResult $resultado,
        string $origen,
        ?string $serie,
        ?string $numeroFiscal,
    ): void {
        $this->transmisiones->registrar($modelo, $tipoDocumento, $resultado, $origen, $serie, $numeroFiscal);
    }

    /**
     * SCRUM-943 tarea 3 — también la llama `PollMslDocumentEstadoJob` cuando resuelve un documento
     * que había quedado `pendiente` (sin `$serie`: ya está persistida desde la certificación
     * inicial, este método nunca la pisa con null). Único punto que agenda el polling — se
     * dispara acá y no en `certificar()` para que ambos callers (certificación inicial y el propio
     * job de polling) reusen la MISMA lógica de persistencia sin poder volver a agendarse a sí
     * mismos (el job nunca llega acá con `estado === 'pendiente'`, ver su docblock).
     */
    public function persistirResultado(
        AdminContInvoice|AdminContCreditNote $modelo,
        string $tipoDocumento,
        FiscalDocumentResult $resultado,
        ?string $serie = null,
        ?string $numeroFiscal = null,
    ): void {
        // `fe_detalle` se pisa siempre (null si el documento no quedó rechazado): una recertificación
        // exitosa no debe arrastrar el motivo de un rechazo anterior.
        $update = ['fe_estado' => $resultado->estado, 'pac_provider_usado' => $resultado->pacProvider, 'fe_detalle' => null];
        if ($serie !== null) {
            $update['serie'] = $serie;
        }

        if ($resultado->certificado()) {
            $update['cufe'] = $resultado->cufe;
            $update['qr_content'] = $resultado->qr;
            $update['fe_certificado_at'] = now();
            $update['xml_firmado_path'] = $this->guardarXmlFirmado($modelo, $tipoDocumento, $resultado->xmlFirmadoBase64);
        } else {
            // Sin truncar acá: `sanitizarDetalle()` redacta primero y trunca después, así una API key
            // que caiga justo en el corte no queda parcialmente visible. El log sí se acota aparte.
            $detalleMsl = is_string($resultado->rawResponse['error'] ?? null) ? $resultado->rawResponse['error'] : null;

            if (in_array($resultado->estado, ['rechazado', 'error_configuracion'], true)) {
                $update['fe_detalle'] = $this->sanitizarDetalle(
                    trim(implode('; ', $resultado->errores).($detalleMsl !== null ? "\n".$detalleMsl : ''))
                );
            }

            Log::warning('Facturación electrónica: documento no quedó certificado', [
                'id' => $modelo->id,
                'tipo' => $tipoDocumento,
                'estado' => $resultado->estado,
                'errores' => $resultado->errores,
                // SCRUM-987 — MSL manda `detalles` (mensaje corto, ej. "9026 - Falló la validación de
                // algunos escenarios.") Y `error` (texto largo con la regla que falló, ej. "VALIDATE_B07a").
                // `extractErrores()` se queda con lo primero; sin esto el motivo real del rechazo se
                // perdía y diagnosticar un 9026 exigió sondear el sandbox número por número.
                'detalle_msl' => $detalleMsl !== null ? mb_substr($detalleMsl, 0, 2000) : null,
            ]);
        }

        $modelo->forceFill($update)->save();

        // SCRUM-1186 — aviso por correo (solo producción, solo `rechazado`; nunca lanza). Cada transmisión rechazada avisa una vez.
        $this->notificador->avisar($modelo, $tipoDocumento, $resultado);

        if ($modelo instanceof AdminContCreditNote && $resultado->certificado()) {
            $this->liberarReemplazoRetenido($modelo);
        }

        if ($resultado->estado === 'pendiente') {
            PollMslDocumentEstadoJob::dispatch(
                serie: $serie ?? (string) $modelo->serie,
                numero: $modelo->numero,
                tipoDocumento: $tipoDocumento,
                tenantSlug: (string) (Tenant::current()->slug ?? ''),
                // SCRUM-987 — nunca $modelo->numero acá: MSL indexa el documento por el numero
                // puramente numérico que de verdad se le mandó (ver FiscalDocumentBuilder::
                // numeroFiscal()), no por el folio interno "F-XXXX"/"NC-XXXX".
                numeroFiscal: $numeroFiscal ?? $this->builder->numeroFiscal($modelo->numero),
            )->delay(now()->addSeconds(90));
        }
    }

    /**
     * SCRUM-1009 (D2) — cuando la NC de anulación de una "Corrección de datos" certifica (por la vía que sea:
     * certificación inicial, reintento manual de SCRUM-1007 o polling, todas pasan por `persistirResultado()`),
     * se libera la factura de reemplazo que quedó retenida en `en_espera_nc` y se certifica.
     *
     * Compare-and-set atómico: `UPDATE … WHERE fe_estado = 'en_espera_nc'`. Si dos caminos certifican la NC a la
     * vez (polling + reintento manual), solo uno afecta la fila y despacha; el otro no hace nada, así el
     * reemplazo se certifica UNA sola vez. Un reemplazo que nunca fue retenido (NC certificada al primer
     * intento) no está en `en_espera_nc`, por lo que este método no lo toca.
     */
    public function liberarReemplazoRetenido(AdminContCreditNote $nota): void
    {
        if ($nota->subtipo_anulacion !== AdminContCreditNote::SUBTIPO_CORRECCION || $nota->factura_nueva_id === null) {
            return;
        }

        $liberadas = AdminContInvoice::query()
            ->whereKey($nota->factura_nueva_id)
            ->where('fe_estado', self::ESTADO_EN_ESPERA_NC)
            ->update(['fe_estado' => null]);

        if ($liberadas !== 1) {
            return;
        }

        /** @var AdminContInvoice|null $reemplazo */
        $reemplazo = AdminContInvoice::find($nota->factura_nueva_id);
        if ($reemplazo === null) {
            return;
        }

        Log::info('Facturación electrónica: nota de anulación certificada, se libera el reemplazo retenido', [
            'nota_credito_id' => $nota->id,
            'factura_nueva_id' => $reemplazo->id,
        ]);

        Event::dispatch(new InvoiceCreated(
            invoiceId: $reemplazo->id,
            orderId: $reemplazo->order_id,
            numero: $reemplazo->numero,
            tenant: Tenant::current()->slug ?? '',
        ));
    }

    /**
     * Texto de un tercero (MSL/DGI) o de una excepción nuestra: acotado a 2000 caracteres y sin
     * ninguna de las API keys configuradas, por si un mensaje de error llegara a citarla.
     */
    private function sanitizarDetalle(string $texto): ?string
    {
        foreach (['sandbox', 'production'] as $ambiente) {
            $apiKey = trim((string) config("services.msl_fe.{$ambiente}.api_key"));
            if ($apiKey !== '') {
                $texto = str_replace($apiKey, '[redactado]', $texto);
            }
        }

        $texto = mb_substr(trim($texto), 0, 2000);

        return $texto === '' ? null : $texto;
    }

    private function guardarXmlFirmado(
        AdminContInvoice|AdminContCreditNote $modelo,
        string $tipoDocumento,
        ?string $xmlBase64,
    ): ?string {
        if ($xmlBase64 === null || $xmlBase64 === '') {
            return null;
        }

        $key = sprintf(
            'admin-contab/facturacion-electronica/%s/%s/%s.xml',
            $tipoDocumento,
            $modelo->id,
            Str::uuid()->toString(),
        );

        $contenido = base64_decode($xmlBase64, true);
        if ($contenido === false) {
            Log::error('Facturación electrónica: xmlFirmadoBase64 no es base64 válido, no se archiva', ['id' => $modelo->id]);

            return null;
        }

        try {
            (new CircuitBreaker(self::DISK))->call(function () use ($key, $contenido): void {
                $uploaded = Storage::disk(self::DISK)->put($key, $contenido);
                if ($uploaded === false) {
                    throw new \RuntimeException('S3 upload failed for disk ['.self::DISK."] key [{$key}]");
                }
            });
        } catch (Throwable $e) {
            // El documento SÍ quedó certificado (CUFE válido) — perder el archivo del XML firmado
            // no cambia ese hecho, solo queda sin archivar hasta un backfill manual.
            Log::error('Facturación electrónica: no se pudo archivar el XML firmado en S3', [
                'id' => $modelo->id,
                'exception' => $e->getMessage(),
            ]);

            return null;
        }

        return $key;
    }
}
