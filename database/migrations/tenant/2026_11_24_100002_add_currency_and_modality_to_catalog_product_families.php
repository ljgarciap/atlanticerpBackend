<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 29b (grupo 6) — moneda y modalidad de compra por familia. Los valores por defecto replican lo que
 * «Generar compra» hacía fijo (USD, directo): ninguna familia existente cambia de comportamiento. Aditiva y reversible.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'ventas_diseno';

    public function up(): void
    {
        Schema::table($this->table('catalog_product_families'), function (Blueprint $table): void {
            $table->string('currency', 3)->default('USD');
            $table->string('modality', 20)->default('directo');
        });
    }

    public function down(): void
    {
        Schema::table($this->table('catalog_product_families'), function (Blueprint $table): void {
            $table->dropColumn(['currency', 'modality']);
        });
    }
};
