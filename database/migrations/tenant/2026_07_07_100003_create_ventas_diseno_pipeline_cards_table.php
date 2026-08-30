<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends TenantSchemaMigration
{
    protected string $module = 'ventas_diseno';

    public function up(): void
    {
        Schema::create($this->table('pipeline_cards'), function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sales_project_id')->constrained()->cascadeOnDelete();
            $table->enum('stage', ['lead', 'design', 'quote', 'proposal', 'approved', 'lost'])
                ->default('lead');
            // Independientes entre sí a propósito (REQ-010): puede haber Master sin
            // Subcliente todavía.
            $table->foreignId('master_client_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('sub_client_id')->nullable()->constrained()->nullOnDelete();
            // Dueño único de la tarjeta (no many-to-many como crm.project_assignees) —
            // el modelo de datos del cliente define un único "responsable_id" por tarjeta.
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $table->decimal('amount', 12, 2)->nullable();
            $table->decimal('worked_area_m2', 10, 2)->nullable();
            $table->enum('delivery_type', ['single', 'partial'])->nullable();
            // "días en etapa" (REQ-004) se calcula en la consulta a partir de este
            // timestamp en vez de guardar un contador que requeriría un cron para no
            // quedar desactualizado. Se resetea a now() en cada cambio de etapa.
            $table->timestampTz('stage_changed_at')->useCurrent();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('pipeline_cards'));
    }
};
