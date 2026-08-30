<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Batch 11 de Admin&Cont (SCRUM-559→564, REQ-482 RN3) — umbral de monto que dispara "pendiente de
 * aprobación de Mark". Es el primer lugar del código que necesita este valor de verdad (Facturación
 * y Comisiones Externas, que según REQ-482 RN3 "usan el mismo umbral", todavía no están
 * implementadas) — se agrega acá, en el singleton ya existente de Configuración Fiscal
 * (`AdminContFiscalSettings::current()`, id=1), para que esos módulos futuros lo lean del mismo
 * lugar en vez de que cada uno hardcodee su propia constante de $5,000 (regla dura del workspace:
 * ningún umbral de negocio como constante en código).
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'admin_contab';

    public function up(): void
    {
        Schema::table($this->table('fiscal_settings'), function (Blueprint $table): void {
            $table->decimal('mark_approval_threshold', 12, 2)->default(5000.00);
        });

        DB::table($this->table('fiscal_settings'))->where('id', 1)->update(['mark_approval_threshold' => 5000.00]);
    }

    public function down(): void
    {
        Schema::table($this->table('fiscal_settings'), function (Blueprint $table): void {
            $table->dropColumn('mark_approval_threshold');
        });
    }
};
