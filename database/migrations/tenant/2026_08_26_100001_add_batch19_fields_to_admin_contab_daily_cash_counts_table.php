<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Batch 19 de Admin&Cont (SCRUM-602→606, REQ-525→529) — ver
 * docs/adr/ADR-SCRUM602-606-batch19-arqueo-caja-parte2.md. Extiende `daily_cash_counts` (creada en
 * Batch 18) con el ciclo de vida de cierre/aprobación que Batch 18 dejó fuera a propósito.
 * `numero` se asigna solo al cerrar (`DailyCashCountService::close()`), por eso nullable+unique en
 * vez de not-null.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'admin_contab';

    public function up(): void
    {
        Schema::table($this->table('daily_cash_counts'), function (Blueprint $table): void {
            $table->unsignedInteger('numero')->nullable()->unique()->after('fecha');
            $table->string('estado', 30)->default('abierto')->after('numero');
            $table->unsignedBigInteger('cerrado_por')->nullable()->after('observacion_general');
            $table->timestampTz('cerrado_at')->nullable()->after('cerrado_por');
            $table->unsignedBigInteger('aprobado_por')->nullable()->after('cerrado_at');
            $table->timestampTz('aprobado_at')->nullable()->after('aprobado_por');
        });
    }

    public function down(): void
    {
        Schema::table($this->table('daily_cash_counts'), function (Blueprint $table): void {
            $table->dropColumn(['numero', 'estado', 'cerrado_por', 'cerrado_at', 'aprobado_por', 'aprobado_at']);
        });
    }
};
