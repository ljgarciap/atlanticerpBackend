<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 8b (docs/specs/fase8-almacenes-configurables.md) — `warehouses.is_active`: un almacén con historial no se borra, se desactiva.
 * Desaparece de los selectores y de las reglas de venta; conserva sus movimientos. Todos los existentes quedan activos.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'bodega';

    public function up(): void
    {
        Schema::table($this->table('warehouses'), function (Blueprint $table): void {
            $table->boolean('is_active')->default(true)->after('tipo');
        });
    }

    public function down(): void
    {
        Schema::table($this->table('warehouses'), function (Blueprint $table): void {
            $table->dropColumn('is_active');
        });
    }
};
