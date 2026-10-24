<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Services;

use App\Modules\Legacy\Models\Cliente;
use App\Modules\Legacy\Models\Cotizacion;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * SCRUM-757 — resuelve `legacy.cotizaciones.legacy_cliente_id`/`orphan` por LOOKUP SIMPLE contra
 * `legacy.clientes.icg_codcliente` (ya poblada y matcheada por CIF/email en SCRUM-755). A
 * diferencia de `ClienteMatcher`, esta clase **no re-matchea por CIF/email** — ese trabajo ya lo
 * hizo `ClienteMatcher` sobre `legacy.clientes` (runbook §12.1: "Cotizaciones/.../Facturas por
 * icg_codcliente → legacy_cliente_id").
 *
 * Regla (§5, tabla "Estrategia de vinculación"): `icg_codcliente` de la cotización se busca en
 * `legacy.clientes.icg_codcliente`. Si resuelve, `legacy_cliente_id` = el `id` interno de ese
 * cliente y `orphan = false`. Si no resuelve (o `icg_codcliente` es `null`), `legacy_cliente_id =
 * null` y `orphan = true` — **la cotización se importa igual, nunca se descarta** (§5: "nunca se
 * inventa un link").
 *
 * Scopeado a la lista de `id` internos de `legacy.cotizaciones` de LA CORRIDA ACTUAL (recibidos ya
 * resueltos por `CotizacionStagingLoader::load()`), no a todo el historial — mismo criterio que
 * `ClienteMatcher`/`ProductoMapper::buildPayloads()`.
 *
 * Chunkeado en ambas queries (a diferencia de `ClienteMatcher`, que no lo necesitó — Clientes son
 * 7,707 filas, muy por debajo del límite de 65535 parámetros de Postgres). Cotizaciones puede
 * llegar a 94,823 cabeceras reales (§2) — un solo `whereIn('id', $todos)` sin chunkear arriesgaría
 * ese límite en una corrida completa real, ver §13.3.
 */
final class CotizacionMatcher
{
    private const CHUNK_SIZE = 500;

    /**
     * @param  list<int> $cotizacionIds ids internos de `legacy.cotizaciones` (esta corrida)
     * @return array{matched: int, orphan: int, errores: int, details: list<array{referencia: string, status: string, motivo: string}>}
     */
    public function match(array $cotizacionIds): array
    {
        if ($cotizacionIds === []) {
            return ['matched' => 0, 'orphan' => 0, 'errores' => 0, 'details' => []];
        }

        return DB::transaction(function () use ($cotizacionIds): array {
            $matched = 0;
            $orphan  = 0;
            $errores = 0;
            $details = [];

            foreach (array_chunk($cotizacionIds, self::CHUNK_SIZE) as $idChunk) {
                $cotizaciones = Cotizacion::query()->whereIn('id', $idChunk)->get();

                /** @var list<int> $codigos */
                $codigos = $cotizaciones
                    ->pluck('icg_codcliente')
                    ->filter(static fn (mixed $codigo): bool => $codigo !== null)
                    ->map(static fn (mixed $codigo): int => (int) $codigo)
                    ->unique()
                    ->values()
                    ->all();

                $clienteIdsByCodigo = $this->resolveClienteIds($codigos);

                foreach ($cotizaciones as $cotizacion) {
                    $legacyClienteId = $cotizacion->icg_codcliente !== null
                        ? ($clienteIdsByCodigo[$cotizacion->icg_codcliente] ?? null)
                        : null;
                    $referencia = $cotizacion->supresupuesto ?? "{$cotizacion->icg_numserie}-{$cotizacion->icg_numpresupuesto}";

                    try {
                        $cotizacion->legacy_cliente_id = $legacyClienteId;
                        $cotizacion->orphan            = $legacyClienteId === null;
                        $cotizacion->save();
                    } catch (Throwable $e) {
                        $errores++;
                        $details[] = ['referencia' => $referencia, 'status' => 'error', 'motivo' => $e->getMessage()];

                        continue;
                    }

                    if ($legacyClienteId === null) {
                        $orphan++;
                        $motivo = $cotizacion->icg_codcliente === null
                            ? 'sin icg_codcliente en ICG'
                            : "icg_codcliente {$cotizacion->icg_codcliente} sin match en legacy.clientes";
                        $details[] = ['referencia' => $referencia, 'status' => 'orphan', 'motivo' => $motivo];
                    } else {
                        $matched++;
                    }
                }
            }

            return ['matched' => $matched, 'orphan' => $orphan, 'errores' => $errores, 'details' => $details];
        });
    }

    /**
     * @param  list<int>       $icgCodclientes
     * @return array<int, int> icg_codcliente => id interno de legacy.clientes
     */
    private function resolveClienteIds(array $icgCodclientes): array
    {
        if ($icgCodclientes === []) {
            return [];
        }

        $map = [];
        foreach (array_chunk($icgCodclientes, self::CHUNK_SIZE) as $chunk) {
            $map += Cliente::query()
                ->whereIn('icg_codcliente', $chunk)
                ->pluck('id', 'icg_codcliente')
                ->all();
        }

        return $map;
    }
}
