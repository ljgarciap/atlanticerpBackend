<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 28 (grupo 5) — sobre qué monto se calcula la comisión externa: `total` (el monto del proyecto con
 * el impuesto incluido, el comportamiento de siempre y el valor por defecto) o `base_imponible` (ese
 * monto sin el ITBMS vigente). Cambiarlo altera los importes de las comisiones FUTURAS y de las que
 * aún no se pagaron; lo ya pagado conserva su movimiento de caja.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'admin_contab';

    public function up(): void
    {
        Schema::table($this->table('fiscal_settings'), function (Blueprint $table): void {
            $table->string('comision_externa_base', 20)->default('total');
        });
    }

    public function down(): void
    {
        Schema::table($this->table('fiscal_settings'), function (Blueprint $table): void {
            $table->dropColumn('comision_externa_base');
        });
    }
};
