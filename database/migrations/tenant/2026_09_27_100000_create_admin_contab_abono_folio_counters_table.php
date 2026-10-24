<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-955 — Recibo de Abono. Folio ABN-XXX, secuencia PROPIA e independiente del REC-XXX de
 * Cobros (decisión confirmada con Luis: un abono y un cobro son movimientos distintos, mantener
 * contadores separados facilita auditar cada tipo de recibo por separado). Mismo mecanismo de
 * contador global de una sola fila que `admin_contab_payment_folio_counters` — ver
 * `AdminContAbonoFolioService::next()`.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'admin_contab';

    public function up(): void
    {
        Schema::create($this->table('abono_folio_counters'), function (Blueprint $table): void {
            $table->smallInteger('id')->primary();
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('abono_folio_counters'));
    }
};
