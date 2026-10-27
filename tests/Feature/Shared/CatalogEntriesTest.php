<?php

declare(strict_types=1);

namespace Tests\Feature\Shared;

use App\Modules\Compras\Models\Provider;
use App\Modules\VentasDiseno\Models\Architect;
use App\Shared\Catalogs\CatalogEntry;
use App\Shared\Catalogs\CatalogService;
use App\Shared\Multitenancy\Tenant;
use Tests\Feature\FeatureTestCase;

/**
 * Fase 7a (docs/specs/fase7-catalogos.md) — catálogos de vocabulario por empresa: lectura general, administración solo de superadmin,
 * entradas de sistema protegidas y comportamiento (origen, validación de identificación) tomado del catálogo.
 */
final class CatalogEntriesTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->firstOrFail()->makeCurrent();
    }

    private function superadmin(): array
    {
        return $this->authHeaders($this->loginAs('superadmin@atlantic.test', 'Password123!'));
    }

    /** Cada petición HTTP olvida el tenant actual: se reactiva antes de leer modelos desde el propio test. */
    private function activarTenant(): void
    {
        Tenant::where('slug', 'atlantic')->firstOrFail()->makeCurrent();
    }

    public function test_se_siembran_los_valores_que_antes_eran_constantes(): void
    {
        $this->assertEqualsCanonicalizing(Provider::CATEGORIES, CatalogService::keys('provider_category'));
        $this->assertEqualsCanonicalizing(Architect::TYPES, CatalogService::keys('architect_type'));
        $this->assertSame('local', CatalogService::prop('provider_category', 'locales', 'origen'));
        $this->assertSame('internacional', CatalogService::prop('provider_category', 'china', 'origen'));
        $this->assertTrue(CatalogService::prop('provider_category', 'locales', 'valida_identificacion'));
        $this->assertSame('local', Provider::deriveOrigin('locales'));
        $this->assertSame('internacional', Provider::deriveOrigin('zona_libre'));
        // Clave desconocida: valor por omisión del dominio.
        $this->assertSame('internacional', Provider::deriveOrigin('inexistente'));
    }

    public function test_la_lista_de_dominios_informa_que_no_puede_cambiar_una_entrada_de_sistema(): void
    {
        $h = $this->authHeaders($this->loginAs('designer@atlantic.test', 'Password123!', 'designer'));
        $dominios = collect($this->withHeaders($h)->getJson('/api/catalogs/domains')->assertOk()->json())->keyBy('domain');

        $this->assertSame(['sort_order', 'categoria'], $dominios['pipeline_stage']['locked_when_system']);
        $this->assertSame([], $dominios['architect_type']['locked_when_system']);
        $this->assertSame(['ganada', 'perdida'], $dominios['pipeline_stage']['props']['categoria']['system_only']);
    }

    public function test_cualquier_autenticado_lee_las_entradas_activas_y_la_administracion_es_de_superadmin(): void
    {
        $this->getJson('/api/catalogs/provider_category')->assertStatus(401);

        $h = $this->authHeaders($this->loginAs('designer@atlantic.test', 'Password123!', 'designer'));
        $this->withHeaders($h)->getJson('/api/catalogs/provider_category')->assertOk()->assertJsonCount(6)->assertJsonPath('0.key', 'locales');
        $this->withHeaders($h)->getJson('/api/catalogs/no_existe')->assertNotFound();
        $this->withHeaders($h)->getJson('/api/catalogs/domains')->assertOk()->assertJsonFragment(['domain' => 'architect_type']);

        $this->withHeaders($h)->getJson('/api/admin/catalogs/provider_category')->assertStatus(403);
        $this->withHeaders($h)->postJson('/api/admin/catalogs/provider_category', ['key' => 'mayorista', 'label' => 'Mayorista'])->assertStatus(403);
    }

    public function test_superadmin_crea_una_categoria_nueva_que_se_comporta_segun_sus_propiedades(): void
    {
        $h = $this->superadmin();

        $this->withHeaders($h)->postJson('/api/admin/catalogs/provider_category', [
            'key' => 'mayorista', 'label' => 'Mayorista nacional', 'sort_order' => 10,
            'props' => ['origen' => 'local', 'valida_identificacion' => true],
        ])->assertCreated()->assertJsonPath('is_system', false);

        $this->activarTenant();
        $this->assertSame('local', Provider::deriveOrigin('mayorista'));
        $this->assertTrue(Provider::categoryValidatesTaxId('mayorista'));
        $this->withHeaders($h)->postJson('/api/compras/providers', ['name' => 'Distribuidora Uno', 'category' => 'mayorista', 'country' => 'Panamá', 'phone' => '6000-0000'])
            ->assertCreated()->assertJsonPath('origin', 'local');

        // Clave repetida, propiedad desconocida y valor fuera del enum.
        $this->withHeaders($h)->postJson('/api/admin/catalogs/provider_category', ['key' => 'mayorista', 'label' => 'X'])->assertStatus(422);
        $this->withHeaders($h)->postJson('/api/admin/catalogs/provider_category', ['key' => 'otra', 'label' => 'X', 'props' => ['magia' => true]])->assertStatus(422);
        $this->withHeaders($h)->postJson('/api/admin/catalogs/provider_category', ['key' => 'otra', 'label' => 'X', 'props' => ['origen' => 'marte']])->assertStatus(422);
    }

    public function test_una_categoria_inexistente_o_inactiva_se_rechaza_al_validar(): void
    {
        $h = $this->superadmin();
        $body = ['name' => 'Proveedor X', 'category' => 'inexistente', 'country' => 'Panamá', 'phone' => '6000-0000'];
        $this->withHeaders($h)->postJson('/api/compras/providers', $body)->assertStatus(422)->assertJsonValidationErrors('category');

        $this->activarTenant();
        $entry = CatalogEntry::where('domain', 'provider_category')->where('key', 'online')->firstOrFail();
        $this->withHeaders($h)->putJson("/api/admin/catalogs/provider_category/{$entry->id}", ['is_active' => false])->assertStatus(422);

        $nueva = $this->withHeaders($h)->postJson('/api/admin/catalogs/provider_category', ['key' => 'temporal', 'label' => 'Temporal'])->assertCreated()->json('id');
        $this->withHeaders($h)->putJson("/api/admin/catalogs/provider_category/{$nueva}", ['is_active' => false])->assertOk();
        $this->withHeaders($h)->postJson('/api/compras/providers', ['category' => 'temporal'] + $body)->assertStatus(422)->assertJsonValidationErrors('category');
    }

    public function test_las_entradas_de_sistema_o_en_uso_no_se_borran_pero_se_renombran(): void
    {
        $h = $this->superadmin();
        $this->activarTenant();
        $sistema = CatalogEntry::where('domain', 'architect_type')->where('key', 'architect')->firstOrFail();

        $this->withHeaders($h)->deleteJson("/api/admin/catalogs/architect_type/{$sistema->id}")->assertStatus(422);
        $this->withHeaders($h)->putJson("/api/admin/catalogs/architect_type/{$sistema->id}", ['label' => 'Prescriptor', 'sort_order' => 5])
            ->assertOk()->assertJsonPath('label', 'Prescriptor');

        // Una entrada propia en uso por un registro no se borra.
        $id = $this->withHeaders($h)->postJson('/api/admin/catalogs/architect_type', ['key' => 'paisajista', 'label' => 'Paisajista'])->assertCreated()->json('id');
        $this->withHeaders($h)->postJson('/api/ventas-diseno/architects', ['name' => 'Ana Paisaje', 'type' => 'paisajista', 'email' => 'ana@test.com'])->assertCreated();
        $this->withHeaders($h)->deleteJson("/api/admin/catalogs/architect_type/{$id}")->assertStatus(422);

        // Sin uso, sí.
        $libre = $this->withHeaders($h)->postJson('/api/admin/catalogs/architect_type', ['key' => 'libre', 'label' => 'Libre'])->assertCreated()->json('id');
        $this->withHeaders($h)->deleteJson("/api/admin/catalogs/architect_type/{$libre}")->assertNoContent();
    }
}
