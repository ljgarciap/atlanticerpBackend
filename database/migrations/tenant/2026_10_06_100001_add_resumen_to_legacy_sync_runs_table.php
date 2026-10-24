<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-1114 — informe de divergencia de `legacy:sync-incremental-stock`. Los contadores de
 * `sync_runs` no alcanzan (unidades positivas/negativas, productos retenidos, los que no cuadran
 * entre el desglose por bodega y `stock_quantity`, motivo del circuit breaker), así que la corrida
 * deja un JSON acotado. Nullable: las demás entidades no lo usan.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'legacy';

    public function up(): void
    {
        Schema::table($this->table('sync_runs'), function (Blueprint $table): void {
            $table->jsonb('resumen')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table($this->table('sync_runs'), function (Blueprint $table): void {
            $table->dropColumn('resumen');
        });
    }
};
