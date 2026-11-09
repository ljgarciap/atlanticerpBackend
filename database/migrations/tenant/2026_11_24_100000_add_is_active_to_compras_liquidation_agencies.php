<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 29b (grupo 5) — las agencias de liquidación solo se desactivan, nunca se borran. Todas las existentes
 * quedan activas: ninguna cambia de comportamiento al desplegar. Aditiva y reversible.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'compras';

    public function up(): void
    {
        Schema::table($this->table('liquidation_agencies'), function (Blueprint $table): void {
            $table->boolean('is_active')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table($this->table('liquidation_agencies'), function (Blueprint $table): void {
            $table->dropColumn('is_active');
        });
    }
};
