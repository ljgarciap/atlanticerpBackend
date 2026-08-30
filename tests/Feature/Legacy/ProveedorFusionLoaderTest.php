<?php

declare(strict_types=1);

namespace Tests\Feature\Legacy;

use App\Modules\Compras\Models\Provider;
use App\Modules\Legacy\Models\Producto;
use App\Modules\Legacy\Models\ProductoCosto;
use App\Modules\Legacy\Models\Proveedor;
use App\Modules\Legacy\Services\ProveedorFusionLoader;
use App\Shared\Multitenancy\Tenant;
use Tests\Feature\FeatureTestCase;

/**
 * Épica SCRUM-749, decisión de Luis 2026-08-21 — matchea por nombre o crea `compras.providers`
 * nuevos para `legacy.proveedores` recurrentes (ver docblock de `ProveedorFusionLoader`).
 */
final class ProveedorFusionLoaderTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'illuminations')->first()?->makeCurrent();
    }

    public function test_crea_provider_nuevo_cuando_no_hay_match_por_nombre(): void
    {
        $proveedor = self::makeProveedor(2001, 'Acme Iluminación SA');
        self::makeCosto($proveedor->icg_codproveedor, articulo: 1);

        $result = (new ProveedorFusionLoader())->fuse();

        $this->assertSame(1, $result['candidatos']);
        $this->assertSame(0, $result['matched']);
        $this->assertSame(1, $result['promovidos']);

        $proveedor->refresh();
        $this->assertSame(Proveedor::MATCH_PROMOTED, $proveedor->match_status);
        $this->assertNotNull($proveedor->provider_id);

        $provider = Provider::query()->findOrFail($proveedor->provider_id);
        $this->assertSame('Acme Iluminación SA', $provider->name);
        $this->assertSame(Provider::ORIGIN_INTERNACIONAL, $provider->origin);
        $this->assertSame('USD', $provider->currency);
    }

    public function test_linkea_a_provider_existente_por_nombre_normalizado_sin_duplicar(): void
    {
        $existing = Provider::query()->create(['name' => 'acme iluminacion sa', 'origin' => 'internacional', 'currency' => 'USD']);
        $proveedor = self::makeProveedor(2002, 'ACME ILUMINACION SA'); // mismo nombre, distinto casing
        self::makeCosto($proveedor->icg_codproveedor, articulo: 2);
        $countAntes = Provider::query()->count(); // el seeder del tenant ya trae providers reales

        $result = (new ProveedorFusionLoader())->fuse();

        $this->assertSame(1, $result['matched']);
        $this->assertSame(0, $result['promovidos']);

        $proveedor->refresh();
        $this->assertSame(Proveedor::MATCH_MATCHED, $proveedor->match_status);
        $this->assertSame($existing->id, $proveedor->provider_id);
        $this->assertSame($countAntes, Provider::query()->count()); // no se duplicó
    }

    public function test_proveedor_sin_articulos_no_se_promueve(): void
    {
        self::makeProveedor(2003, 'Sin Actividad SA'); // sin producto_costos

        $result = (new ProveedorFusionLoader())->fuse();

        $this->assertSame(0, $result['candidatos']);
        $proveedor = Proveedor::query()->where('icg_codproveedor', 2003)->firstOrFail();
        $this->assertSame(Proveedor::MATCH_UNMATCHED, $proveedor->match_status);
        $this->assertNull($proveedor->provider_id);
    }

    public function test_proveedor_ya_linkeado_nunca_se_toca(): void
    {
        $provider = Provider::query()->create(['name' => 'Ya Linkeado SA', 'origin' => 'internacional', 'currency' => 'USD']);
        $proveedor = self::makeProveedor(2004, 'Ya Linkeado SA');
        $proveedor->provider_id  = $provider->id;
        $proveedor->match_status = Proveedor::MATCH_MATCHED;
        $proveedor->save();
        self::makeCosto($proveedor->icg_codproveedor, articulo: 3);
        $countAntes = Provider::query()->count();

        $result = (new ProveedorFusionLoader())->fuse();

        $this->assertSame(0, $result['candidatos']);
        $this->assertSame($countAntes, Provider::query()->count());
    }

    public function test_re_correr_es_idempotente_no_duplica_providers(): void
    {
        $proveedor = self::makeProveedor(2005, 'Repetido SA');
        self::makeCosto($proveedor->icg_codproveedor, articulo: 4);

        (new ProveedorFusionLoader())->fuse();
        $countDespuesDePrimeraCorrida = Provider::query()->count();
        $result2 = (new ProveedorFusionLoader())->fuse();

        $this->assertSame(0, $result2['candidatos']);
        $this->assertSame($countDespuesDePrimeraCorrida, Provider::query()->count());
    }

    private static function makeProveedor(int $icgCodproveedor, string $nombre): Proveedor
    {
        return Proveedor::query()->create([
            'icg_codproveedor' => $icgCodproveedor,
            'nombre'           => $nombre,
        ]);
    }

    private static function makeCosto(int $codproveedor, int $articulo): ProductoCosto
    {
        // producto_costos.icg_codarticulo tiene FK real contra legacy.productos.icg_codarticulo.
        Producto::query()->firstOrCreate(
            ['icg_codarticulo' => $articulo],
            ['descripcion' => "Articulo {$articulo}"],
        );

        return ProductoCosto::query()->create([
            'icg_codarticulo' => $articulo, 'codproveedor' => $codproveedor, 'costo_neto' => 10.0,
        ]);
    }
}
