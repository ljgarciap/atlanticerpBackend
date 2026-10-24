<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-818 — flujo de aprobación/rechazo de Comparación de Referencias. `approved_by` ya
 * existía sin usarse (Oleada 2 nunca implementó approve()/reject()); `rejected_by` es el mismo
 * criterio para el camino de rechazo, mismo patrón que `bodega_zona_libre_requests`.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'compras';

    public function up(): void
    {
        Schema::table($this->table('replacement_requests'), function (Blueprint $table): void {
            $table->foreignId('rejected_by')->nullable()
                ->constrained('users', 'id', 'replacement_requests_rejected_by_fk')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table($this->table('replacement_requests'), function (Blueprint $table): void {
            $table->dropConstrainedForeignId('rejected_by');
        });
    }
};
