<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Support\Facades\DB;

/**
 * SCRUM-292 (rebote QA 2026-08-13) — la migración original de `service_quotes` (2026_08_11_100011)
 * documentaba a propósito "numero se asigna al crear la fila (create-time == 'guardar por primera
 * vez')" — esa asunción era incorrecta: "Generar cotización" (`ServiceQuoteService::startNew()`)
 * persiste la fila y consume folio en el momento de ABRIR el formulario, no cuando el usuario
 * guarda algo real. Cerrar sin tocar nada dejaba un borrador de $0 con folio consumido, visible en
 * el historial global y en el indicador del ticket — mismo patrón de borradores huérfanos ya visto
 * en Ventas & Diseño (`Quote::folio`, ver `QuoteService::generate()`), que resuelve esto asignando
 * el folio de forma perezosa. Se replica acá el mismo mecanismo: `numero` nullable, asignado recién
 * en el primer guardado real (`ServiceQuoteService::ensureFolioAssigned()`).
 *
 * `unique('numero')` sigue funcionando con múltiples NULL — Postgres no los considera duplicados
 * entre sí, no hace falta un índice único parcial. Sin doctrine/dbal en el proyecto, se altera la
 * constraint directo con SQL en vez de Blueprint::change() (mismo criterio que
 * 2026_07_13_100001_make_sub_client_id_nullable_on_ventas_diseno_sales_projects_table.php).
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'servicios';

    public function up(): void
    {
        $table = $this->table('service_quotes');
        DB::statement("ALTER TABLE {$table} ALTER COLUMN numero DROP NOT NULL");
    }

    public function down(): void
    {
        $table = $this->table('service_quotes');
        DB::statement("ALTER TABLE {$table} ALTER COLUMN numero SET NOT NULL");
    }
};
