<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Batch 19 de Admin&Cont (SCRUM-602→606, REQ-527) — ver
 * docs/adr/ADR-SCRUM602-606-batch19-arqueo-caja-parte2.md, reconciliación punto 4. Piso desde el
 * cual el bloqueo secuencial de arqueos atrasados empieza a exigir cierre — sin esto, los cobros
 * reales previos a Batch 18 generarían un backlog de meses de "atrasados" el día del deploy.
 * Nullable: si nunca se fija, `DailyCashCountService::activeDate()` usa `MIN(daily_cash_counts.fecha)`
 * o, si la tabla está vacía, hoy mismo (cero backlog). Mismo patrón que `petty_cash_balance`
 * (migración `2026_08_25_100010`): editable por Mark vía `PUT /fiscal-settings`.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'admin_contab';

    public function up(): void
    {
        Schema::table($this->table('fiscal_settings'), function (Blueprint $table): void {
            $table->date('arqueo_tracking_start_date')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table($this->table('fiscal_settings'), function (Blueprint $table): void {
            $table->dropColumn('arqueo_tracking_start_date');
        });
    }
};
