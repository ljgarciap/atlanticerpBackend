<?php

declare(strict_types=1);

namespace Tests\Feature\Compras;

use App\Modules\Compras\Models\Provider;
use App\Modules\Compras\Models\PurchaseOrder;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-183→190 (REQ-120→127) — Proveedores, sprint2 (rama sprint2, sin push a dev).
 *
 * ADR-SCRUM819 (2026-09-01): subir una imagen bancaria ahora dispara ExtractProviderBankInfoJob
 * automáticamente — con QUEUE_CONNECTION=sync en testing corre dentro del mismo request. Mismo
 * gotcha ya documentado en PurchaseOrderDocumentControllerTest: sin `services.anthropic.api_key`
 * configurada + un `Http::fake()` con forma de tool_use válida, AnthropicClient::captureUsage()
 * revienta con TypeError. Los tests de extracción real (parcial, rate limit, validación de
 * schema, etc.) viven en ProviderBankInfoExtractionTest.php — acá solo lo mínimo para que los
 * tests de upload preexistentes (que no les interesa el resultado de la IA) sigan pasando.
 */
class ProviderControllerTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        Storage::fake('s3-private');
        config(['services.anthropic.api_key' => 'sk-ant-test-fake-key-for-tests']);
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [[
                    'type' => 'tool_use',
                    'id' => 'toolu_test',
                    'name' => 'extract_bank_data',
                    'input' => ['fields_found' => [], 'confidence' => 'baja'],
                ]],
            ], 200),
        ]);
    }

    private function grantCompras(string $email, array $perms): void
    {
        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');
        DB::table("{$slug}_auth.users")
            ->where('email', $email)
            ->update(['extra_permissions' => json_encode($perms)]);
    }

    private function makeProvider(array $overrides = []): Provider
    {
        return Provider::create(['name' => 'Proveedor Demo A', 'currency' => 'USD', ...$overrides]);
    }

    public function test_listado_sin_autenticar_retorna_401(): void
    {
        $this->getJson('/api/compras/providers')->assertStatus(401);
    }

    public function test_listado_incluye_solo_proveedores_activos(): void
    {
        $this->grantCompras('vendedor@atlantic.test', ['compras.read']);
        $this->makeProvider(['name' => 'Activo SA', 'is_active' => true]);
        $this->makeProvider(['name' => 'Inactivo SA', 'is_active' => false]);

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/providers')
            ->assertStatus(200);

        $names = collect($response->json('data'))->pluck('name');
        $this->assertContains('Activo SA', $names);
        $this->assertNotContains('Inactivo SA', $names);
    }

    public function test_crear_proveedor_con_minimo_obligatorio_usa_moneda_usd_por_defecto(): void
    {
        // REQ-126 RN2. RN1 realineado 2026-08-06 (hallazgo Gerencia Test): obligatorios
        // name/category/country + al menos un contacto — ver test_crear_proveedor_sin_* abajo
        // para cada validación negativa por separado.
        $this->grantCompras('vendedor@atlantic.test', ['compras.write']);

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');
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
        $this->grantCompras('vendedor@atlantic.test', ['compras.write']);

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/compras/providers', ['name' => 'X', 'country' => 'Panamá', 'phone' => '6000-0000'])
            ->assertStatus(422);
    }

    public function test_crear_proveedor_sin_pais_retorna_422(): void
    {
        $this->grantCompras('vendedor@atlantic.test', ['compras.write']);

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/compras/providers', ['name' => 'X', 'category' => 'locales', 'phone' => '6000-0000'])
            ->assertStatus(422);
    }

    public function test_crear_proveedor_sin_ningun_contacto_retorna_422(): void
    {
        $this->grantCompras('vendedor@atlantic.test', ['compras.write']);

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/compras/providers', ['name' => 'X', 'category' => 'locales', 'country' => 'Panamá'])
            ->assertStatus(422);
    }

    public function test_crear_proveedor_con_solo_email_como_contacto_es_valido(): void
    {
        $this->grantCompras('vendedor@atlantic.test', ['compras.write']);

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/compras/providers', [
                'name' => 'X', 'category' => 'locales', 'country' => 'Panamá', 'email' => 'x@x.com',
            ])
            ->assertStatus(201);
    }

    public function test_crear_proveedor_sin_permiso_retorna_403(): void
    {
        $this->grantCompras('vendedor@atlantic.test', ['compras.read']);

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/compras/providers', ['name' => 'Nuevo Proveedor SA'])
            ->assertStatus(403);
    }

    public function test_crear_proveedor_disponible_de_inmediato_en_listado(): void
    {
        // REQ-127/REQ-126 Escenario 1.
        $this->grantCompras('vendedor@atlantic.test', ['compras.read', 'compras.write']);

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');
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
        $this->grantCompras('vendedor@atlantic.test', ['compras.edit']);
        $provider = $this->makeProvider();

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/compras/providers/{$provider->id}", ['name' => ''])
            ->assertStatus(422);
    }

    // SCRUM-1014 — RUC y DV se guardan al crear/editar y vuelven en el detalle.
    public function test_crear_proveedor_guarda_ruc_y_dv_y_los_devuelve_en_el_detalle(): void
    {
        $this->grantCompras('vendedor@atlantic.test', ['compras.read', 'compras.write']);
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $id = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/compras/providers', [
                'name' => 'Con RUC SA', 'category' => 'locales', 'country' => 'Panamá',
                'phone' => '6000-0000', 'tax_id' => '8-123-4567', 'tax_id_dv' => '12',
            ])
            ->assertStatus(201)
            ->assertJsonPath('tax_id', '8-123-4567')
            ->assertJsonPath('tax_id_dv', '12')
            ->json('id');

        $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/compras/providers/{$id}")
            ->assertStatus(200)
            ->assertJsonPath('tax_id', '8-123-4567')
            ->assertJsonPath('tax_id_dv', '12');
    }

    public function test_editar_proveedor_actualiza_ruc_y_dv(): void
    {
        $this->grantCompras('vendedor@atlantic.test', ['compras.read', 'compras.edit']);
        $provider = $this->makeProvider(['name' => 'Proveedor Demo A', 'tax_id' => 'VIEJO-1', 'tax_id_dv' => '1']);
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/compras/providers/{$provider->id}", [
                'name' => 'Proveedor Demo A', 'tax_id' => 'NUEVO-2', 'tax_id_dv' => '07',
            ])
            ->assertStatus(200)
            ->assertJsonPath('tax_id', 'NUEVO-2')
            ->assertJsonPath('tax_id_dv', '07');

        $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/compras/providers/{$provider->id}")
            ->assertJsonPath('tax_id', 'NUEVO-2')
            ->assertJsonPath('tax_id_dv', '07');
    }

    public function test_dv_invalido_retorna_422(): void
    {
        $this->grantCompras('vendedor@atlantic.test', ['compras.write']);
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/compras/providers', [
                'name' => 'DV Malo SA', 'category' => 'locales', 'country' => 'Panamá',
                'phone' => '6000-0000', 'tax_id' => '1-1-1', 'tax_id_dv' => 'abc',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['tax_id_dv']);
    }

    public function test_editar_telefono_se_refleja_en_la_tabla_principal(): void
    {
        // REQ-125 Escenario 1.
        $this->grantCompras('vendedor@atlantic.test', ['compras.read', 'compras.edit']);
        $provider = $this->makeProvider(['name' => 'Proveedor Demo A']);

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/compras/providers/{$provider->id}", ['name' => 'Proveedor Demo A', 'phone' => '6000-0000'])
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
        $this->grantCompras('vendedor@atlantic.test', ['compras.read']);
        $this->makeProvider(['name' => 'Sin Compras SA']);

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/providers')
            ->assertStatus(200);

        $row = collect($response->json('data'))->firstWhere('name', 'Sin Compras SA');
        $this->assertNull($row['last_purchase_at']);
    }

    public function test_crear_orden_actualiza_ultima_compra_del_proveedor(): void
    {
        // REQ-123 Escenario 1: campo calculado desde las órdenes reales, no editable a mano.
        $this->grantCompras('vendedor@atlantic.test', ['compras.read']);
        $provider = $this->makeProvider(['name' => 'Con Compras SA']);
        PurchaseOrder::create(['provider_id' => $provider->id, 'currency' => 'USD']);

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/providers')
            ->assertStatus(200);

        $row = collect($response->json('data'))->firstWhere('name', 'Con Compras SA');
        $this->assertSame(now()->toDateString(), $row['last_purchase_at']);
    }

    public function test_proveedor_sin_ordenes_recibidas_muestra_sin_calificar(): void
    {
        // REQ-124 RN2/Escenario 2: nunca 0/100.
        $this->grantCompras('vendedor@atlantic.test', ['compras.read']);
        $provider = $this->makeProvider(['name' => 'Sin Calificar SA']);
        PurchaseOrder::create([
            'provider_id' => $provider->id, 'currency' => 'USD', 'status' => PurchaseOrder::STATUS_POR_APROBAR,
        ]);

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');
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
        $this->grantCompras('vendedor@atlantic.test', ['compras.read']);
        $this->makeProvider(['name' => 'A']);
        $this->makeProvider(['name' => 'B']);

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');
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
        $this->grantCompras('vendedor@atlantic.test', ['compras.read']);
        $this->makeProvider(['name' => 'A', 'category' => 'Catalogación']);
        $this->makeProvider(['name' => 'B', 'category' => 'Cableado']);
        $this->makeProvider(['name' => 'C', 'category' => 'Catalogación']);
        $this->makeProvider(['name' => 'D', 'category' => null]);

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/providers')
            ->assertStatus(200);

        // + 'zona_libre': ZonaLibreProviderSeeder (sembrado en TODOS los entornos, incl. testing)
        // le asigna esa categoría desde el 2026-07-30 (SCRUM-189), consistente con
        // Provider::deriveOrigin().
        $this->assertEqualsCanonicalizing(['Cableado', 'Catalogación', 'zona_libre'], $response->json('kpis.categories'));
    }

    public function test_filtro_por_categoria(): void
    {
        $this->grantCompras('vendedor@atlantic.test', ['compras.read']);
        $this->makeProvider(['name' => 'A', 'category' => 'Catalogación']);
        $this->makeProvider(['name' => 'B', 'category' => 'Cableado']);

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/providers?category='.urlencode('Cableado'))
            ->assertStatus(200);

        $names = collect($response->json('data'))->pluck('name');
        $this->assertEquals(['B'], $names->all());
    }

    public function test_busqueda_por_nombre(): void
    {
        // REQ-122.
        $this->grantCompras('vendedor@atlantic.test', ['compras.read']);
        $this->makeProvider(['name' => 'Proveedor Demo A']);
        $this->makeProvider(['name' => 'Proveedor Demo B SA']);

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/providers?search=Proveedor Demo A')
            ->assertStatus(200);

        $names = collect($response->json('data'))->pluck('name');
        $this->assertCount(1, $names);
        $this->assertContains('Proveedor Demo A', $names);
    }

    public function test_default_muestra_20_por_pagina(): void
    {
        // Política global de paginación (memory/feedback_pagination_sorting_policy.md). SCRUM-754
        // (2026-08-18): default subió de 5 a 20. Total = 21 creados acá + 1 ("Zona Libre de
        // Colón", sembrado en todos los entornos, ver Bloque B3) = 22.
        $this->grantCompras('vendedor@atlantic.test', ['compras.read']);
        foreach (range(1, 21) as $i) {
            $this->makeProvider(['name' => "Proveedor {$i}"]);
        }

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');
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
        $this->grantCompras('vendedor@atlantic.test', ['compras.read']);
        foreach (range(1, 6) as $i) {
            $this->makeProvider(['name' => "Proveedor {$i}"]);
        }

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/providers?per_page=all')
            ->assertStatus(200);

        $this->assertCount(7, $response->json('data'));
    }

    public function test_sort_by_name_desc(): void
    {
        // "BK INTERPRISES S.A." (sembrado en todos los entornos, ver Bloque B3 / SCRUM-1135).
        $this->grantCompras('vendedor@atlantic.test', ['compras.read']);
        $this->makeProvider(['name' => 'Alfa']);
        $this->makeProvider(['name' => 'Coral']);

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/providers?sort_by=name&sort_dir=desc')
            ->assertStatus(200);

        $names = collect($response->json('data'))->pluck('name')->values()->all();
        $this->assertSame(['Coral', 'BK INTERPRISES S.A.', 'Alfa'], $names);
    }

    /**
     * SCRUM-189 (REQ-126, hallazgo de Gerencia Test 2026-07-30): el form divergía del mockup real
     * `2G__Compras_Proveedores.html`. Cubre los campos realineados y que `origin` (REQ-141) ya
     * no se acepta como input directo — se deriva de `category`.
     */
    public function test_crear_proveedor_con_campos_del_mockup_deriva_origin_de_categoria(): void
    {
        $this->grantCompras('vendedor@atlantic.test', ['compras.read', 'compras.write']);
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/compras/providers', [
                'name' => 'Proveedor Demo A Asia', 'category' => 'china',
                'contact_name' => 'Wei Chen', 'whatsapp' => '+86 138-0000-0000', 'phone' => '+86 21-0000-0000',
                'email' => 'wei@proveedor-demo-a.cn', 'address' => 'Nanshan District', 'country' => 'China',
                'bank_name' => 'Bank of China', 'bank_account_number' => 'CN00 0000 0000',
                'bank_swift' => 'BKCHCNBJ', 'bank_beneficiary' => 'Proveedor Demo A Asia Ltd',
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
        $response->assertJsonPath('bank_beneficiary', 'Proveedor Demo A Asia Ltd');
    }

    public function test_crear_proveedor_categoria_locales_deriva_origin_local(): void
    {
        $this->grantCompras('vendedor@atlantic.test', ['compras.write']);
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

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
        $this->grantCompras('vendedor@atlantic.test', ['compras.write']);
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/compras/providers', ['name' => 'X', 'category' => 'catalogación'])
            ->assertStatus(422);
    }

    /** SCRUM-190 (REQ-127 RN3, hallazgo de Gerencia Test): no debe haber 2 proveedores con el mismo nombre. */
    public function test_crear_proveedor_con_nombre_duplicado_retorna_422(): void
    {
        $this->grantCompras('vendedor@atlantic.test', ['compras.write']);
        $this->makeProvider(['name' => 'Proveedor Demo A']);
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/compras/providers', ['name' => 'Proveedor Demo A'])
            ->assertStatus(422);
    }

    public function test_crear_proveedor_con_nombre_duplicado_case_insensitive_retorna_422(): void
    {
        $this->grantCompras('vendedor@atlantic.test', ['compras.write']);
        $this->makeProvider(['name' => 'Proveedor Demo A']);
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/compras/providers', ['name' => 'proveedor demo a'])
            ->assertStatus(422);
    }

    public function test_editar_proveedor_sin_cambiar_nombre_no_se_bloquea_a_si_mismo(): void
    {
        $this->grantCompras('vendedor@atlantic.test', ['compras.edit']);
        $provider = $this->makeProvider(['name' => 'Proveedor Demo A']);
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/compras/providers/{$provider->id}", ['name' => 'Proveedor Demo A', 'category' => 'europa'])
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
        $this->grantCompras('vendedor@atlantic.test', ['compras.edit']);
        $provider = $this->makeProvider(['name' => 'Talleres del Istmo', 'category' => 'locales']);

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/compras/providers/{$provider->id}", ['name' => 'Talleres del Istmo', 'category' => null])
            ->assertStatus(200)
            ->assertJsonPath('category', null)
            ->assertJsonPath('origin', 'internacional');
    }

    public function test_editar_proveedor_con_nombre_de_otro_proveedor_retorna_422(): void
    {
        $this->grantCompras('vendedor@atlantic.test', ['compras.edit']);
        $this->makeProvider(['name' => 'Proveedor Demo A']);
        $otro = $this->makeProvider(['name' => 'Proveedor Demo B SA']);
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/compras/providers/{$otro->id}", ['name' => 'Proveedor Demo A'])
            ->assertStatus(422);
    }

    public function test_pagina_2_muestra_el_resto(): void
    {
        // Total = 21 creados acá + 1 ("Zona Libre de Colón", sembrado en todos los entornos) = 22,
        // per_page 20 (SCRUM-754) -> página 2 tiene los 2 restantes.
        $this->grantCompras('vendedor@atlantic.test', ['compras.read']);
        foreach (range(1, 21) as $i) {
            $this->makeProvider(['name' => "Proveedor {$i}"]);
        }

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/providers?page=2')
            ->assertStatus(200);

        $this->assertCount(2, $response->json('data'));
        $response->assertJsonPath('meta.current_page', 2);
    }

    // ── SCRUM-819 — imagen de respaldo de datos bancarios ───────────────────────────────────────

    public function test_sube_imagen_bancaria_y_aparece_en_el_detalle(): void
    {
        $this->grantCompras('vendedor@atlantic.test', ['compras.edit']);
        $provider = $this->makeProvider();

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/compras/providers/{$provider->id}/bank-image", [
                'file' => UploadedFile::fake()->image('datos-bancarios.jpg'),
            ])
            ->assertStatus(200);

        $response->assertJsonPath('bank_image.filename', 'datos-bancarios.jpg');
        $this->assertNotNull($response->json('bank_image.url'));
    }

    /** RN9 — la imagen es independiente de los campos bank_* existentes, no los reemplaza. */
    public function test_subir_imagen_bancaria_no_modifica_los_campos_bank_existentes(): void
    {
        $this->grantCompras('vendedor@atlantic.test', ['compras.edit']);
        $provider = $this->makeProvider(['bank_name' => 'Banco Demo A', 'bank_account_number' => '123456']);

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/compras/providers/{$provider->id}/bank-image", [
                'file' => UploadedFile::fake()->image('datos.png'),
            ])
            ->assertStatus(200);

        $response->assertJsonPath('bank_name', 'Banco Demo A');
        $response->assertJsonPath('bank_account_number', '123456');
    }

    /** RN8 — subir una imagen nueva reemplaza la anterior, no la acumula. */
    public function test_subir_una_segunda_imagen_reemplaza_la_primera(): void
    {
        $this->grantCompras('vendedor@atlantic.test', ['compras.edit']);
        $provider = $this->makeProvider();
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/compras/providers/{$provider->id}/bank-image", ['file' => UploadedFile::fake()->image('primera.jpg')])
            ->assertStatus(200);

        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $firstKey = $provider->fresh()->bank_image_storage_key;

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/compras/providers/{$provider->id}/bank-image", ['file' => UploadedFile::fake()->image('segunda.png')])
            ->assertStatus(200);

        $response->assertJsonPath('bank_image.filename', 'segunda.png');
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $secondKey = $provider->fresh()->bank_image_storage_key;
        $this->assertNotEquals($firstKey, $secondKey);
        Storage::disk('s3-private')->assertMissing($firstKey);
        Storage::disk('s3-private')->assertExists($secondKey);
    }

    /** RN7 — eliminar sin reemplazar deja bank_image en null. */
    public function test_elimina_la_imagen_bancaria(): void
    {
        $this->grantCompras('vendedor@atlantic.test', ['compras.edit']);
        $provider = $this->makeProvider();
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/compras/providers/{$provider->id}/bank-image", ['file' => UploadedFile::fake()->image('foto.jpg')])
            ->assertStatus(200);

        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $key = $provider->fresh()->bank_image_storage_key;

        $response = $this->withHeaders($this->authHeaders($token))
            ->deleteJson("/api/compras/providers/{$provider->id}/bank-image")
            ->assertStatus(200);

        $response->assertJsonPath('bank_image', null);
        Storage::disk('s3-private')->assertMissing($key);
    }

    /** RN3 — solo formatos de imagen, un PDF no puede colarse como "respaldo bancario". */
    public function test_subir_un_pdf_como_imagen_bancaria_retorna_422(): void
    {
        $this->grantCompras('vendedor@atlantic.test', ['compras.edit']);
        $provider = $this->makeProvider();

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/compras/providers/{$provider->id}/bank-image", [
                'file' => UploadedFile::fake()->create('documento.pdf', 100, 'application/pdf'),
            ])
            ->assertStatus(422);
    }

    /** RN10 — mismo permiso que editar el proveedor (compras.edit), no compras.write ni compras.read solo. */
    public function test_subir_imagen_bancaria_sin_permiso_edit_retorna_403(): void
    {
        $this->grantCompras('vendedor@atlantic.test', ['compras.read', 'compras.write']);
        $provider = $this->makeProvider();

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/compras/providers/{$provider->id}/bank-image", [
                'file' => UploadedFile::fake()->image('foto.jpg'),
            ])
            ->assertStatus(403);
    }

    /** Escenario 7 del ticket — sin imagen, el detalle sigue devolviendo bank_image: null. */
    public function test_detalle_sin_imagen_bancaria_devuelve_null(): void
    {
        $this->grantCompras('vendedor@atlantic.test', ['compras.read']);
        $provider = $this->makeProvider();

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/compras/providers/{$provider->id}")
            ->assertStatus(200);

        $response->assertJsonPath('bank_image', null);
    }

    // ── SCRUM-820 — categoría "Latinoamérica" ───────────────────────────────────────────────────

    public function test_crear_proveedor_con_categoria_latinoamerica_es_valido(): void
    {
        $this->grantCompras('vendedor@atlantic.test', ['compras.write']);

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/compras/providers', [
                'name' => 'Proveedor LatAm', 'category' => 'latinoamerica', 'country' => 'Colombia',
                'phone' => '6000-0000',
            ])
            ->assertStatus(201);

        $response->assertJsonPath('category', 'latinoamerica');
        // REQ-141 (Provider::deriveOrigin) — solo "locales" es origen local, el resto internacional.
        $response->assertJsonPath('origin', 'internacional');
    }

    public function test_editar_categoria_a_latinoamerica_es_valido(): void
    {
        $this->grantCompras('vendedor@atlantic.test', ['compras.edit']);
        $provider = $this->makeProvider(['category' => 'china']);

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/compras/providers/{$provider->id}", ['name' => 'Proveedor Demo A', 'category' => 'latinoamerica'])
            ->assertStatus(200)
            ->assertJsonPath('category', 'latinoamerica');
    }
}
