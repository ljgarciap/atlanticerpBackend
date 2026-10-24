<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-970/971 (épica SCRUM-969 — integración Lafayette Lighting). Una fila por corrida REAL de
 * `lafayette:sync-productos` (no por `--dry-run`), mismo criterio que `legacy.sync_runs`
 * (SCRUM-750) pero SIN capa de staging intermedia — este pipeline no tiene fase de staging, el
 * Mapper escribe directo a `catalog_products`/`product_warehouse_stock`, así que no hace falta
 * columnas `staged`/`mapeados` separadas.
 *
 * Vive en el schema `ventas_diseno` (no un schema `integrations` nuevo) — una sola tabla chica no
 * justifica abrir un schema de tenant nuevo (que además requeriría agregarlo a `Tenant::schemas()`
 * y a la lista de migraciones por schema en todos lados). Directamente relacionada con
 * `catalog_products`, que también vive acá.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'ventas_diseno';

    public function up(): void
    {
        Schema::create($this->table('lafayette_sync_runs'), function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('extraidos')->default(0);
            $table->unsignedInteger('creados')->default(0);
            $table->unsignedInteger('actualizados')->default(0);
            $table->unsignedInteger('excluidos')->default(0);
            $table->unsignedInteger('errores')->default(0);
            $table->unsignedInteger('duracion_ms')->default(0);
            $table->string('status', 20); // success | partial | failed
            $table->timestampTz('started_at');
            $table->timestampTz('finished_at');

            $table->timestamp('created_at')->nullable();

            $table->index('started_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('lafayette_sync_runs'));
    }
};
