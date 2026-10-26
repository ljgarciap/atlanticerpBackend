<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Services;

use App\Modules\Legacy\Data\ExtractedFacturas;
use App\Modules\Legacy\Data\FacturaRow;
use App\Modules\Legacy\Models\Factura;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * SCRUM-760 — "Loader-A" (staging). Upsert idempotente en `legacy.facturas` por la llave natural
 * compuesta de ICG (`icg_numserie`+`icg_numfactura`+`icg_n`, UNIQUE — 3 columnas, mismo patrón que
 * `AlbaranStagingLoader`/`PedidoStagingLoader`; `icg_n` es texto, no entero). Re-correr el mismo
 * extracto dos veces nunca duplica ni pisa mal:
 *
 *  - `legacy.facturas`: upsert por la llave compuesta. `legacy_cliente_id`/`orphan` NUNCA se
 *    incluyen en el `$update` de `upsert()` — así una segunda corrida jamás resetea el link/estado
 *    que puso `FacturaMatcher` en una corrida anterior.
 *
 * Sin líneas — a diferencia de `AlbaranStagingLoader`/`PedidoStagingLoader`, FACTURASVENTA no tiene
 * tabla de líneas documentada (ver docblock de la migración), así que este Loader es más simple:
 * un único `upsertFacturas()`, sin el patrón delete-then-insert de items.
 *
 * Requiere tenant activo (`Tenant::current()`, ver `getTable()` de `Factura`) — el llamador
 * (`App\Modules\Legacy\Console\Commands\LegacySyncFacturasCommand`) es responsable de `makeCurrent()` antes de
 * invocar `load()`.
 */
final class FacturaStagingLoader
{
    /**
     * Postgres tiene un límite duro de 65535 parámetros bindeados por statement (§13.3 del
     * diseño). Mismo margen que `AlbaranStagingLoader`/`PedidoStagingLoader` — 500 deja de sobra.
     */
    private const CHUNK_SIZE = 500;

    /**
     * Envuelto en su propia transacción — mismo criterio que `AlbaranStagingLoader::load()`.
     *
     * @return array{facturas: int, factura_ids: list<int>}
     */
    public function load(ExtractedFacturas $extracted): array
    {
        $now = now();

        return DB::transaction(function () use ($extracted, $now): array {
            $facturasCount = $this->upsertFacturas($extracted->facturas, $now);
            $idsByKey       = $this->resolveFacturaIds($extracted->compositeKeys());

            return [
                'facturas'    => $facturasCount,
                'factura_ids' => array_values($idsByKey),
            ];
        });
    }

    /** @param list<FacturaRow> $rows */
    private function upsertFacturas(array $rows, Carbon $now): int
    {
        if ($rows === []) {
            return 0;
        }

        $values = array_map(static fn (FacturaRow $row): array => [
            'icg_numserie'     => $row->icgNumserie,
            'icg_numfactura'   => $row->icgNumfactura,
            'icg_n'            => $row->icgN,
            'icg_codcliente'   => $row->icgCodcliente,
            'icg_codvendedor'  => $row->icgCodvendedor,
            'fecha'            => $row->fecha?->format('Y-m-d H:i:s'),
            'total_neto'       => $row->totalNeto,
            'total_impuestos'  => $row->totalImpuestos,
            'total_bruto'      => $row->totalBruto,
            'sufactura'        => $row->sufactura,
            // SCRUM-841 — Facturación Electrónica. SÍ se incluyen en el $update (a diferencia de
            // legacy_cliente_id/orphan): estos campos no dependen de ningún Matcher, vienen
            // directo del Extractor en cada corrida, así que una segunda corrida debe poder
            // refrescarlos (ej. una factura que se autoriza ante la DGI después de la primera
            // corrida de import).
            'fe_qr'               => $row->feQr,
            'fe_auth_number'      => $row->feAuthNumber,
            'fe_serie'            => $row->feSerie,
            'fe_numero'           => $row->feNumero,
            'fe_fecha_emision'    => $row->feFechaEmision,
            'fe_fecha_recepcion'  => $row->feFechaRecepcion,
            'fe_error'            => $row->feError,
            'fe_estado'           => $row->feEstado,
            'created_at'       => $now,
        ], $rows);

        foreach (array_chunk($values, self::CHUNK_SIZE) as $chunk) {
            Factura::query()->upsert(
                $chunk,
                ['icg_numserie', 'icg_numfactura', 'icg_n'],
                [
                    'icg_codcliente', 'icg_codvendedor', 'fecha', 'total_neto', 'total_impuestos',
                    'total_bruto', 'sufactura',
                    'fe_qr', 'fe_auth_number', 'fe_serie', 'fe_numero',
                    'fe_fecha_emision', 'fe_fecha_recepcion', 'fe_error', 'fe_estado',
                ],
            );
        }

        return count($values);
    }

    /**
     * Resuelve el `id` interno de cada cabecera por su llave natural compuesta — Postgres soporta
     * comparación de tuplas nativa, evita traer TODA la tabla o hacer N queries individuales.
     *
     * @param  list<array{0: string, 1: int, 2: string}> $keys
     * @return array<string, int> llave compuesta (string) => id interno
     */
    private function resolveFacturaIds(array $keys): array
    {
        if ($keys === []) {
            return [];
        }

        $map = [];
        foreach (array_chunk($keys, self::CHUNK_SIZE) as $chunk) {
            $placeholders = implode(', ', array_fill(0, count($chunk), '(?, ?, ?)'));
            $bindings     = [];
            foreach ($chunk as $key) {
                $bindings[] = $key[0];
                $bindings[] = $key[1];
                $bindings[] = $key[2];
            }

            $rows = Factura::query()
                ->select(['id', 'icg_numserie', 'icg_numfactura', 'icg_n'])
                ->whereRaw("(icg_numserie, icg_numfactura, icg_n) IN ({$placeholders})", $bindings)
                ->get();

            foreach ($rows as $row) {
                $compositeKey = ExtractedFacturas::compositeKeyString(
                    (string) $row->icg_numserie,
                    (int) $row->icg_numfactura,
                    (string) $row->icg_n,
                );

                $map[$compositeKey] = (int) $row->id;
            }
        }

        return $map;
    }
}
