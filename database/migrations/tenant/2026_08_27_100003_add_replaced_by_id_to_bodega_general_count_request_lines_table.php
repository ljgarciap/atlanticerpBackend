<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-797 (rebote de Daniela Amaya 2026-08-27, "Validación de Conteos Pendientes por Producto y
 * Bodega") — el chequeo de duplicados de Inventario General pasa de bodega completa a
 * producto+bodega (ver docblock de `GeneralCountController::store()`/`findConflictingLines()`).
 * Como un "Conteo General" sigue precargando TODOS los productos de la bodega a la vez (eso no
 * cambió), el reemplazo por confirmación ya no puede vivir en `general_count_requests`
 * (`replaced_by_id` de la migración `2026_08_26_100005_...` — esa columna queda sin usarse desde
 * este ticket, no se borra por compatibilidad con filas `reemplazada` ya existentes) — se necesita
 * el mismo mecanismo pero por LÍNEA, mismo patrón que `adjustment_request_lines.replaced_by_id`.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'bodega';

    public function up(): void
    {
        Schema::table($this->table('general_count_request_lines'), function (Blueprint $table): void {
            $table->foreignId('replaced_by_id')->nullable()->after('diferencia')
                ->constrained($this->table('general_count_request_lines'), 'id', 'general_count_request_lines_replaced_by_fk')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table($this->table('general_count_request_lines'), function (Blueprint $table): void {
            $table->dropForeign('general_count_request_lines_replaced_by_fk');
            $table->dropColumn('replaced_by_id');
        });
    }
};
