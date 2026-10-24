<?php

declare(strict_types=1);

namespace Tests\Feature\Legacy;

use App\Modules\Auth\Models\AuditLog;
use App\Modules\Bodega\Models\ProductWarehouseStock;
use App\Modules\Bodega\Models\Warehouse;
use App\Modules\Legacy\Models\AlmacenEquivalencia;
use App\Modules\Legacy\Models\Producto;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Shared\Multitenancy\Tenant;
use App\Shared\Services\AuditService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Feature\FeatureTestCase;

/** SCRUM-1136 F6 — `legacy:crear-articulos-sin-catalogo` (activos de ICG con stock en un almacén confirmado y sin catálogo → inactivos). Fixtures, sin ICG ni red. */
final class ArticulosSinCatalogoTest extends FeatureTestCase
{
    private int $cod = 920000;

    private string $ultimaSalida = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant();
        config(['legacy.carga_stock_excluir_codarticulos' => [2999, 68602]]);
        AlmacenEquivalencia::query()->create(['codalmacen_icg' => 'B2', 'warehouse_id' => (int) Warehouse::query()->where('name', 'Showroom SM')->value('id'), 'estado' => 'confirmado']);
    }

    private function tenant(): void
    {
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    /** @param array<string, mixed> $opciones */
    private function correr(array $opciones = []): int
    {
        $code = Artisan::call('legacy:crear-articulos-sin-catalogo', ['--tenant' => 'atlantic'] + $opciones);
        $this->tenant();
        $this->ultimaSalida = Artisan::output();

        return $code;
    }

    private function articulo(string $ref, bool $descatalogado = false, float $stock = 2, string $almacen = 'B2', ?string $descripcion = null): int
    {
        $codigo = ++$this->cod;
        Producto::query()->create(['icg_codarticulo' => $codigo, 'descripcion' => $descripcion ?? "Mueble {$codigo}", 'ref_proveedor' => $ref === '' ? null : $ref, 'descatalogado' => $descatalogado]);
        DB::table('atlantic_legacy.stock_almacen')->insert([
            'codarticulo' => $codigo, 'talla' => '.', 'color' => '.', 'codalmacen' => $almacen, 'stock' => $stock, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $codigo;
    }

    public function test_dry_run_clasifica_y_no_escribe(): void
    {
        $this->articulo('SOFA-EPIC-' . $this->cod);
        $this->articulo('');
        $this->articulo('DESC-' . $this->cod, descatalogado: true);
        $this->articulo('OTRO-ALM-' . $this->cod, almacen: 'ALX');        // almacén no confirmado
        $antes = CatalogProduct::query()->count();

        $this->assertSame(0, $this->correr());
        $salida = $this->ultimaSalida;
        $this->assertStringContainsString('DRY-RUN', $salida);
        $this->assertSame($antes, CatalogProduct::query()->count());
        $this->assertSame(0, AuditLog::query()->where('action', AuditService::LEGACY_CREAR_ARTICULOS_SIN_CATALOGO)->count());
    }

    public function test_apply_exige_operador(): void
    {
        $this->articulo('SOFA-A-' . $this->cod);
        $this->assertSame(1, $this->correr(['--apply' => true]));
        $this->assertSame(0, CatalogProduct::query()->where('reference', 'like', 'SOFA-A-%')->count());
    }

    public function test_crea_inactivos_sin_stock_es_idempotente_y_deja_el_resto_en_revision(): void
    {
        $crear = $this->articulo('SOFA-EPIC-77');
        $sinRef = $this->articulo('');
        $desc = $this->articulo('DESC-77', descatalogado: true);
        $otroAlm = $this->articulo('OTRO-ALM-77', almacen: 'ALX');
        $colision = $this->articulo('YA-EXISTE-77');
        CatalogProduct::create(['reference' => 'ya-existe-77', 'name' => 'ya estaba', 'price_full' => 1.0, 'is_active' => true]);

        $this->assertSame(0, $this->correr(['--apply' => true, '--operador' => 'tests']));

        $cp = Producto::query()->where('icg_codarticulo', $crear)->firstOrFail()->catalog_product_id;
        $this->assertNotNull($cp);
        $producto = CatalogProduct::findOrFail($cp);
        $this->assertFalse((bool) $producto->is_active);
        $this->assertNull($producto->stock_quantity, 'no escribe stock');
        $this->assertSame(0, ProductWarehouseStock::query()->where('catalog_product_id', $cp)->count());
        foreach ([$sinRef, $desc, $otroAlm, $colision] as $icg) {
            $this->assertNull(Producto::query()->where('icg_codarticulo', $icg)->value('catalog_product_id'), "no se crea {$icg}");
        }

        $m = AuditLog::query()->where('action', AuditService::LEGACY_CREAR_ARTICULOS_SIN_CATALOGO)->firstOrFail()->metadata;
        $this->assertSame(1, $m['creados']);
        $this->assertSame([[$crear, (int) $cp]], $m['ids_creados']);
        $this->assertSame('tests', $m['operador']);

        $productos = CatalogProduct::query()->count();
        $this->assertSame(0, $this->correr(['--apply' => true, '--operador' => 'tests']));
        $this->assertSame($productos, CatalogProduct::query()->count(), 'la segunda corrida crea 0');
        $this->assertSame(1, AuditLog::query()->where('action', AuditService::LEGACY_CREAR_ARTICULOS_SIN_CATALOGO)->count());
    }

    public function test_sin_equivalencias_confirmadas_no_hace_nada(): void
    {
        AlmacenEquivalencia::query()->delete();
        $this->articulo('SOFA-SIN-EQ-' . $this->cod);

        $this->assertSame(0, $this->correr(['--apply' => true, '--operador' => 'tests']));
        $this->assertSame(0, CatalogProduct::query()->where('reference', 'like', 'SOFA-SIN-EQ-%')->count());
    }
}
