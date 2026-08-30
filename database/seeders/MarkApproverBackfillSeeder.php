<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Compras\Models\ComprasSettings;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\Seeder;

/**
 * Pre-QA 2026-07-24 (Bloque B4, SCRUM-458) — `compras_settings.mark_approver_user_id` (SCRUM-206,
 * gate "exclusivo de Mark" reusado por `AdjustmentRequestController` y ahora
 * `RelocationRequestController`) se backfillea desde la migración
 * `2026_07_20_100009_add_mark_approver_to_compras_settings_table.php`, pero esa migración corre
 * DENTRO de `tenants:artisan migrate`, que en el pipeline de deploy (`ci-backend.yml`) corre
 * ANTES de `db:seed --force` — en cualquier entorno reprovisionado desde cero, `CoreUserSeeder`
 * (que crea a Mark) todavía no corrió cuando la migración busca su usuario, así que el backfill
 * de la migración queda en NULL en silencio (confirmado en vivo el 2026-07-24: no afectó a
 * dev.atlanticerp.ai ni test.atlanticerp.ai porque ya tenían a Mark sembrado de un deploy anterior a esta
 * migración, pero SÍ afectaría a un tenant nuevo o un disaster-recovery desde cero).
 *
 * Corre en TODOS los entornos (bloque "Tipo A" de `DatabaseSeeder`, después de `CoreUserSeeder`)
 * — solo rellena si sigue NULL, nunca pisa un valor ya configurado (Luis puede reasignar "Mark" a
 * otra persona desde Configuración sin que un redeploy lo revierta). Mismo criterio que
 * `ZonaLibreProviderSeeder` (create/backfill-if-missing, no force-overwrite).
 */
class MarkApproverBackfillSeeder extends Seeder
{
    private const MARK_EMAIL = 'mbekhar@illuminations.com.pa';

    public function run(): void
    {
        $tenant = Tenant::where('slug', 'illuminations')->first();

        if ($tenant === null) {
            $this->command->warn('Tenant "illuminations" not found — MarkApproverBackfillSeeder skipped.');

            return;
        }

        $tenant->makeCurrent();

        try {
            $settings = ComprasSettings::current();
            if ($settings->mark_approver_user_id !== null) {
                return;
            }

            $mark = User::where('email', self::MARK_EMAIL)->first();
            if ($mark === null) {
                $this->command->warn('Usuario Mark ('.self::MARK_EMAIL.') no encontrado — mark_approver_user_id sigue NULL.');

                return;
            }

            $settings->update(['mark_approver_user_id' => $mark->id]);
        } finally {
            Tenant::forgetCurrent();
        }
    }
}
