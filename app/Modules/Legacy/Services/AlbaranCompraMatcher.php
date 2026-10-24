<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Services;

use App\Modules\Legacy\Models\AlbaranCompra;
use App\Modules\Legacy\Models\Proveedor;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * SCRUM-840 — resuelve `legacy.albaranes_compra.legacy_proveedor_id`/`orphan` por LOOKUP SIMPLE
 * contra `legacy.proveedores.icg_codproveedor` (ya poblada, SCRUM-756) — mismo patrón exacto que
 * `AlbaranMatcher`, pero vinculando a Proveedor en vez de Cliente. Proveedores es standalone
 * completo (§5 del diseño), así que en la práctica `orphan` debería ser raro — igual se tolera,
 * nunca se descarta el documento (§5: "nunca se inventa un link").
 *
 * Scopeado a los `id` internos de `legacy.albaranes_compra` de LA CORRIDA ACTUAL (recibidos ya
 * resueltos por `AlbaranCompraStagingLoader::loadPaginated()`), mismo criterio que `AlbaranMatcher`.
 */
final class AlbaranCompraMatcher
{
    private const CHUNK_SIZE = 500;

    /**
     * @param  list<int> $albaranIds ids internos de `legacy.albaranes_compra` (esta corrida)
     * @return array{matched: int, orphan: int, errores: int, details: list<array{referencia: string, status: string, motivo: string}>}
     */
    public function match(array $albaranIds): array
    {
        if ($albaranIds === []) {
            return ['matched' => 0, 'orphan' => 0, 'errores' => 0, 'details' => []];
        }

        return DB::transaction(function () use ($albaranIds): array {
            $matched = 0;
            $orphan  = 0;
            $errores = 0;
            $details = [];

            foreach (array_chunk($albaranIds, self::CHUNK_SIZE) as $idChunk) {
                $albaranes = AlbaranCompra::query()->whereIn('id', $idChunk)->get();

                /** @var list<int> $codigos */
                $codigos = $albaranes
                    ->pluck('icg_codproveedor')
                    ->filter(static fn (mixed $codigo): bool => $codigo !== null)
                    ->map(static fn (mixed $codigo): int => (int) $codigo)
                    ->unique()
                    ->values()
                    ->all();

                $proveedorIdsByCodigo = $this->resolveProveedorIds($codigos);

                foreach ($albaranes as $albaran) {
                    $legacyProveedorId = $albaran->icg_codproveedor !== null
                        ? ($proveedorIdsByCodigo[$albaran->icg_codproveedor] ?? null)
                        : null;
                    $referencia = "{$albaran->icg_numserie}-{$albaran->icg_numalbaran}";

                    try {
                        $albaran->legacy_proveedor_id = $legacyProveedorId;
                        $albaran->orphan              = $legacyProveedorId === null;
                        $albaran->save();
                    } catch (Throwable $e) {
                        $errores++;
                        $details[] = ['referencia' => $referencia, 'status' => 'error', 'motivo' => $e->getMessage()];

                        continue;
                    }

                    if ($legacyProveedorId === null) {
                        $orphan++;
                        $motivo = $albaran->icg_codproveedor === null
                            ? 'sin icg_codproveedor en ICG'
                            : "icg_codproveedor {$albaran->icg_codproveedor} sin match en legacy.proveedores";
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
     * @param  list<int>       $icgCodproveedores
     * @return array<int, int> icg_codproveedor => id interno de legacy.proveedores
     */
    private function resolveProveedorIds(array $icgCodproveedores): array
    {
        if ($icgCodproveedores === []) {
            return [];
        }

        $map = [];
        foreach (array_chunk($icgCodproveedores, self::CHUNK_SIZE) as $chunk) {
            $map += Proveedor::query()
                ->whereIn('icg_codproveedor', $chunk)
                ->pluck('id', 'icg_codproveedor')
                ->all();
        }

        return $map;
    }
}
