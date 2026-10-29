<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 13 (2.10) — reapertura de un arqueo cerrado, con aprobación y motivo. Guarda la ÚLTIMA reapertura
 * (quién, cuándo y por qué); el historial completo vive en `audit_logs` (acción `admin_contab.daily_cash_count.reopened`).
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'admin_contab';

    public function up(): void
    {
        Schema::table($this->table('daily_cash_counts'), function (Blueprint $table): void {
            $table->unsignedBigInteger('reabierto_por')->nullable()->after('aprobado_at');
            $table->timestampTz('reabierto_at')->nullable()->after('reabierto_por');
            $table->text('reabierto_motivo')->nullable()->after('reabierto_at');
        });
    }

    public function down(): void
    {
        Schema::table($this->table('daily_cash_counts'), function (Blueprint $table): void {
            $table->dropColumn(['reabierto_por', 'reabierto_at', 'reabierto_motivo']);
        });
    }
};
