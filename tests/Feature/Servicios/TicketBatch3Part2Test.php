<?php

declare(strict_types=1);

namespace Tests\Feature\Servicios;

use App\Modules\Servicios\Models\Ticket;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Modules\VentasDiseno\Models\MasterClient;
use App\Modules\VentasDiseno\Models\SalesProject;
use App\Modules\VentasDiseno\Models\SubClient;
use App\Shared\Multitenancy\Tenant;
use Tests\Feature\FeatureTestCase;

/**
 * Fase 4 — Servicios, Batch 3 parte 2 (REQ-245/246/247) — formulario completo de "Nuevo ticket":
 * requerimientos especiales estructurados, productos reclamados/afectados, y los 3 endpoints de
 * búsqueda Cliente Master → Subcliente → Proyecto + Catálogo. Mismos usuarios reales que
 * TicketBatch2Test/TicketBatch3Test.
 */
class TicketBatch3Part2Test extends FeatureTestCase
{
    private const LIDER_SERVICIOS = 'liderservicios@test.com';

    private const TECNICO_SERVICIOS = 'tecnicoservicios@test.com';

    private const VENDEDOR_DISENADOR = 'vendedordisenador@test.com';

    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    /** @return array{master: MasterClient, sub: SubClient, project: SalesProject} */
    private function makeClientHierarchy(): array
    {
        $unique = uniqid();
        $master = MasterClient::create(['name' => "Cliente {$unique}"]);
        $sub = SubClient::create(['master_client_id' => $master->id, 'business_name' => "Cliente {$unique} SA", 'tax_id' => $unique]);
        $project = SalesProject::create(['sub_client_id' => $sub->id, 'name' => "Proyecto {$unique}"]);

        return ['master' => $master, 'sub' => $sub, 'project' => $project];
    }

    private function makeCatalogProduct(string $reference, string $description, ?string $brand = null): CatalogProduct
    {
        return CatalogProduct::create([
            'reference' => $reference, 'name' => $description, 'brand' => $brand,
            'price_full' => 100, 'cost' => 50, 'stock_quantity' => 10, 'is_active' => true,
        ]);
    }

    // ── REQ-247 — requerimientos especiales + productos al crear ───────────────────────────────

