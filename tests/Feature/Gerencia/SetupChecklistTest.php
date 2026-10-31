<?php

declare(strict_types=1);

namespace Tests\Feature\Gerencia;

use App\Models\User;
use App\Modules\AdminCont\Models\AdminContBankAccount;
use App\Modules\Compras\Models\ComprasSettings;
use App\Modules\Gerencia\Services\SetupChecklistService;
use App\Shared\Multitenancy\Tenant;
use Tests\Feature\FeatureTestCase;

/** Fase 16 (5.1) — lista de verificación de primera puesta en marcha. */
class SetupChecklistTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    /** @return array<string, bool> */
    private function pasos(): array
    {
        return collect(app(SetupChecklistService::class)->checklist()['pasos'])->pluck('completo', 'clave')->all();
    }

    public function test_cada_paso_se_completa_cuando_existe_su_dato(): void
    {
        $ajustes = ComprasSettings::current();
        $ajustes->primary_approver_user_id = null;
        $ajustes->save();
        AdminContBankAccount::query()->delete();

        $antes = $this->pasos();
        $this->assertFalse($antes['cuenta_bancaria']);
        $this->assertFalse($antes['aprobador_primario']);

        AdminContBankAccount::create(['banco' => 'Banco de prueba', 'tipo_cuenta' => 'corriente', 'ultimos_4_digitos' => '1234', 'moneda' => 'USD', 'activa' => true]);
        $settings = ComprasSettings::current();
        $settings->primary_approver_user_id = (int) User::where('email', 'gerencia@test.com')->value('id');
        $settings->save();

        $despues = $this->pasos();
        $this->assertTrue($despues['cuenta_bancaria']);
        $this->assertTrue($despues['aprobador_primario']);
    }

    public function test_una_cuenta_inactiva_no_cuenta(): void
    {
        AdminContBankAccount::create(['banco' => 'Banco inactivo', 'tipo_cuenta' => 'corriente', 'ultimos_4_digitos' => '9999', 'moneda' => 'USD', 'activa' => false]);

        $this->assertFalse($this->pasos()['cuenta_bancaria']);
    }

    public function test_el_almacenamiento_s3_sin_credenciales_queda_pendiente(): void
    {
        config(['filesystems.disks.s3-private' => ['driver' => 's3', 'key' => null, 'secret' => null, 'bucket' => null]]);
        $this->assertFalse($this->pasos()['almacenamiento']);

        config(['filesystems.disks.s3-private' => ['driver' => 's3', 'key' => 'k', 'secret' => 's', 'bucket' => 'b']]);
        $this->assertTrue($this->pasos()['almacenamiento']);

        config(['filesystems.disks.s3-private' => ['driver' => 'local']]);
        $this->assertTrue($this->pasos()['almacenamiento']);
    }

    public function test_el_endpoint_lo_ve_gerencia_y_no_un_vendedor(): void
    {
        $token = $this->loginAs('gerencia@test.com', 'gerencia@test.com', 'management');
        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/gerencia/setup-checklist')
            ->assertOk()
            ->assertJsonStructure(['completo', 'pasos' => [['clave', 'completo', 'ruta']]])
            ->assertJsonCount(7, 'pasos');

        $vendedor = $this->loginAs('vendedor@test.com', 'vendedor@test.com', 'vendedor');
        $this->withHeaders($this->authHeaders($vendedor))
            ->getJson('/api/gerencia/setup-checklist')
            ->assertStatus(403);
    }
}
