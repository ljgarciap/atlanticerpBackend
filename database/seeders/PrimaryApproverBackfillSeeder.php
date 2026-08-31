<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Compras\Models\ComprasSettings;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\Seeder;

/**
 * `compras_settings.primary_approver_user_id` (aprobador único configurable, reusado por
 * `AdjustmentRequestController` y `RelocationRequestController`) se backfillea desde la migración
 * `2026_07_20_100009_add_mark_approver_to_compras_settings_table.php`, pero esa migración corre
 * DENTRO de `tenants:artisan migrate`, que en el pipeline de deploy (`ci-backend.yml`) corre ANTES
 * de `db:seed --force` — en cualquier entorno reprovisionado desde cero, `CoreUserSeeder` (que
 * crea al aprobador por default) todavía no corrió cuando la migración busca su usuario, así que
 * el backfill de la migración queda en NULL en silencio en un tenant nuevo o un disaster-recovery
 * desde cero.
 *
 * Corre en TODOS los entornos (bloque "Tipo A" de `DatabaseSeeder`, después de `CoreUserSeeder`)
 * — solo rellena si sigue NULL, nunca pisa un valor ya configurado (el aprobador se puede
 * reasignar a otra persona desde Configuración sin que un redeploy lo revierta). Mismo criterio
 * que `ZonaLibreProviderSeeder` (create/backfill-if-missing, no force-overwrite).
 */
class PrimaryApproverBackfillSeeder extends Seeder
{
    private const APPROVER_EMAIL = 'gerencia3@test.com';

    public function run(): void
    {
        $tenant = Tenant::where('slug', 'atlantic')->first();

        if ($tenant === null) {
            $this->command->warn('Tenant "atlantic" not found — PrimaryApproverBackfillSeeder skipped.');

            return;
        }

        $tenant->makeCurrent();

        try {
            $settings = ComprasSettings::current();
            if ($settings->primary_approver_user_id !== null) {
                return;
            }

            $approver = User::where('email', self::APPROVER_EMAIL)->first();
            if ($approver === null) {
                $this->command->warn('Usuario aprobador ('.self::APPROVER_EMAIL.') no encontrado — primary_approver_user_id sigue NULL.');

                return;
            }

            $settings->update(['primary_approver_user_id' => $approver->id]);
        } finally {
            Tenant::forgetCurrent();
        }
    }
}
