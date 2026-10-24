<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Services;

use App\Modules\Legacy\Models\Cliente;
use App\Modules\Legacy\Models\Factura;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * SCRUM-760 — resuelve `legacy.facturas.legacy_cliente_id`/`orphan` por LOOKUP SIMPLE contra
 * `legacy.clientes.icg_codcliente` (ya poblada y matcheada por CIF/email en SCRUM-755). Mismo
 * patrón exacto que `AlbaranMatcher`/`PedidoMatcher` — esta clase **no re-matchea por CIF/email**.
 *
 * Regla (§5, tabla "Estrategia de vinculación"): `icg_codcliente` de la factura se busca en
 * `legacy.clientes.icg_codcliente`. Si resuelve, `legacy_cliente_id` = el `id` interno de ese
 * cliente y `orphan = false`. Si no resuelve (o `icg_codcliente` es `null`), `legacy_cliente_id =
 * null` y `orphan = true` — **la factura se importa igual, nunca se descarta** (§5).
 *
 * Scopeado a la lista de `id` internos de `legacy.facturas` de LA CORRIDA ACTUAL (recibidos ya
 * resueltos por `FacturaStagingLoader::load()`), no a todo el historial — mismo criterio que
 * `AlbaranMatcher`.
 *
 * Chunkeado en ambas queries — Facturas puede llegar a 60,215 cabeceras reales (§2), muy por debajo
 * del límite de 65535 parámetros de Postgres para un `whereIn` sin chunkear, pero se mantiene el
 * mismo margen conservador por consistencia de patrón.
 */
final class FacturaMatcher
{
    private const CHUNK_SIZE = 500;

    /**
     * @param  list<int> $facturaIds ids internos de `legacy.facturas` (esta corrida)
     * @return array{matched: int, orphan: int, errores: int, details: list<array{referencia: string, status: string, motivo: string}>}
     */
    public function match(array $facturaIds): array
    {
        if ($facturaIds === []) {
            return ['matched' => 0, 'orphan' => 0, 'errores' => 0, 'details' => []];
        }

        return DB::transaction(function () use ($facturaIds): array {
            $matched = 0;
            $orphan  = 0;
            $errores = 0;
            $details = [];

            foreach (array_chunk($facturaIds, self::CHUNK_SIZE) as $idChunk) {
                $facturas = Factura::query()->whereIn('id', $idChunk)->get();

                /** @var list<int> $codigos */
                $codigos = $facturas
                    ->pluck('icg_codcliente')
                    ->filter(static fn (mixed $codigo): bool => $codigo !== null)
                    ->map(static fn (mixed $codigo): int => (int) $codigo)
                    ->unique()
                    ->values()
                    ->all();

                $clienteIdsByCodigo = $this->resolveClienteIds($codigos);

                foreach ($facturas as $factura) {
                    $legacyClienteId = $factura->icg_codcliente !== null
                        ? ($clienteIdsByCodigo[$factura->icg_codcliente] ?? null)
                        : null;
                    $referencia = $factura->sufactura ?? "{$factura->icg_numserie}-{$factura->icg_numfactura}";

                    try {
                        $factura->legacy_cliente_id = $legacyClienteId;
                        $factura->orphan            = $legacyClienteId === null;
                        $factura->save();
                    } catch (Throwable $e) {
                        $errores++;
                        $details[] = ['referencia' => $referencia, 'status' => 'error', 'motivo' => $e->getMessage()];

                        continue;
                    }

                    if ($legacyClienteId === null) {
                        $orphan++;
                        $motivo = $factura->icg_codcliente === null
                            ? 'sin icg_codcliente en ICG'
                            : "icg_codcliente {$factura->icg_codcliente} sin match en legacy.clientes";
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
