<?php

declare(strict_types=1);

namespace Tests\Feature\AdminCont;

use App\Models\User;
use App\Modules\AdminCont\Models\AdminContFiscalSettings;
use App\Shared\Multitenancy\Tenant;
use Database\Seeders\AdminContPrimaryApproverBackfillSeeder;
use Tests\Feature\FeatureTestCase;

/** Mismo criterio que Tests\Feature\Compras\PrimaryApproverBackfillSeederTest. */
class AdminContPrimaryApproverBackfillSeederTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    public function test_primary_approver_user_id_queda_seteado_tras_el_seeding_normal(): void
    {
        $approver = User::where('email', 'gerencia3@test.com')->firstOrFail();

        $this->assertSame($approver->id, AdminContFiscalSettings::current()->primary_approver_user_id);
    }

    public function test_el_seeder_rellena_si_esta_en_null(): void
    {
        $approver = User::where('email', 'gerencia3@test.com')->firstOrFail();
        AdminContFiscalSettings::current()->update(['primary_approver_user_id' => null]);

        (new AdminContPrimaryApproverBackfillSeeder)->run();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();

        $this->assertSame($approver->id, AdminContFiscalSettings::current()->primary_approver_user_id);
    }

    public function test_el_seeder_no_pisa_un_approver_ya_reasignado(): void
    {
        $otroUsuario = User::where('email', '!=', 'gerencia3@test.com')->firstOrFail();
        AdminContFiscalSettings::current()->update(['primary_approver_user_id' => $otroUsuario->id]);

        (new AdminContPrimaryApproverBackfillSeeder)->run();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();

        $this->assertSame($otroUsuario->id, AdminContFiscalSettings::current()->primary_approver_user_id);
    }
}
