<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Support\Facades\DB;

/**
 * Fase 4 — Servicios, Batch 3 parte 2 (REQ-247, docs/architecture/servicios-fase4-diseno.md
 * sección 8). `requerimientos_especiales` pasa de texto libre a JSONB estructurado:
 * `{"catalog": ["casco", "arnes", ...], "otros": ["texto libre 1", ...]}` — `catalog` son claves
 * de `Ticket::REQUIREMENT_LABELS` (checklist fijo de 18 ítems, RN1), `otros` son entradas de texto
 * libre agregadas con "+ Agregar otro" (RN6).
 *
 * Sin doctrine/dbal en el proyecto — se altera el tipo de columna directo con SQL en vez de
 * `Blueprint::change()` (mismo criterio que
 * `2026_07_13_100001_make_sub_client_id_nullable_on_ventas_diseno_sales_projects_table.php`).
 *
 * Ningún valor existente se descarta: cualquier texto libre ya guardado por el endpoint mínimo de
 * REQ-249 (Batch 1) se envuelve como `{"catalog": [], "otros": ["<texto original>"]}` antes de
 * cambiar el tipo — confirmado en vivo que hoy no hay filas con dato real en dev local, pero
 * dev.atlanticerp.ai/test.atlanticerp.ai pueden tener registros de prueba de sesiones de QA anteriores.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'servicios';

    public function up(): void
    {
        $table = $this->table('tickets');

        DB::table($table)
            ->whereNotNull('requerimientos_especiales')
            ->where('requerimientos_especiales', '!=', '')
            ->orderBy('id')
            ->each(function (object $row) use ($table): void {
                $wrapped = json_encode(['catalog' => [], 'otros' => [$row->requerimientos_especiales]]);
                DB::table($table)->where('id', $row->id)->update(['requerimientos_especiales' => $wrapped]);
            });

        DB::statement("ALTER TABLE {$table} ALTER COLUMN requerimientos_especiales TYPE jsonb USING requerimientos_especiales::jsonb");
    }

    public function down(): void
    {
        $table = $this->table('tickets');

        DB::statement("ALTER TABLE {$table} ALTER COLUMN requerimientos_especiales TYPE text USING requerimientos_especiales::text");
    }
};
