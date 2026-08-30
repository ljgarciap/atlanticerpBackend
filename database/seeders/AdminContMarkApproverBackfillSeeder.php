<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use App\Modules\AdminCont\Models\AdminContFiscalSettings;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\Seeder;

/**
 * Mismo criterio y mismo gotcha que `MarkApproverBackfillSeeder` (Compras, SCRUM-206/458): corre
 * DESPUÉS de `CoreUserSeeder` en el bloque "Tipo A" de `DatabaseSeeder`, en todos los entornos.
 * Backfill-if-null, nunca pisa un `mark_approver_user_id` ya reasignado por Luis desde
 * Configuración.
 */
class AdminContMarkApproverBackfillSeeder extends Seeder
{
    private const MARK_EMAIL = 'mbekhar@illuminations.com.pa';

    public function run(): void
    {
        $tenant = Tenant::where('slug', 'illuminations')->first();

        if ($tenant === null) {
            $this->command->warn('Tenant "illuminations" not found — AdminContMarkApproverBackfillSeeder skipped.');

            return;
        }

        $tenant->makeCurrent();

        try {
            $settings = AdminContFiscalSettings::current();
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
