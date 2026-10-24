<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 1 del aislamiento del núcleo (docs/specs/fase1-limpieza-nucleo.md, bloque C) — REQ-139 RN2: una orden «Por aprobar» es crítica a partir de N días.
 * Antes `PurchaseOrder::CRITICAL_DAYS_POR_APROBAR = 3` fijo en código; ahora vive en `compras.settings` (default 3: el mismo valor, sin cambio de comportamiento).
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'compras';

    public function up(): void
    {
        Schema::table($this->table('settings'), function (Blueprint $table): void {
            $table->integer('critical_days_por_aprobar')->default(3);
        });
    }

    public function down(): void
    {
        Schema::table($this->table('settings'), function (Blueprint $table): void {
            $table->dropColumn('critical_days_por_aprobar');
        });
    }
};
