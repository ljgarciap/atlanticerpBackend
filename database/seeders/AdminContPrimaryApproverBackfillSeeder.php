<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use App\Modules\AdminCont\Models\AdminContFiscalSettings;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\Seeder;

/**
 * Mismo criterio y mismo gotcha que `PrimaryApproverBackfillSeeder` (Compras, SCRUM-206/458): corre
 * DESPUÉS de `CoreUserSeeder` en el bloque "Tipo A" de `DatabaseSeeder`, en todos los entornos.
 * Backfill-if-null, nunca pisa un `primary_approver_user_id` ya reasignado por Luis desde
 * Configuración.
 */
class AdminContPrimaryApproverBackfillSeeder extends Seeder
{
    private const APPROVER_EMAIL = 'gerencia3@test.com';

    public function run(): void
    {
        $tenant = Tenant::where('slug', 'atlantic')->first();

        if ($tenant === null) {
            $this->command->warn('Tenant "atlantic" not found — AdminContPrimaryApproverBackfillSeeder skipped.');

            return;
        }

        $tenant->makeCurrent();

        try {
            $settings = AdminContFiscalSettings::current();
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
