<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Services;

use App\Modules\VentasDiseno\Exceptions\QuoteValidationBlockedException;
use App\Modules\VentasDiseno\Models\CotizaYaSettings;
use App\Modules\VentasDiseno\Models\MasterClient;
use App\Modules\VentasDiseno\Models\Quote;
use App\Modules\VentasDiseno\Models\QuoteConditionsSettings;
use App\Modules\VentasDiseno\Models\QuotePart;
use Illuminate\Support\Facades\DB;

/**
 * SCRUM-1120 — ciclo de vida de una cotización de CotizaYA (venta chica).
 *
 * Reglas que NO cambian respecto a Cotizaciones: partidas, precios, descuentos, motor de margen y totales (se reutilizan
 * los mismos modelos). Reglas propias: el cliente es siempre el fijo «CotizaYA»; Generar solo exige al menos un ítem
 * (y el margen mínimo, salvo el permiso de excepción); no hay Subcliente/RUC/Proyecto/Contacto/Fecha de entrega.
 *
 * Generar deja la cotización FIJA: asigna folio propio y `confirmed_at`, que es lo que ya bloquea toda edición en los
 * controladores de partidas/ítems/contactos. No usa `QuoteService::confirm()` ni `generate()` — nunca toca Pipeline,
 * proyectos ni eventos del flujo comercial.
 */
final class CotizaYaService
{
    public const NOT_CONFIGURED_MESSAGE = 'CotizaYA no está configurado en este entorno: falta el cliente CotizaYA.';

    public function __construct(private readonly CotizaYaFolioService $folios) {}

    /**
     * @param array<string, mixed> $data campos propios ya validados
     */
    public function createDraft(array $data, int $ownerId): ?Quote
    {
        $settings = CotizaYaSettings::current();
        if ($settings === null || MasterClient::find($settings->master_client_id) === null) {
            return null;
        }

        return Quote::create(array_merge($data, [
            'origin'           => Quote::ORIGIN_COTIZAYA,
            'master_client_id' => $settings->master_client_id,
            'sub_client_id'    => $settings->sub_client_id,
            'owner_id'         => $ownerId,
            // Mismo criterio que QuoteService::create(): las Condiciones vigentes se congelan en la cotización.
            'conditions_text'  => QuoteConditionsSettings::current()->text,
        ]));
    }

    /**
     * @throws QuoteValidationBlockedException
     */
    public function generate(Quote $quote, bool $skipMarginCheck = false): Quote
    {
        return DB::transaction(function () use ($quote, $skipMarginCheck): Quote {
            // Se vuelve a leer BAJO BLOQUEO: dos pestañas (o un doble clic) generando a la vez no pueden pasar ambas la
            // revisión de "ya tiene folio" con el modelo viejo en memoria, que sobrescribiría el folio de la primera y
            // dejaría un hueco en la serie.
            /** @var Quote $locked */
            $locked = Quote::query()->lockForUpdate()->findOrFail($quote->id);
            if ($locked->folio !== null) {
                return $locked; // idempotente: generar dos veces no cambia el folio
            }

            $locked->load('parts.items');

            $missing = [];
            if ($locked->parts->flatMap(fn (QuotePart $p) => $p->items)->isEmpty()) {
                $missing[] = 'Ítems';
            }
            if ($missing === [] && ! $skipMarginCheck && $locked->hasAnyMarginViolation()) {
                $missing[] = 'Margen';
            }
            if ($missing !== []) {
                throw new QuoteValidationBlockedException($missing);
            }

            $now = now();
            $locked->update(['folio' => $this->folios->next(), 'generated_at' => $now, 'confirmed_at' => $now]);

            return $locked;
        });
    }
}
