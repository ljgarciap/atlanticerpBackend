<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-797 (RN8, "Ver detalle" de un conteo) — hasta ahora solo existían `created_at` (creación
 * del conteo) y `resuelto_at` (decisión de Mark), sin registrar el momento en que
 * `GeneralCountController::submit()` mueve el conteo a `pendiente_aprobacion` — el ticket pide
 * mostrar esa fecha explícitamente en el detalle. Nullable: nunca se llena en conteos que todavía
 * están en borrador (`pendiente_evaluacion`/`evaluado`).
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'bodega';

    public function up(): void
    {
        Schema::table($this->table('general_count_requests'), function (Blueprint $table): void {
            $table->timestamp('fecha_solicitud_aprobacion')->nullable()->after('estado');
        });
    }

    public function down(): void
    {
        Schema::table($this->table('general_count_requests'), function (Blueprint $table): void {
            $table->dropColumn('fecha_solicitud_aprobacion');
        });
    }
};
