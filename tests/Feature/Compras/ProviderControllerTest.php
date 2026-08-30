<?php

declare(strict_types=1);

namespace Tests\Feature\Compras;

use App\Modules\Compras\Models\Provider;
use App\Modules\Compras\Models\PurchaseOrder;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-183→190 (REQ-120→127) — Proveedores, sprint2 (rama sprint2, sin push a dev).
 */
class ProviderControllerTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'illuminations')->first()?->makeCurrent();
    }

    private function grantCompras(string $email, array $perms): void
    {
        $slug = (string) Tenant::where('slug', 'illuminations')->value('slug');
        DB::table("{$slug}_auth.users")
            ->where('email', $email)
            ->update(['extra_permissions' => json_encode($perms)]);
    }

    private function makeProvider(array $overrides = []): Provider
    {
        return Provider::create(['name' => 'LightCorp', 'currency' => 'USD', ...$overrides]);
    }

    public function test_listado_sin_autenticar_retorna_401(): void
    {
        $this->getJson('/api/compras/providers')->assertStatus(401);
    }

    public function test_listado_incluye_solo_proveedores_activos(): void
    {
        $this->grantCompras('designer@illuminations.test', ['compras.read']);
        $this->makeProvider(['name' => 'Activo SA', 'is_active' => true]);
        $this->makeProvider(['name' => 'Inactivo SA', 'is_active' => false]);

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/providers')
            ->assertStatus(200);

        $names = collect($response->json('data'))->pluck('name');
        $this->assertContains('Activo SA', $names);
        $this->assertNotContains('Inactivo SA', $names);
    }

    public function test_crear_proveedor_con_minimo_obligatorio_usa_moneda_usd_por_defecto(): void
    {
        // REQ-126 RN2. RN1 realineado 2026-08-06 (hallazgo Daniela Amaya): obligatorios
        // name/category/country + al menos un contacto — ver test_crear_proveedor_sin_* abajo
        // para cada validación negativa por separado.
        $this->grantCompras('designer@illuminations.test', ['compras.write']);

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/compras/providers', [
                'name' => 'Nuevo Proveedor SA', 'category' => 'locales', 'country' => 'Panamá',
                'phone' => '6000-0000',
            ])
            ->assertStatus(201);

        $response->assertJsonPath('name', 'Nuevo Proveedor SA');
        $response->assertJsonPath('currency', 'USD');
    }

    public function test_crear_proveedor_sin_categoria_retorna_422(): void
    {
        $this->grantCompras('designer@illuminations.test', ['compras.write']);

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/compras/providers', ['name' => 'X', 'country' => 'Panamá', 'phone' => '6000-0000'])
            ->assertStatus(422);
    }

    public function test_crear_proveedor_sin_pais_retorna_422(): void
    {
        $this->grantCompras('designer@illuminations.test', ['compras.write']);

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/compras/providers', ['name' => 'X', 'category' => 'locales', 'phone' => '6000-0000'])
            ->assertStatus(422);
    }

    public function test_crear_proveedor_sin_ningun_contacto_retorna_422(): void
    {
        $this->grantCompras('designer@illuminations.test', ['compras.write']);

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/compras/providers', ['name' => 'X', 'category' => 'locales', 'country' => 'Panamá'])
            ->assertStatus(422);
    }

    public function test_crear_proveedor_con_solo_email_como_contacto_es_valido(): void
    {
        $this->grantCompras('designer@illuminations.test', ['compras.write']);

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/compras/providers', [
                'name' => 'X', 'category' => 'locales', 'country' => 'Panamá', 'email' => 'x@x.com',
            ])
            ->assertStatus(201);
    }

    public function test_crear_proveedor_sin_permiso_retorna_403(): void
    {
        $this->grantCompras('designer@illuminations.test', ['compras.read']);

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/compras/providers', ['name' => 'Nuevo Proveedor SA'])
            ->assertStatus(403);
    }

    public function test_crear_proveedor_disponible_de_inmediato_en_listado(): void
    {
        // REQ-127/REQ-126 Escenario 1.
        $this->grantCompras('designer@illuminations.test', ['compras.read', 'compras.write']);

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/compras/providers', [
                'name' => 'Nuevo Proveedor SA', 'category' => 'locales', 'country' => 'Panamá',
                'phone' => '6000-0000',
            ])
            ->assertStatus(201);

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/providers')
            ->assertStatus(200);

        $this->assertContains('Nuevo Proveedor SA', collect($response->json('data'))->pluck('name'));
    }

    public function test_editar_borrando_el_nombre_retorna_422(): void
    {
        // REQ-125 Escenario 2.
        $this->grantCompras('designer@illuminations.test', ['compras.edit']);
        $provider = $this->makeProvider();

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/compras/providers/{$provider->id}", ['name' => ''])
            ->assertStatus(422);
    }

    public function test_editar_telefono_se_refleja_en_la_tabla_principal(): void
    {
        // REQ-125 Escenario 1.
        $this->grantCompras('designer@illuminations.test', ['compras.read', 'compras.edit']);
        $provider = $this->makeProvider(['name' => 'LightCorp']);

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/compras/providers/{$provider->id}", ['name' => 'LightCorp', 'phone' => '6000-0000'])
            ->assertStatus(200)
            ->assertJsonPath('phone', '6000-0000');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/compras/providers/{$provider->id}")
            ->assertStatus(200);
        $response->assertJsonPath('phone', '6000-0000');
    }

    public function test_proveedor_sin_ordenes_muestra_ultima_compra_null(): void
    {
        // REQ-123 RN2: "Sin compras registradas aún" — el backend nunca inventa una fecha.
        $this->grantCompras('designer@illuminations.test', ['compras.read']);
        $this->makeProvider(['name' => 'Sin Compras SA']);

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/providers')
            ->assertStatus(200);

        $row = collect($response->json('data'))->firstWhere('name', 'Sin Compras SA');
        $this->assertNull($row['last_purchase_at']);
    }

    public function test_crear_orden_actualiza_ultima_compra_del_proveedor(): void
    {
        // REQ-123 Escenario 1: campo calculado desde las órdenes reales, no editable a mano.
        $this->grantCompras('designer@illuminations.test', ['compras.read']);
        $provider = $this->makeProvider(['name' => 'Con Compras SA']);
        PurchaseOrder::create(['provider_id' => $provider->id, 'currency' => 'USD']);

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/providers')
            ->assertStatus(200);

        $row = collect($response->json('data'))->firstWhere('name', 'Con Compras SA');
        $this->assertSame(now()->toDateString(), $row['last_purchase_at']);
    }

    public function test_proveedor_sin_ordenes_recibidas_muestra_sin_calificar(): void
    {
        // REQ-124 RN2/Escenario 2: nunca 0/100.
        $this->grantCompras('designer@illuminations.test', ['compras.read']);
        $provider = $this->makeProvider(['name' => 'Sin Calificar SA']);
        PurchaseOrder::create([
            'provider_id' => $provider->id, 'currency' => 'USD', 'status' => PurchaseOrder::STATUS_POR_APROBAR,
        ]);

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/providers')
            ->assertStatus(200);

        $row = collect($response->json('data'))->firstWhere('name', 'Sin Calificar SA');
        $this->assertNull($row['rating']);
    }

    public function test_kpis_promedio_de_calificacion_excluye_sin_calificar(): void
    {
        // REQ-121 Escenario 1. Total = 2 creados acá + 1 ("Zona Libre de Colón", sembrado por
        // `ZonaLibreProviderSeeder` en TODOS los entornos, incl. testing, ver Bloque B3) — sin
        // órdenes recibidas tampoco, así que sigue sin afectar el promedio/low_rating_count.
        $this->grantCompras('designer@illuminations.test', ['compras.read']);
        $this->makeProvider(['name' => 'A']);
        $this->makeProvider(['name' => 'B']);

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/providers')
            ->assertStatus(200);

        $response->assertJsonPath('kpis.total_providers', 3);
        $response->assertJsonPath('kpis.average_rating', null);
        $response->assertJsonPath('kpis.low_rating_count', 0);
    }

    /**
     * SCRUM-185 (hallazgo QA 2026-07-16): el filtro de categoría era un input de texto libre —
     * ahora el frontend lo renderiza como <select> a partir de esta lista real.
     */
    public function test_kpis_expone_lista_de_categorias_reales(): void
    {
        $this->grantCompras('designer@illuminations.test', ['compras.read']);
        $this->makeProvider(['name' => 'A', 'category' => 'Iluminación']);
        $this->makeProvider(['name' => 'B', 'category' => 'Cableado']);
        $this->makeProvider(['name' => 'C', 'category' => 'Iluminación']);
        $this->makeProvider(['name' => 'D', 'category' => null]);

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/providers')
            ->assertStatus(200);

        // + 'zona_libre': ZonaLibreProviderSeeder (sembrado en TODOS los entornos, incl. testing)
        // le asigna esa categoría desde el 2026-07-30 (SCRUM-189), consistente con
        // Provider::deriveOrigin().
        $this->assertEqualsCanonicalizing(['Cableado', 'Iluminación', 'zona_libre'], $response->json('kpis.categories'));
    }

    public function test_filtro_por_categoria(): void
    {
        $this->grantCompras('designer@illuminations.test', ['compras.read']);
        $this->makeProvider(['name' => 'A', 'category' => 'Iluminación']);
        $this->makeProvider(['name' => 'B', 'category' => 'Cableado']);

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/providers?category=' . urlencode('Cableado'))
            ->assertStatus(200);

        $names = collect($response->json('data'))->pluck('name');
        $this->assertEquals(['B'], $names->all());
    }

    public function test_busqueda_por_nombre(): void
    {
        // REQ-122.
        $this->grantCompras('designer@illuminations.test', ['compras.read']);
        $this->makeProvider(['name' => 'LightCorp']);
        $this->makeProvider(['name' => 'Iluminex SA']);

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/providers?search=LightCorp')
            ->assertStatus(200);

        $names = collect($response->json('data'))->pluck('name');
        $this->assertCount(1, $names);
        $this->assertContains('LightCorp', $names);
    }

    public function test_default_muestra_20_por_pagina(): void
    {
        // Política global de paginación (memory/feedback_pagination_sorting_policy.md). SCRUM-754
        // (2026-08-18): default subió de 5 a 20. Total = 21 creados acá + 1 ("Zona Libre de
        // Colón", sembrado en todos los entornos, ver Bloque B3) = 22.
        $this->grantCompras('designer@illuminations.test', ['compras.read']);
        foreach (range(1, 21) as $i) {
            $this->makeProvider(['name' => "Proveedor {$i}"]);
        }

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/providers')
            ->assertStatus(200);

        $this->assertCount(20, $response->json('data'));
        $response->assertJsonPath('meta.total', 22);
        $response->assertJsonPath('meta.per_page', 20);
        $response->assertJsonPath('meta.last_page', 2);
    }

    public function test_per_page_all_devuelve_todos(): void
    {
        // Total = 6 creados acá + 1 ("Zona Libre de Colón", sembrado en todos los entornos).
        $this->grantCompras('designer@illuminations.test', ['compras.read']);
        foreach (range(1, 6) as $i) {
            $this->makeProvider(['name' => "Proveedor {$i}"]);
        }

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/providers?per_page=all')
            ->assertStatus(200);

        $this->assertCount(7, $response->json('data'));
    }

    public function test_sort_by_name_desc(): void
    {
        // "Zona Libre de Colón" (sembrado en todos los entornos, ver Bloque B3) ordena primero en
        // DESC ("Z" > "B" > "A").
        $this->grantCompras('designer@illuminations.test', ['compras.read']);
        $this->makeProvider(['name' => 'Alfa']);
        $this->makeProvider(['name' => 'Beta']);

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/providers?sort_by=name&sort_dir=desc')
            ->assertStatus(200);

        $names = collect($response->json('data'))->pluck('name')->values()->all();
        $this->assertSame(['Zona Libre de Colón', 'Beta', 'Alfa'], $names);
    }

    /**
     * SCRUM-189 (REQ-126, hallazgo de Daniela Amaya 2026-07-30): el form divergía del mockup real
     * `2G__Compras_Proveedores.html`. Cubre los campos realineados y que `origin` (REQ-141) ya
     * no se acepta como input directo — se deriva de `category`.
     */
    public function test_crear_proveedor_con_campos_del_mockup_deriva_origin_de_categoria(): void
    {
        $this->grantCompras('designer@illuminations.test', ['compras.read', 'compras.write']);
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/compras/providers', [
                'name' => 'LightCorp Asia', 'category' => 'china',
                'contact_name' => 'Wei Chen', 'whatsapp' => '+86 138-0000-0000', 'phone' => '+86 21-0000-0000',
                'email' => 'wei@lightcorp.cn', 'address' => 'Nanshan District', 'country' => 'China',
                'bank_name' => 'Bank of China', 'bank_account_number' => 'CN00 0000 0000',
                'bank_swift' => 'BKCHCNBJ', 'bank_beneficiary' => 'LightCorp Asia Ltd',
                // origin enviado explícitamente por el cliente: se ignora, no lo acepta la validación.
                'origin' => 'local',
            ])
            ->assertStatus(201);

        $response->assertJsonPath('category', 'china');
        $response->assertJsonPath('origin', 'internacional'); // derivado de category=china, no del 'local' enviado
        $response->assertJsonPath('whatsapp', '+86 138-0000-0000');
        $response->assertJsonPath('address', 'Nanshan District');
        $response->assertJsonPath('country', 'China');
        $response->assertJsonPath('bank_swift', 'BKCHCNBJ');
        $response->assertJsonPath('bank_beneficiary', 'LightCorp Asia Ltd');
    }

    public function test_crear_proveedor_categoria_locales_deriva_origin_local(): void
    {
        $this->grantCompras('designer@illuminations.test', ['compras.write']);
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/compras/providers', [
                'name' => 'Talleres del Istmo', 'category' => 'locales', 'country' => 'Panamá',
                'phone' => '6000-0000',
            ])
            ->assertStatus(201);

        $response->assertJsonPath('origin', 'local');
    }

    public function test_crear_proveedor_categoria_invalida_retorna_422(): void
    {
        $this->grantCompras('designer@illuminations.test', ['compras.write']);
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/compras/providers', ['name' => 'X', 'category' => 'iluminacion'])
            ->assertStatus(422);
    }

    /** SCRUM-190 (REQ-127 RN3, hallazgo de Daniela Amaya): no debe haber 2 proveedores con el mismo nombre. */
    public function test_crear_proveedor_con_nombre_duplicado_retorna_422(): void
    {
        $this->grantCompras('designer@illuminations.test', ['compras.write']);
        $this->makeProvider(['name' => 'LightCorp']);
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/compras/providers', ['name' => 'LightCorp'])
            ->assertStatus(422);
    }

    public function test_crear_proveedor_con_nombre_duplicado_case_insensitive_retorna_422(): void
    {
        $this->grantCompras('designer@illuminations.test', ['compras.write']);
        $this->makeProvider(['name' => 'LightCorp']);
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/compras/providers', ['name' => 'lightcorp'])
            ->assertStatus(422);
    }

    public function test_editar_proveedor_sin_cambiar_nombre_no_se_bloquea_a_si_mismo(): void
    {
        $this->grantCompras('designer@illuminations.test', ['compras.edit']);
        $provider = $this->makeProvider(['name' => 'LightCorp']);
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/compras/providers/{$provider->id}", ['name' => 'LightCorp', 'category' => 'europa'])
            ->assertStatus(200)
            ->assertJsonPath('origin', 'internacional');
    }

    /**
     * Senior Review propio (2026-07-30): `$data['category'] ?? $provider->category` confundía un
     * `category: null` explícito (vaciar el campo) con "no lo mandé" — `??` trata ambos igual y
     * dejaba el `origin` viejo sin recalcular. Corregido con `array_key_exists`.
     */
    public function test_editar_proveedor_vaciando_categoria_recalcula_origin_a_internacional(): void
    {
        $this->grantCompras('designer@illuminations.test', ['compras.edit']);
        $provider = $this->makeProvider(['name' => 'Talleres del Istmo', 'category' => 'locales']);

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/compras/providers/{$provider->id}", ['name' => 'Talleres del Istmo', 'category' => null])
            ->assertStatus(200)
            ->assertJsonPath('category', null)
            ->assertJsonPath('origin', 'internacional');
    }

    public function test_editar_proveedor_con_nombre_de_otro_proveedor_retorna_422(): void
    {
        $this->grantCompras('designer@illuminations.test', ['compras.edit']);
        $this->makeProvider(['name' => 'LightCorp']);
        $otro = $this->makeProvider(['name' => 'Iluminex SA']);
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/compras/providers/{$otro->id}", ['name' => 'LightCorp'])
            ->assertStatus(422);
    }

    public function test_pagina_2_muestra_el_resto(): void
    {
        // Total = 21 creados acá + 1 ("Zona Libre de Colón", sembrado en todos los entornos) = 22,
        // per_page 20 (SCRUM-754) -> página 2 tiene los 2 restantes.
        $this->grantCompras('designer@illuminations.test', ['compras.read']);
        foreach (range(1, 21) as $i) {
            $this->makeProvider(['name' => "Proveedor {$i}"]);
        }

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/providers?page=2')
            ->assertStatus(200);

        $this->assertCount(2, $response->json('data'));
        $response->assertJsonPath('meta.current_page', 2);
    }
}
