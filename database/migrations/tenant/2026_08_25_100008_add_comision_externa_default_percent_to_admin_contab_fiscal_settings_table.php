<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Batch 16 de Admin&Cont (SCRUM-585→590, REQ-508/511 RN1) — % de comisión por defecto para
 * proyectos referidos por arquitectos externos. Mismo criterio ya usado para
 * `mark_approval_threshold` (migración `2026_08_24_100002`, ver su propio docblock): se agrega al
 * singleton ya existente de Configuración Fiscal (`AdminContFiscalSettings::current()`, id=1) en
 * vez de una constante de `10.0` hardcodeada en `ArchitectCommissionService` — regla dura del
 * workspace, ningún porcentaje de negocio como constante en código.
 *
 * Sin pantalla de edición propia todavía (mismo criterio que
 * `client_billing_profiles`/`bank_accounts.ultimos_4_digitos`) — el override POR PROYECTO con
 * aprobación de Mark es REQ-514 (Batch 17); hasta que exista esa UI, este valor es el % que aplica
 * a todos los proyectos por igual, editable por seeder/tinker si el negocio lo pide antes.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'admin_contab';

    public function up(): void
    {
        Schema::table($this->table('fiscal_settings'), function (Blueprint $table): void {
            $table->decimal('comision_externa_default_percent', 5, 2)->default(10.00);
        });

        DB::table($this->table('fiscal_settings'))->where('id', 1)->update(['comision_externa_default_percent' => 10.00]);
    }

    public function down(): void
    {
        Schema::table($this->table('fiscal_settings'), function (Blueprint $table): void {
            $table->dropColumn('comision_externa_default_percent');
        });
    }
};
