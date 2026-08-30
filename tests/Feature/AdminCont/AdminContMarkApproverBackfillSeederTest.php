<?php

declare(strict_types=1);

namespace Tests\Feature\AdminCont;

use App\Models\User;
use App\Modules\AdminCont\Models\AdminContFiscalSettings;
use App\Shared\Multitenancy\Tenant;
use Database\Seeders\AdminContMarkApproverBackfillSeeder;
use Tests\Feature\FeatureTestCase;

/** Mismo criterio que Tests\Feature\Compras\MarkApproverBackfillSeederTest. */
class AdminContMarkApproverBackfillSeederTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'illuminations')->first()?->makeCurrent();
    }

    public function test_mark_approver_user_id_queda_seteado_tras_el_seeding_normal(): void
    {
        $mark = User::where('email', 'mbekhar@illuminations.com.pa')->firstOrFail();

        $this->assertSame($mark->id, AdminContFiscalSettings::current()->mark_approver_user_id);
    }

    public function test_el_seeder_rellena_si_esta_en_null(): void
    {
        $mark = User::where('email', 'mbekhar@illuminations.com.pa')->firstOrFail();
        AdminContFiscalSettings::current()->update(['mark_approver_user_id' => null]);

        (new AdminContMarkApproverBackfillSeeder)->run();
        Tenant::where('slug', 'illuminations')->first()?->makeCurrent();

        $this->assertSame($mark->id, AdminContFiscalSettings::current()->mark_approver_user_id);
    }

    public function test_el_seeder_no_pisa_un_approver_ya_reasignado(): void
    {
        $otroUsuario = User::where('email', '!=', 'mbekhar@illuminations.com.pa')->firstOrFail();
        AdminContFiscalSettings::current()->update(['mark_approver_user_id' => $otroUsuario->id]);

        (new AdminContMarkApproverBackfillSeeder)->run();
        Tenant::where('slug', 'illuminations')->first()?->makeCurrent();

        $this->assertSame($otroUsuario->id, AdminContFiscalSettings::current()->mark_approver_user_id);
    }
}
