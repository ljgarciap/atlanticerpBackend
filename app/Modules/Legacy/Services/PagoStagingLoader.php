<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Services;

use App\Modules\Legacy\Data\ExtractedPagos;
use App\Modules\Legacy\Data\PagoRow;
use App\Modules\Legacy\Models\Pago;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * SCRUM-761 — "Loader-A" (staging). Upsert idempotente en `legacy.pagos` por la llave natural
 * compuesta REAL de ICG (`icg_origen`+`icg_tipodocumento`+`icg_serie`+`icg_numero`+`icg_n`+
 * `icg_posicion`, UNIQUE — 6 columnas, ver docblock de la migración). Re-correr el mismo extracto
 * dos veces nunca duplica ni pisa mal — es el único paso del pipeline (sin Matcher, sin Loader-B,
 * mismo patrón que `ProveedorStagingLoader`): el pipeline de esta entidad termina acá.
 *
 * Requiere tenant activo (`Tenant::current()`, ver `getTable()` de `Pago`) — el llamador
 * (`App\Console\Commands\LegacySyncPagosCommand`) es responsable de `makeCurrent()` antes de
 * invocar `load()`/`loadPaginated()`.
 *
 * `loadPaginated()` agregado 2026-08-27 (hallazgo real: 2 OOM kills confirmados en dev.atlanticerp.ai,
 * ver docblock de `App\Modules\Legacy\Extractors\PagoExtractor`) — `load()` sigue existiendo tal
 * cual para datasets acotados (todos los tests existentes lo usan sin cambios), pero asume que el
 * llamador YA tiene las 145,250 filas completas en memoria, que es exactamente lo que causa el OOM
 * real. `loadPaginated()` recibe un callback que pagina la extracción y nunca materializa más de
 * UNA página a la vez — misma atomicidad (una sola `DB::transaction()` para toda la corrida).
 */
final class PagoStagingLoader
{
    /**
     * Postgres tiene un límite duro de 65535 parámetros bindeados por statement (§13.3 del
     * diseño). Con hasta 12 columnas por fila (6 de llave + 6 de datos) y TESORERIA pudiendo
     * llegar a 144,194 filas reales (§2), 500 deja margen de sobra por chunk (500*12=6000, muy por
     * debajo del límite).
     */
    private const CHUNK_SIZE = 500;

    /**
     * Envuelto en su propia transacción — mismo criterio que `ProveedorStagingLoader::load()`
     * (hallazgo Pre-QA SCRUM-750): un fallo a mitad de camino deja la conexión Postgres limpia
     * para que `legacy.sync_runs` pueda registrar la corrida fallida.
     *
     * @return array{pagos: int}
     */
    public function load(ExtractedPagos $extracted): array
    {
        $now = now();

        return DB::transaction(fn (): array => [
            'pagos' => $this->upsertPagos($extracted->pagos, $now),
        ]);
    }

    /**
     * Entrada paginada para volumen real (ver docblock de la clase) — nunca materializa más de UNA
     * página en memoria a la vez. Misma atomicidad que `load()` (una sola transacción para toda la
     * corrida, todas las páginas).
     *
     * @param  callable(int, int): list<PagoRow> $pageFetcher ($offset, $limit) => página, [] = fin
     * @return array{pagos: int}
     */
    public function loadPaginated(callable $pageFetcher, int $pageSize = 20000): array
    {
        return DB::transaction(function () use ($pageFetcher, $pageSize): array {
            $now   = now();
            $total = 0;
            $offset = 0;
            while (true) {
                /** @var list<PagoRow> $page */
                $page = $pageFetcher($offset, $pageSize);
                if ($page === []) {
                    break;
                }

                $total += $this->upsertPagos($page, $now);

                if (count($page) < $pageSize) {
                    break;
                }

                $offset += $pageSize;
            }

            return ['pagos' => $total];
        });
    }

    /** @param list<PagoRow> $rows */
    private function upsertPagos(array $rows, Carbon $now): int
    {
        if ($rows === []) {
            return 0;
        }

        $values = array_map(static fn (PagoRow $row): array => [
            'icg_origen'         => $row->icgOrigen,
            'icg_tipodocumento'  => $row->icgTipodocumento,
            'icg_serie'          => $row->icgSerie,
            'icg_numero'         => $row->icgNumero,
            'icg_n'              => $row->icgN,
            'icg_posicion'       => $row->icgPosicion,
            'fecha_documento'    => $row->fechaDocumento?->format('Y-m-d H:i:s'),
            'fecha_vencimiento'  => $row->fechaVencimiento?->format('Y-m-d H:i:s'),
            'importe'            => $row->importe,
            'icg_codformapago'   => $row->icgCodformapago,
            'estado'             => $row->estado,
            'fecha_saldado'      => $row->fechaSaldado?->format('Y-m-d H:i:s'),
            'created_at'         => $now,
            'updated_at'         => $now,
        ], $rows);

        foreach (array_chunk($values, self::CHUNK_SIZE) as $chunk) {
            Pago::query()->upsert(
                $chunk,
                ['icg_origen', 'icg_tipodocumento', 'icg_serie', 'icg_numero', 'icg_n', 'icg_posicion'],
                [
                    'fecha_documento', 'fecha_vencimiento', 'importe', 'icg_codformapago', 'estado',
                    'fecha_saldado', 'updated_at',
                ],
            );
        }

        return count($values);
    }
}
