<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Batch 21 (SCRUM-618→623, REQ-542) — Senior Review, hallazgo #5: la regla de "2 intentos" no
 * puede ser un literal `2` en el código (regla dura del workspace, ver CLAUDE.md raíz — "Nunca
 * hardcodear umbrales/márgenes/porcentajes de negocio"). Mismo patrón exacto que
 * `petty_cash_balance`/`primary_approver_user_id` ya tienen en este singleton: editable por Mark vía
 * `PUT /fiscal-settings`, sin deploy para cambiarlo.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'admin_contab';

    public function up(): void
    {
        Schema::table($this->table('fiscal_settings'), function (Blueprint $table): void {
            $table->unsignedSmallInteger('petty_cash_max_intentos_rechazo')->default(2);
        });

        DB::table($this->table('fiscal_settings'))->where('id', 1)->update(['petty_cash_max_intentos_rechazo' => 2]);
    }

    public function down(): void
    {
        Schema::table($this->table('fiscal_settings'), function (Blueprint $table): void {
            $table->dropColumn('petty_cash_max_intentos_rechazo');
        });
    }
};
