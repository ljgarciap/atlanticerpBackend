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
        // "sales_projects" (no "projects") para no confundirse con crm.projects —
        // son entidades distintas de módulos distintos. Ver REQ-027/PROYECTO en el
        // modelo de datos del cliente (sprint1/Requerimientos Ventas Diseño.xlsx).
        Schema::create($this->table('sales_projects'), function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sub_client_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            // Libre, editable en cualquier momento, no cambia automáticamente de etapa (REQ-006).
            $table->enum('tag', ['design', 'quote', 'both'])->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('sales_projects'));
    }
};
