<?php

declare(strict_types=1);

namespace Tests\Feature\Bodega;

use App\Models\User;
use App\Modules\Bodega\Models\GeneralCountRequest;
use App\Modules\Bodega\Models\GeneralCountRequestLine;
use App\Modules\Bodega\Models\Warehouse;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Shared\Multitenancy\Tenant;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-800 — cubre `bodega:backfill-general-count-replaced-lines`: marca como reemplazadas las
 * líneas de conteo general huérfanas dejadas por el bug de `keyBy()` en
 * `GeneralCountController::findConflictingLines()` (ver fix en ese controller).
 */
class BodegaBackfillGeneralCountReplacedLinesCommandTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'illuminations')->first()?->makeCurrent();
    }

    private function reMakeCurrent(): void
    {
        Tenant::where('slug', 'illuminations')->first()?->makeCurrent();
    }

    private function makeProduct(): CatalogProduct
    {
        return CatalogProduct::create([
            'reference' => 'REF-'.uniqid(), 'name' => 'Producto de prueba', 'description' => 'Producto de prueba',
            'price_full' => 100, 'cost' => 60, 'stock_quantity' => 0, 'is_active' => true,
        ]);
    }

    public function test_marca_todas_las_lineas_huerfanas_del_mismo_producto_y_bodega(): void
    {
        $central = Warehouse::where('name', 'Bodega Central')->first();
        $product = $this->makeProduct();
        $designerId = User::where('email', 'designer@illuminations.test')->first()->id;

        $r1 = GeneralCountRequest::create(['warehouse_id' => $central->id, 'realizado_por' => $designerId, 'estado' => GeneralCountRequest::ESTADO_EVALUADO]);
        $l1 = GeneralCountRequestLine::create(['general_count_request_id' => $r1->id, 'catalog_product_id' => $product->id, 'cantidad_sistema' => 10, 'cantidad_contada' => 8, 'diferencia' => -2]);

        $r2 = GeneralCountRequest::create(['warehouse_id' => $central->id, 'realizado_por' => $designerId, 'estado' => GeneralCountRequest::ESTADO_PENDIENTE_APROBACION]);
        $l2 = GeneralCountRequestLine::create(['general_count_request_id' => $r2->id, 'catalog_product_id' => $product->id, 'cantidad_sistema' => 10, 'cantidad_contada' => 9, 'diferencia' => -1]);

        // La línea más reciente sigue sin resolver con diferencia real — bloqueo legítimo vigente,
        // el backfill NUNCA debe tocarla (no puede reemplazarse a sí misma).
        $r3 = GeneralCountRequest::create(['warehouse_id' => $central->id, 'realizado_por' => $designerId, 'estado' => GeneralCountRequest::ESTADO_EVALUADO]);
        $l3 = GeneralCountRequestLine::create(['general_count_request_id' => $r3->id, 'catalog_product_id' => $product->id, 'cantidad_sistema' => 10, 'cantidad_contada' => 7, 'diferencia' => -3]);

        $this->artisan('bodega:backfill-general-count-replaced-lines', ['--tenant' => 'illuminations'])
            ->assertExitCode(0);

        $this->reMakeCurrent();
        $this->assertSame($l3->id, $l1->fresh()->replaced_by_id);
        $this->assertSame($l3->id, $l2->fresh()->replaced_by_id);
        $this->assertNull($l3->fresh()->replaced_by_id);
    }

    public function test_no_toca_lineas_sin_diferencia_real_ni_lineas_ya_reemplazadas(): void
    {
        $central = Warehouse::where('name', 'Bodega Central')->first();
        $productSinDiferencia = $this->makeProduct();
        $productYaReemplazado = $this->makeProduct();
        $designerId = User::where('email', 'designer@illuminations.test')->first()->id;

        $r1 = GeneralCountRequest::create(['warehouse_id' => $central->id, 'realizado_por' => $designerId, 'estado' => GeneralCountRequest::ESTADO_EVALUADO]);
        $sinDiferencia = GeneralCountRequestLine::create(['general_count_request_id' => $r1->id, 'catalog_product_id' => $productSinDiferencia->id, 'cantidad_sistema' => 10, 'cantidad_contada' => 10, 'diferencia' => 0]);
        $r2 = GeneralCountRequest::create(['warehouse_id' => $central->id, 'realizado_por' => $designerId, 'estado' => GeneralCountRequest::ESTADO_EVALUADO]);
        GeneralCountRequestLine::create(['general_count_request_id' => $r2->id, 'catalog_product_id' => $productSinDiferencia->id, 'cantidad_sistema' => 10, 'cantidad_contada' => 10, 'diferencia' => 0]);

        $r3 = GeneralCountRequest::create(['warehouse_id' => $central->id, 'realizado_por' => $designerId, 'estado' => GeneralCountRequest::ESTADO_PENDIENTE_APROBACION]);
        $r4 = GeneralCountRequest::create(['warehouse_id' => $central->id, 'realizado_por' => $designerId, 'estado' => GeneralCountRequest::ESTADO_EVALUADO]);
        $yaReemplazadoNuevaLinea = GeneralCountRequestLine::create(['general_count_request_id' => $r4->id, 'catalog_product_id' => $productYaReemplazado->id, 'cantidad_sistema' => 10, 'cantidad_contada' => 6, 'diferencia' => -4]);
        $yaReemplazado = GeneralCountRequestLine::create(['general_count_request_id' => $r3->id, 'catalog_product_id' => $productYaReemplazado->id, 'cantidad_sistema' => 10, 'cantidad_contada' => 6, 'diferencia' => -4, 'replaced_by_id' => $yaReemplazadoNuevaLinea->id]);

        $this->artisan('bodega:backfill-general-count-replaced-lines', ['--tenant' => 'illuminations'])
            ->assertExitCode(0);

        $this->reMakeCurrent();
        $this->assertNull($sinDiferencia->fresh()->replaced_by_id);
        $this->assertSame($yaReemplazadoNuevaLinea->id, $yaReemplazado->fresh()->replaced_by_id);
    }

    public function test_dry_run_no_escribe_nada(): void
    {
        $central = Warehouse::where('name', 'Bodega Central')->first();
        $product = $this->makeProduct();
        $designerId = User::where('email', 'designer@illuminations.test')->first()->id;

        $r1 = GeneralCountRequest::create(['warehouse_id' => $central->id, 'realizado_por' => $designerId, 'estado' => GeneralCountRequest::ESTADO_EVALUADO]);
        $l1 = GeneralCountRequestLine::create(['general_count_request_id' => $r1->id, 'catalog_product_id' => $product->id, 'cantidad_sistema' => 10, 'cantidad_contada' => 8, 'diferencia' => -2]);
        $r2 = GeneralCountRequest::create(['warehouse_id' => $central->id, 'realizado_por' => $designerId, 'estado' => GeneralCountRequest::ESTADO_EVALUADO]);
        GeneralCountRequestLine::create(['general_count_request_id' => $r2->id, 'catalog_product_id' => $product->id, 'cantidad_sistema' => 10, 'cantidad_contada' => 7, 'diferencia' => -3]);

        $this->artisan('bodega:backfill-general-count-replaced-lines', ['--tenant' => 'illuminations', '--dry-run' => true])
            ->assertExitCode(0);

        $this->reMakeCurrent();
        $this->assertNull($l1->fresh()->replaced_by_id);
    }
}
