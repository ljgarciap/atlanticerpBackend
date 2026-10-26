<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Services;

use App\Modules\Legacy\Data\ExtractedProveedores;
use App\Modules\Legacy\Data\ProveedorRow;
use App\Modules\Legacy\Models\Proveedor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * SCRUM-756 — "Loader-A" (staging). Upsert idempotente en `legacy.proveedores` por la llave
 * natural de ICG (`icg_codproveedor`, UNIQUE). Re-correr el mismo extracto dos veces nunca
 * duplica ni pisa mal — es el único paso del pipeline (sin Matcher, sin Loader-B, ver docblock de
 * la migración): el pipeline de esta entidad termina acá.
 *
 * Requiere tenant activo (`Tenant::current()`, ver `getTable()` de `Proveedor`) — el llamador
 * (`App\Modules\Legacy\Console\Commands\LegacySyncProveedoresCommand`) es responsable de `makeCurrent()` antes de
 * invocar `load()`.
 */
final class ProveedorStagingLoader
{
    /**
     * Mismo criterio que `ProductoStagingLoader`/`ClienteStagingLoader` — Postgres tiene un límite
     * duro de 65535 parámetros bindeados por statement. Con 114 filas reales (§2 del diseño) este
     * chunking nunca se activa de verdad, pero se replica igual por consistencia con el resto del
     * módulo (y por si el histórico completo de PROVEEDORES resulta ser mayor al confirmarse
     * contra ICG real).
     */
    private const CHUNK_SIZE = 500;

    /**
     * Envuelto en su propia transacción — mismo criterio que `ProductoStagingLoader::load()`
     * (hallazgo Pre-QA SCRUM-750): un fallo a mitad de camino deja la conexión Postgres limpia
     * para que `legacy.sync_runs` pueda registrar la corrida fallida.
     *
     * @return array{proveedores: int}
     */
    public function load(ExtractedProveedores $extracted): array
    {
        $now = now();

        return DB::transaction(fn (): array => [
            'proveedores' => $this->upsertProveedores($extracted->proveedores, $now),
        ]);
    }

    /** @param list<ProveedorRow> $rows */
    private function upsertProveedores(array $rows, Carbon $now): int
    {
        if ($rows === []) {
            return 0;
        }

        $values = array_map(static fn (ProveedorRow $row): array => [
            'icg_codproveedor' => $row->icgCodproveedor,
            'nombre'           => $row->nombre,
            'nombre_comercial' => $row->nombreComercial,
            'cif'              => $row->cif,
            'direccion'        => $row->direccion,
            'telefono1'        => $row->telefono1,
            'email'            => $row->email,
            'created_at'       => $now,
            'updated_at'       => $now,
        ], $rows);

        foreach (array_chunk($values, self::CHUNK_SIZE) as $chunk) {
            Proveedor::query()->upsert(
                $chunk,
                ['icg_codproveedor'],
                ['nombre', 'nombre_comercial', 'cif', 'direccion', 'telefono1', 'email', 'updated_at'],
            );
        }

        return count($values);
    }
}
