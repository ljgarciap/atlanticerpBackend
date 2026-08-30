<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Services;

use App\Modules\Legacy\Models\Cliente;
use App\Modules\Legacy\Models\Pedido;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * SCRUM-758 — resuelve `legacy.pedidos.legacy_cliente_id`/`orphan` por LOOKUP SIMPLE contra
 * `legacy.clientes.icg_codcliente` (ya poblada y matcheada por CIF/email en SCRUM-755). Mismo
 * patrón exacto que `CotizacionMatcher` (SCRUM-757) — esta clase **no re-matchea por CIF/email**,
 * ese trabajo ya lo hizo `ClienteMatcher` sobre `legacy.clientes` (runbook §12.1: "Cotizaciones/
 * Pedidos/.../Facturas por icg_codcliente → legacy_cliente_id").
 *
 * Regla (§5, tabla "Estrategia de vinculación"): `icg_codcliente` del pedido se busca en
 * `legacy.clientes.icg_codcliente`. Si resuelve, `legacy_cliente_id` = el `id` interno de ese
 * cliente y `orphan = false`. Si no resuelve (o `icg_codcliente` es `null`), `legacy_cliente_id =
 * null` y `orphan = true` — **el pedido se importa igual, nunca se descarta** (§5: "nunca se
 * inventa un link").
 *
 * Scopeado a la lista de `id` internos de `legacy.pedidos` de LA CORRIDA ACTUAL (recibidos ya
 * resueltos por `PedidoStagingLoader::load()`), no a todo el historial — mismo criterio que
 * `CotizacionMatcher`.
 *
 * Chunkeado en ambas queries — Pedidos puede llegar a 43,778 cabeceras reales (§2), muy por debajo
 * del límite de 65535 parámetros de Postgres para un `whereIn` sin chunkear, pero se mantiene el
 * mismo margen conservador que `CotizacionMatcher` (94,823 filas) por consistencia de patrón.
 */
final class PedidoMatcher
{
    private const CHUNK_SIZE = 500;

    /**
     * @param  list<int> $pedidoIds ids internos de `legacy.pedidos` (esta corrida)
     * @return array{matched: int, orphan: int, errores: int}
     */
    public function match(array $pedidoIds): array
    {
        if ($pedidoIds === []) {
            return ['matched' => 0, 'orphan' => 0, 'errores' => 0];
        }

        return DB::transaction(function () use ($pedidoIds): array {
            $matched = 0;
            $orphan  = 0;
            $errores = 0;

            foreach (array_chunk($pedidoIds, self::CHUNK_SIZE) as $idChunk) {
                $pedidos = Pedido::query()->whereIn('id', $idChunk)->get();

                /** @var list<int> $codigos */
                $codigos = $pedidos
                    ->pluck('icg_codcliente')
                    ->filter(static fn (mixed $codigo): bool => $codigo !== null)
                    ->map(static fn (mixed $codigo): int => (int) $codigo)
                    ->unique()
                    ->values()
                    ->all();

                $clienteIdsByCodigo = $this->resolveClienteIds($codigos);

                foreach ($pedidos as $pedido) {
                    $legacyClienteId = $pedido->icg_codcliente !== null
                        ? ($clienteIdsByCodigo[$pedido->icg_codcliente] ?? null)
                        : null;

                    try {
                        $pedido->legacy_cliente_id = $legacyClienteId;
                        $pedido->orphan            = $legacyClienteId === null;
                        $pedido->save();
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
