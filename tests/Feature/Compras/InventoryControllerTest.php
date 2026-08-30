<?php

declare(strict_types=1);

namespace Tests\Feature\Compras;

use App\Modules\Bodega\Models\ProductWarehouseStock;
use App\Modules\Bodega\Models\Warehouse;
use App\Modules\Compras\Models\Provider;
use App\Modules\Compras\Models\PurchaseOrder;
use App\Modules\Compras\Models\PurchaseOrderLine;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Modules\VentasDiseno\Models\CatalogProductFamily;
use App\Modules\VentasDiseno\Models\SalesProject;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-231→244 (REQ-168→181) — Inventario. `designer@illuminations.test` (role_key
 * vendedor_disenador) naturalmente tiene ventas_diseno.read pero NO compras.read — se usa sin
 * grantCompras() para probar el modo restringido, y con grantCompras() para el modo completo.
 */
class InventoryControllerTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'illuminations')->first()?->makeCurrent();
        // SCRUM-426 (ficha técnica de archivo real) usa CatalogService::uploadTechnicalSheet(),
        // disco 's3-private' — sin fakear, CI revienta con "Argument #2 ($bucket) must be of type
        // string, null given" porque no hay bucket real configurado en el entorno de test. Mismo
        // patrón ya usado en CatalogControllerTest::setUp() para el mismo disco.
        Storage::fake('s3-private');
    }

    /** @param list<string> $perms */
    private function grantCompras(string $email, array $perms): void
    {
        $slug = (string) Tenant::where('slug', 'illuminations')->value('slug');
        DB::table("{$slug}_auth.users")
            ->where('email', $email)
            ->update(['extra_permissions' => json_encode($perms)]);
    }

    /** @param array<string, mixed> $overrides */
    private function makeProvider(array $overrides = []): Provider
    {
        return Provider::create(['name' => 'LightCorp', 'currency' => 'USD', ...$overrides]);
    }

    /** @param array<string, mixed> $overrides */
    private function makeProduct(array $overrides = []): CatalogProduct
    {
        return CatalogProduct::create([
            'reference' => 'REF-'.uniqid(), 'name' => 'Bombillo E27', 'description' => 'Bombillo E27',
            'price_full' => 20, 'cost' => 12, 'stock_quantity' => 10, 'is_active' => true,
            ...$overrides,
        ]);
    }

    /**
     * SCRUM-425/426 — payload de ficha técnica completo (los 12 campos de
     * CatalogProduct::TECHNICAL_SPEC_FIELDS), usado por los POST de creación que ya exigen
     * `technical_spec` completo (StoreInventoryProductRequest, REQ-356 RN1/RN2).
     *
     * @return array<string, string>
     */
    private function makeTechnicalSpec(): array
    {
        return [
            'voltage'            => '110V - 220V',
            'power'              => '12 W',
            'socket_type'        => 'E27',
            'color_temperature'  => '3000K (cálida)',
            'luminous_flux'      => '1050 lúmenes',
            'dimensions'         => '40 x 40 x 55 cm',
            'weight'             => '2.3 kg',
            'material_finish'    => 'Metal pintado, difusor en vidrio',
            'ip_rating'          => 'IP20 (interior)',
            'estimated_lifespan' => '25,000 horas',
            'warranty'           => '12 meses',
            'certifications'     => 'CE, RoHS',
        ];
    }

    /**
     * SCRUM-240 (corrección de Daniela Amaya 2026-08-09) — payload mínimo válido para
     * POST /api/compras/inventory tras la reescritura de StoreInventoryProductRequest: todos los
     * campos que antes eran opcionales (provider_id/factory_reference/brand/barcode/category/
     * rotation/reorder_point/other_cost) ahora son obligatorios. `technical_spec` YA NO se manda
     * acá (ver docblock de StoreInventoryProductRequest) — se sube aparte, como documento.
     *
     * @return array<string, mixed>
     */
    private function makeValidCreatePayload(int $providerId, array $overrides = []): array
    {
        return [
            'reference'         => 'REF-'.uniqid(),
            'factory_reference' => 'FAB-'.uniqid(),
            'name'              => 'Producto nuevo',
            'description'       => 'Producto nuevo',
            'brand'             => 'Marca X',
            'barcode'           => '7501234567890',
            'price_full'        => 30,
            'cost'              => 15,
            'other_cost'        => 2.5,
            'reorder_point'     => 5,
            'provider_id'       => $providerId,
            'category'          => 'bombillos',
            'rotation'          => 'media',
            // SCRUM-426 — ficha técnica (Caso A/B: archivo O link) vuelve a ser obligatoria.
            'link'              => 'https://proveedor.example.com/ficha.pdf',
            ...$overrides,
        ];
    }

    // ── SCRUM-231/232/233/234 — index, KPIs, filtros, Estado ─────────────────

    public function test_modo_ventas_no_expone_costo_ni_margen(): void
    {
        $this->makeProduct();
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/inventory')
            ->assertStatus(200);

        $response->assertJsonPath('restricted', true);
        $this->assertArrayNotHasKey('cost', $response->json('data.0'));
        $this->assertArrayNotHasKey('margin_percent', $response->json('data.0'));
    }

    /**
     * Hallazgo de Pre-QA 2026-07-19 (dev.atlanticerp.ai) — REQ-172 PERMISOS dice "Ver: todos en Compras
     * y Gerencia", a diferencia del resto de Inventario (visible también en modo restringido de
     * Ventas & Diseño). "Por ingresar"/"En camino" vivían en el bloque común y se filtraban.
     *
     * Ajustado 2026-08-15 (SCRUM-238, decisión explícita de Luis en el comentario del ticket):
     * el detalle de UN producto (`show()`) en modo restringido SÍ expone Por ingresar/En camino
     * desde ahora — el mockup de Daniela Amaya las pide como parte de las 9 métricas del modal, y
     * ninguna de las 9 es costo/margen (no choca con RN1). La regla original de arriba sigue
     * vigente SOLO para el LISTADO (`index()`, sin cambios) — ver
     * `InventoryController::format()`, parámetro `$includeRestrictedDetail`.
     */
    public function test_modo_ventas_expone_por_ingresar_y_en_camino_en_el_detalle(): void
    {
        $provider = $this->makeProvider();
        $product = $this->makeProduct(['stock_quantity' => 0]);
        $order = PurchaseOrder::create([
            'provider_id' => $provider->id, 'currency' => 'USD',
            'status' => PurchaseOrder::STATUS_ORDENADO, 'status_changed_at' => now(),
        ]);
        PurchaseOrderLine::create([
            'purchase_order_id' => $order->id, 'catalog_product_id' => $product->id,
            'quantity' => 10, 'unit_cost' => 10, 'subtotal' => 100,
        ]);
        $this->grantCompras('designer@illuminations.test', ['compras.read', 'compras.edit']);
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $headers = $this->authHeaders($token);
        $warehouseId = (int) DB::table('illuminations_bodega.warehouses')->value('id');
        $this->withHeaders($headers)->postJson('/api/compras/goods-receipts', [
            'purchase_order_id' => $order->id,
            'lines' => [['catalog_product_id' => $product->id, 'quantity' => 10, 'unit_cost' => 10, 'warehouse_id' => $warehouseId]],
        ])->assertStatus(201);

        // Confirmamos con el producto tiene 10 "Por ingresar" y 0 "En camino" en modo completo...
        $full = $this->withHeaders($headers)->getJson("/api/compras/inventory/{$product->id}")->assertStatus(200);
        $full->assertJsonPath('por_ingresar', 10);

        // ...y en modo restringido (Ventas & Diseño) el DETALLE de un producto también los expone
        // desde SCRUM-238 (ver docblock arriba) — a diferencia del listado, que sigue sin
        // mostrarlos.
        $this->grantCompras('designer@illuminations.test', []);
        $restrictedToken = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $restricted = $this->withHeaders($this->authHeaders($restrictedToken))
            ->getJson("/api/compras/inventory/{$product->id}")
            ->assertStatus(200);
        $restricted->assertJsonPath('por_ingresar', 10);
        $restricted->assertJsonPath('en_camino', 0);
        // El resto de datos financieros (costo/margen/proveedor de compra) sigue oculto.
        $this->assertArrayNotHasKey('cost', $restricted->json());
        $this->assertArrayNotHasKey('margin_percent', $restricted->json());
    }

    /**
     * Rebote REQ-427 (Daniela Amaya 2026-08-13) — el detalle de Compras ("Por ingresar > Detalle")
     * debe reflejar el estado real de la confirmación física de Bodega: pendiente antes, y
     * cantidad + usuario después de que Bodega confirme.
     */
    public function test_show_expone_bodega_confirmation_pendiente_y_luego_confirmada(): void
    {
        $provider = Provider::create(['name' => 'LightCorp', 'currency' => 'USD']);
        $product = $this->makeProduct(['stock_quantity' => 0]);
        $order = PurchaseOrder::create([
            'provider_id' => $provider->id, 'currency' => 'USD',
            'status' => PurchaseOrder::STATUS_ORDENADO, 'status_changed_at' => now(),
        ]);
        PurchaseOrderLine::create([
            'purchase_order_id' => $order->id, 'catalog_product_id' => $product->id,
            'quantity' => 5, 'unit_cost' => 10, 'subtotal' => 50,
        ]);
        $this->grantCompras('designer@illuminations.test', ['compras.read', 'compras.edit']);
        $comprasToken = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $comprasHeaders = $this->authHeaders($comprasToken);
        $warehouseId = (int) DB::table('illuminations_bodega.warehouses')->value('id');
        $this->withHeaders($comprasHeaders)->postJson('/api/compras/goods-receipts', [
            'purchase_order_id' => $order->id,
            'lines' => [['catalog_product_id' => $product->id, 'quantity' => 5, 'unit_cost' => 10, 'warehouse_id' => $warehouseId]],
        ])->assertStatus(201);

        $before = $this->withHeaders($comprasHeaders)->getJson("/api/compras/inventory/{$product->id}")->assertStatus(200);
        $before->assertJsonPath('bodega_confirmation.awaiting_compras', false);
        $before->assertJsonPath('bodega_confirmation.confirmed_quantity', 0);
        $before->assertJsonPath('bodega_confirmation.confirmed_by_name', null);

        // `almacen@illuminations.com.pa` (Esteban Cardenas) ya viene seeded por
        // BusinessRoleUserSeeder en entorno testing (mismo usuario que BodegaInventoryControllerTest),
        // pero el rol no trae bodega.write por defecto en el fixture — mismo patrón que
        // BodegaOrderTestHelpers::grantBodegaPermissions().
        DB::table('illuminations_auth.users')->where('email', 'almacen@illuminations.com.pa')
            ->update(['extra_permissions' => json_encode(['bodega.read', 'bodega.write'])]);
        $bodegaToken = $this->loginAs('almacen@illuminations.com.pa', 'almacen@illuminations.com.pa', 'lider_bodega');
        $this->withHeaders($this->authHeaders($bodegaToken))
            ->postJson("/api/bodega/inventory/{$product->id}/confirm-arrival", ['cantidad_entregada' => 5])
            ->assertStatus(200);

        $after = $this->withHeaders($comprasHeaders)->getJson("/api/compras/inventory/{$product->id}")->assertStatus(200);
        $after->assertJsonPath('bodega_confirmation.awaiting_compras', true);
        $after->assertJsonPath('bodega_confirmation.confirmed_quantity', 5);
        $after->assertJsonPath('bodega_confirmation.confirmed_by_name', 'Esteban Cardenas');
    }

    /**
     * SCRUM-741 — hallazgo real en producción: David/Mark (Gerencia, ventas_diseno.read
     * transversal) seguían con acceso funcional completo a Inventario pese a que Compras ya
     * estaba oculto por la máscara UAT para todos los no-superadmin. La rama restringida es el
     * único camino que un usuario sin compras.read tiene hacia estos datos, así que acá (a
     * diferencia del resto de la app, donde la máscara UAT es cosmética a propósito) también debe
     * cortar el acceso real — ver UatVisibilityService::isModuleHidden().
     */
    public function test_modo_ventas_pierde_acceso_cuando_compras_esta_oculto_por_uat(): void
    {
        $this->makeProduct();
        $superToken = $this->loginAs('superadmin@illuminations.test', 'Password123!');
        $this->withHeaders($this->authHeaders($superToken))
            ->postJson('/api/admin/module-visibility/bulk', ['modules' => ['compras'], 'menuItems' => [], 'action' => 'hide'])
            ->assertStatus(200);

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/inventory')
            ->assertStatus(403);

        // Restaurar deja pasar de nuevo — la máscara es reversible, no un cambio de permisos real.
        $this->withHeaders($this->authHeaders($superToken))
            ->postJson('/api/admin/module-visibility/bulk', ['modules' => ['compras'], 'menuItems' => [], 'action' => 'restore'])
            ->assertStatus(200);
        $restoredToken = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($restoredToken))
            ->getJson('/api/compras/inventory')
            ->assertStatus(200);
    }

    /**
     * SCRUM-741, revisado tras hallazgo real de Pre-QA (2026-08-09, con las cuentas reales del
     * ticket): David y Mark (Gerencia) tienen compras.read REAL vía su rol `management`
     * (role_module_visibility, "Gerencia ve todo, transversal") — no solo el fallback restringido
     * de ventas_diseno.read. La primera versión de este test (y del fix) asumía que compras.read
     * real debía conservar acceso siempre; Luis decidió lo contrario para esta etapa de rollout:
     * mientras Compras esté oculto por UAT, NADIE no-superadmin accede de verdad, ni siquiera con
     * el permiso real — es exactamente el camino que David/Mark usaban en producción.
     */
    public function test_modo_compras_pierde_acceso_cuando_compras_esta_oculto_por_uat(): void
    {
        $this->grantCompras('designer@illuminations.test', ['compras.read']);
        $this->makeProduct(['price_full' => 20, 'cost' => 10]);

        $superToken = $this->loginAs('superadmin@illuminations.test', 'Password123!');
        $this->withHeaders($this->authHeaders($superToken))
            ->postJson('/api/admin/module-visibility/bulk', ['modules' => ['compras'], 'menuItems' => [], 'action' => 'hide'])
            ->assertStatus(200);

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/inventory')
            ->assertStatus(403);

        // Restaurar deja pasar de nuevo — la máscara es reversible, no un cambio de permisos real.
        $this->withHeaders($this->authHeaders($superToken))
            ->postJson('/api/admin/module-visibility/bulk', ['modules' => ['compras'], 'menuItems' => [], 'action' => 'restore'])
            ->assertStatus(200);
        $restoredToken = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($restoredToken))
            ->getJson('/api/compras/inventory')
            ->assertStatus(200);
        $response->assertJsonPath('restricted', false);
        $this->assertArrayHasKey('cost', $response->json('data.0'));
    }

    /** SCRUM-741 — superadmin.all nunca se ve afectado por la máscara UAT, en ningún escenario. */
    public function test_superadmin_conserva_acceso_aunque_compras_este_oculto_por_uat(): void
    {
        $this->makeProduct(['price_full' => 20, 'cost' => 10]);

        $superToken = $this->loginAs('superadmin@illuminations.test', 'Password123!');
        $this->withHeaders($this->authHeaders($superToken))
            ->postJson('/api/admin/module-visibility/bulk', ['modules' => ['compras'], 'menuItems' => [], 'action' => 'hide'])
            ->assertStatus(200);

        $freshSuperToken = $this->loginAs('superadmin@illuminations.test', 'Password123!');
        $response = $this->withHeaders($this->authHeaders($freshSuperToken))
            ->getJson('/api/compras/inventory')
            ->assertStatus(200);
        $this->assertArrayHasKey('cost', $response->json('data.0'));
    }

    public function test_usuario_completo_puede_previsualizar_modo_ventas(): void
    {
        // REQ-168 Escenario 1: un usuario con acceso completo puede pasar ?mode=ventas para
        // previsualizar lo que ve Ventas & Diseño (oculta costo/margen), sin perder su acceso real.
        $this->grantCompras('designer@illuminations.test', ['compras.read']);
        $this->makeProduct(['price_full' => 20, 'cost' => 10]);
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/inventory?mode=ventas')
            ->assertStatus(200);

        $response->assertJsonPath('restricted', true);
        $this->assertArrayNotHasKey('cost', $response->json('data.0'));
    }

    public function test_modo_compras_expone_costo_y_margen_en_vivo(): void
    {
        $this->grantCompras('designer@illuminations.test', ['compras.read']);
        $this->makeProduct(['price_full' => 20, 'cost' => 10]);
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/inventory')
            ->assertStatus(200);

        $response->assertJsonPath('restricted', false);
        // json_encode colapsa un float sin decimales a entero — comparar contra lo que realmente
        // viaja por HTTP, no contra el tipo PHP interno.
        $response->assertJsonPath('data.0.cost', 10);
        $response->assertJsonPath('data.0.margin_percent', 50);
    }

    public function test_estado_bajo_stock_cuando_disponible_es_positivo_pero_bajo_el_minimo(): void
    {
        // REQ-171 Escenario 1: Stock Total 3, mínimo 10 -> "Bajo stock", no "Sin stock".
        $this->grantCompras('designer@illuminations.test', ['compras.read']);
        $this->makeProduct(['stock_quantity' => 3, 'reorder_point' => 10]);
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/inventory')
            ->assertStatus(200);

        $response->assertJsonPath('data.0.estado', 'bajo_stock');
    }

    public function test_disponible_descuenta_lo_reservado_por_proyecto(): void
    {
        // REQ-171 Escenario 2: 20 unidades totales, 8 con proyecto asignado -> Por servir 8, Disponible 12.
        $this->grantCompras('designer@illuminations.test', ['compras.read']);
        $provider = $this->makeProvider();
        $product = $this->makeProduct(['stock_quantity' => 20]);
        $salesProject = SalesProject::create(['name' => 'Proyecto Test']);
        $order = PurchaseOrder::create([
            'provider_id' => $provider->id, 'currency' => 'USD',
            'status' => PurchaseOrder::STATUS_ORDENADO, 'status_changed_at' => now(),
        ]);
        PurchaseOrderLine::create([
            'purchase_order_id' => $order->id, 'catalog_product_id' => $product->id,
            'quantity' => 8, 'unit_cost' => 12, 'subtotal' => 96, 'sales_project_id' => $salesProject->id,
        ]);

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/compras/inventory/{$product->id}")
            ->assertStatus(200);

        $response->assertJsonPath('por_servir', 8);
        $response->assertJsonPath('disponible', 12);
    }

    public function test_kpi_en_atencion_excluye_rotacion_baja_en_cero_unidades(): void
    {
        // REQ-169 Escenario 1: producto rotación "baja" en 0 unidades NO cuenta en "en atención",
        // aunque sí en "sin stock".
        $this->grantCompras('designer@illuminations.test', ['compras.read']);
        $this->makeProduct(['stock_quantity' => 0, 'rotation' => CatalogProduct::ROTATION_BAJA]);
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/inventory')
            ->assertStatus(200);

        $response->assertJsonPath('kpis.in_attention', 0);
        $response->assertJsonPath('kpis.out_of_stock', 1);
    }

    /**
     * REQ-169 — regresión explícita post SCRUM-416/420 (Pre-QA 2026-07-28): Bodega ganó su propio
     * criterio ALLOWLIST (`BodegaInventoryKpiService::isInAttentionBodega()`, excluye `rotation
     * === null`), pero Compras > Inventario sigue con el BLOCKLIST original de REQ-169
     * (`InventoryKpiService::isInAttention()`) — `rotation === null` ("sin clasificar") SÍ debe
     * seguir contando acá, comportamiento conservador ya en producción, nunca tocado por ese fix.
     */
    public function test_kpi_en_atencion_cuenta_rotacion_null_sin_clasificar(): void
    {
        $this->grantCompras('designer@illuminations.test', ['compras.read']);
        $this->makeProduct(['stock_quantity' => 0, 'rotation' => null]);
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/inventory')
            ->assertStatus(200);

        $response->assertJsonPath('kpis.in_attention', 1);
        $response->assertJsonPath('kpis.out_of_stock', 1);
    }

    public function test_chip_por_ingresar_oculto_por_defecto_de_todos(): void
    {
        // REQ-170 Escenario 1 — el chip "por_ingresar" es para un producto que ni siquiera
        // existe en Inventario todavía (alta automática vía ingreso, RN de REQ-166, no
        // implementada), no para uno existente con unidades "Por ingresar" mezcladas — ese caso
        // se ve en la columna de la fila, ver test_columna_por_ingresar_y_en_camino_* más abajo.
        $this->grantCompras('designer@illuminations.test', ['compras.read']);
        $this->makeProduct();
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $todos = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/inventory')->assertStatus(200);
        $this->assertCount(1, $todos->json('data'));

        $porIngresar = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/inventory?chip=por_ingresar')->assertStatus(200);
        $this->assertCount(0, $porIngresar->json('data'));
    }

    public function test_chip_inactivos_muestra_solo_inactivos(): void
    {
        $this->grantCompras('designer@illuminations.test', ['compras.read']);
        $this->makeProduct(['reference' => 'ACT-1', 'is_active' => true]);
        $this->makeProduct(['reference' => 'INACT-1', 'is_active' => false]);
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/inventory?chip=inactivos')
            ->assertStatus(200);

        $this->assertCount(1, $response->json('data'));
        $response->assertJsonPath('data.0.reference', 'INACT-1');
    }

    // ── SCRUM-237 — editar con margen, y unicidad (SCRUM-241) ─────────────────

    public function test_editar_costo_recalcula_margen(): void
    {
        $this->grantCompras('designer@illuminations.test', ['compras.edit', 'compras.read']);
        $product = $this->makeProduct(['price_full' => 100, 'cost' => 50]);
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/compras/inventory/{$product->id}", ['cost' => 25])
            ->assertStatus(200);

        $response->assertJsonPath('margin_percent', 75);
    }

    public function test_editar_desglose_de_costo_recalcula_costo_total_y_margen(): void
    {
        // SCRUM-237 — Costo Total = cost + import_cost + freight_cost + handling_cost + other_cost;
        // margin_percent debe calcularse sobre ese total, no solo sobre `cost`.
        $this->grantCompras('designer@illuminations.test', ['compras.edit', 'compras.read']);
        $product = $this->makeProduct(['price_full' => 100, 'cost' => 50]);
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/compras/inventory/{$product->id}", [
                'import_cost' => 5, 'freight_cost' => 3, 'handling_cost' => 1, 'other_cost' => 1,
            ])
            ->assertStatus(200);

        $response->assertJsonPath('cost_total', 60);
        $response->assertJsonPath('margin_percent', 40);
    }

    public function test_editar_familia_asigna_y_quita_via_family_id(): void
    {
        // SCRUM-237 — selector único de familia en el modal (N:M por debajo, ver
        // CatalogProduct::families()); no existía ningún endpoint para esto antes de este ticket.
        $this->grantCompras('designer@illuminations.test', ['compras.edit', 'compras.read']);
        $product = $this->makeProduct();
        $family = CatalogProductFamily::create(['name' => 'Kit Baño', 'is_active' => true]);
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/compras/inventory/{$product->id}", ['family_id' => $family->id])
            ->assertStatus(200);
        $response->assertJsonPath('family_id', $family->id);
        $response->assertJsonPath('family_name', 'Kit Baño');

        $response = $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/compras/inventory/{$product->id}", ['family_id' => null])
            ->assertStatus(200);
        $response->assertJsonPath('family_id', null);
    }

    public function test_editar_family_id_inexistente_retorna_422(): void
    {
        $this->grantCompras('designer@illuminations.test', ['compras.edit', 'compras.read']);
        $product = $this->makeProduct();
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/compras/inventory/{$product->id}", ['family_id' => 999999])
            ->assertStatus(422);
    }

    /**
     * SCRUM-237/240 — el selector de Familia del modal (edición/creación de producto) permite
     * crear una familia nueva escribiendo un nombre, en vez de solo elegir de las ya sembradas.
     */
    public function test_crear_familia_por_nombre_la_crea_y_queda_disponible(): void
    {
        // compras.write (crear la familia) + compras.edit/read (asignarla a un producto después)
        // otorgados de una — el JWT ya emitido no se actualiza solo si se re-otorgan permisos a
        // mitad del test sin volver a loguear.
        $this->grantCompras('designer@illuminations.test', ['compras.write', 'compras.edit', 'compras.read']);
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/compras/families', ['name' => 'Kit Sala Nueva'])
            ->assertStatus(201);

        $response->assertJsonPath('name', 'Kit Sala Nueva');
        $familyId = $response->json('id');

        Tenant::where('slug', 'illuminations')->first()?->makeCurrent();
        $this->assertNotNull(CatalogProductFamily::find($familyId));

        // Queda disponible de inmediato para asignarla a un producto (mismo endpoint que ya
        // usaba family_id).
        $product = $this->makeProduct();
        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/compras/inventory/{$product->id}", ['family_id' => $familyId])
            ->assertStatus(200)
            ->assertJsonPath('family_name', 'Kit Sala Nueva');
    }

    /** SCRUM-237/240 — find-or-create case-insensitive, nunca crea un duplicado. */
    public function test_crear_familia_por_nombre_ya_existente_reusa_la_misma(): void
    {
        $this->grantCompras('designer@illuminations.test', ['compras.write']);
        $existing = CatalogProductFamily::create(['name' => 'Kit Baño', 'is_active' => true]);
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/compras/families', ['name' => 'kit baño'])
            ->assertStatus(200);

        $response->assertJsonPath('id', $existing->id);
        Tenant::where('slug', 'illuminations')->first()?->makeCurrent();
        $this->assertSame(1, CatalogProductFamily::where('is_active', true)->count());
    }

    public function test_crear_familia_sin_nombre_retorna_422(): void
    {
        $this->grantCompras('designer@illuminations.test', ['compras.write']);
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/compras/families', ['name' => ''])
            ->assertStatus(422);
    }

    public function test_crear_familia_sin_permiso_de_compras_retorna_403(): void
    {
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/compras/families', ['name' => 'Kit Cualquiera'])
            ->assertStatus(403);
    }

    public function test_crear_producto_con_referencia_duplicada_retorna_422(): void
    {
        // Hallazgo de QA (2026-07-18): el mensaje debe indicar a qué producto pertenece la
        // referencia (mismo texto que Nueva Orden), no el genérico "El reference ya esta en uso."
        $this->grantCompras('designer@illuminations.test', ['compras.write', 'compras.edit', 'compras.read']);
        $this->makeProduct(['reference' => 'DUP-1', 'name' => 'Producto original']);
        $provider = $this->makeProvider();
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/compras/inventory', $this->makeValidCreatePayload($provider->id, [
                'reference' => 'DUP-1', 'name' => 'Otro',
            ]))
            ->assertStatus(422);

        $this->assertSame(
            "La referencia pública 'DUP-1' ya pertenece al producto 'Producto original'.",
            $response->json('errors.reference.0'),
        );
    }

    /**
     * SCRUM-240 (corrección de Daniela Amaya) — provider_id/factory_reference/brand/barcode/
     * category/rotation/reorder_point/other_cost pasan de opcionales a obligatorios.
     */
    public function test_crear_producto_sin_los_campos_ahora_obligatorios_retorna_422(): void
    {
        $this->grantCompras('designer@illuminations.test', ['compras.write', 'compras.edit', 'compras.read']);
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/compras/inventory', [
                'reference' => 'MISSING-1', 'description' => 'Producto incompleto',
                'price_full' => 30, 'cost' => 15,
            ])
            ->assertStatus(422);

        $response->assertJsonValidationErrors([
            'provider_id', 'factory_reference', 'brand', 'barcode', 'category', 'rotation',
            'reorder_point', 'other_cost',
        ]);
    }

    /** SCRUM-240 — categoría debe ser una de las 8 fijas, consistente con el resto de Inventario. */
    public function test_crear_producto_con_categoria_invalida_retorna_422(): void
    {
        $this->grantCompras('designer@illuminations.test', ['compras.write', 'compras.edit', 'compras.read']);
        $provider = $this->makeProvider();
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/compras/inventory', $this->makeValidCreatePayload($provider->id, [
                'category' => 'categoria_inventada',
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['category']);
    }

    /** SCRUM-240 — familia opcional: si se manda, sincroniza la relación N:M (mismo criterio que update()). */
    public function test_crear_producto_con_family_id_sincroniza_la_familia(): void
    {
        $this->grantCompras('designer@illuminations.test', ['compras.write', 'compras.edit', 'compras.read']);
        $provider = $this->makeProvider();
        $family = CatalogProductFamily::create(['name' => 'Kit Baño Familia Pre-QA']);
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/compras/inventory', $this->makeValidCreatePayload($provider->id, [
                'family_id' => $family->id,
            ]))
            ->assertStatus(201);

        $response->assertJsonPath('family_id', $family->id);
        $response->assertJsonPath('family_name', 'Kit Baño Familia Pre-QA');
    }

    /** SCRUM-240 — "Costo adicional estimado" (ya resuelto a $ por el frontend) se persiste en other_cost. */
    public function test_crear_producto_persiste_other_cost(): void
    {
        $this->grantCompras('designer@illuminations.test', ['compras.write', 'compras.edit', 'compras.read']);
        $provider = $this->makeProvider();
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/compras/inventory', $this->makeValidCreatePayload($provider->id, [
                'other_cost' => 7.5,
            ]))
            ->assertStatus(201);

        $response->assertJsonPath('other_cost', 7.5);
        $response->assertJsonPath('cost_total', 15 + 7.5);
    }

    /**
     * SCRUM-240 — "Stock inicial (opcional)" ahora es una entrada por bodega, no una sola; el
     * total de la columna `stock_quantity` es la suma, y cada bodega con cantidad > 0 crea su
     * propia fila de ProductWarehouseStock.
     */
    public function test_crear_producto_con_stock_inicial_en_varias_bodegas(): void
    {
        $this->grantCompras('designer@illuminations.test', ['compras.write', 'compras.edit', 'compras.read']);
        $provider = $this->makeProvider();
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $warehouses = DB::table('illuminations_bodega.warehouses')->pluck('id', 'name');
        $centralId = (int) $warehouses['Bodega Central'];
        $mermaId = (int) $warehouses['Merma'];

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/compras/inventory', $this->makeValidCreatePayload($provider->id, [
                'initial_stock' => [
                    ['warehouse_id' => $centralId, 'quantity' => 12],
                    ['warehouse_id' => $mermaId, 'quantity' => 3],
                ],
            ]))
            ->assertStatus(201);

        $response->assertJsonPath('stock_quantity', 15);
        $response->assertJsonPath('disponible', 15);
        $response->assertJsonPath('por_ingresar', 0);

        $breakdown = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/compras/inventory/{$response->json('id')}/warehouse-stock")
            ->assertStatus(200)
            ->json('data');
        $byWarehouse = collect($breakdown)->keyBy('warehouse_name');
        $this->assertSame(12, $byWarehouse['Bodega Central']['quantity']);
        $this->assertSame(3, $byWarehouse['Merma']['quantity']);
    }

    /** SCRUM-240 — sin "Stock inicial" (sección completa omitida), el producto nace sin stock, sin error. */
    public function test_crear_producto_sin_stock_inicial_queda_en_cero(): void
    {
        $this->grantCompras('designer@illuminations.test', ['compras.write', 'compras.edit', 'compras.read']);
        $provider = $this->makeProvider();
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/compras/inventory', $this->makeValidCreatePayload($provider->id))
            ->assertStatus(201);

        $response->assertJsonPath('stock_quantity', 0);
        $response->assertJsonPath('disponible', 0);
    }

    public function test_editar_factory_reference_sin_reenviar_provider_id_respeta_scope_del_proveedor_real(): void
    {
        // Regresión Senior Review 20260718 — sin fallback al provider_id ya persistido, la
        // validación de unicidad de factory_reference scopeaba a "provider_id IS NULL" cuando el
        // payload no reenviaba provider_id (caso común: editar solo factory_reference), dejando
        // colar un duplicado dentro del MISMO proveedor real.
        $this->grantCompras('designer@illuminations.test', ['compras.edit', 'compras.read']);
        $provider = $this->makeProvider();
        $this->makeProduct(['provider_id' => $provider->id, 'factory_reference' => 'FX-1']);
        $target = $this->makeProduct(['provider_id' => $provider->id, 'factory_reference' => 'FX-2']);
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/compras/inventory/{$target->id}", ['factory_reference' => 'FX-1'])
            ->assertStatus(422);
    }

    // ── SCRUM-239 — activar/inactivar sin pérdida de datos ────────────────────

    public function test_reactivar_producto_conserva_stock_y_referencias(): void
    {
        $this->grantCompras('designer@illuminations.test', ['compras.edit', 'compras.read']);
        $product = $this->makeProduct(['reference' => 'REACT-1', 'stock_quantity' => 15, 'is_active' => true]);
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/compras/inventory/{$product->id}/toggle-active")
            ->assertStatus(200)
            ->assertJsonPath('is_active', false);

        $response = $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/compras/inventory/{$product->id}/toggle-active")
            ->assertStatus(200);

        $response->assertJsonPath('is_active', true);
        $response->assertJsonPath('reference', 'REACT-1');
        $response->assertJsonPath('stock_quantity', 15);
    }

    /**
     * REQ-173 RN — no se puede inactivar un producto mientras tiene unidades "Por ingresar" sin
     * confirmar (evitaría poder confirmarlas después, porque el producto dejaría de aparecer en
     * las vistas activas de Inventario).
     */
    public function test_no_permite_inactivar_producto_con_unidades_por_ingresar(): void
    {
        $this->grantCompras('designer@illuminations.test', ['compras.read', 'compras.edit']);
        $provider = $this->makeProvider();
        $product = $this->makeProduct(['stock_quantity' => 0]);
        $order = PurchaseOrder::create([
            'provider_id' => $provider->id, 'currency' => 'USD',
            'status' => PurchaseOrder::STATUS_ORDENADO, 'status_changed_at' => now(),
        ]);
        PurchaseOrderLine::create([
            'purchase_order_id' => $order->id, 'catalog_product_id' => $product->id,
            'quantity' => 10, 'unit_cost' => 10, 'subtotal' => 100,
        ]);
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $headers = $this->authHeaders($token);
        $warehouseId = (int) DB::table('illuminations_bodega.warehouses')->value('id');

        $receipt = $this->withHeaders($headers)->postJson('/api/compras/goods-receipts', [
            'purchase_order_id' => $order->id,
            'lines' => [['catalog_product_id' => $product->id, 'quantity' => 10, 'unit_cost' => 10, 'warehouse_id' => $warehouseId]],
        ])->assertStatus(201)->json();

        $this->withHeaders($headers)->patchJson("/api/compras/inventory/{$product->id}/toggle-active")
            ->assertStatus(422);

        $this->withHeaders($headers)->postJson("/api/compras/goods-receipts/lines/{$receipt['lines'][0]['id']}/confirm")
            ->assertStatus(200);

        $this->withHeaders($headers)->patchJson("/api/compras/inventory/{$product->id}/toggle-active")
            ->assertStatus(200)
            ->assertJsonPath('is_active', false);
    }

    // ── SCRUM-240 — crear con stock inicial, sin pasar por "Por ingresar" ─────

    public function test_crear_producto_con_stock_inicial_queda_disponible_de_inmediato(): void
    {
        $this->grantCompras('designer@illuminations.test', ['compras.write', 'compras.edit', 'compras.read']);
        $provider = $this->makeProvider();
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $centralId = (int) DB::table('illuminations_bodega.warehouses')->where('name', 'Bodega Central')->value('id');

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/compras/inventory', $this->makeValidCreatePayload($provider->id, [
                'reference' => 'NEW-1', 'description' => 'Producto nuevo',
                'initial_stock' => [['warehouse_id' => $centralId, 'quantity' => 20]],
            ]))
            ->assertStatus(201);

        $response->assertJsonPath('disponible', 20);
        $response->assertJsonPath('por_ingresar', 0);

        $breakdown = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/compras/inventory/{$response->json('id')}/warehouse-stock")
            ->assertStatus(200);
        $breakdown->assertJsonPath('data.0.warehouse_name', 'Bodega Central');
        $breakdown->assertJsonPath('data.0.quantity', 20);
    }

    // ── SCRUM-242 — aviso de mercancía en camino ───────────────────────────────

    public function test_order_prefill_avisa_mercancia_en_camino(): void
    {
        $this->grantCompras('designer@illuminations.test', ['compras.read']);
        $provider = $this->makeProvider();
        $product = $this->makeProduct(['stock_quantity' => 5, 'reorder_point' => 20]);
        $order = PurchaseOrder::create([
            'provider_id' => $provider->id, 'currency' => 'USD',
            'status' => PurchaseOrder::STATUS_EN_TRANSITO, 'status_changed_at' => now(),
        ]);
        PurchaseOrderLine::create([
            'purchase_order_id' => $order->id, 'catalog_product_id' => $product->id,
            'quantity' => 50, 'unit_cost' => 12, 'subtotal' => 600,
        ]);
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/compras/inventory/{$product->id}/order-prefill")
            ->assertStatus(200);

        $response->assertJsonPath('has_pending_shipment', true);
        $response->assertJsonPath('pending_quantity', 50);
        $response->assertJsonPath('suggested_quantity', 15); // reorder_point(20) - stock(5)
    }

    // ── SCRUM-235/236 (REQ-172) — columnas "Por ingresar" y "En camino" ───────

    public function test_columna_por_ingresar_y_en_camino_antes_y_despues_de_confirmar(): void
    {
        // REQ-172 AC — mismos números del ticket: la orden espera 120, se registra un ingreso de
        // 10 sin confirmar (Escenario 1), después se confirma (Escenario 2).
        $this->grantCompras('designer@illuminations.test', ['compras.read', 'compras.edit']);
        $provider = $this->makeProvider();
        $product = $this->makeProduct(['stock_quantity' => 0, 'reorder_point' => 5]);
        $order = PurchaseOrder::create([
            'provider_id' => $provider->id, 'currency' => 'USD',
            'status' => PurchaseOrder::STATUS_EN_TRANSITO, 'status_changed_at' => now(),
        ]);
        PurchaseOrderLine::create([
            'purchase_order_id' => $order->id, 'catalog_product_id' => $product->id,
            'quantity' => 120, 'unit_cost' => 10, 'subtotal' => 1200,
        ]);
        $warehouseId = (int) DB::table('illuminations_bodega.warehouses')->value('id');
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $headers = $this->authHeaders($token);

        $baseline = $this->withHeaders($headers)->getJson("/api/compras/inventory/{$product->id}")->assertStatus(200);
        $baseline->assertJsonPath('disponible', 0);
        $baseline->assertJsonPath('por_ingresar', 0);
        $baseline->assertJsonPath('en_camino', 120);

        $receipt = $this->withHeaders($headers)->postJson('/api/compras/goods-receipts', [
            'purchase_order_id' => $order->id,
            'pending_remainder_status' => PurchaseOrder::REMAINDER_EN_TRANSITO,
            'lines' => [['catalog_product_id' => $product->id, 'quantity' => 10, 'unit_cost' => 10, 'warehouse_id' => $warehouseId]],
        ])->assertStatus(201);
        $lineId = $receipt->json('lines.0.id');

        // Escenario 1 — antes de confirmar: Disponible no cambia, Por ingresar=10, En camino=110.
        $before = $this->withHeaders($headers)->getJson("/api/compras/inventory/{$product->id}")->assertStatus(200);
        $before->assertJsonPath('disponible', 0);
        $before->assertJsonPath('por_ingresar', 10);
        $before->assertJsonPath('en_camino', 110);

        $this->withHeaders($headers)->postJson("/api/compras/goods-receipts/lines/{$lineId}/confirm")->assertStatus(200);

        // Escenario 2 — después de confirmar: Disponible sube en 10, Por ingresar vacío, En
        // camino se mantiene en 110.
        $after = $this->withHeaders($headers)->getJson("/api/compras/inventory/{$product->id}")->assertStatus(200);
        $after->assertJsonPath('disponible', 10);
        $after->assertJsonPath('por_ingresar', 0);
        $after->assertJsonPath('en_camino', 110);
    }

    public function test_chip_por_ingresar_lista_producto_existente_con_unidades_pendientes(): void
    {
        // REQ-172/235 — fix Pre-QA 2026-07-20, rechazado por marly: el chip devolvía [] siempre,
        // aunque un producto EXISTENTE tuviera unidades "Por ingresar" reales. A diferencia de
        // test_chip_por_ingresar_oculto_por_defecto_de_todos (0 unidades pendientes, chip vacío
        // es lo correcto), acá el producto SÍ tiene unidades pendientes y debe listarse.
        $this->grantCompras('designer@illuminations.test', ['compras.read', 'compras.edit']);
        $provider = $this->makeProvider();
        $product = $this->makeProduct(['reference' => 'PEND-1', 'stock_quantity' => 0]);
        $order = PurchaseOrder::create([
            'provider_id' => $provider->id, 'currency' => 'USD',
            'status' => PurchaseOrder::STATUS_EN_TRANSITO, 'status_changed_at' => now(),
        ]);
        PurchaseOrderLine::create([
            'purchase_order_id' => $order->id, 'catalog_product_id' => $product->id,
            'quantity' => 10, 'unit_cost' => 10, 'subtotal' => 100,
        ]);
        $warehouseId = (int) DB::table('illuminations_bodega.warehouses')->value('id');
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $headers = $this->authHeaders($token);

        $receipt = $this->withHeaders($headers)->postJson('/api/compras/goods-receipts', [
            'purchase_order_id' => $order->id,
            'pending_remainder_status' => PurchaseOrder::REMAINDER_EN_TRANSITO,
            'lines' => [['catalog_product_id' => $product->id, 'quantity' => 10, 'unit_cost' => 10, 'warehouse_id' => $warehouseId]],
        ])->assertStatus(201);
        $lineId = $receipt->json('lines.0.id');

        $porIngresar = $this->withHeaders($headers)
            ->getJson('/api/compras/inventory?chip=por_ingresar')->assertStatus(200);
        $this->assertCount(1, $porIngresar->json('data'));
        $porIngresar->assertJsonPath('data.0.reference', 'PEND-1');
        $porIngresar->assertJsonPath('data.0.por_ingresar', 10);

        $this->withHeaders($headers)->postJson("/api/compras/goods-receipts/lines/{$lineId}/confirm")->assertStatus(200);

        // Confirmado: ya no debe listarse en el chip (vuelve al caso "sin pendientes").
        $afterConfirm = $this->withHeaders($headers)
            ->getJson('/api/compras/inventory?chip=por_ingresar')->assertStatus(200);
        $this->assertCount(0, $afterConfirm->json('data'));
    }

    // ── SCRUM-764 — listado de familias, propio de Compras (no el 403 de ventas_diseno.read) ──

    public function test_listado_de_familias_accesible_con_solo_compras_read(): void
    {
        // Repro real del hallazgo: un rol de Compras puro (compras.read, SIN ventas_diseno.read,
        // el caso real de lider_compras) debe poder listar familias — antes de este fix el
        // frontend llamaba al endpoint de VentasDiseno y devolvía 403 para este mismo perfil.
        $this->grantCompras('designer@illuminations.test', ['compras.read']);
        $product = $this->makeProduct(['reference' => 'FAM-A']);
        $family = CatalogProductFamily::create(['name' => 'Kit Baño', 'is_active' => true]);
        $family->products()->attach([$product->id]);

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/families')
            ->assertStatus(200);

        $response->assertJsonPath('data.0.name', 'Kit Baño');
        $response->assertJsonPath('data.0.product_count', 1);
    }

    /**
     * Lote 4 (SCRUM-243, 2026-08-17) — `total_value` y `restricted` (a nivel de respuesta, no solo
     * por familia) agregados al listado: el mockup pide mostrar el valor total en cada card
     * COLAPSADA, sin necesidad de expandir/pedir el detalle.
     */
    public function test_listado_de_familias_expone_total_value_por_familia_en_modo_compras(): void
    {
        $this->grantCompras('designer@illuminations.test', ['compras.read']);
        $product = $this->makeProduct(['reference' => 'FAM-A', 'cost' => 10, 'stock_quantity' => 3]);
        $family = CatalogProductFamily::create(['name' => 'Kit Baño', 'is_active' => true]);
        $family->products()->attach([$product->id]);

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/families')
            ->assertStatus(200);

        $response->assertJsonPath('restricted', false);
        $response->assertJsonPath('data.0.total_value', 30);
    }

    public function test_listado_de_familias_accesible_en_modo_restringido_ventas_diseno(): void
    {
        // Sin compras.read (rol de Ventas & Diseño) — mismo `resolveAccess()` que familyDetail(),
        // debe seguir entrando (restricted=true a nivel de detalle, acá el listado no expone costo).
        $product = $this->makeProduct(['reference' => 'FAM-A', 'cost' => 10, 'stock_quantity' => 3]);
        $family = CatalogProductFamily::create(['name' => 'Kit Baño', 'is_active' => true]);
        $family->products()->attach([$product->id]);

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/families')
            ->assertStatus(200)
            ->assertJsonPath('data.0.name', 'Kit Baño')
            ->assertJsonPath('restricted', true)
            ->assertJsonPath('data.0.total_value', null);
    }

    public function test_listado_de_familias_excluye_inactivas_y_cuenta_solo_productos_activos(): void
    {
        $this->grantCompras('designer@illuminations.test', ['compras.read']);
        $active = $this->makeProduct(['reference' => 'FAM-ACTIVE']);
        $inactive = $this->makeProduct(['reference' => 'FAM-INACTIVE', 'is_active' => false]);
        $family = CatalogProductFamily::create(['name' => 'Kit Baño', 'is_active' => true]);
        $family->products()->attach([$active->id, $inactive->id]);
        CatalogProductFamily::create(['name' => 'Familia Inactiva', 'is_active' => false]);

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/families')
            ->assertStatus(200);

        $names = collect($response->json('data'))->pluck('name');
        $this->assertEqualsCanonicalizing(['Kit Baño'], $names->all());
        $response->assertJsonPath('data.0.product_count', 1);
    }

    // ── SCRUM-243 — detalle de familia con costo/valor total (endpoint propio, cost-aware) ──

    public function test_detalle_de_familia_expone_costo_y_valor_total_en_modo_compras(): void
    {
        $this->grantCompras('designer@illuminations.test', ['compras.read']);
        $productA = $this->makeProduct(['reference' => 'FAM-A', 'cost' => 10, 'stock_quantity' => 3]);
        $productB = $this->makeProduct(['reference' => 'FAM-B', 'cost' => 20, 'stock_quantity' => 2]);
        $family = CatalogProductFamily::create(['name' => 'Kit Baño', 'is_active' => true]);
        $family->products()->attach([$productA->id, $productB->id]);

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/compras/families/{$family->id}")
            ->assertStatus(200);

        $response->assertJsonPath('restricted', false);
        // (10*3) + (20*2) = 70
        $response->assertJsonPath('total_value', 70);
        $costs = collect($response->json('products'))->pluck('cost');
        $this->assertEqualsCanonicalizing([10, 20], $costs->all());
    }

    public function test_detalle_de_familia_oculta_costo_en_modo_ventas(): void
    {
        $productA = $this->makeProduct(['reference' => 'FAM-A', 'cost' => 10, 'stock_quantity' => 3]);
        $family = CatalogProductFamily::create(['name' => 'Kit Baño', 'is_active' => true]);
        $family->products()->attach([$productA->id]);

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/compras/families/{$family->id}")
            ->assertStatus(200);

        $response->assertJsonPath('restricted', true);
        $response->assertJsonPath('total_value', null);
        $response->assertJsonPath('products.0.cost', null);
    }

    /**
     * Lote 4 (SCRUM-243, 2026-08-17) — el mockup pide que el detalle expandido de una familia
     * muestre la MISMA tabla completa de 21 columnas que "Productos" (`index()`/`format()`), no un
     * resumen aparte con 3-4 campos.
     */
    public function test_detalle_de_familia_expone_las_mismas_columnas_que_la_tabla_de_productos(): void
    {
        $this->grantCompras('designer@illuminations.test', ['compras.read']);
        $provider = $this->makeProvider(['name' => 'LightCorp']);
        $warehouse = Warehouse::firstOrCreate(['name' => 'Bodega Central']);
        $product = $this->makeProduct([
            'reference' => 'FAM-COLUMNS', 'category' => 'Iluminación LED Básica', 'rotation' => 'alta',
            'reorder_point' => 5, 'provider_id' => $provider->id, 'stock_quantity' => 14,
        ]);
        ProductWarehouseStock::create(['catalog_product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'quantity' => 14]);
        $family = CatalogProductFamily::create(['name' => 'Kit Baño', 'is_active' => true]);
        $family->products()->attach([$product->id]);

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/compras/families/{$family->id}")
            ->assertStatus(200);

        $response->assertJsonPath('products.0.category', 'Iluminación LED Básica');
        $response->assertJsonPath('products.0.rotation', 'alta');
        $response->assertJsonPath('products.0.provider_name', 'LightCorp');
        $response->assertJsonPath('products.0.disponible', 14);
        $response->assertJsonPath('products.0.estado', 'disponible');
        $response->assertJsonPath('products.0.warehouses.0.warehouse_name', 'Bodega Central');
        $this->assertEquals($product->marginPercent(), $response->json('products.0.margin_percent'));
        $response->assertJsonPath('product_count', 1);
    }

    public function test_detalle_de_familia_excluye_productos_inactivos(): void
    {
        $this->grantCompras('designer@illuminations.test', ['compras.read']);
        $active = $this->makeProduct(['reference' => 'FAM-ACTIVE']);
        $inactive = $this->makeProduct(['reference' => 'FAM-INACTIVE', 'is_active' => false]);
        $family = CatalogProductFamily::create(['name' => 'Kit Baño', 'is_active' => true]);
        $family->products()->attach([$active->id, $inactive->id]);

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/compras/families/{$family->id}")
            ->assertStatus(200);

        $references = collect($response->json('products'))->pluck('reference');
        $this->assertEqualsCanonicalizing(['FAM-ACTIVE'], $references->all());
    }

    // ── SCRUM-243 — generar compra combinada de familia, separada por proveedor ──

    public function test_generar_compra_de_familia_crea_una_orden_por_proveedor(): void
    {
        $this->grantCompras('designer@illuminations.test', ['compras.edit', 'compras.read']);
        $providerA = $this->makeProvider(['name' => 'LightCorp']);
        $providerB = $this->makeProvider(['name' => 'Iluminex SA']);
        $productA = $this->makeProduct(['reference' => 'FAM-A', 'provider_id' => $providerA->id, 'stock_quantity' => 2, 'reorder_point' => 10]);
        $productB = $this->makeProduct(['reference' => 'FAM-B', 'provider_id' => $providerB->id, 'stock_quantity' => 5, 'reorder_point' => 8]);

        $family = CatalogProductFamily::create(['name' => 'Kit Baño', 'is_active' => true]);
        $family->products()->attach([$productA->id, $productB->id]);

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/compras/families/{$family->id}/generate-purchase")
            ->assertStatus(201);

        $this->assertCount(2, $response->json('order_ids'));
        Tenant::where('slug', 'illuminations')->first()?->makeCurrent();
        $providerIds = PurchaseOrder::whereIn('id', $response->json('order_ids'))->pluck('provider_id')->sort()->values()->all();
        $this->assertEqualsCanonicalizing([$providerA->id, $providerB->id], $providerIds);
    }

    // ── SCRUM-425/426 (REQ-355/356) — ficha técnica del producto ──────────────

    /**
     * SCRUM-240 (corrección de Daniela Amaya 2026-08-09) — el modal nuevo saca los 12 campos
     * estructurados del formulario de creación (technical_spec ya NO se valida acá, la ficha
     * técnica pasa a ser el documento/link de SCRUM-237) — `technical_spec = null` en un producto
     * creado por este flujo es el comportamiento esperado, no una regresión.
     */
    public function test_crear_producto_no_expone_technical_spec_estructurado(): void
    {
        $this->grantCompras('designer@illuminations.test', ['compras.write', 'compras.edit', 'compras.read']);
        $provider = $this->makeProvider();
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/compras/inventory', $this->makeValidCreatePayload($provider->id, ['reference' => 'NOSPEC-1']))
            ->assertStatus(201);

        $response->assertJsonPath('technical_spec', null);
        // SCRUM-426 — el documento/link SÍ es obligatorio (ver test de abajo); makeValidCreatePayload
        // ya manda uno por default, así que acá viene en true.
        $response->assertJsonPath('has_technical_sheet', true);
    }

    /**
     * SCRUM-426 (rebote de Daniela Amaya 2026-08-13) — regresión real corregida: la ficha técnica
     * (documento/link, Caso A/B de SCRUM-237) vuelve a ser obligatoria para crear el producto, y
     * ahora se procesa en el MISMO request de creación (dentro de la misma transacción) — nunca un
     * producto sin ficha técnica en la base, ni siquiera transitoriamente entre 2 llamadas como
     * antes de este fix.
     */
    public function test_crear_producto_sin_ficha_tecnica_retorna_422(): void
    {
        $this->grantCompras('designer@illuminations.test', ['compras.write', 'compras.edit', 'compras.read']);
        $provider = $this->makeProvider();
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $payload = $this->makeValidCreatePayload($provider->id);
        unset($payload['link']);

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/compras/inventory', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['file', 'link']);
    }

    /** SCRUM-426 — el link de ficha técnica queda disponible de inmediato en la MISMA respuesta de creación. */
    public function test_crear_producto_con_link_de_ficha_tecnica_queda_disponible_de_inmediato(): void
    {
        $this->grantCompras('designer@illuminations.test', ['compras.write', 'compras.edit', 'compras.read']);
        $provider = $this->makeProvider();
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/compras/inventory', $this->makeValidCreatePayload($provider->id, [
                'link' => 'https://proveedor.example.com/fichas/producto-1.pdf',
            ]))
            ->assertStatus(201);

        $response->assertJsonPath('has_technical_sheet', true);
        $response->assertJsonPath('technical_sheet_filename', 'https://proveedor.example.com/fichas/producto-1.pdf');
    }

    /** SCRUM-426 — Caso A: un archivo real, no un link, también queda disponible en el mismo POST. */
    public function test_crear_producto_con_archivo_de_ficha_tecnica_queda_disponible_de_inmediato(): void
    {
        $this->grantCompras('designer@illuminations.test', ['compras.write', 'compras.edit', 'compras.read']);
        $provider = $this->makeProvider();
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $payload = $this->makeValidCreatePayload($provider->id);
        unset($payload['link']);
        $payload['file'] = UploadedFile::fake()->create('ficha.pdf', 10, 'application/pdf');

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/compras/inventory', $payload)
            ->assertStatus(201);

        $response->assertJsonPath('has_technical_sheet', true);
    }

    public function test_detalle_de_producto_viejo_sin_ficha_tecnica_devuelve_null(): void
    {
        // Producto creado antes de SCRUM-426, directo por el modelo (sin pasar por la validación
        // de StoreInventoryProductRequest) — no debe romper el detalle ni inventarse datos.
        $this->grantCompras('designer@illuminations.test', ['compras.read']);
        $product = $this->makeProduct(['reference' => 'LEGACY-1']);
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/compras/inventory/{$product->id}")
            ->assertStatus(200);

        $response->assertJsonPath('technical_spec', null);
    }

    // ── SCRUM-743 — filtro multiselección de bodegas (`warehouse_ids[]`) ──────

    private function warehouseId(string $name): int
    {
        return (int) DB::table('illuminations_bodega.warehouses')->where('name', $name)->value('id');
    }

    /** @param array<string, mixed> $overrides */
    private function makeStock(CatalogProduct $product, string $warehouseName, int $quantity, array $overrides = []): void
    {
        ProductWarehouseStock::create([
            'catalog_product_id' => $product->id,
            'warehouse_id'       => $this->warehouseId($warehouseName),
            'quantity'           => $quantity,
            ...$overrides,
        ]);
    }

    public function test_sin_warehouse_ids_devuelve_productos_de_todas_las_bodegas(): void
    {
        $this->grantCompras('designer@illuminations.test', ['compras.read']);
        $productA = $this->makeProduct(['reference' => 'WH-A']);
        $productB = $this->makeProduct(['reference' => 'WH-B']);
        $this->makeStock($productA, 'Bodega Central', 5);
        $this->makeStock($productB, 'Merma', 3);
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/inventory')
            ->assertStatus(200);

        $references = collect($response->json('data'))->pluck('reference');
        $this->assertEqualsCanonicalizing(['WH-A', 'WH-B'], $references->all());
    }

    /**
     * Criterio explícito del ticket (AC6): `warehouse_ids[]` vacío (todos sus elementos filtrados
     * por no ser IDs numéricos válidos) se comporta igual que ausente — nunca tabla vacía por
     * default.
     */
    public function test_warehouse_ids_vacio_devuelve_productos_de_todas_las_bodegas(): void
    {
        $this->grantCompras('designer@illuminations.test', ['compras.read']);
        $productA = $this->makeProduct(['reference' => 'WH-EMPTY-A']);
        $productB = $this->makeProduct(['reference' => 'WH-EMPTY-B']);
        $this->makeStock($productA, 'Bodega Central', 5);
        $this->makeStock($productB, 'Merma', 3);
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/inventory?warehouse_ids[]=')
            ->assertStatus(200);

        $references = collect($response->json('data'))->pluck('reference');
        $this->assertEqualsCanonicalizing(['WH-EMPTY-A', 'WH-EMPTY-B'], $references->all());
    }

    public function test_warehouse_ids_con_una_bodega_filtra_solo_esa_bodega(): void
    {
        $this->grantCompras('designer@illuminations.test', ['compras.read']);
        $inCentral = $this->makeProduct(['reference' => 'WH-CENTRAL']);
        $inMerma = $this->makeProduct(['reference' => 'WH-MERMA']);
        $withoutStock = $this->makeProduct(['reference' => 'WH-NONE', 'stock_quantity' => 0]);
        $this->makeStock($inCentral, 'Bodega Central', 5);
        $this->makeStock($inMerma, 'Merma', 5);
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $centralId = $this->warehouseId('Bodega Central');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/compras/inventory?warehouse_ids[]={$centralId}")
            ->assertStatus(200);

        $references = collect($response->json('data'))->pluck('reference');
        $this->assertEqualsCanonicalizing(['WH-CENTRAL'], $references->all());
        $this->assertNotContains('WH-MERMA', $references->all());
        $this->assertNotContains('WH-NONE', $references->all());
    }

    /**
     * AC3 — selección múltiple es una UNIÓN entre bodegas, no una intersección: un producto con
     * stock EXCLUSIVAMENTE en una de las bodegas seleccionadas debe matchear igual, no solo el que
     * tiene stock en ambas.
     */
    public function test_warehouse_ids_con_dos_bodegas_es_union_no_interseccion(): void
    {
        $this->grantCompras('designer@illuminations.test', ['compras.read']);
        $onlyCentral = $this->makeProduct(['reference' => 'UNION-CENTRAL']);
        $onlyMerma = $this->makeProduct(['reference' => 'UNION-MERMA']);
        $inBoth = $this->makeProduct(['reference' => 'UNION-BOTH']);
        $inNeither = $this->makeProduct(['reference' => 'UNION-NONE', 'stock_quantity' => 0]);
        $this->makeStock($onlyCentral, 'Bodega Central', 5);
        $this->makeStock($onlyMerma, 'Merma', 5);
        $this->makeStock($inBoth, 'Bodega Central', 2);
        $this->makeStock($inBoth, 'Merma', 3);
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $centralId = $this->warehouseId('Bodega Central');
        $mermaId = $this->warehouseId('Merma');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/compras/inventory?warehouse_ids[]={$centralId}&warehouse_ids[]={$mermaId}")
            ->assertStatus(200);

        $references = collect($response->json('data'))->pluck('reference');
        $this->assertEqualsCanonicalizing(['UNION-CENTRAL', 'UNION-MERMA', 'UNION-BOTH'], $references->all());
    }

    public function test_warehouse_ids_combinado_con_busqueda_de_texto(): void
    {
        // AC8 — la selección de bodegas se combina (AND) con el resto de filtros activos, acá
        // búsqueda de texto: solo el producto que matchea AMBOS criterios debe aparecer.
        $this->grantCompras('designer@illuminations.test', ['compras.read']);
        $matchInCentral = $this->makeProduct(['reference' => 'SEARCH-MATCH', 'name' => 'Bombillo especial LED']);
        $matchInMerma = $this->makeProduct(['reference' => 'OTHER-MATCH', 'name' => 'Bombillo especial LED']);
        $noMatchInCentral = $this->makeProduct(['reference' => 'NOMATCH', 'name' => 'Apliqu de pared distinto']);
        $this->makeStock($matchInCentral, 'Bodega Central', 5);
        $this->makeStock($matchInMerma, 'Merma', 5);
        $this->makeStock($noMatchInCentral, 'Bodega Central', 5);
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $centralId = $this->warehouseId('Bodega Central');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/compras/inventory?warehouse_ids[]={$centralId}&search=Bombillo especial LED")
            ->assertStatus(200);

        $references = collect($response->json('data'))->pluck('reference');
        $this->assertEqualsCanonicalizing(['SEARCH-MATCH'], $references->all());
    }

    public function test_warehouse_ids_combinado_con_chip_categoria(): void
    {
        // AC8 — combinado con el chip de filtro rápido (acá "sin_stock"): ambos filtros aplican
        // juntos (AND), la unión sigue siendo solo entre las bodegas seleccionadas.
        $this->grantCompras('designer@illuminations.test', ['compras.read']);
        $sinStockCentral = $this->makeProduct(['reference' => 'CHIP-SINSTOCK-CENTRAL', 'stock_quantity' => 0, 'reorder_point' => 0]);
        $sinStockMerma = $this->makeProduct(['reference' => 'CHIP-SINSTOCK-MERMA', 'stock_quantity' => 0, 'reorder_point' => 0]);
        $disponibleCentral = $this->makeProduct(['reference' => 'CHIP-DISPONIBLE-CENTRAL', 'stock_quantity' => 50, 'reorder_point' => 5]);
        // ProductWarehouseStock.quantity > 0 es lo que hace al producto "visible" en esa bodega
        // para el filtro — independiente de catalog_products.stock_quantity, que es lo que decide
        // el chip "sin_stock". Se combinan ambos ejes a propósito en este test.
        $this->makeStock($sinStockCentral, 'Bodega Central', 1);
        $this->makeStock($sinStockMerma, 'Merma', 1);
        $this->makeStock($disponibleCentral, 'Bodega Central', 1);
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $centralId = $this->warehouseId('Bodega Central');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/compras/inventory?warehouse_ids[]={$centralId}&chip=sin_stock")
            ->assertStatus(200);

        $references = collect($response->json('data'))->pluck('reference');
        $this->assertEqualsCanonicalizing(['CHIP-SINSTOCK-CENTRAL'], $references->all());
    }

    /** AC — IDs de bodega no numéricos/negativos/cero se descartan en silencio, nunca 500. */
    public function test_warehouse_ids_invalidos_no_numericos_se_ignoran_como_sin_filtro(): void
    {
        $this->grantCompras('designer@illuminations.test', ['compras.read']);
        $product = $this->makeProduct(['reference' => 'WH-JUNK']);
        $this->makeStock($product, 'Bodega Central', 5);
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/inventory?warehouse_ids[]=abc&warehouse_ids[]=-1&warehouse_ids[]=0')
            ->assertStatus(200);

        $references = collect($response->json('data'))->pluck('reference');
        $this->assertContains('WH-JUNK', $references->all());
    }

    /** AC — un ID de bodega con formato válido pero inexistente filtra sin matches, nunca rompe. */
    public function test_warehouse_ids_inexistente_devuelve_lista_vacia_sin_error(): void
    {
        $this->grantCompras('designer@illuminations.test', ['compras.read']);
        $product = $this->makeProduct(['reference' => 'WH-GHOST']);
        $this->makeStock($product, 'Bodega Central', 5);
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/inventory?warehouse_ids[]=999999')
            ->assertStatus(200);

        $this->assertCount(0, $response->json('data'));
    }

    // ── Permisos negativos ──────────────────────────────────────────────────────

    public function test_editar_sin_permiso_edit_retorna_403(): void
    {
        $this->grantCompras('designer@illuminations.test', ['compras.read']);
        $product = $this->makeProduct();
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/compras/inventory/{$product->id}", ['cost' => 1])
            ->assertStatus(403);
    }
}
