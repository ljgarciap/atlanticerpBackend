<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 28 (grupo 3) — días de crédito por cliente. NULL = «usa el valor global»
 * (`fiscal_settings.dias_credito_factura`): ningún cliente cambia de comportamiento al desplegar, y
 * quien no se ajuste sigue el global si éste cambia. El vencimiento de una factura es su fecha más los
 * días de crédito de SU cliente. Migración aditiva y reversible.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'admin_contab';

    public function up(): void
    {
        Schema::table($this->table('client_billing_profiles'), function (Blueprint $table): void {
            $table->unsignedSmallInteger('dias_credito')->nullable()->after('terminos_pago');
        });
    }

    public function down(): void
    {
        Schema::table($this->table('client_billing_profiles'), function (Blueprint $table): void {
            $table->dropColumn('dias_credito');
        });
    }
};
