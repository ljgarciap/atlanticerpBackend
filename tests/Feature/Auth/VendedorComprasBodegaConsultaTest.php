<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\RoleModuleVisibility;
use App\Modules\Compras\Models\Provider;
use App\Modules\Compras\Models\PurchaseOrder;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-1013 (rebote de Gerencia, 2026-10-02) y SCRUM-1021 — el vendedor/diseñador conserva lo que
 * ya tenía (Inventario restringido y Comparación de Referencias, vía Ventas & Diseño) y gana
 * ÚNICAMENTE Compras → Órdenes → Logística y Envío y Bodega → Bodegas, ambos en solo consulta.
 *
 * `designer@atlantic.test` hace de vendedor: tiene `ventas_diseno.read` pero no `compras.read`
 * ni `bodega.read`; los dos permisos angostos se le conceden como extra_permissions, igual que
 * SpecialPermissionSeeder hace con los 10 reales.
 */
class VendedorComprasBodegaConsultaTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    /** @param list<string> $perms */
    private function conceder(array $perms): void
    {
        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');
        DB::table("{$slug}_auth.users")->where('email', 'designer@atlantic.test')
            ->update(['extra_permissions' => json_encode($perms)]);
    }

    /** @return array<string, string> */
    private function vendedor(): array
    {
        return $this->authHeaders($this->loginAs('designer@atlantic.test', 'Password123!', 'designer'));
    }

    private function orden(): PurchaseOrder
    {
        $provider = Provider::create(['name' => 'Proveedor '.uniqid(), 'currency' => 'USD']);

        return PurchaseOrder::create([
            'provider_id' => $provider->id, 'currency' => 'USD',
            'status' => PurchaseOrder::STATUS_ORDENADO, 'status_changed_at' => now(),
        ]);
    }

    // ── Estado del rol (migración de reversión) ──────────────────────────────────────────────

    public function test_el_rol_vendedor_no_tiene_modulo_compras_asi_el_menu_no_muestra_todo_compras(): void
    {
        $role = Role::where('key', 'vendedor')->firstOrFail();

        $this->assertFalse(
            RoleModuleVisibility::where('role_id', $role->id)->where('module', 'compras')->exists(),
            'Con compras.view el Sidebar muestra TODO el menú de Compras (rebote de SCRUM-1013).',
        );
    }

    public function test_operaciones_conserva_su_acceso_de_compras(): void
    {
        $role = Role::where('key', 'lider_operaciones')->firstOrFail();

        $this->assertTrue(RoleModuleVisibility::where('role_id', $role->id)->where('module', 'compras')->exists());
    }

    // ── Logística y Envío: lo único nuevo de Compras ────────────────────────────────────────

    public function test_vendedor_ve_logistica_en_consulta(): void
    {
        $orden = $this->orden();
        $this->conceder(['compras.logistica.view']);
        $h = $this->vendedor();

        $this->withHeaders($h)->getJson('/api/compras/orders')->assertStatus(200);
        $this->withHeaders($h)->getJson('/api/compras/orders/shipment-projects?q=a')->assertStatus(200);
        $this->withHeaders($h)->getJson("/api/compras/orders/{$orden->id}")->assertStatus(200);
        $this->withHeaders($h)->getJson("/api/compras/orders/{$orden->id}/documents")->assertStatus(200);
    }

    public function test_vendedor_no_puede_escribir_en_logistica(): void
    {
        $orden = $this->orden();
        $this->conceder(['compras.logistica.view']);
        $h = $this->vendedor();

        $this->withHeaders($h)->postJson('/api/compras/orders', [])->assertStatus(403);
        $this->withHeaders($h)->putJson("/api/compras/orders/{$orden->id}", [])->assertStatus(403);
        $this->withHeaders($h)->patchJson("/api/compras/orders/{$orden->id}/advance")->assertStatus(403);
        $this->withHeaders($h)->postJson("/api/compras/orders/{$orden->id}/documents", [])->assertStatus(403);
    }

    public function test_vendedor_no_accede_al_resto_de_compras(): void
    {
        $this->conceder(['compras.logistica.view']);
        $h = $this->vendedor();

        foreach (['/api/compras/providers', '/api/compras/goods-receipts', '/api/compras/settings', '/api/compras/home/summary'] as $url) {
            $this->withHeaders($h)->getJson($url)->assertStatus(403);
        }
    }

    // ── Bodega → Bodegas: lo único nuevo de Bodega (SCRUM-1021) ─────────────────────────────

    public function test_vendedor_ve_bodegas_y_zona_libre_en_consulta(): void
    {
        $this->conceder(['bodega.warehouses.view']);
        $h = $this->vendedor();

        $lista = $this->withHeaders($h)->getJson('/api/bodega/warehouses')->assertStatus(200);
        $nombres = collect($lista->json('data'))->pluck('name')->all();
        $this->assertContains('Bodega Zona Libre', $nombres);
        $this->assertContains('Bodega Central', $nombres);

        $zonaLibre = (int) collect($lista->json('data'))->firstWhere('name', 'Bodega Zona Libre')['id'];
        $this->withHeaders($h)->getJson("/api/bodega/warehouses/{$zonaLibre}")->assertStatus(200);

        $catalogo = $this->withHeaders($h)->getJson('/api/bodega/zona-libre/productos')->assertStatus(200);
        $this->assertFalse($catalogo->json('meta.puede_ver_precio') ?? $catalogo->json('puede_ver_precio') ?? false, 'El vendedor no ve el precio de Zona Libre.');
    }

    public function test_vendedor_no_puede_modificar_ni_reubicar_inventario_de_bodega(): void
    {
        $this->conceder(['bodega.warehouses.view']);
        $h = $this->vendedor();
        $central = (int) DB::table('atlantic_bodega.warehouses')->where('name', 'Bodega Central')->value('id');

        $this->withHeaders($h)->postJson('/api/bodega/relocations', [])->assertStatus(403);
        $this->withHeaders($h)->postJson("/api/bodega/warehouses/{$central}/locations", ['name' => 'X'])->assertStatus(403);
        $this->withHeaders($h)->postJson('/api/bodega/adjustment-requests', [])->assertStatus(403);
    }

    public function test_vendedor_no_accede_al_resto_de_bodega(): void
    {
        $this->conceder(['bodega.warehouses.view']);
        $h = $this->vendedor();

        foreach (['/api/bodega/kardex', '/api/bodega/relocations', '/api/bodega/orders', '/api/bodega/zona-libre/requests'] as $url) {
            $this->withHeaders($h)->getJson($url)->assertStatus(403);
        }
    }

    public function test_sin_los_permisos_nuevos_no_hay_acceso(): void
    {
        $this->conceder([]);
        $h = $this->vendedor();

        $this->withHeaders($h)->getJson('/api/compras/orders')->assertStatus(403);
        $this->withHeaders($h)->getJson('/api/bodega/warehouses')->assertStatus(403);
    }

    // ── Pre-QA H1/H2: el vendedor nunca recibe costos ni montos por la API ────────────────────

    public function test_la_orden_del_vendedor_llega_sin_costos_ni_montos(): void
    {
        $orden = $this->orden();
        $producto = \App\Modules\VentasDiseno\Models\CatalogProduct::create([
            'reference' => 'REF-'.uniqid(), 'name' => 'Bombillo', 'description' => 'Bombillo',
            'price_full' => 20, 'cost' => 55.5, 'stock_quantity' => 1, 'is_active' => true,
        ]);
        \App\Modules\Compras\Models\PurchaseOrderLine::create([
            'purchase_order_id' => $orden->id, 'catalog_product_id' => $producto->id,
            'quantity' => 10, 'unit_cost' => 55.5, 'subtotal' => 555,
        ]);
        $orden->update(['total_amount' => 555, 'shipping_cost' => 40]);
        $this->conceder(['compras.logistica.view']);
        $h = $this->vendedor();

        $detalle = $this->withHeaders($h)->getJson("/api/compras/orders/{$orden->id}")->assertStatus(200);
        foreach (['total_amount', 'shipping_cost', 'paid_amount', 'pending_amount_change'] as $campo) {
            $this->assertNull($detalle->json($campo), $campo);
        }
        $linea = $detalle->json('lines.0');
        foreach (['unit_cost', 'subtotal', 'additional_cost_percent', 'additional_cost_amount'] as $campo) {
            $this->assertNull($linea[$campo], "lines.0.{$campo}");
        }
        $this->assertSame(10, $linea['quantity'], 'La cantidad sí se ve: es lo que Logística necesita.');

        $lista = $this->withHeaders($h)->getJson('/api/compras/orders')->assertStatus(200);
        $fila = collect($lista->json('data'))->firstWhere('id', $orden->id);
        $this->assertNull($fila['total_amount']);
        $this->assertNull($fila['shipping_cost']);
        $this->assertNull($fila['paid_amount']);
    }

    public function test_el_vendedor_no_puede_ordenar_las_ordenes_por_monto(): void
    {
        $this->orden();
        $this->conceder(['compras.logistica.view']);

        // Ordenar por monto revelaría cuánto cuesta cada orden: el criterio se ignora (no hay 500).
        $this->withHeaders($this->vendedor())->getJson('/api/compras/orders?sort_by=total_amount&sort_dir=desc')->assertStatus(200);
    }

    public function test_quien_ve_montos_de_verdad_sigue_viendolos(): void
    {
        $orden = $this->orden();
        $orden->update(['total_amount' => 555]);
        // Operaciones: compras.limited.view (no es solo logistica.view) ve los montos como antes.
        $this->conceder(['compras.limited.view', 'compras.logistica.view']);

        $this->assertEquals(555, $this->withHeaders($this->vendedor())->getJson("/api/compras/orders/{$orden->id}")->json('total_amount'));
    }

    public function test_el_inventario_restringido_no_devuelve_el_valor_total_en_los_kpis(): void
    {
        \App\Modules\VentasDiseno\Models\CatalogProduct::create([
            'reference' => 'REF-'.uniqid(), 'name' => 'Bombillo', 'description' => 'Bombillo',
            'price_full' => 20, 'cost' => 12, 'stock_quantity' => 10, 'is_active' => true,
        ]);
        $this->conceder(['compras.logistica.view']);

        $r = $this->withHeaders($this->vendedor())->getJson('/api/compras/inventory')->assertStatus(200);

        $r->assertJsonPath('restricted', true);
        $this->assertNull($r->json('kpis.total_value'), 'total_value (precio × stock) no viaja en modo restringido.');
    }

    // ── Pre-QA H3: los documentos de la orden también traen precios ────────────────────────────

    private function documento(PurchaseOrder $orden, string $categoria): void
    {
        \App\Modules\Compras\Models\PurchaseOrderDocument::create([
            'purchase_order_id' => $orden->id, 'category' => $categoria,
            'original_filename' => "{$categoria}.pdf", 'storage_key' => "po/{$orden->id}/{$categoria}.pdf",
            'size_bytes' => 10, 'mime_type' => 'application/pdf',
        ]);
    }

    public function test_el_vendedor_solo_ve_documentos_sin_precios_y_quien_ve_montos_los_ve_todos(): void
    {
        Storage::fake('s3-private');
        $orden = $this->orden();
        foreach (['factura_comercial', 'confirmacion_proveedor', 'declaracion_nacionalizacion', 'otro', 'bl', 'permiso_importacion'] as $categoria) {
            $this->documento($orden, $categoria);
        }

        $this->conceder(['compras.logistica.view']);
        $vendedor = collect($this->withHeaders($this->vendedor())->getJson("/api/compras/orders/{$orden->id}/documents")->assertStatus(200)->json('data'))
            ->pluck('category')->sort()->values()->all();
        $this->assertSame(['bl', 'permiso_importacion'], $vendedor, 'Factura, confirmación, declaración y "otro" traen precios.');

        $this->conceder(['compras.limited.view', 'compras.logistica.view']);
        $operaciones = $this->withHeaders($this->vendedor())->getJson("/api/compras/orders/{$orden->id}/documents")->assertStatus(200)->json('data');
        $this->assertCount(6, $operaciones, 'Operaciones ve todos los documentos, como antes.');
    }
}
