<?php

declare(strict_types=1);

namespace Tests\Feature\Legacy;

use App\Modules\Legacy\Models\Albaran;
use App\Modules\Legacy\Models\AlbaranCompra;
use App\Modules\Legacy\Models\AlbaranCompraItem;
use App\Modules\Legacy\Models\AlbaranItem;
use App\Modules\Legacy\Models\Cliente;
use App\Modules\Legacy\Models\ContactoCliente;
use App\Modules\Legacy\Models\Cotizacion;
use App\Modules\Legacy\Models\CotizacionItem;
use App\Modules\Legacy\Models\Factura;
use App\Modules\Legacy\Models\Producto;
use App\Modules\Legacy\Models\ProductoCosto;
use App\Modules\Legacy\Models\ProductoPrecio;
use App\Modules\Legacy\Models\Proveedor;
use App\Shared\Multitenancy\Tenant;
use Tests\Feature\FeatureTestCase;

/**
 * "Vista ICG" (SCRUM-749, 2026-09-03) — pantalla propia bajo Migración, sin partir de un
 * Subcliente puntual (a diferencia de SubClientHistoryController). Gate `role:superadmin`
 * exclusivo, mismo criterio que Pagos ICG.
 */
final class LegacyVistaIcgControllerTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    private function superadminToken(): string
    {
        return $this->loginAs('superadmin@atlantic.test', 'Password123!', 'superadmin');
    }

    private function makeCliente(int $icgCodcliente = 9001): Cliente
    {
        return Cliente::query()->create([
            'icg_codcliente' => $icgCodcliente,
            'razon_social'   => "Cliente {$icgCodcliente}",
            'match_status'   => Cliente::MATCH_UNMATCHED,
            'bloqueado'      => false,
        ]);
    }

    private function makeProveedor(int $icgCodproveedor = 501, string $nombre = 'Proveedor Uno'): Proveedor
    {
        return Proveedor::query()->create([
            'icg_codproveedor' => $icgCodproveedor,
            'nombre'           => $nombre,
            'match_status'     => Proveedor::MATCH_UNMATCHED,
        ]);
    }

    // ---- Gate de acceso -----------------------------------------------------------------

    public function test_management_no_puede_ver_vista_icg(): void
    {
        $token = $this->loginAs();

        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/legacy/migracion/vista/cotizaciones')
            ->assertStatus(403);
    }

    public function test_designer_no_puede_ver_vista_icg(): void
    {
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/legacy/migracion/vista/cotizaciones')
            ->assertStatus(403);
    }

    public function test_entidad_invalida_retorna_404(): void
    {
        $token = $this->superadminToken();

        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/legacy/migracion/vista/pagos')
            ->assertStatus(404);
    }

    // ---- Cotizaciones (representa las 4 entidades documento) ----------------------------

    public function test_index_cotizaciones_incluye_nombre_de_cliente_y_filtra_por_fecha(): void
    {
        $cliente = $this->makeCliente(9001);
        Cotizacion::query()->create([
            'icg_numserie' => 'A', 'icg_numpresupuesto' => 100, 'icg_n' => 1, 'icg_version' => 1,
            'legacy_cliente_id' => $cliente->id, 'fecha' => '2026-01-01', 'total_neto' => '10.00',
            'orphan' => false,
        ]);
        Cotizacion::query()->create([
            'icg_numserie' => 'A', 'icg_numpresupuesto' => 101, 'icg_n' => 1, 'icg_version' => 1,
            'legacy_cliente_id' => $cliente->id, 'fecha' => '2026-06-01', 'total_neto' => '20.00',
            'orphan' => false,
        ]);
        $token = $this->superadminToken();

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/legacy/migracion/vista/cotizaciones?fecha_desde=2026-05-01')
            ->assertStatus(200);

        $rows = $response->json('data');
        $this->assertCount(1, $rows);
        $this->assertSame('A-101', $rows[0]['numero']);
        $this->assertSame('Cliente 9001', $rows[0]['cliente']);
    }

    public function test_index_cotizaciones_busca_por_nombre_de_cliente(): void
    {
        $clienteA = $this->makeCliente(9002);
        $clienteA->update(['razon_social' => 'Delta Residencial']);
        $clienteB = $this->makeCliente(9003);
        $clienteB->update(['razon_social' => 'Otro Cliente']);
        Cotizacion::query()->create([
            'icg_numserie' => 'A', 'icg_numpresupuesto' => 200, 'icg_n' => 1, 'icg_version' => 1,
            'legacy_cliente_id' => $clienteA->id, 'fecha' => '2026-01-01', 'total_neto' => '10.00', 'orphan' => false,
        ]);
        Cotizacion::query()->create([
            'icg_numserie' => 'A', 'icg_numpresupuesto' => 201, 'icg_n' => 1, 'icg_version' => 1,
            'legacy_cliente_id' => $clienteB->id, 'fecha' => '2026-01-01', 'total_neto' => '20.00', 'orphan' => false,
        ]);
        $token = $this->superadminToken();

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/legacy/migracion/vista/cotizaciones?q=Delta')
            ->assertStatus(200);

        $rows = $response->json('data');
        $this->assertCount(1, $rows);
        $this->assertSame('A-200', $rows[0]['numero']);
    }

    public function test_show_cotizacion_no_requiere_subcliente_y_trae_lineas(): void
    {
        $cliente = $this->makeCliente(9004);
        $cotizacion = Cotizacion::query()->create([
            'icg_numserie' => 'A', 'icg_numpresupuesto' => 300, 'icg_n' => 1, 'icg_version' => 1,
            'legacy_cliente_id' => $cliente->id, 'fecha' => '2026-01-01', 'total_neto' => '10.00', 'orphan' => false,
            'proyecto' => 'Torre Delta', 'promocion' => 'Verano2026',
        ]);
        CotizacionItem::query()->create([
            'legacy_cotizacion_id' => $cotizacion->id, 'icg_codarticulo' => 1, 'referencia' => 'REF-A',
            'descripcion' => 'Producto A', 'cantidad' => 1, 'precio' => 10, 'total' => 10,
        ]);
        $token = $this->superadminToken();

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/legacy/migracion/vista/cotizaciones/{$cotizacion->id}")
            ->assertStatus(200);

        $response->assertJsonPath('header.folio', 'A-300-1-1');
        $response->assertJsonPath('header.cliente.razon_social', 'Cliente 9004');
        // SCRUM-842 — campos libres (PRESUPUESTOSCAMPOSLIBRES)
        $response->assertJsonPath('header.proyecto', 'Torre Delta');
        $response->assertJsonPath('header.promocion', 'Verano2026');
        $this->assertCount(1, $response->json('items'));
    }

    public function test_show_cotizacion_inexistente_retorna_404(): void
    {
        $token = $this->superadminToken();

        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/legacy/migracion/vista/cotizaciones/999999')
            ->assertStatus(404);
    }

    // ---- Albaranes (confirma estado derivado + factura relacionada, mismo criterio que SubClientHistoryController) --

    public function test_show_albaran_incluye_facturado_y_factura_relacionada(): void
    {
        $cliente = $this->makeCliente(9005);
        $albaran = Albaran::query()->create([
            'icg_numserie' => 'A', 'icg_numalbaran' => 400, 'icg_n' => '1',
            'legacy_cliente_id' => $cliente->id, 'fecha' => '2026-01-01', 'totalneto' => '10.00',
            'facturado' => true, 'icg_numseriefac' => 'A', 'icg_numfac' => 500, 'orphan' => false,
            'observaciones' => 'Entregar en bodega',
        ]);
        AlbaranItem::query()->create([
            'legacy_albaran_id' => $albaran->id, 'icg_codarticulo' => 1, 'referencia' => 'REF-B',
            'descripcion' => 'Producto B', 'cantidad' => 1, 'precio' => 10, 'total' => 10,
        ]);
        $token = $this->superadminToken();

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/legacy/migracion/vista/albaranes/{$albaran->id}")
            ->assertStatus(200);

        $response->assertJsonPath('header.facturado', true);
        $response->assertJsonPath('header.factura_relacionada', 'A-500');
        // SCRUM-842 — campo libre (ALBVENTACAMPOSLIBRES)
        $response->assertJsonPath('header.observaciones', 'Entregar en bodega');
    }

    // ---- Facturas (nunca items) ------------------------------------------------------------

    public function test_show_factura_nunca_trae_items(): void
    {
        $cliente = $this->makeCliente(9006);
        $factura = Factura::query()->create([
            'icg_numserie' => 'A', 'icg_numfactura' => 600, 'icg_n' => '1',
            'legacy_cliente_id' => $cliente->id, 'fecha' => '2026-01-01', 'total_neto' => '10.00', 'orphan' => false,
            'fe_estado' => 'aceptado', 'fe_auth_number' => 'AUTH-0001',
        ]);
        $token = $this->superadminToken();

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/legacy/migracion/vista/facturas/{$factura->id}")
            ->assertStatus(200);

        $this->assertNull($response->json('items'));
        // SCRUM-841 — Facturación Electrónica (FACTURASVENTACAMPOSLIBRES)
        $response->assertJsonPath('header.fe_estado', 'aceptado');
        $response->assertJsonPath('header.fe_auth_number', 'AUTH-0001');
    }

    // ---- Albaranes de Compra (SCRUM-840, vinculado por Proveedor) -----------------------

    public function test_index_albaranes_compra_incluye_nombre_de_proveedor_y_filtra_por_fecha(): void
    {
        $proveedor = $this->makeProveedor(9101, 'Proveedor Delta');
        AlbaranCompra::query()->create([
            'icg_numserie' => 'A', 'icg_numalbaran' => 800, 'icg_n' => 'B',
            'legacy_proveedor_id' => $proveedor->id, 'icg_codproveedor' => $proveedor->icg_codproveedor,
            'fecha' => '2026-01-01', 'totalneto' => '10.00', 'facturado' => false, 'orphan' => false,
        ]);
        AlbaranCompra::query()->create([
            'icg_numserie' => 'A', 'icg_numalbaran' => 801, 'icg_n' => 'B',
            'legacy_proveedor_id' => $proveedor->id, 'icg_codproveedor' => $proveedor->icg_codproveedor,
            'fecha' => '2026-06-01', 'totalneto' => '20.00', 'facturado' => false, 'orphan' => false,
        ]);
        $token = $this->superadminToken();

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/legacy/migracion/vista/albaranes_compra?fecha_desde=2026-05-01')
            ->assertStatus(200);

        $rows = $response->json('data');
        $this->assertCount(1, $rows);
        $this->assertSame('A-801', $rows[0]['numero']);
        $this->assertSame('Proveedor Delta', $rows[0]['proveedor']);
        $this->assertArrayNotHasKey('cliente', $rows[0]);
    }

    public function test_index_albaranes_compra_busca_por_nombre_de_proveedor(): void
    {
        $proveedorA = $this->makeProveedor(9102, 'Delta Iluminación');
        $proveedorB = $this->makeProveedor(9103, 'Otro Proveedor');
        AlbaranCompra::query()->create([
            'icg_numserie' => 'A', 'icg_numalbaran' => 900, 'icg_n' => 'B',
            'legacy_proveedor_id' => $proveedorA->id, 'icg_codproveedor' => $proveedorA->icg_codproveedor,
            'fecha' => '2026-01-01', 'totalneto' => '10.00', 'facturado' => false, 'orphan' => false,
        ]);
        AlbaranCompra::query()->create([
            'icg_numserie' => 'A', 'icg_numalbaran' => 901, 'icg_n' => 'B',
            'legacy_proveedor_id' => $proveedorB->id, 'icg_codproveedor' => $proveedorB->icg_codproveedor,
            'fecha' => '2026-01-01', 'totalneto' => '20.00', 'facturado' => false, 'orphan' => false,
        ]);
        $token = $this->superadminToken();

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/legacy/migracion/vista/albaranes_compra?q=Delta')
            ->assertStatus(200);

        $rows = $response->json('data');
        $this->assertCount(1, $rows);
        $this->assertSame('A-900', $rows[0]['numero']);
    }

    public function test_show_albaran_compra_incluye_proveedor_lineas_y_factura_relacionada(): void
    {
        $proveedor = $this->makeProveedor(9104, 'Proveedor Show');
        $albaranCompra = AlbaranCompra::query()->create([
            'icg_numserie' => 'A', 'icg_numalbaran' => 1000, 'icg_n' => 'B',
            'legacy_proveedor_id' => $proveedor->id, 'icg_codproveedor' => $proveedor->icg_codproveedor,
            'fecha' => '2026-01-01', 'totalneto' => '850.75', 'facturado' => true,
            'icg_numseriefac' => 'A', 'icg_numfac' => 7001, 'orphan' => false,
        ]);
        AlbaranCompraItem::query()->create([
            'legacy_albaran_compra_id' => $albaranCompra->id, 'icg_codarticulo' => 1,
            'referencia' => 'REF-COMPRA-01', 'descripcion' => 'Perfil de aluminio',
            'cantidad' => 10, 'precio' => 25, 'total' => 250,
        ]);
        $token = $this->superadminToken();

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/legacy/migracion/vista/albaranes_compra/{$albaranCompra->id}")
            ->assertStatus(200);

        $response->assertJsonPath('header.folio', 'A-1000-B');
        $response->assertJsonPath('header.proveedor.nombre', 'Proveedor Show');
        $response->assertJsonPath('header.facturado', true);
        $response->assertJsonPath('header.factura_relacionada', 'A-7001');
        $this->assertArrayNotHasKey('cliente', $response->json('header'));
        $this->assertCount(1, $response->json('items'));
    }

    public function test_show_albaran_compra_huerfano_trae_proveedor_null(): void
    {
        $albaranCompra = AlbaranCompra::query()->create([
            'icg_numserie' => 'A', 'icg_numalbaran' => 1001, 'icg_n' => 'B',
            'legacy_proveedor_id' => null, 'icg_codproveedor' => 9999999,
            'fecha' => '2026-01-01', 'totalneto' => '10.00', 'facturado' => false, 'orphan' => true,
        ]);
        $token = $this->superadminToken();

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/legacy/migracion/vista/albaranes_compra/{$albaranCompra->id}")
            ->assertStatus(200);

        $response->assertJsonPath('header.proveedor', null);
        $response->assertJsonPath('header.icg_codproveedor', 9999999);
        $response->assertJsonPath('header.orphan', true);
    }

    public function test_show_albaran_compra_inexistente_retorna_404(): void
    {
        $token = $this->superadminToken();

        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/legacy/migracion/vista/albaranes_compra/999999')
            ->assertStatus(404);
    }

    // ---- Clientes -----------------------------------------------------------------------

    public function test_index_clientes_busca_por_nombre_y_show_incluye_contactos(): void
    {
        $cliente = $this->makeCliente(9007);
        $cliente->update(['nombre_comercial' => 'Ferretería Delta']);
        $this->makeCliente(9008);
        ContactoCliente::query()->create([
            'legacy_cliente_id' => $cliente->id, 'icg_id' => 1, 'cargo' => 'Gerente',
            'nombre' => 'Juan Pérez', 'telefono' => '123', 'email' => 'juan@example.test',
        ]);
        $token = $this->superadminToken();

        $indexResponse = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/legacy/migracion/vista/clientes?q=Delta')
            ->assertStatus(200);
        $this->assertCount(1, $indexResponse->json('data'));

        $showResponse = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/legacy/migracion/vista/clientes/{$cliente->id}")
            ->assertStatus(200);
        $showResponse->assertJsonPath('header.nombre_comercial', 'Ferretería Delta');
        $this->assertCount(1, $showResponse->json('contactos'));
        $showResponse->assertJsonPath('contactos.0.nombre', 'Juan Pérez');
    }

    // ---- Proveedores --------------------------------------------------------------------

    public function test_index_y_show_proveedores(): void
    {
        $proveedor = Proveedor::query()->create([
            'icg_codproveedor' => 501, 'nombre' => 'Proveedor Uno', 'cif' => 'P1',
            'match_status' => Proveedor::MATCH_UNMATCHED,
        ]);
        $token = $this->superadminToken();

        $indexResponse = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/legacy/migracion/vista/proveedores?q=Uno')
            ->assertStatus(200);
        $this->assertCount(1, $indexResponse->json('data'));

        $showResponse = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/legacy/migracion/vista/proveedores/{$proveedor->id}")
            ->assertStatus(200);
        $showResponse->assertJsonPath('header.nombre', 'Proveedor Uno');
        $this->assertArrayNotHasKey('items', $showResponse->json());
    }

    // ---- Productos (precios + costos, no "items" genérico) -------------------------------

    public function test_show_producto_incluye_precios_y_costos(): void
    {
        $producto = Producto::query()->create([
            'icg_codarticulo' => 701, 'descripcion' => 'Lámpara LED', 'marca_nombre' => 'MarcaX',
            'familia_nombre' => 'FamiliaY', 'descatalogado' => false,
        ]);
        ProductoPrecio::query()->create([
            'icg_codarticulo' => 701, 'idtarifav' => 1, 'precio_neto' => 100, 'precio_bruto' => 118,
            'descatalogado_fila' => false,
        ]);
        ProductoCosto::query()->create([
            'icg_codarticulo' => 701, 'codproveedor' => 1, 'costo_neto' => 60,
        ]);
        $token = $this->superadminToken();

        $indexResponse = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/legacy/migracion/vista/productos?q=Lámpara')
            ->assertStatus(200);
        $this->assertCount(1, $indexResponse->json('data'));

        $showResponse = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/legacy/migracion/vista/productos/{$producto->id}")
            ->assertStatus(200);
        $showResponse->assertJsonPath('header.descripcion', 'Lámpara LED');
        $this->assertCount(1, $showResponse->json('precios'));
        $showResponse->assertJsonPath('precios.0.price_type', 'public');
        $this->assertCount(1, $showResponse->json('costos'));
    }
}
