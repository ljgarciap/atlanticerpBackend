<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Services;

use App\Modules\Compras\Models\Provider;
use App\Modules\Legacy\Models\Proveedor;
use App\Modules\Legacy\Models\ProductoCosto;
use Illuminate\Support\Facades\DB;

/**
 * Épica SCRUM-749 (Migración ICG→AtlanticERP) — "Loader-B" de Proveedores, decisión explícita de Luis
 * 2026-08-21: reabre la decisión original de `legacy.proveedores` (sin fusión, ver
 * `Illumination/migracion-icg-diseno.md` §5) — proveedores recurrentes se convierten en
 * `compras.providers` reales.
 *
 * A diferencia de Clientes (que ya tenía matching por CIF/email desde SCRUM-755), Proveedores
 * NUNCA tuvo un paso de matching — el riesgo real que el diseño original señalaba ("mezclarlas
 * automáticamente arriesga duplicar o pisar algo real", §5) sigue vigente acá: `compras.providers`
 * puede tener proveedores ya cargados a mano por el equipo de Compras. Antes de crear, se intenta
 * un match por NOMBRE normalizado (trim + mayúsculas, único identificador cruzable — ICG no da
 * ningún otro campo comparable como un CIF) contra `compras.providers.name` existentes. Si matchea,
 * se linkea sin duplicar (`match_status = matched`); si no, se crea nuevo (`match_status =
 * promoted`) — mismo vocabulario/trazabilidad que `ClienteFusionLoader`.
 *
 * Alcance (decidido con Luis): candidato = `legacy.proveedores` con `provider_id IS NULL` y AL
 * MENOS 1 artículo en `legacy.producto_costos` (umbral ≥1, mismo criterio amplio que Clientes —
 * Luis lo prefirió sobre un umbral más estricto). Campos que ICG no trae (`origin`, `currency`) se
 * dejan en el default de la tabla (`internacional`/`USD`, vía `Provider::deriveOrigin(null)` para
 * seguir la regla real de negocio en vez de hardcodear el string) — decisión explícita de Luis, sin
 * dato real por proveedor disponible hoy.
 */
final class ProveedorFusionLoader
{
    private const CHUNK_SIZE = 500;

    /**
     * @return array{candidatos: int, matched: int, promovidos: int, errores: int}
     */
    public function fuse(): array
    {
        $candidateIds = $this->candidateIds();

        $matched    = 0;
        $promovidos = 0;
        $errores    = 0;

        foreach (array_chunk($candidateIds, self::CHUNK_SIZE) as $chunk) {
            $proveedores = Proveedor::query()->whereIn('id', $chunk)->get();

            foreach ($proveedores as $proveedor) {
                try {
                    if ($this->linkToExisting($proveedor)) {
                        $matched++;
                    } else {
                        $this->promote($proveedor);
                        $promovidos++;
                    }
                } catch (\Throwable) {
                    $errores++;
                }
            }
        }

        return [
            'candidatos' => count($candidateIds),
            'matched'    => $matched,
            'promovidos' => $promovidos,
            'errores'    => $errores,
        ];
    }

    /**
     * @return list<int>
     */
    private function candidateIds(): array
    {
        $table       = (new Proveedor())->getTable();
        $costosTable = (new ProductoCosto())->getTable();

        $ids = Proveedor::query()
            ->whereNull('provider_id')
            ->whereExists(function ($sub) use ($table, $costosTable): void {
                $sub->selectRaw('1')
                    ->from($costosTable)
                    ->whereColumn("{$costosTable}.codproveedor", "{$table}.icg_codproveedor");
            })
            ->pluck('id')
            ->all();

        return array_values(array_map(static fn (mixed $id): int => (int) $id, $ids));
    }

    /** @return bool true si encontró y linkeó un provider existente, false si no hay match */
    private function linkToExisting(Proveedor $proveedor): bool
    {
        $normalizedName = self::normalize($proveedor->nombre);

        $existing = Provider::query()
            ->whereRaw('UPPER(TRIM(name)) = ?', [$normalizedName])
            ->first();

        if ($existing === null) {
            return false;
        }

        $proveedor->provider_id  = $existing->id;
        $proveedor->match_status = Proveedor::MATCH_MATCHED;
        $proveedor->save();

        return true;
    }

    private function promote(Proveedor $proveedor): void
    {
        DB::transaction(function () use ($proveedor): void {
            $provider = Provider::query()->create([
                'name'    => $proveedor->nombre,
                'origin'  => Provider::deriveOrigin(null),
                'currency' => 'USD',
                'phone'   => $proveedor->telefono1,
                'email'   => $proveedor->email,
                'address' => $proveedor->direccion,
            ]);

            $proveedor->provider_id  = $provider->id;
            $proveedor->match_status = Proveedor::MATCH_PROMOTED;
            $proveedor->save();
        });
    }

    private static function normalize(string $name): string
    {
        return mb_strtoupper(trim($name));
    }
}
