<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Services;

use App\Modules\Legacy\Models\Albaran;
use App\Modules\Legacy\Models\Cliente;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * SCRUM-759 — resuelve `legacy.albaranes.legacy_cliente_id`/`orphan` por LOOKUP SIMPLE contra
 * `legacy.clientes.icg_codcliente` (ya poblada y matcheada por CIF/email en SCRUM-755). Mismo
 * patrón exacto que `PedidoMatcher`/`CotizacionMatcher` (SCRUM-758/757) — esta clase **no
 * re-matchea por CIF/email**, ese trabajo ya lo hizo `ClienteMatcher` sobre `legacy.clientes`
 * (runbook §12.1: "Cotizaciones/Pedidos/Albaranes/Facturas por icg_codcliente → legacy_cliente_id").
 *
 * Regla (§5, tabla "Estrategia de vinculación"): `icg_codcliente` del albarán se busca en
 * `legacy.clientes.icg_codcliente`. Si resuelve, `legacy_cliente_id` = el `id` interno de ese
 * cliente y `orphan = false`. Si no resuelve (o `icg_codcliente` es `null`), `legacy_cliente_id =
 * null` y `orphan = true` — **el albarán se importa igual, nunca se descarta** (§5: "nunca se
 * inventa un link"). Nota: `ALBVENTACAB.CODCLIENTE→CLIENTES` SÍ declara FK real en ICG (§2, a
 * diferencia del link "por convención" de Pedidos/Cotizaciones), pero igual puede no resolver acá
 * si ese cliente específico no matcheó al staging de `legacy.clientes` (CIF ausente/duplicado,
 * etc.) — el mismo criterio conservador aplica sin excepción.
 *
 * Scopeado a la lista de `id` internos de `legacy.albaranes` de LA CORRIDA ACTUAL (recibidos ya
 * resueltos por `AlbaranStagingLoader::load()`), no a todo el historial — mismo criterio que
 * `PedidoMatcher`.
 *
 * Chunkeado en ambas queries — Albaranes puede llegar a ~60,985 cabeceras reales (confirmado
 * 2026-08-17), muy por debajo del límite de 65535 parámetros de Postgres para un `whereIn` sin
 * chunkear, pero se mantiene el mismo margen conservador que `PedidoMatcher`/`CotizacionMatcher`
 * por consistencia de patrón.
 */
final class AlbaranMatcher
{
    private const CHUNK_SIZE = 500;

    /**
     * @param  list<int> $albaranIds ids internos de `legacy.albaranes` (esta corrida)
     * @return array{matched: int, orphan: int, errores: int}
     */
    public function match(array $albaranIds): array
    {
        if ($albaranIds === []) {
            return ['matched' => 0, 'orphan' => 0, 'errores' => 0];
        }

        return DB::transaction(function () use ($albaranIds): array {
            $matched = 0;
            $orphan  = 0;
            $errores = 0;

            foreach (array_chunk($albaranIds, self::CHUNK_SIZE) as $idChunk) {
                $albaranes = Albaran::query()->whereIn('id', $idChunk)->get();

                /** @var list<int> $codigos */
                $codigos = $albaranes
                    ->pluck('icg_codcliente')
                    ->filter(static fn (mixed $codigo): bool => $codigo !== null)
                    ->map(static fn (mixed $codigo): int => (int) $codigo)
                    ->unique()
                    ->values()
                    ->all();

                $clienteIdsByCodigo = $this->resolveClienteIds($codigos);

                foreach ($albaranes as $albaran) {
                    $legacyClienteId = $albaran->icg_codcliente !== null
                        ? ($clienteIdsByCodigo[$albaran->icg_codcliente] ?? null)
                        : null;

                    try {
                        $albaran->legacy_cliente_id = $legacyClienteId;
                        $albaran->orphan            = $legacyClienteId === null;
                        $albaran->save();
                    } catch (Throwable) {
                        $errores++;

                        continue;
                    }

                    if ($legacyClienteId === null) {
                        $orphan++;
                    } else {
                        $matched++;
                    }
                }
            }

            return ['matched' => $matched, 'orphan' => $orphan, 'errores' => $errores];
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
