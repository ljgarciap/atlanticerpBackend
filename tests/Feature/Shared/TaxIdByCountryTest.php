<?php

declare(strict_types=1);

namespace Tests\Feature\Shared;

use App\Modules\VentasDiseno\Support\ReceptorTipo;
use App\Shared\Fiscal\TaxId\ValidTaxId;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Tests\Feature\FeatureTestCase;

/**
 * Fase 6c — la identificación fiscal se valida según el país del tenant: RUC+DV (Panamá, sin cambios), NIT con DV por módulo 11 (Colombia)
 * y EIN sin DV (EE. UU.). Un proveedor del exterior no usa las reglas del país de la empresa.
 */
final class TaxIdByCountryTest extends FeatureTestCase
{
    private function ponerPais(string $pais): void
    {
        $this->assertSame(0, Artisan::call('tenant:set-region', ['slug' => 'atlantic', '--country' => $pais]));
        Tenant::where('slug', 'atlantic')->firstOrFail()->makeCurrent();
    }

    /** @param array<string, mixed> $data */
    private function errores(array $data): array
    {
        return Validator::make($data, ['tax_id' => ['nullable', 'string', new ValidTaxId]])->errors()->get('tax_id');
    }

    public function test_en_panama_el_comportamiento_no_cambia(): void
    {
        Tenant::where('slug', 'atlantic')->firstOrFail()->makeCurrent();

        $this->assertSame([], $this->errores(['tax_id' => '8-123-4567', 'tax_id_dv' => '12']));
        $this->assertSame([], $this->errores(['tax_id' => 'PE-1-234']));
        $this->assertNotSame([], $this->errores(['tax_id' => '8-123-4567', 'tax_id_dv' => '123']));
    }

    public function test_en_colombia_valida_el_nit_y_su_dv(): void
    {
        $this->ponerPais('CO');

        $this->assertSame([], $this->errores(['tax_id' => '899999063', 'tax_id_dv' => '3']));
        $this->assertSame([], $this->errores(['tax_id' => '800.197.268', 'tax_id_dv' => '4']));
        $this->assertNotSame([], $this->errores(['tax_id' => '899999063', 'tax_id_dv' => '9']), 'DV que no corresponde');
        $this->assertNotSame([], $this->errores(['tax_id' => '8-123-4567', 'tax_id_dv' => '12']), 'formato de RUC panameño');
    }

    public function test_en_estados_unidos_valida_el_ein_y_rechaza_el_dv(): void
    {
        $this->ponerPais('US');

        $this->assertSame([], $this->errores(['tax_id' => '12-3456789']));
        $this->assertNotSame([], $this->errores(['tax_id' => '12-3456789', 'tax_id_dv' => '5']));
        $this->assertNotSame([], $this->errores(['tax_id' => '8-123-4567']));
    }

    public function test_un_valor_vacio_no_se_valida(): void
    {
        $this->ponerPais('CO');

        $this->assertSame([], $this->errores(['tax_id' => '']));
        $this->assertSame([], $this->errores([]));
    }

    public function test_las_reglas_de_clientes_usan_la_validacion_por_pais(): void
    {
        $this->ponerPais('CO');
        $reglas = ReceptorTipo::reglas();

        $malo = Validator::make(['receptor_tipo' => '01', 'tax_id' => '12', 'tax_id_dv' => '1'], $reglas);
        $this->assertTrue($malo->fails());
        $this->assertArrayHasKey('tax_id', $malo->errors()->toArray());

        $bueno = Validator::make(['receptor_tipo' => '01', 'tax_id' => '899999063', 'tax_id_dv' => '3'], $reglas);
        $this->assertFalse($bueno->fails());

        $consumidor = Validator::make(['receptor_tipo' => '02'], $reglas);
        $this->assertFalse($consumidor->fails(), 'Consumidor Final no lleva identificación');
    }

    public function test_un_proveedor_local_usa_las_reglas_del_pais_y_uno_del_exterior_no(): void
    {
        $this->ponerPais('CO');
        DB::table('atlantic_auth.users')->where('email', 'vendedor@atlantic.test')->update(['extra_permissions' => json_encode(['compras.read', 'compras.write'])]);
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');
        $h = $this->authHeaders($token);

        $local = ['name' => 'Local SAS', 'category' => 'locales', 'country' => 'Colombia', 'phone' => '6000-0000'];
        $this->withHeaders($h)->postJson('/api/compras/providers', $local + ['tax_id' => '899999063', 'tax_id_dv' => '9'])
            ->assertStatus(422)->assertJsonValidationErrors('tax_id');
        $this->withHeaders($h)->postJson('/api/compras/providers', $local + ['tax_id' => '899999063', 'tax_id_dv' => '3'])->assertStatus(201);

        // Un proveedor de China trae su propio identificador: no se le exigen las reglas del NIT.
        $this->withHeaders($h)->postJson('/api/compras/providers', ['name' => 'Shenzhen Lighting', 'category' => 'china', 'country' => 'China', 'phone' => '6000-0001', 'tax_id' => '91440300MA5ABCD', 'tax_id_dv' => '12'])
            ->assertStatus(201);
    }

    public function test_el_login_y_auth_me_dicen_como_se_captura_la_identificacion(): void
    {
        $this->ponerPais('CO');

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');
        $esperado = ['label' => 'NIT', 'uses_dv' => true, 'dv_max_length' => 1];
        $this->withHeaders($this->authHeaders($token))->getJson('/api/auth/me')->assertOk()->assertJsonPath('fiscal_id', $esperado);
        $this->assertSame($esperado, $this->postJson('/api/auth/login', ['email' => 'vendedor@atlantic.test', 'password' => 'Password123!'])->assertOk()->json('user.fiscal_id'));
    }

    /** Fase 6d — el nombre visible del impuesto sale del país: ITBMS (PA), IVA (CO), Sales tax (US). */
    public function test_el_nombre_visible_del_impuesto_depende_del_pais(): void
    {
        foreach (['PA' => 'ITBMS', 'CO' => 'IVA', 'US' => 'Sales tax'] as $pais => $etiqueta) {
            $this->ponerPais($pais);
            $this->assertSame($etiqueta, \App\Shared\Multitenancy\TenantRegion::taxLabel());

            $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');
            $this->withHeaders($this->authHeaders($token))->getJson('/api/auth/me')->assertOk()->assertJsonPath('tax_label', $etiqueta);
        }

        $this->assertSame('Impuesto', \App\Shared\Multitenancy\TenantRegion::taxLabel('XX'));
    }
}
