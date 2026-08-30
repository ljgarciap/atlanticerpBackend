<?php

declare(strict_types=1);

use App\Shared\Multitenancy\Tenant;
use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-428/447/448/449 (REQ-358/377/378/379, entidad SOLICITUD_AJUSTE_LINEA) — una bodega
 * dentro de una solicitud de ajuste, con su propio estado. `bodega` es FK a `warehouses` (las 7
 * físicas), no el enum de 5 que traía el Modelo de Datos original (ya obsoleto, ver
 * 2026_07_21_100019).
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'bodega';

    public function up(): void
    {
        $authSchema = Tenant::current()->slug.'_auth';

        Schema::create($this->table('adjustment_request_lines'), function (Blueprint $table) use ($authSchema): void {
            $table->id();
            $table->foreignId('adjustment_request_id')
                ->constrained($this->table('adjustment_requests'), 'id', 'adjustment_request_lines_request_fk')
                ->cascadeOnDelete();
            $table->foreignId('warehouse_id')
                ->constrained($this->table('warehouses'), 'id', 'adjustment_request_lines_warehouse_fk');
            $table->string('tipo'); // Sumar|Restar
            $table->unsignedInteger('cantidad');
            $table->text('motivo');
            $table->string('evidencia_storage_key')->nullable();
            $table->string('estado')->default('Pendiente'); // Pendiente|Aprobada|Rechazada
            $table->text('motivo_rechazo')->nullable();
            $table->foreignId('resuelto_por')->nullable()
                ->constrained("{$authSchema}.users", 'id', 'adjustment_request_lines_resolver_fk')
                ->nullOnDelete();
            $table->timestamp('resuelto_at')->nullable();
            $table->timestamps();

            $table->index('estado', 'adjustment_request_lines_estado_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('adjustment_request_lines'));
    }
};
