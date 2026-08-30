<?php

declare(strict_types=1);

use App\Shared\Multitenancy\Tenant;
use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-329 Oleada A / Batch A1 — auditoría append-only de transiciones de etapa de un pedido.
 * Nunca se edita ni se borra (mismo criterio que `inventory_movements`, el Kardex) — por eso no
 * tiene `updated_at`. `changed_by` es nullable: la primera fila (`from_stage = null`, `to_stage =
 * 'asignado'`) la escribe el sistema al crear el pedido automáticamente (REQ-308), sin un usuario
 * humano detrás del clic.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'bodega';

    public function up(): void
    {
        $authSchema = Tenant::current()->slug.'_auth';

        Schema::create($this->table('order_stage_history'), function (Blueprint $table) use ($authSchema): void {
            $table->id();
            $table->foreignId('order_id')
                ->constrained($this->table('orders'), 'id', 'order_stage_history_order_fk')
                ->cascadeOnDelete();
            $table->string('from_stage')->nullable();
            $table->string('to_stage');
            $table->foreignId('changed_by')->nullable()
                ->constrained("{$authSchema}.users", 'id', 'order_stage_history_user_fk')
                ->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index('order_id', 'order_stage_history_order_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('order_stage_history'));
    }
};
