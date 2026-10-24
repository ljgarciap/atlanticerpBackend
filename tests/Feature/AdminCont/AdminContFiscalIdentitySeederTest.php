<?php

declare(strict_types=1);

namespace Tests\Feature\AdminCont;

use App\Models\User;
use App\Modules\AdminCont\Models\AdminContFiscalSettings;
use App\Shared\Multitenancy\Tenant;
use Database\Seeders\AdminContFiscalIdentitySeeder;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-857 / SCRUM-858 — mismo criterio que AdminContPrimaryApproverBackfillSeederTest.
 */
class AdminContFiscalIdentitySeederTest extends FeatureTestCase
{
    private const CONTABILIDAD_EMAIL = 'contabilidad@test.com';

    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    private function reMakeCurrent(): void
    {
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    public function test_la_identidad_fiscal_queda_seteada_tras_el_seeding_normal(): void
    {
        $contabilidad    = User::where('email', self::CONTABILIDAD_EMAIL)->firstOrFail();
        $settings = AdminContFiscalSettings::current();

        $this->assertSame('ATLANTIC USA CORP', $settings->nombre_comercial);
        $this->assertSame('ATLANTIC USA CORP', $settings->razon_social);
        $this->assertSame('1838853-1-711641', $settings->ruc);
        $this->assertSame('81', $settings->dv, 'SCRUM-928 — DV del RUC de Atlantic');
        $this->assertSame('Calle 57 Este, Times Square Plaza - Obarrio, Panama', $settings->direccion_fiscal);
        $this->assertSame($contabilidad->id, $settings->responsable_administracion_user_id);
    }

    public function test_el_seeder_rellena_solo_los_campos_vacios(): void
    {
        AdminContFiscalSettings::current()->update([
            'nombre_comercial' => '',
            'razon_social'     => '',
            'ruc'              => '',
            'direccion_fiscal' => '',
            'responsable_administracion_user_id' => null,
        ]);

        (new AdminContFiscalIdentitySeeder)->run();
        $this->reMakeCurrent();

        $settings = AdminContFiscalSettings::current();
        $this->assertSame('ATLANTIC USA CORP', $settings->nombre_comercial);
        $this->assertSame('1838853-1-711641', $settings->ruc);
        $this->assertNotNull($settings->responsable_administracion_user_id);
    }

    public function test_el_seeder_no_pisa_valores_ya_cargados(): void
    {
        $otro = User::where('email', '!=', self::CONTABILIDAD_EMAIL)->firstOrFail();
        AdminContFiscalSettings::current()->update([
            'nombre_comercial' => 'Atlantic Panamá S.A.',
            'ruc'              => '999-999-999',
            'responsable_administracion_user_id' => $otro->id,
        ]);

        (new AdminContFiscalIdentitySeeder)->run();
        $this->reMakeCurrent();

        $settings = AdminContFiscalSettings::current();
        $this->assertSame('Atlantic Panamá S.A.', $settings->nombre_comercial);
        $this->assertSame('999-999-999', $settings->ruc);
        $this->assertSame($otro->id, $settings->responsable_administracion_user_id);
        // Un campo que sí estaba vacío se rellena igual (fusión por campo, no todo-o-nada).
        $this->assertSame('Calle 57 Este, Times Square Plaza - Obarrio, Panama', $settings->direccion_fiscal);
    }

    public function test_el_seeder_tambien_corre_en_produccion(): void
    {
        // Aprobado por Luis (2026-09-13/14, SCRUM-857/858) — el gate a no-prod se quitó a
        // propósito, mismo criterio que CoreUserSeeder/SpecialPermissionSeeder.
        AdminContFiscalSettings::current()->update([
            'nombre_comercial' => '',
            'responsable_administracion_user_id' => null,
        ]);

        $this->app->detectEnvironment(fn () => 'production');
        (new AdminContFiscalIdentitySeeder)->run();
        $this->reMakeCurrent();

        $settings = AdminContFiscalSettings::current();
        $this->assertSame('ATLANTIC USA CORP', $settings->nombre_comercial);
        $this->assertNotNull($settings->responsable_administracion_user_id);
    }

    public function test_928_el_dv_se_rellena_si_esta_vacio_y_nunca_pisa_uno_ya_cargado(): void
    {
        AdminContFiscalSettings::current()->update(['dv' => '']);
        (new AdminContFiscalIdentitySeeder)->run();
        $this->reMakeCurrent();
        $this->assertSame('81', AdminContFiscalSettings::current()->dv);

        AdminContFiscalSettings::current()->update(['dv' => '55']);
        (new AdminContFiscalIdentitySeeder)->run();
        $this->reMakeCurrent();
        $this->assertSame('55', AdminContFiscalSettings::current()->dv, 'un DV ya cargado desde Configuración Fiscal no se pisa');
    }
}
