<?php

declare(strict_types=1);

namespace App\Modules\Bodega\Services;

use App\Modules\Bodega\Models\ProductWarehouseStock;
use App\Modules\Bodega\Models\Warehouse;
use Illuminate\Support\Facades\DB;

/**
 * Fase 8b — ¿se puede desactivar o borrar un almacén? Responde con el historial real: recorre las claves foráneas SIN cascada que apuntan a
 * `warehouses` (catálogo de Postgres; las de cascada, como ubicaciones y stock, caen con el almacén), así que una tabla nueva que lo referencie bloquea la baja sin tocar este código.
 */
final class WarehouseUsage
{
    /** Unidades en existencia (cualquier signo) en el almacén: debe ser 0 para desactivarlo o borrarlo. */
    public function stockUnits(int $warehouseId): int
    {
        return (int) abs((float) ProductWarehouseStock::query()->where('warehouse_id', $warehouseId)->sum('quantity'));
    }

    /**
     * Tablas (sin la de stock, que se evalúa por cantidad) con filas que apuntan al almacén.
     *
     * @return list<string>
     */
    public function referencingTables(int $warehouseId): array
    {
        $stockTable = (new ProductWarehouseStock)->getTable();
        $found = [];

        foreach ($this->foreignKeys() as $fk) {
            if ($fk['table'] === $stockTable) {
                continue;
            }
            $row = DB::selectOne("SELECT EXISTS (SELECT 1 FROM {$fk['table']} WHERE \"{$fk['column']}\" = ?) AS e", [$warehouseId]);
            if ($row !== null && $row->e) {
                $found[] = preg_replace('/^.*\./', '', $fk['table']) ?? $fk['table'];
            }
        }

        return array_values(array_unique($found));
    }

    /**
     * Motivo por el que NO se puede borrar (null = se puede). Un almacén de sistema, el de despacho por defecto, uno con stock o con
     * historial se desactiva en lugar de borrarse.
     */
    public function motivoParaNoBorrar(Warehouse $w): ?string
    {
        $motivo = $this->motivoParaNoDesactivar($w);
        if ($motivo !== null) {
            return $motivo;
        }

        $tablas = $this->referencingTables($w->id);

        return $tablas === [] ? null : 'Tiene historial ('.implode(', ', $tablas).'): desactívalo en lugar de borrarlo.';
    }

    /** Motivo por el que NO se puede desactivar (null = se puede). */
    public function motivoParaNoDesactivar(Warehouse $w): ?string
    {
        if ($w->funcion !== null) {
            return 'Es un almacén de sistema ('.$w->funcion.'): no se desactiva ni se borra.';
        }
        if ($w->despacho_por_defecto) {
            return 'Es el almacén de despacho por defecto: elige otro como por defecto primero.';
        }
        $unidades = $this->stockUnits($w->id);
        if ($unidades > 0) {
            return "Tiene {$unidades} unidades en existencia: muévelas a otro almacén primero.";
        }

        return null;
    }

    /** @return list<array{table: string, column: string}> */
    private function foreignKeys(): array
    {
        $table = (new Warehouse)->getTable();
        $rows = DB::select(
            'SELECT c.conrelid::regclass::text AS tbl, a.attname AS col FROM pg_constraint c '
            .'JOIN pg_attribute a ON a.attrelid = c.conrelid AND a.attnum = ANY (c.conkey) '
            ."WHERE c.contype = 'f' AND c.confdeltype <> 'c' AND c.confrelid = ?::regclass",
            [$table],
        );

        return array_values(array_map(static fn ($r): array => ['table' => (string) $r->tbl, 'column' => (string) $r->col], $rows));
    }
}
