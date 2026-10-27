<?php

declare(strict_types=1);

namespace Tests\Feature\Compras;

use App\Models\User;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\RoleModuleVisibility;
use App\Modules\Compras\Models\Provider;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Modules\VentasDiseno\Models\MasterClient;
use App\Modules\VentasDiseno\Models\PipelineCard;
use App\Modules\VentasDiseno\Models\Quote;
use App\Modules\VentasDiseno\Models\QuoteItem;
use App\Modules\VentasDiseno\Models\QuotePart;
use App\Modules\VentasDiseno\Models\SalesProject;
use App\Modules\VentasDiseno\Models\SubClient;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Feature\FeatureTestCase;

/**
 * REQ-133 (sprint2) — buscador de Pedidos Aprobados para Compras, sin depender de
 * ventas_diseno.read (Lider Compras/lider_compras no tiene ese permiso).
 */
class ApprovedProjectControllerTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    private function grantCompras(string $email, array $perms): void
    {
        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');
        DB::table("{$slug}_auth.users")
            ->where('email', $email)
            ->update(['extra_permissions' => json_encode($perms)]);
    }

    private function makeQuote(string $projectName, ?string $stage, bool $withFolio = true, string $ownerEmail = 'designer@atlantic.test'): Quote
    {
        $owner = User::where('email', $ownerEmail)->firstOrFail();
        $master = MasterClient::create(['name' => "Cliente {$projectName}"]);
        $sub = SubClient::create([
            'master_client_id' => $master->id, 'business_name' => "Cliente {$projectName} SA", 'tax_id' => uniqid(),
        ]);
        $salesProject = SalesProject::create(['sub_client_id' => $sub->id, 'name' => $projectName]);
        // SCRUM-723: confirmed_at (no folio) es lo que decide document_status ==
        // "approved" — este helper simula una cotización ya guardada/oficial.
        $quote = Quote::create([
            'sub_client_id' => $sub->id, 'sales_project_id' => $salesProject->id,
            'master_client_id' => $master->id, 'owner_id' => $owner->id,
            'folio'        => $withFolio ? 'COT-'.random_int(1000, 9999) : null,
            'confirmed_at' => $withFolio ? now() : null,
        ]);

        if ($stage !== null) {
            PipelineCard::create([
                'sales_project_id' => $salesProject->id, 'stage' => $stage,
                'sub_client_id' => $sub->id, 'owner_id' => $owner->id,
            ]);
        }

        return $quote;
    }

    private function makeCatalogProduct(): CatalogProduct
    {
        $provider = Provider::create(['name' => 'Proveedor Demo A', 'currency' => 'USD']);

        return CatalogProduct::create([
            'reference' => 'REF-'.uniqid(), 'name' => 'Artículo XL', 'description' => 'Artículo XL',
            'price_full' => 20, 'cost' => 12, 'provider_id' => $provider->id, 'is_active' => true,
        ]);
    }

    private function addItemToQuote(Quote $quote, CatalogProduct $product): void
    {
        $part = QuotePart::create(['quote_id' => $quote->id, 'name' => 'L1', 'position' => 1]);
        QuoteItem::create([
            'quote_part_id' => $part->id, 'catalog_product_id' => $product->id, 'is_custom' => false,
            'reference' => $product->reference, 'description' => $product->name, 'quantity' => 1, 'unit_price' => 20,
        ]);
    }

    public function test_sin_autenticar_retorna_401(): void
    {
        $this->getJson('/api/compras/approved-projects')->assertStatus(401);
    }

    public function test_solo_incluye_pedidos_aprobados(): void
    {
        $this->grantCompras('designer@atlantic.test', ['compras.read']);
        $this->makeQuote('Proyecto Aprobado', 'approved');
        $this->makeQuote('Proyecto Enviado', 'quote');
        $this->makeQuote('Proyecto Sin Folio', 'approved', withFolio: false);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/approved-projects')
            ->assertStatus(200);

        $names = collect($response->json('data'))->pluck('project_name');
        $this->assertContains('Proyecto Aprobado', $names);
        $this->assertNotContains('Proyecto Enviado', $names);
        $this->assertNotContains('Proyecto Sin Folio', $names);
    }

    public function test_usuario_de_compras_sin_ventas_diseno_read_puede_buscar(): void
    {
        // El propio caso que motivó este endpoint: rol lider_compras no tiene ventas_diseno.read.
        $this->grantCompras('designer@atlantic.test', ['compras.read']);
        $this->makeQuote('Proyecto Compras', 'approved');

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/approved-projects')
            ->assertStatus(200);
    }

    public function test_respuesta_no_incluye_monto(): void
    {
        // Mismo criterio que "el costo nunca viaja al buscador" (CatalogProductController).
        $this->grantCompras('designer@atlantic.test', ['compras.read']);
        $this->makeQuote('Proyecto Aprobado', 'approved');

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/approved-projects')
            ->assertStatus(200);

        $this->assertArrayNotHasKey('amount', $response->json('data.0'));
    }

    /**
     * SCRUM-734 (Senior Review retroactivo 2026-08-06) — un proyecto Aprobado
     * puede tener más de una cotización con documentStatus()==='approved' en su
     * historial (todas las confirmadas antes de llegar a Aprobado quedan con ese
     * mismo estado calculado); antes de este fix aparecían como filas duplicadas
     * del mismo proyecto acá.
     */
    public function test_proyecto_con_varias_cotizaciones_aprobadas_aparece_una_sola_vez(): void
    {
        $this->grantCompras('designer@atlantic.test', ['compras.read']);
        $owner = User::where('email', 'designer@atlantic.test')->firstOrFail();
        $master = MasterClient::create(['name' => 'Cliente Duplicado']);
        $sub = SubClient::create(['master_client_id' => $master->id, 'business_name' => 'Cliente Duplicado SA', 'tax_id' => uniqid()]);
        $salesProject = SalesProject::create(['sub_client_id' => $sub->id, 'name' => 'Proyecto Con Versiones']);
        PipelineCard::create([
            'sales_project_id' => $salesProject->id, 'stage' => 'approved',
            'sub_client_id' => $sub->id, 'owner_id' => $owner->id,
        ]);

        Quote::create([
            'sub_client_id' => $sub->id, 'sales_project_id' => $salesProject->id,
            'master_client_id' => $master->id, 'owner_id' => $owner->id,
            'folio' => 'COT-1111', 'confirmed_at' => now()->subMinutes(10),
        ]);
        $latest = Quote::create([
            'sub_client_id' => $sub->id, 'sales_project_id' => $salesProject->id,
            'master_client_id' => $master->id, 'owner_id' => $owner->id,
            'folio' => 'COT-2222', 'confirmed_at' => now(),
        ]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/approved-projects')
            ->assertStatus(200);

        $rows = collect($response->json('data'))->where('project_name', 'Proyecto Con Versiones');
        $this->assertCount(1, $rows);
        $this->assertSame('COT-2222', $rows->first()['folio']);
    }

    // ── SCRUM-818 — picker "Proyecto de venta" filtrado por producto (Comparación de Referencias) ──

    /** Sin catalog_product_id (Nueva Orden), cero cambio de comportamiento — mismo resultado que antes. */
    public function test_sin_catalog_product_id_no_filtra_por_producto(): void
    {
        $this->grantCompras('designer@atlantic.test', ['compras.read']);
        $product = $this->makeCatalogProduct();
        $withProduct = $this->makeQuote('Proyecto Con Producto', 'approved');
        $this->addItemToQuote($withProduct, $product);
        $this->makeQuote('Proyecto Sin Producto', 'approved');

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/approved-projects')
            ->assertStatus(200);

        $names = collect($response->json('data'))->pluck('project_name');
        $this->assertContains('Proyecto Con Producto', $names);
        $this->assertContains('Proyecto Sin Producto', $names);
    }

    /** Con catalog_product_id, solo aparecen los proyectos cuya cotización aprobada incluye ese producto. */
    public function test_con_catalog_product_id_filtra_a_proyectos_que_contienen_el_producto(): void
    {
        $this->grantCompras('lidercompras@test.com', ['compras.read']);
        $product = $this->makeCatalogProduct();
        $withProduct = $this->makeQuote('Proyecto Con Producto', 'approved');
        $this->addItemToQuote($withProduct, $product);
        $this->makeQuote('Proyecto Sin Producto', 'approved');

        $token = $this->loginAs('lidercompras@test.com', 'lidercompras@test.com', 'lider_compras');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/compras/approved-projects?catalog_product_id={$product->id}")
            ->assertStatus(200);

        $names = collect($response->json('data'))->pluck('project_name');
        $this->assertContains('Proyecto Con Producto', $names);
        $this->assertNotContains('Proyecto Sin Producto', $names);
    }

    /** Sin ningún proyecto que contenga el producto, la lista queda vacía (CA06). */
    public function test_con_catalog_product_id_sin_ningun_proyecto_que_lo_contenga_devuelve_vacio(): void
    {
        $this->grantCompras('lidercompras@test.com', ['compras.read']);
        $product = $this->makeCatalogProduct();
        $this->makeQuote('Proyecto Sin Producto', 'approved');

        $token = $this->loginAs('lidercompras@test.com', 'lidercompras@test.com', 'lider_compras');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/compras/approved-projects?catalog_product_id={$product->id}")
            ->assertStatus(200);

        $this->assertSame([], $response->json('data'));
    }

    /** Vendedor con catalog_product_id: solo ve SUS proyectos con el producto, aunque otro proyecto ajeno también lo tenga. */
    public function test_vendedor_con_catalog_product_id_solo_ve_sus_propios_proyectos(): void
    {
        $this->grantCompras('designer@atlantic.test', []);
        $product = $this->makeCatalogProduct();
        $ownProject = $this->makeQuote('Proyecto Propio', 'approved', ownerEmail: 'designer@atlantic.test');
        $this->addItemToQuote($ownProject, $product);
        $otherProject = $this->makeQuote('Proyecto Ajeno', 'approved', ownerEmail: 'supervisor@atlantic.test');
        $this->addItemToQuote($otherProject, $product);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/compras/approved-projects?catalog_product_id={$product->id}")
            ->assertStatus(200);

        $names = collect($response->json('data'))->pluck('project_name');
        $this->assertContains('Proyecto Propio', $names);
        $this->assertNotContains('Proyecto Ajeno', $names);
    }

    /** Líder de Compras/Operaciones/Gerencia con catalog_product_id: ven TODOS los proyectos con el producto, sin restricción de owner. */
    public function test_lider_compras_con_catalog_product_id_ve_proyectos_de_cualquier_vendedor(): void
    {
        $this->grantCompras('lidercompras@test.com', ['compras.read']);
        $product = $this->makeCatalogProduct();
        $otherProject = $this->makeQuote('Proyecto De Otro Vendedor', 'approved', ownerEmail: 'supervisor@atlantic.test');
        $this->addItemToQuote($otherProject, $product);

        $token = $this->loginAs('lidercompras@test.com', 'lidercompras@test.com', 'lider_compras');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/compras/approved-projects?catalog_product_id={$product->id}")
            ->assertStatus(200);

        $names = collect($response->json('data'))->pluck('project_name');
        $this->assertContains('Proyecto De Otro Vendedor', $names);
    }

    /** Operaciones (compras.sustitutos.manage, sin compras.read) también ve todos los proyectos, sin restricción de owner. */
    public function test_operaciones_con_catalog_product_id_ve_proyectos_de_cualquier_vendedor(): void
    {
        $this->grantCompras('lideroperaciones@test.com', ['compras.sustitutos.manage', 'compras.limited.view']);
        $product = $this->makeCatalogProduct();
        $otherProject = $this->makeQuote('Proyecto De Otro Vendedor', 'approved', ownerEmail: 'supervisor@atlantic.test');
        $this->addItemToQuote($otherProject, $product);

        $token = $this->loginAs('lideroperaciones@test.com', 'lideroperaciones@test.com', 'lider_operaciones');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/compras/approved-projects?catalog_product_id={$product->id}")
            ->assertStatus(200);

        $names = collect($response->json('data'))->pluck('project_name');
        $this->assertContains('Proyecto De Otro Vendedor', $names);
    }

    // ── SCRUM-818 (rebote 2026-09-03) — reproduce el bug real reportado por Gerencia ────────────

    /**
     * Hallazgo 2 — a diferencia de los tests de arriba (que simulan el caso "ya arreglado"
     * otorgando `compras.read` manualmente vía `grantCompras()`, un permiso v1), este reproduce
     * la cuenta REAL de Lider Compras: acceso a Compras solo vía su ROL (`RoleModuleVisibility`, v2) —
     * nunca tuvo `compras.read`/`compras.sustitutos.manage` cargado en `extra_permissions`. Antes
     * del fix, `QuoteListService::hasFullComprasAccess()` usaba `$user->hasPermission()` (v1) y
     * la trataba como si no tuviera ningún acceso — "No existe ningún proyecto que contenga este
     * producto" para cualquier producto, sin importar cuál.
     */
    public function test_lider_compras_ve_proyectos_solo_con_acceso_de_rol_sin_permiso_v1_extra(): void
    {
        $role = Role::where('key', 'lider_compras')->firstOrFail();
        RoleModuleVisibility::updateOrCreate(
            ['role_id' => $role->id, 'module' => 'compras'],
            ['can_view' => RoleModuleVisibility::VIEW_FULL, 'can_view_team' => false],
        );

        $product = $this->makeCatalogProduct();
        $otherProject = $this->makeQuote('Proyecto De Otro Vendedor', 'approved', ownerEmail: 'supervisor@atlantic.test');
        $this->addItemToQuote($otherProject, $product);

        $token = $this->loginAs('lidercompras@test.com', 'lidercompras@test.com', 'lider_compras');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/compras/approved-projects?catalog_product_id={$product->id}")
            ->assertStatus(200);

        $names = collect($response->json('data'))->pluck('project_name');
        $this->assertContains('Proyecto De Otro Vendedor', $names);
    }

    /** Mismo caso que arriba, pero para Gerencia (Gerencia/Mark) — rol `management`, sin `gerencia.view` en v1. */
    public function test_gerencia_ve_proyectos_solo_con_acceso_de_rol_sin_permiso_v1_extra(): void
    {
        $role = Role::where('key', 'management')->firstOrFail();
        RoleModuleVisibility::updateOrCreate(
            ['role_id' => $role->id, 'module' => 'compras'],
            ['can_view' => RoleModuleVisibility::VIEW_FULL, 'can_view_team' => true],
        );

        $product = $this->makeCatalogProduct();
        $otherProject = $this->makeQuote('Proyecto De Otro Vendedor', 'approved', ownerEmail: 'supervisor@atlantic.test');
        $this->addItemToQuote($otherProject, $product);

        $token = $this->loginAs('gerencia3@test.com', 'gerencia3@test.com', 'management');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/compras/approved-projects?catalog_product_id={$product->id}")
            ->assertStatus(200);

        $names = collect($response->json('data'))->pluck('project_name');
        $this->assertContains('Proyecto De Otro Vendedor', $names);
    }

    /**
     * Hallazgo 4 — con catalog_product_id, un proyecto con cotización real confirmada (folio) pero
     * aún en etapa Cotización (documentStatus() === 'sent', stage ni 'proposal' ni 'approved') SÍ
     * debe aparecer — a diferencia de "Nueva Orden" (test_solo_incluye_pedidos_aprobados arriba),
     * que sigue excluyéndolo a propósito (picker deliberadamente más estricto, "Pedidos Aprobados").
     */
    public function test_con_catalog_product_id_incluye_proyectos_en_etapa_cotizacion(): void
    {
        $this->grantCompras('designer@atlantic.test', ['compras.read']);
        $product = $this->makeCatalogProduct();
        $stillInCotizacion = $this->makeQuote('Proyecto En Cotizacion', 'quote'); // documentStatus() === 'sent'
        $this->addItemToQuote($stillInCotizacion, $product);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/compras/approved-projects?catalog_product_id={$product->id}")
            ->assertStatus(200);

        $names = collect($response->json('data'))->pluck('project_name');
        $this->assertContains('Proyecto En Cotizacion', $names);
    }
}
