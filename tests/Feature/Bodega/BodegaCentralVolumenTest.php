<?php

declare(strict_types=1);

namespace Tests\Feature\Bodega;

use App\Modules\Bodega\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Bodega\Concerns\BodegaOrderTestHelpers;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-1016 (M3) — tras `legacy:backfill-stock-bodega-central` el listado de Bodega Central pasa de ~28 a ~3.300 filas. Dataset sintético de ~3.500 filas
 * (positivas y negativas) para comprobar que `GET /bodega/warehouses/{Central}` se mantiene dentro de lo razonable (objetivo de producción p95 < 500 ms y
 * pico < 64 MB; la suite corre con margen en tiempo, el pico de memoria se exige tal cual). Medir de nuevo en dev (que ya tiene ~3.368 filas) antes de promover.
 */
final class BodegaCentralVolumenTest extends FeatureTestCase
{
    use BodegaOrderTestHelpers;

    public function test_el_listado_de_bodega_central_con_3500_filas_cumple_los_presupuestos(): void
    {
        $this->makeCurrentTenant();
        $central = (int) Warehouse::query()->where('name', 'Bodega Central')->value('id');
        DB::statement("INSERT INTO atlantic_ventas_diseno.catalog_products (reference, name, price_full, is_active, stock_quantity, created_at, updated_at)
            SELECT 'VOL-' || g, 'Producto volumen ' || g, 10, true, CASE WHEN g % 10 = 0 THEN -g ELSE g END, now(), now() FROM generate_series(1, 3500) g");
        DB::statement("INSERT INTO atlantic_bodega.product_warehouse_stock (catalog_product_id, warehouse_id, quantity, created_at, updated_at)
            SELECT id, {$central}, stock_quantity, now(), now() FROM atlantic_ventas_diseno.catalog_products WHERE reference LIKE 'VOL-%'");

        $this->grantBodegaPermissions('designer@atlantic.test');
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $url = "/api/bodega/warehouses/{$central}";
        $this->withHeaders($this->authHeaders($token))->getJson($url)->assertOk(); // calienta

        gc_collect_cycles();
        memory_reset_peak_usage();
        $base = memory_get_usage(true);
        $ms = [];
        foreach (['' => '', 'con search' => '?search=producto', 'pagina 20' => '?page=20&per_page=100', 'search sin resultados' => '?search=zzzzqqq'] as $nombre => $q) {
            $t0 = microtime(true);
            $r = $this->withHeaders($this->authHeaders($token))->getJson($url . $q)->assertOk();
            $ms[$nombre] = (microtime(true) - $t0) * 1000;
            $this->assertLessThan(1500, $ms[$nombre], "'{$nombre}' tardó {$ms[$nombre]} ms");
            if ($q === '') {
                $this->assertSame(3150, $r->json('meta.total'), 'solo las filas con quantity > 0 (los negativos quedan fuera del listado)');
            }
        }
        $picoMb = (memory_get_peak_usage(true) - $base) / 1048576;
        $this->assertLessThan(64, $picoMb, sprintf('pico de memoria %.1f MB (tiempos ms: %s)', $picoMb, json_encode(array_map('round', $ms))));
    }
}
