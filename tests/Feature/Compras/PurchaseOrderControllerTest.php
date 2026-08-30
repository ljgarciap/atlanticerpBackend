<?php

declare(strict_types=1);

namespace Tests\Feature\Compras;

use App\Models\User;
use App\Modules\Compras\Models\ComprasSettings;
use App\Modules\Compras\Models\Provider;
use App\Modules\Compras\Models\PurchaseOrder;
use App\Modules\Compras\Models\PurchaseOrderPayment;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Modules\VentasDiseno\Models\MasterClient;
use App\Modules\VentasDiseno\Models\PipelineCard;
use App\Modules\VentasDiseno\Models\Quote;
use App\Modules\VentasDiseno\Models\SalesProject;
use App\Modules\VentasDiseno\Models\SubClient;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-191→196 (REQ-128→133) — Nueva Orden, sprint2 (rama sprint2, sin push a dev).
 */
class PurchaseOrderControllerTest extends FeatureTestCase
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

    private function makeCatalogProduct(int $providerId, array $overrides = []): CatalogProduct
    {
        return CatalogProduct::create([
            'reference' => 'REF-'.uniqid(), 'name' => 'Bombillo decorativo E27 Ámbar',
            'price_full' => 20, 'cost' => 12, 'provider_id' => $providerId, 'is_active' => true,
            ...$overrides,
        ]);
    }

    /**
     * REQ-133 RN dura: `documentStatus()` solo es 'approved' con confirmed_at no
     * nulo (SCRUM-723 — antes era folio) Y una PipelineCard en stage
     * proposal/approved (Quote::documentStatus()) — mismo patrón que
     * ApprovedProjectControllerTest::makeQuote().
     */
    private function makeApprovedSalesProject(string $projectName): SalesProject
    {
        $owner = User::where('email', 'designer@illuminations.test')->firstOrFail();
        $master = MasterClient::create(['name' => "Cliente {$projectName}"]);
        $sub = SubClient::create([
            'master_client_id' => $master->id, 'business_name' => "Cliente {$projectName} SA", 'tax_id' => uniqid(),
        ]);
        $salesProject = SalesProject::create(['sub_client_id' => $sub->id, 'name' => $projectName]);
        Quote::create([
            'sub_client_id' => $sub->id, 'sales_project_id' => $salesProject->id,
            'master_client_id' => $master->id, 'owner_id' => $owner->id,
            'folio' => 'COT-'.random_int(1000, 9999), 'confirmed_at' => now(),
        ]);
        PipelineCard::create([
            'sales_project_id' => $salesProject->id, 'stage' => 'approved',
            'sub_client_id' => $sub->id, 'owner_id' => $owner->id,
        ]);

        return $salesProject;
    }

    public function test_crear_orden_sin_autenticar_retorna_401(): void
    {
        $this->postJson('/api/compras/orders', [])->assertStatus(401);
    }

    public function test_crear_orden_con_producto_existente_sin_unit_cost_usa_el_costo_del_catalogo(): void
    {
        // REQ-130 Escenario 2 — sin unit_cost explícito (compatibilidad), se usa el costo real
        // del producto del catálogo.
        $this->grantCompras('designer@illuminations.test', ['compras.read', 'compras.write']);
        $provider = $this->makeProvider();
        $product = $this->makeCatalogProduct($provider->id, ['cost' => 12.50]);

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/compras/orders', [
                'provider_id' => $provider->id,
                'lines' => [
                    ['catalog_product_id' => $product->id, 'quantity' => 3],
                ],
            ])
            ->assertStatus(201);

        $response->assertJsonPath('lines.0.unit_cost', 12.5);
        $response->assertJsonPath('lines.0.subtotal', 37.5);
        $response->assertJsonPath('total_amount', 37.5);
        // SCRUM-204 (2026-08-06, hallazgo Daniela Amaya): sin shipping_type/who_pays_shipping no
        // requiere aprobación de Mark (RN3/REQ-118) — nace directo en "Pendiente/Por liquidar".
        $response->assertJsonPath('status', PurchaseOrder::STATUS_PENDIENTE_LIQUIDAR);
    }

    /**
     * SCRUM-193 (REQ-130 Escenario 2, hallazgo de Daniela Amaya 2026-07-30) — el costo precargado
     * del catálogo debe ser editable por línea (ej. precio renegociado para esta orden puntual);
     * antes el backend lo pisaba siempre con el costo real del producto, dejando el campo de la
     * UI editable pero sin efecto real.
     */
    public function test_crear_orden_con_producto_existente_respeta_unit_cost_editado(): void
    {
        $this->grantCompras('designer@illuminations.test', ['compras.read', 'compras.write']);
        $provider = $this->makeProvider();
        $product = $this->makeCatalogProduct($provider->id, ['cost' => 12.50]);

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/compras/orders', [
                'provider_id' => $provider->id,
                'lines' => [
                    ['catalog_product_id' => $product->id, 'quantity' => 3, 'unit_cost' => 10],
                ],
            ])
            ->assertStatus(201);

        $response->assertJsonPath('lines.0.unit_cost', 10);
        $response->assertJsonPath('lines.0.subtotal', 30);
        $response->assertJsonPath('total_amount', 30);
    }

    public function test_producto_de_otro_proveedor_no_puede_agregarse_a_la_orden(): void
    {
        // REQ-130 Escenario 1: implícito — un producto de OTRO proveedor no es válido acá.
        // La regla real vive en el buscador (CatalogProductController?provider_id=), pero el
        // wizard igual debe rechazar un catalog_product_id inexistente/inactivo.
        $this->grantCompras('designer@illuminations.test', ['compras.write']);
        $provider = $this->makeProvider();

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/compras/orders', [
                'provider_id' => $provider->id,
                'lines' => [['catalog_product_id' => 999999, 'quantity' => 1]],
            ])
            ->assertStatus(422);
    }

    public function test_crear_producto_nuevo_lo_agrega_al_catalogo_y_a_la_orden(): void
    {
        // REQ-131: creación simultánea en Inventario (catalog_products) y en la orden.
        $this->grantCompras('designer@illuminations.test', ['compras.write']);
        $provider = $this->makeProvider();

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/compras/orders', [
                'provider_id' => $provider->id,
                'lines' => [[
                    'new_product' => [
                        'reference' => 'NORDIC-40', 'factory_reference' => 'LMP-001',
                        'name' => 'Lámpara colgante Nordic 40cm', 'price_full' => 150, 'cost' => 100,
                    ],
                    'quantity' => 2,
                ]],
            ])
            ->assertStatus(201);

        $response->assertJsonPath('lines.0.reference', 'NORDIC-40');
        $response->assertJsonPath('lines.0.subtotal', 200);

        $slug = (string) Tenant::where('slug', 'illuminations')->value('slug');
        $this->assertDatabaseHas(
            "{$slug}_ventas_diseno.catalog_products",
            ['reference' => 'NORDIC-40', 'provider_id' => $provider->id],
        );
    }

    public function test_referencia_de_fabrica_repetida_mismo_proveedor_retorna_422(): void
    {
        // REQ-131 Escenario 1.
        $this->grantCompras('designer@illuminations.test', ['compras.write']);
        $provider = $this->makeProvider();
        $this->makeCatalogProduct($provider->id, ['factory_reference' => 'LMP-001']);

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/compras/orders', [
                'provider_id' => $provider->id,
                'lines' => [[
                    'new_product' => [
                        'reference' => 'OTRA-REF', 'factory_reference' => 'LMP-001',
                        'name' => 'Otro producto', 'price_full' => 50, 'cost' => 30,
                    ],
                    'quantity' => 1,
                ]],
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['lines.0.new_product.factory_reference']);
    }

    public function test_referencia_de_fabrica_repetida_proveedor_distinto_si_se_permite(): void
    {
        // REQ-131 Escenario 2.
        $this->grantCompras('designer@illuminations.test', ['compras.write']);
        $lightCorp = $this->makeProvider(['name' => 'LightCorp']);
        $iluminex = $this->makeProvider(['name' => 'Iluminex SA']);
        $this->makeCatalogProduct($lightCorp->id, ['factory_reference' => 'LMP-001']);

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/compras/orders', [
                'provider_id' => $iluminex->id,
                'lines' => [[
                    'new_product' => [
                        'reference' => 'OTRA-REF-2', 'factory_reference' => 'LMP-001',
                        'name' => 'Producto de Iluminex', 'price_full' => 50, 'cost' => 30,
                    ],
                    'quantity' => 1,
                ]],
            ])
            ->assertStatus(201);
    }

    public function test_referencia_publica_repetida_cualquier_proveedor_retorna_422(): void
    {
        // REQ-131 Escenario 3: sin excepción.
        $this->grantCompras('designer@illuminations.test', ['compras.write']);
        $provider = $this->makeProvider();
        $other = $this->makeProvider(['name' => 'Otro proveedor']);
        $this->makeCatalogProduct($other->id, ['reference' => 'NORDIC-40']);

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/compras/orders', [
                'provider_id' => $provider->id,
                'lines' => [[
                    'new_product' => [
                        'reference' => 'NORDIC-40', 'name' => 'Otro', 'price_full' => 50, 'cost' => 30,
                    ],
                    'quantity' => 1,
                ]],
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['lines.0.new_product.reference']);
    }

    /**
     * SCRUM-194 (REQ-131, hallazgo real de Daniela Amaya 2026-07-30) — 2 productos NUEVOS con la
     * misma referencia pública en el MISMO request: ninguno existe todavía en BD al momento de
     * validar, así que `uniquePublicReferenceRule()` (que solo consulta la BD) dejaba pasar ambos
     * y `createProduct()` terminaba creando 2 productos duplicados. Pre-QA (2026-07-17) solo había
     * probado la colisión contra un producto YA existente en BD — este escenario nunca se cubrió.
     */
    public function test_dos_productos_nuevos_con_misma_referencia_publica_en_la_misma_orden_retorna_422(): void
    {
        $this->grantCompras('designer@illuminations.test', ['compras.write']);
        $provider = $this->makeProvider();

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/compras/orders', [
                'provider_id' => $provider->id,
                'lines' => [
                    ['new_product' => [
                        'reference' => 'DUP-001', 'name' => 'Producto A', 'price_full' => 50, 'cost' => 30,
                    ], 'quantity' => 1],
                    ['new_product' => [
                        'reference' => 'DUP-001', 'name' => 'Producto B', 'price_full' => 60, 'cost' => 35,
                    ], 'quantity' => 1],
                ],
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['lines.1.new_product.reference']);

        $slug = (string) Tenant::where('slug', 'illuminations')->value('slug');
        $this->assertDatabaseCount("{$slug}_ventas_diseno.catalog_products", 0);
    }

    public function test_dos_productos_nuevos_con_misma_referencia_de_fabrica_en_la_misma_orden_retorna_422(): void
    {
        $this->grantCompras('designer@illuminations.test', ['compras.write']);
        $provider = $this->makeProvider();

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/compras/orders', [
                'provider_id' => $provider->id,
                'lines' => [
                    ['new_product' => [
                        'reference' => 'PUB-A', 'factory_reference' => 'FAB-001',
                        'name' => 'Producto A', 'price_full' => 50, 'cost' => 30,
                    ], 'quantity' => 1],
                    ['new_product' => [
                        'reference' => 'PUB-B', 'factory_reference' => 'FAB-001',
                        'name' => 'Producto B', 'price_full' => 60, 'cost' => 35,
                    ], 'quantity' => 1],
                ],
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['lines.1.new_product.factory_reference']);
    }

    /** SCRUM-194 (REQ-131, mockup 2H) — Categoría + Rotación esperada, ausentes hasta ahora. */
    public function test_crear_producto_nuevo_persiste_categoria_y_rotacion(): void
    {
        $this->grantCompras('designer@illuminations.test', ['compras.write']);
        $provider = $this->makeProvider();

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/compras/orders', [
                'provider_id' => $provider->id,
                'lines' => [[
                    'new_product' => [
                        'reference' => 'CAT-001', 'name' => 'Bombillo LED', 'price_full' => 20, 'cost' => 10,
                        'category' => 'bombillos', 'rotation' => 'unica',
                    ],
                    'quantity' => 1,
                ]],
            ])
            ->assertStatus(201);

        $slug = (string) Tenant::where('slug', 'illuminations')->value('slug');
        $this->assertDatabaseHas(
            "{$slug}_ventas_diseno.catalog_products",
            ['reference' => 'CAT-001', 'category' => 'bombillos', 'rotation' => 'unica'],
        );
    }

    public function test_crear_producto_nuevo_categoria_invalida_retorna_422(): void
    {
        $this->grantCompras('designer@illuminations.test', ['compras.write']);
        $provider = $this->makeProvider();

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/compras/orders', [
                'provider_id' => $provider->id,
                'lines' => [[
                    'new_product' => [
                        'reference' => 'CAT-002', 'name' => 'X', 'price_full' => 20, 'cost' => 10,
                        'category' => 'iluminacion',
                    ],
                    'quantity' => 1,
                ]],
            ])
            ->assertStatus(422);
    }

    /** SCRUM-194 (REQ-131, mockup 2H) — toggle $ monto / % del costo, sustituye el % único de antes. */
    public function test_costo_adicional_estimado_por_monto(): void
    {
        $this->grantCompras('designer@illuminations.test', ['compras.write']);
        $provider = $this->makeProvider();
        $product = $this->makeCatalogProduct($provider->id, ['cost' => 100]);

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/compras/orders', [
                'provider_id' => $provider->id,
                'lines' => [[
                    'catalog_product_id' => $product->id, 'quantity' => 1,
                    'additional_cost_amount' => 15, 'additional_cost_type' => 'monto',
                ]],
            ])
            ->assertStatus(201);

        $response->assertJsonPath('lines.0.additional_cost_amount', 15);
        $response->assertJsonPath('lines.0.additional_cost_type', 'monto');
        $response->assertJsonPath('lines.0.additional_cost_percent', null);
    }

    public function test_linea_sin_producto_ni_producto_nuevo_retorna_422(): void
    {
        $this->grantCompras('designer@illuminations.test', ['compras.write']);
        $provider = $this->makeProvider();

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/compras/orders', [
                'provider_id' => $provider->id,
                'lines' => [['quantity' => 1]],
            ])
            ->assertStatus(422);
    }

    public function test_envio_aereo_pagado_por_illuminations_requiere_aprobacion_de_mark(): void
    {
        // REQ-118 RN3 (hallazgo 5) / Escenario 1.
        $this->grantCompras('designer@illuminations.test', ['compras.write']);
        $provider = $this->makeProvider();
        $product = $this->makeCatalogProduct($provider->id);

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/compras/orders', [
                'provider_id' => $provider->id,
                'shipping_type' => 'aereo',
                'who_pays_shipping' => 'illuminations',
                'lines' => [['catalog_product_id' => $product->id, 'quantity' => 1]],
            ])
            ->assertStatus(201);

        $response->assertJsonPath('requires_mark_approval', true);
        $response->assertJsonPath('approved_by', null);
        // SCRUM-204 (2026-08-06, hallazgo Daniela Amaya): antes toda orden nacía en "Por
        // aprobar" sin importar requires_mark_approval — ahora solo las que sí lo requieren.
        $response->assertJsonPath('status', PurchaseOrder::STATUS_POR_APROBAR);
    }

    public function test_envio_maritimo_no_requiere_aprobacion(): void
    {
        $this->grantCompras('designer@illuminations.test', ['compras.write']);
        $provider = $this->makeProvider();
        $product = $this->makeCatalogProduct($provider->id);

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/compras/orders', [
                'provider_id' => $provider->id,
                'shipping_type' => 'maritimo',
                'who_pays_shipping' => 'illuminations',
                'lines' => [['catalog_product_id' => $product->id, 'quantity' => 1]],
            ])
            ->assertStatus(201);

        $response->assertJsonPath('requires_mark_approval', false);
        // SCRUM-204 (2026-08-06): no requiere aprobación -> nace directo en "Pendiente/Por liquidar".
        $response->assertJsonPath('status', PurchaseOrder::STATUS_PENDIENTE_LIQUIDAR);
    }

    /** SCRUM-197 (REQ-134, hallazgo de Daniela Amaya 2026-07-30): el mockup solo contempla Cliente/Illuminations. */
    public function test_envio_aereo_pagado_por_cliente_no_requiere_aprobacion(): void
    {
        $this->grantCompras('designer@illuminations.test', ['compras.write']);
        $provider = $this->makeProvider();
        $product = $this->makeCatalogProduct($provider->id);

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/compras/orders', [
                'provider_id' => $provider->id,
                'shipping_type' => 'aereo',
                'who_pays_shipping' => 'cliente',
                'lines' => [['catalog_product_id' => $product->id, 'quantity' => 1]],
            ])
            ->assertStatus(201);

        $response->assertJsonPath('requires_mark_approval', false);
        $response->assertJsonPath('status', PurchaseOrder::STATUS_PENDIENTE_LIQUIDAR);
    }

    public function test_who_pays_shipping_proveedor_ya_no_es_un_valor_valido(): void
    {
        $this->grantCompras('designer@illuminations.test', ['compras.write']);
        $provider = $this->makeProvider();
        $product = $this->makeCatalogProduct($provider->id);

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/compras/orders', [
                'provider_id' => $provider->id,
                'shipping_type' => 'aereo',
                'who_pays_shipping' => 'proveedor',
                'lines' => [['catalog_product_id' => $product->id, 'quantity' => 1]],
            ])
            ->assertStatus(422);
    }

    public function test_aprobar_orden_sin_permiso_approve_retorna_403(): void
    {
        $this->grantCompras('designer@illuminations.test', ['compras.write', 'compras.read']);
        $provider = $this->makeProvider();
        $order = PurchaseOrder::create([
            'provider_id' => $provider->id, 'currency' => 'USD', 'requires_mark_approval' => true,
        ]);

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/compras/orders/{$order->id}/approve")
            ->assertStatus(403);
    }

    public function test_aprobar_orden_que_no_requiere_aprobacion_retorna_422(): void
    {
        $this->grantCompras('designer@illuminations.test', ['compras.approve']);
        $provider = $this->makeProvider();
        $order = PurchaseOrder::create([
            'provider_id' => $provider->id, 'currency' => 'USD', 'requires_mark_approval' => false,
        ]);

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/compras/orders/{$order->id}/approve")
            ->assertStatus(422);
    }

    /**
     * `MarkApproverBackfillSeeder` (Pre-QA 2026-07-24) ya deja `mark_approver_user_id` seteado al
     * usuario real de Mark tras el seeding normal — este test seteaba a `designer` como aprobador
     * implícito confiando en que el valor global quedara NULL, lo cual enmascaraba el gate real
     * (SCRUM-206). Se autocontiene seteando `designer` como el Mark configurado, mismo patrón que
     * `test_aprobar_orden_siendo_el_mark_configurado_es_exitosa` de abajo.
     */
    public function test_aprobar_orden_exitosa(): void
    {
        $this->grantCompras('designer@illuminations.test', ['compras.approve']);
        $markId = (int) User::where('email', 'designer@illuminations.test')->value('id');
        ComprasSettings::current()->update(['mark_approver_user_id' => $markId]);

        $provider = $this->makeProvider();
        $order = PurchaseOrder::create([
            'provider_id' => $provider->id, 'currency' => 'USD', 'requires_mark_approval' => true,
        ]);

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/compras/orders/{$order->id}/approve")
            ->assertStatus(200);

        $response->assertJsonPath('approved_by', fn ($v) => $v !== null);
    }

    public function test_solo_pedidos_aprobados_pueden_asignarse_a_una_linea(): void
    {
        // REQ-133 Escenario 1.
        $this->grantCompras('designer@illuminations.test', ['compras.write']);
        $provider = $this->makeProvider();
        $product = $this->makeCatalogProduct($provider->id);

        $owner = User::where('email', 'designer@illuminations.test')->firstOrFail();
        $master = MasterClient::create(['name' => 'Cliente Test']);
        $sub = SubClient::create(['master_client_id' => $master->id, 'business_name' => 'Cliente Test SA', 'tax_id' => '1-1-1']);
        $salesProject = SalesProject::create(['sub_client_id' => $sub->id, 'name' => 'Proyecto Test']);
        Quote::create([
            'sub_client_id' => $sub->id, 'sales_project_id' => $salesProject->id,
            'master_client_id' => $master->id, 'owner_id' => $owner->id,
        ]);
        // Sin folio -> documentStatus() = 'draft', nunca 'approved'.

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/compras/orders', [
                'provider_id' => $provider->id,
                'lines' => [[
                    'catalog_product_id' => $product->id, 'quantity' => 1,
                    'sales_project_id' => $salesProject->id,
                ]],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['lines.0.sales_project_id']);
    }

    public function test_asignacion_de_proyecto_es_independiente_por_linea(): void
    {
        // REQ-133 Escenario 2.
        $this->grantCompras('designer@illuminations.test', ['compras.read', 'compras.write']);
        $provider = $this->makeProvider();
        $product1 = $this->makeCatalogProduct($provider->id, ['reference' => 'A1']);
        $product2 = $this->makeCatalogProduct($provider->id, ['reference' => 'A2']);

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/compras/orders', [
                'provider_id' => $provider->id,
                'lines' => [
                    ['catalog_product_id' => $product1->id, 'quantity' => 1],
                    ['catalog_product_id' => $product2->id, 'quantity' => 1],
                ],
            ])
            ->assertStatus(201);

        $response->assertJsonPath('lines.0.sales_project_id', null);
        $response->assertJsonPath('lines.1.sales_project_id', null);
    }

    public function test_index_pagina_default_20_ordenado_por_creacion_descendente(): void
    {
        // Política global de paginación (memory/feedback_pagination_sorting_policy.md).
        // SCRUM-754 (2026-08-18): default subió de 5 a 20 — 21 filas para ejercitar el corte real.
        $this->grantCompras('designer@illuminations.test', ['compras.read']);
        $provider = $this->makeProvider();
        foreach (range(1, 21) as $i) {
            PurchaseOrder::create(['provider_id' => $provider->id, 'currency' => 'USD']);
        }

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/orders')
            ->assertStatus(200);

        $this->assertCount(20, $response->json('data'));
        $response->assertJsonPath('meta.total', 21);
        $response->assertJsonPath('meta.per_page', 20);
    }

    public function test_index_orden_sin_proyecto_asignado_expone_resumen_vacio(): void
    {
        // REQ-140/146 (SCRUM-203/209): ninguna línea con sales_project_id -> resumen vacío,
        // sin ningún string hardcodeado tipo "Sin proyecto" del lado del backend.
        $this->grantCompras('designer@illuminations.test', ['compras.read', 'compras.write']);
        $provider = $this->makeProvider();
        $product = $this->makeCatalogProduct($provider->id);

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/compras/orders', [
                'provider_id' => $provider->id,
                'lines' => [['catalog_product_id' => $product->id, 'quantity' => 1]],
            ])
            ->assertStatus(201);

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/orders')
            ->assertStatus(200);

        $response->assertJsonPath('data.0.sales_project_summary', null);
        $response->assertJsonPath('data.0.has_multiple_projects', false);
        $response->assertJsonPath('data.0.sales_project_count', 0);
    }

    public function test_index_orden_con_un_proyecto_expone_su_nombre(): void
    {
        // REQ-140/146: todas las líneas de la orden comparten el mismo proyecto -> resumen = nombre.
        $this->grantCompras('designer@illuminations.test', ['compras.read', 'compras.write']);
        $provider = $this->makeProvider();
        $product1 = $this->makeCatalogProduct($provider->id, ['reference' => 'B1']);
        $product2 = $this->makeCatalogProduct($provider->id, ['reference' => 'B2']);
        $project = $this->makeApprovedSalesProject('Torre Norte');

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/compras/orders', [
                'provider_id' => $provider->id,
                'lines' => [
                    ['catalog_product_id' => $product1->id, 'quantity' => 1, 'sales_project_id' => $project->id],
                    ['catalog_product_id' => $product2->id, 'quantity' => 2, 'sales_project_id' => $project->id],
                ],
            ])
            ->assertStatus(201);

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/orders')
            ->assertStatus(200);

        $response->assertJsonPath('data.0.sales_project_summary', 'Torre Norte');
        $response->assertJsonPath('data.0.has_multiple_projects', false);
        $response->assertJsonPath('data.0.sales_project_count', 1);
    }

    public function test_index_orden_con_lineas_de_mas_de_un_proyecto_expone_bandera_de_multiples(): void
    {
        // REQ-140 Escenario 1 / REQ-146: 2+ proyectos distintos entre líneas -> resumen null,
        // has_multiple_projects=true — el frontend decide cómo mostrar "Ver detalle".
        $this->grantCompras('designer@illuminations.test', ['compras.read', 'compras.write']);
        $provider = $this->makeProvider();
        $product1 = $this->makeCatalogProduct($provider->id, ['reference' => 'C1']);
        $product2 = $this->makeCatalogProduct($provider->id, ['reference' => 'C2']);
        $projectA = $this->makeApprovedSalesProject('Torre Norte');
        $projectB = $this->makeApprovedSalesProject('Torre Sur');

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/compras/orders', [
                'provider_id' => $provider->id,
                'lines' => [
                    ['catalog_product_id' => $product1->id, 'quantity' => 1, 'sales_project_id' => $projectA->id],
                    ['catalog_product_id' => $product2->id, 'quantity' => 1, 'sales_project_id' => $projectB->id],
                ],
            ])
            ->assertStatus(201);

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/orders')
            ->assertStatus(200);

        $response->assertJsonPath('data.0.sales_project_summary', null);
        $response->assertJsonPath('data.0.has_multiple_projects', true);
        $response->assertJsonPath('data.0.sales_project_count', 2);
    }

    /**
     * SCRUM-206 (hallazgo QA 2026-07-16): cualquier usuario con `compras.approve` podía aprobar,
     * no solo Mark — usa `gerencia@illuminations.test` como "no-Mark" en vez de la cuenta real de
     * Mark (CoreUserSeeder, password no fijo en testing) para no depender de esa credencial.
     */
    public function test_aprobar_orden_no_siendo_el_mark_configurado_retorna_403(): void
    {
        $this->grantCompras('management@illuminations.test', ['compras.approve']);
        // `gerencia@illuminations.test` carga Nivel 10 (superadmin.all) — desde que superadmin
        // bypassa el gate de Mark (2026-08-22), usar `multi@illuminations.test` (Nivel 8) para
        // simular un management genuino, no-Mark y no-superadmin.
        $this->grantCompras('multi@illuminations.test', ['compras.approve']);

        $markId = (int) User::where('email', 'management@illuminations.test')->value('id');
        ComprasSettings::current()->update(['mark_approver_user_id' => $markId]);

        $provider = $this->makeProvider();
        $order = PurchaseOrder::create([
            'provider_id' => $provider->id, 'currency' => 'USD', 'requires_mark_approval' => true,
        ]);

        $token = $this->loginAs('multi@illuminations.test', 'Password123!', 'management');
        $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/compras/orders/{$order->id}/approve")
            ->assertStatus(403);

        Tenant::where('slug', 'illuminations')->first()?->makeCurrent();
        $this->assertNull(PurchaseOrder::find($order->id)?->approved_by);
    }

    /** superadmin.all bypassa el gate de Mark (decisión de Luis, 2026-08-22) — a diferencia de
     *  Gerencia genérica sin ser el Mark configurado (test de arriba, sigue en 403). */
    public function test_aprobar_orden_superadmin_bypassa_el_gate_de_mark(): void
    {
        $markId = (int) User::where('email', 'management@illuminations.test')->value('id');
        ComprasSettings::current()->update(['mark_approver_user_id' => $markId]);

        $provider = $this->makeProvider();
        $order = PurchaseOrder::create([
            'provider_id' => $provider->id, 'currency' => 'USD', 'requires_mark_approval' => true,
        ]);

        $token = $this->loginAs('superadmin@illuminations.test', 'Password123!', 'superadmin');
        $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/compras/orders/{$order->id}/approve")
            ->assertStatus(200);
    }

    public function test_aprobar_orden_siendo_el_mark_configurado_es_exitosa(): void
    {
        $this->grantCompras('management@illuminations.test', ['compras.approve']);
        $markId = (int) User::where('email', 'management@illuminations.test')->value('id');
        ComprasSettings::current()->update(['mark_approver_user_id' => $markId]);

        $provider = $this->makeProvider();
        $order = PurchaseOrder::create([
            'provider_id' => $provider->id, 'currency' => 'USD', 'requires_mark_approval' => true,
        ]);

        $token = $this->loginAs('management@illuminations.test', 'Password123!', 'management');
        $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/compras/orders/{$order->id}/approve")
            ->assertStatus(200)
            ->assertJsonPath('approved_by', $markId)
            ->assertJsonPath('approved_by_name', fn ($v) => $v !== null && $v !== '');
    }

    /** SCRUM-197 — la RN de REQ-134 pide capturar costo del envío aéreo, no solo quién lo paga. */
    public function test_crear_orden_persiste_costo_de_envio_aereo(): void
    {
        $this->grantCompras('designer@illuminations.test', ['compras.write']);
        $provider = $this->makeProvider();
        $product = CatalogProduct::create([
            'reference' => 'SHIP-1', 'name' => 'Producto', 'description' => 'Producto', 'cost' => 10, 'price_full' => 20,
            'provider_id' => $provider->id, 'is_active' => true,
        ]);

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/compras/orders', [
                'provider_id' => $provider->id,
                'shipping_type' => 'aereo',
                'who_pays_shipping' => 'illuminations',
                'shipping_cost' => 125.50,
                'lines' => [['catalog_product_id' => $product->id, 'quantity' => 1]],
            ])
            ->assertStatus(201);

        $response->assertJsonPath('shipping_cost', 125.5);
    }

    /**
     * REQ-140 (SCRUM-203) — hallazgo de reconciliación contra el mockup 2A/Excel de requerimientos:
     * "Tipo de envío" y "Costo de envío" ya vivían en el modelo (capturados en Nueva Orden/Logística)
     * pero no se exponían en index()/formatSummary(), la fuente real de la tabla principal.
     */
    public function test_index_expone_tipo_de_envio_y_costo_de_envio(): void
    {
        $this->grantCompras('designer@illuminations.test', ['compras.read', 'compras.write']);
        $provider = $this->makeProvider();
        $product = $this->makeCatalogProduct($provider->id);

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/compras/orders', [
                'provider_id' => $provider->id,
                'shipping_type' => 'aereo',
                'who_pays_shipping' => 'illuminations',
                'shipping_cost' => 200,
                'lines' => [['catalog_product_id' => $product->id, 'quantity' => 1]],
            ])
            ->assertStatus(201);

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/orders')
            ->assertStatus(200);

        $response->assertJsonPath('data.0.shipping_type', 'aereo');
        $response->assertJsonPath('data.0.shipping_cost', 200);
    }

    /**
     * REQ-140 (SCRUM-203) — "Responsable" y "Fecha de orden" de la tabla principal: ya se
     * calculaban en formatSummary() (`created_by_name`/`created_at`) para otras pantallas
     * (Logística), este test los fija explícitamente como columnas de Ver Órdenes también.
     */
    public function test_index_expone_responsable_y_fecha_de_orden(): void
    {
        $this->grantCompras('designer@illuminations.test', ['compras.read', 'compras.write']);
        $provider = $this->makeProvider();
        $product = $this->makeCatalogProduct($provider->id);

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/compras/orders', [
                'provider_id' => $provider->id,
                'lines' => [['catalog_product_id' => $product->id, 'quantity' => 1]],
            ])
            ->assertStatus(201);

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/orders')
            ->assertStatus(200);

        $response->assertJsonPath('data.0.created_by_name', fn ($v) => $v !== null && $v !== '');
        $response->assertJsonPath('data.0.created_at', fn ($v) => $v !== null && $v !== '');
    }

    /**
     * REQ-140 (SCRUM-203) — "Fecha de pago" no es una columna propia de la orden: se deriva del
     * pago más reciente en `purchase_order_payments`. Sin pagos registrados, expone null (el
     * frontend lo renderiza como "—", mismo criterio que el mockup 2A).
     */
    public function test_index_sin_pagos_expone_fecha_de_pago_null(): void
    {
        $this->grantCompras('designer@illuminations.test', ['compras.read']);
        $provider = $this->makeProvider();
        PurchaseOrder::create(['provider_id' => $provider->id, 'currency' => 'USD']);

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/orders')
            ->assertStatus(200);

        $response->assertJsonPath('data.0.last_payment_date', null);
    }

    /**
     * REQ-140 (SCRUM-203) — con 2 pagos registrados, "Fecha de pago" toma el más reciente (el
     * historial viene ordenado por antigüedad ascendente vía PurchaseOrder::payments()), no el
     * primero ni una suma/promedio de fechas.
     */
    public function test_index_con_pagos_expone_fecha_del_pago_mas_reciente(): void
    {
        $this->grantCompras('designer@illuminations.test', ['compras.read']);
        $provider = $this->makeProvider();
        $order = PurchaseOrder::create(['provider_id' => $provider->id, 'currency' => 'USD', 'total_amount' => 1000]);

        PurchaseOrderPayment::create([
            'purchase_order_id' => $order->id, 'amount' => 300,
            'payment_proof_storage_key' => 'compras/payments/proof-1.pdf',
        ])->forceFill(['created_at' => now()->subDays(5)])->save();

        PurchaseOrderPayment::create([
            'purchase_order_id' => $order->id, 'amount' => 700,
            'payment_proof_storage_key' => 'compras/payments/proof-2.pdf',
        ])->forceFill(['created_at' => now()->subDay()])->save();

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/orders')
            ->assertStatus(200);

        $response->assertJsonPath(
            'data.0.last_payment_date',
            now()->subDay()->toDateString(),
        );
    }
}
