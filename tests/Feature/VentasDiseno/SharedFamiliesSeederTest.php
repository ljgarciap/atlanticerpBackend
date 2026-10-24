<?php

declare(strict_types=1);

namespace Tests\Feature\VentasDiseno;

use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Modules\VentasDiseno\Models\CatalogProductFamily;
use App\Shared\Multitenancy\Tenant;
use Database\Seeders\SharedFamiliesSeeder;
use Tests\Feature\FeatureTestCase;

/** SCRUM-1010 — familias compartidas: idempotencia, enlace por referencia y no tocar lo existente. */
class SharedFamiliesSeederTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    private function makeProduct(string $reference): CatalogProduct
    {
        return CatalogProduct::create([
            'reference' => $reference, 'name' => $reference, 'description' => $reference,
            'price_full' => 20, 'cost' => 12, 'stock_quantity' => 1, 'is_active' => true,
        ]);
    }

    private function runSeeder(): void
    {
        (new SharedFamiliesSeeder())->run();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    public function test_crea_las_tres_familias_con_los_nombres_del_excel(): void
    {
        $this->runSeeder();

        foreach (array_keys(SharedFamiliesSeeder::FAMILIES) as $name) {
            $this->assertSame(1, CatalogProductFamily::where('name', $name)->where('is_active', true)->count(), $name);
        }
        $this->assertSame(3, count(SharedFamiliesSeeder::FAMILIES));
    }

    public function test_es_idempotente_y_no_duplica_familias_ni_enlaces(): void
    {
        $product = $this->makeProduct('ML-27129A-1M');

        $this->runSeeder();
        $this->runSeeder();

        $this->assertSame(3, CatalogProductFamily::count());
        $family = CatalogProductFamily::where('name', 'Mylight Riel Magnético Mini')->firstOrFail();
        $this->assertSame([$product->id], $family->products()->pluck('catalog_products.id')->all());
    }

    public function test_no_duplica_una_familia_existente_con_otro_casing_ni_toca_otras(): void
    {
        $existing = CatalogProductFamily::create(['name' => 'flua luna s', 'is_active' => true]);
        $other    = CatalogProductFamily::create(['name' => 'Familia Ajena', 'is_active' => true]);
        $ownProduct = $this->makeProduct('AJENO-1');
        $other->products()->attach($ownProduct->id);
        $shared = $this->makeProduct('001680');
        $existing->products()->attach($ownProduct->id);

        $this->runSeeder();

        // 'flua luna s' (existente) + 'Familia Ajena' + las 2 familias nuevas (Mini y Normal).
        $this->assertSame(4, CatalogProductFamily::count());
        $this->assertSame('flua luna s', $existing->fresh()->name);
        $this->assertEqualsCanonicalizing([$ownProduct->id, $shared->id], $existing->products()->pluck('catalog_products.id')->all());
        $this->assertSame([$ownProduct->id], $other->products()->pluck('catalog_products.id')->all());
    }

    public function test_un_producto_puede_quedar_en_otra_familia_sin_perderla(): void
    {
        $product = $this->makeProduct('ML-27126-1M');
        $prior   = CatalogProductFamily::create(['name' => 'Familia Previa', 'is_active' => true]);
        $prior->products()->attach($product->id);

        $this->runSeeder();

        $this->assertSame(2, $product->families()->count());
    }

    public function test_una_familia_inactiva_homonima_no_se_reactiva_y_se_crea_una_activa(): void
    {
        $inactive = CatalogProductFamily::create(['name' => 'Flua Luna S', 'is_active' => false]);

        $this->runSeeder();

        $this->assertFalse($inactive->fresh()->is_active);
        $this->assertSame(1, CatalogProductFamily::where('name', 'Flua Luna S')->where('is_active', true)->count());
    }

    public function test_referencias_que_aparecen_despues_se_enlazan_en_una_segunda_corrida(): void
    {
        $first = $this->makeProduct('001680');
        $this->runSeeder();

        $late = $this->makeProduct('004568-PAB');
        $this->runSeeder();

        $family = CatalogProductFamily::where('name', 'Flua Luna S')->where('is_active', true)->firstOrFail();
        $this->assertEqualsCanonicalizing([$first->id, $late->id], $family->products()->pluck('catalog_products.id')->all());
    }

    public function test_el_typo_04569_del_excel_se_enlaza_a_la_referencia_real_004569(): void
    {
        $product = $this->makeProduct('004569-PAB');

        $this->runSeeder();

        $family = CatalogProductFamily::where('name', 'Flua Luna S')->where('is_active', true)->firstOrFail();
        $this->assertSame([$product->id], $family->products()->pluck('catalog_products.id')->all());
    }
}