    public function test_crear_ticket_con_requerimientos_y_productos(): void
    {
        $hierarchy = $this->makeClientHierarchy();
        $product = $this->makeCatalogProduct('LUM-COL-014', 'Colgante LED', 'Atlantic');
        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/servicios/tickets', [
                'tipo' => Ticket::TIPO_CLAIM,
                'tipo_instalacion' => Ticket::TIPO_INSTALACION_INTERNAL,
                'descripcion' => 'Reclamo con productos',
                'sales_project_id' => $hierarchy['project']->id,
                'requerimientos_especiales' => [
                    'catalog' => ['casco', 'arnes'],
                    'otros' => ['Requiere grúa'],
                ],
                'productos' => [
                    ['catalog_product_id' => $product->id, 'cantidad_reclamo' => 3],
                ],
            ])
            ->assertStatus(201);

        // RN1 — cliente derivado del proyecto elegido, nunca de un string suelto.
        $response->assertJsonPath('cliente_master', $hierarchy['master']->name);
        $response->assertJsonPath('subcliente', $hierarchy['sub']->business_name);
        $response->assertJsonPath('proyecto', $hierarchy['project']->name);

        $response->assertJsonPath('requerimientos_especiales.catalog.0.key', 'casco');
        $response->assertJsonPath('requerimientos_especiales.catalog.1.key', 'arnes');
        $response->assertJsonPath('requerimientos_especiales.otros.0', 'Requiere grúa');

        $response->assertJsonPath('productos.0.referencia', 'LUM-COL-014');
        $response->assertJsonPath('productos.0.cantidad_reclamada', 3);
        $response->assertJsonPath('productos.0.cantidad_recibida', 0);
        $response->assertJsonPath('productos.0.cantidad_pendiente', 3);
    }

    /** RN2 — la sección de productos es completamente opcional. */
    public function test_crear_ticket_sin_productos_ni_requerimientos(): void
    {
        $hierarchy = $this->makeClientHierarchy();
        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/servicios/tickets', [
                'tipo' => Ticket::TIPO_CLAIM,
                'tipo_instalacion' => Ticket::TIPO_INSTALACION_INTERNAL,
                'descripcion' => 'Sin extras',
                'sales_project_id' => $hierarchy['project']->id,
            ])
            ->assertStatus(201);

        $response->assertJsonPath('productos', []);
        $response->assertJsonPath('requerimientos_especiales.catalog', []);
        $response->assertJsonPath('requerimientos_especiales.otros', []);
    }

    /** RN4 — un producto no puede agregarse 2 veces en la misma lista. */
    public function test_crear_ticket_con_producto_duplicado_retorna_422(): void
    {
        $hierarchy = $this->makeClientHierarchy();
        $product = $this->makeCatalogProduct('LUM-COL-020', 'Colgante LED 2');
        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/servicios/tickets', [
                'tipo' => Ticket::TIPO_CLAIM,
                'tipo_instalacion' => Ticket::TIPO_INSTALACION_INTERNAL,
                'descripcion' => 'Producto duplicado',
                'sales_project_id' => $hierarchy['project']->id,
                'productos' => [
                    ['catalog_product_id' => $product->id, 'cantidad_reclamo' => 1],
                    ['catalog_product_id' => $product->id, 'cantidad_reclamo' => 2],
                ],
            ])
            ->assertStatus(422);
    }

    public function test_crear_ticket_con_clave_de_requerimiento_invalida_retorna_422(): void
    {
        $hierarchy = $this->makeClientHierarchy();
        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/servicios/tickets', [
                'tipo' => Ticket::TIPO_CLAIM,
                'tipo_instalacion' => Ticket::TIPO_INSTALACION_INTERNAL,
                'descripcion' => 'Clave inventada',
                'sales_project_id' => $hierarchy['project']->id,
                'requerimientos_especiales' => ['catalog' => ['clave-que-no-existe']],
            ])
            ->assertStatus(422);
    }

    // ── REQ-246 — búsqueda Cliente Master → Subcliente → Proyecto ──────────────────────────────

    public function test_buscar_master_clients_filtra_por_nombre(): void
    {
        MasterClient::create(['name' => 'Inmobiliaria Pacífico']);
        MasterClient::create(['name' => 'Torre Business Park']);

        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/servicios/lookup/master-clients?search=Pacífico')
            ->assertStatus(200);

        $names = collect($response->json('data'))->pluck('name')->all();
        $this->assertContains('Inmobiliaria Pacífico', $names);
        $this->assertNotContains('Torre Business Park', $names);
    }

    /** RN3 — solo subclientes del Master elegido, nunca de otro. */
    public function test_buscar_sub_clients_acotado_al_master(): void
    {
        $masterA = MasterClient::create(['name' => 'Cliente A']);
        $masterB = MasterClient::create(['name' => 'Cliente B']);
        SubClient::create(['master_client_id' => $masterA->id, 'business_name' => 'Sub de A', 'tax_id' => uniqid()]);
        SubClient::create(['master_client_id' => $masterB->id, 'business_name' => 'Sub de B', 'tax_id' => uniqid()]);

        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/servicios/lookup/master-clients/{$masterA->id}/sub-clients")
            ->assertStatus(200);

        $names = collect($response->json('data'))->pluck('business_name')->all();
        $this->assertContains('Sub de A', $names);
        $this->assertNotContains('Sub de B', $names);
    }

    /** RN4 — solo proyectos ya existentes del Subcliente elegido. */
    public function test_buscar_proyectos_acotado_al_subcliente(): void
    {
        $hierarchy = $this->makeClientHierarchy();
        $otherSub = SubClient::create(['master_client_id' => $hierarchy['master']->id, 'business_name' => 'Otro sub', 'tax_id' => uniqid()]);
        SalesProject::create(['sub_client_id' => $otherSub->id, 'name' => 'Proyecto de otro sub']);

        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/servicios/lookup/sub-clients/{$hierarchy['sub']->id}/projects")
            ->assertStatus(200);

        $names = collect($response->json('data'))->pluck('name')->all();
        $this->assertContains($hierarchy['project']->name, $names);
        $this->assertNotContains('Proyecto de otro sub', $names);
    }

    /** RN3/RN4 — `exclude` deja fuera productos ya agregados (no-duplicados). */
    public function test_buscar_productos_excluye_ids_dados(): void
    {
        $productA = $this->makeCatalogProduct('LUM-A', 'Producto A');
        $productB = $this->makeCatalogProduct('LUM-B', 'Producto B');

        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/servicios/lookup/products?exclude={$productA->id}")
            ->assertStatus(200);

        $refs = collect($response->json('data'))->pluck('reference')->all();
        $this->assertNotContains('LUM-A', $refs);
        $this->assertContains('LUM-B', $refs);
    }

    // ── REQ-245 RN4 — los mismos roles que pueden crear tickets pueden buscar ───────────────────

    public function test_vendedor_disenador_puede_usar_los_buscadores(): void
    {
        MasterClient::create(['name' => 'Cliente visible']);
        $token = $this->loginAs(self::VENDEDOR_DISENADOR, self::VENDEDOR_DISENADOR, 'vendedor_disenador');

        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/servicios/lookup/master-clients')
            ->assertStatus(200);
    }

    public function test_tecnico_servicios_no_puede_usar_los_buscadores(): void
    {
        $token = $this->loginAs(self::TECNICO_SERVICIOS, self::TECNICO_SERVICIOS, 'tecnico_servicios');

        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/servicios/lookup/master-clients')
            ->assertStatus(403);
    }
}
