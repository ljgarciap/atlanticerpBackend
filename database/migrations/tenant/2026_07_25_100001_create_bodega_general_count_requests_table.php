<?php

declare(strict_types=1);

use App\Shared\Multitenancy\Tenant;
use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-460→466 (REQ-390→396, Bloque B5 "Conteos generales de inventario") — ver
 * ADR-SCRUM460-466-conteos-generales.md. Contenedora (patrón `adjustment_requests`, no plano
 * como `relocation_requests`) porque un conteo es inherentemente multi-producto (toda la
 * bodega). `aplicado_at` está separado de `resuelto_at` a propósito: es el primer bloque del
 * proyecto donde "Mark aprueba" (resuelto_at) y "se aplica al Kardex" (aplicado_at, SCRUM-466)
 * son dos pasos desacoplados en el tiempo, en vez de la aprobación aplicar el ajuste de
 * inmediato como en Ajustes/Reubicaciones.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'bodega';

    public function up(): void
    {
        $authSchema = Tenant::current()->slug.'_auth';

        Schema::create($this->table('general_count_requests'), function (Blueprint $table) use ($authSchema): void {
            $table->id();
            $table->foreignId('warehouse_id')
                ->constrained($this->table('warehouses'), 'id', 'general_count_requests_warehouse_fk');
            $table->foreignId('realizado_por')
                ->constrained("{$authSchema}.users", 'id', 'general_count_requests_user_fk');
            // pendiente_evaluacion|evaluado|pendiente_aprobacion|aprobada|rechazada
            $table->string('estado')->default('pendiente_evaluacion');
            $table->text('motivo_rechazo')->nullable();
            $table->foreignId('resuelto_por')->nullable()
                ->constrained("{$authSchema}.users", 'id', 'general_count_requests_resolver_fk')
                ->nullOnDelete();
            $table->timestamp('resuelto_at')->nullable();
            $table->timestamp('aplicado_at')->nullable();
            $table->timestamps();

            $table->index('estado', 'general_count_requests_estado_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('general_count_requests'));
    }
};
