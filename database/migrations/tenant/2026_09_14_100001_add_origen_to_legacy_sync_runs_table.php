<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-838 — distingue una corrida disparada por el Scheduler (`legacy:sync-incremental-*`,
 * agendado en routes/console.php) de una corrida manual (`legacy:sync-*` vía infra/legacy-sync.sh)
 * — ambas siguen registrándose en la misma `legacy.sync_runs`, solo se agrega esta columna para
 * distinguir el origen (criterio de aceptación de SCRUM-838).
 *
 * `status` gana un tercer valor posible además de success/partial/failed: `held_for_review` — una
 * corrida incremental cuyo volumen de cambio superó el umbral del circuit breaker (SCRUM-838,
 * punto 6) y NO aplicó sus cambios. La columna ya es un `string(20)` sin CHECK constraint, no
 * requiere migración de esquema para el nuevo valor — ver App\Modules\Legacy\Models\SyncRun.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'legacy';

    public function up(): void
    {
        Schema::table($this->table('sync_runs'), function (Blueprint $table): void {
            $table->string('origen', 20)->default('manual')->after('entidad'); // manual | scheduler
        });
    }

    public function down(): void
    {
        Schema::table($this->table('sync_runs'), function (Blueprint $table): void {
            $table->dropColumn('origen');
        });
    }
};
