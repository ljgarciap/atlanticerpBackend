<?php

declare(strict_types=1);

namespace Tests\Feature\Servicios;

use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Bodega\Concerns\BodegaOrderTestHelpers;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-996 (auditoría SCRUM-995, sitio B) — `GET /api/servicios/lookup/products` (picker de producto de Tickets,
 * Insumos y del ítem Producto de Cotización) hidrataba el catálogo activo completo con `->get()->all()` y aplicaba
 * Levenshtein en PHP; sin `search` devolvía TODOS los productos (4 MB al navegador con ~30K). Ahora la búsqueda es
 * SQL (`search_text`, índice trigram) y hay un tope de resultados (decisión de Luis, 2026-09-29).
 */
class ProductLookupSqlTest extends FeatureTestCase
{
    use BodegaOrderTestHelpers;

    private const LIDER_SERVICIOS = 'liderservicios@test.com';

    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return $this->authHeaders($this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios'));
    }

    /** @return array<int, string> */
    private function refs(string $query = ''): array
    {
        $response = $this->withHeaders($this->headers())
            ->getJson('/api/servicios/lookup/products' . ($query !== '' ? "?{$query}" : ''))
            ->assertStatus(200);

        return array_column($response->json('data'), 'reference');
    }

    /** Cuenta los `CatalogProduct` hidratados mientras corre `$fn` (variables propias por llamada: no se pisan entre iteraciones). */
    private function countRetrieved(callable $fn): int
    {
        $hydrated = 0;
        $active = true;
        CatalogProduct::retrieved(function () use (&$hydrated, &$active): void {
            if ($active) {
                $hydrated++;
            }
        });
        try {
            $fn();
        } finally {
            $active = false;
        }

        return $hydrated;
    }

    public function test_busca_sin_distinguir_acentos_ni_mayusculas_y_da_prioridad_a_la_coincidencia_exacta(): void
    {
        $this->makeCatalogProduct(['reference' => 'LUZ-COL-1', 'name' => 'Producto colgante luz']);
        $exact = $this->makeCatalogProduct(['reference' => 'LUZ', 'name' => 'Otro producto']);
        $this->makeCatalogProduct(['reference' => 'SIN-RELACION', 'name' => 'Riel magnético']);

        $refs = $this->refs('search=LUZ');

        $this->assertSame($exact->reference, $refs[0], 'la coincidencia exacta de referencia va primero');
        $this->assertContains('LUZ-COL-1', $refs);
        $this->assertNotContains('SIN-RELACION', $refs);

        $this->assertContains('LUZ-COL-1', $this->refs('search=' . rawurlencode('PRODUCTO COLGANTE')));
    }

    public function test_busca_por_marca_y_tolera_un_typo_solo_si_no_hay_coincidencia_parcial(): void
    {
        $this->makeCatalogProduct(['reference' => 'MARCA-1', 'name' => 'Spot', 'brand' => 'Philips']);
        $this->makeCatalogProduct(['reference' => 'TYPO-1', 'name' => 'Modelo cristal imperial']);

        $this->assertContains('MARCA-1', $this->refs('search=philips'));
        $this->assertContains('TYPO-1', $this->refs('search=cristl'));
    }

    public function test_sin_texto_devuelve_los_primeros_100_por_nombre_no_todo_el_catalogo(): void
    {
        $table = (new CatalogProduct())->getTable();
        $now = now()->toDateTimeString();
        DB::table($table)->insert(array_map(fn (int $i): array => [
            'reference' => sprintf('TOPE-%03d', $i), 'name' => sprintf('Tope %03d', $i), 'description' => 'x',
            'price_full' => 10, 'cost' => 5, 'stock_quantity' => 1, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
        ], range(1, 130)));

        $refs = $this->refs();

        $this->assertCount(100, $refs, 'tope de resultados acordado con Luis (antes devolvía todo el catálogo)');
    }

    public function test_conserva_exclude_inactivos_y_el_shape(): void
    {
        $keep = $this->makeCatalogProduct(['reference' => 'EXC-KEEP', 'name' => 'Filtro exc keep']);
        $drop = $this->makeCatalogProduct(['reference' => 'EXC-DROP', 'name' => 'Filtro exc drop']);
        $this->makeCatalogProduct(['reference' => 'EXC-OFF', 'name' => 'Filtro exc off', 'is_active' => false]);

        $response = $this->withHeaders($this->headers())
            ->getJson('/api/servicios/lookup/products?search=filtro&exclude=' . $drop->id)
            ->assertStatus(200);

        $this->assertSame(['EXC-KEEP'], array_column($response->json('data'), 'reference'));
        $this->assertSame(['id', 'reference', 'name', 'description', 'brand', 'price_full'], array_keys($response->json('data.0')));
        $this->assertNotSame($keep->id, $drop->id);
    }

    public function test_parametros_hostiles_no_dan_5xx(): void
    {
        $headers = $this->headers();
        foreach (['search[]=x', 'search=%27', 'search=%25', 'search=_', 'search=%22%3B--', 'search=%20%20', 'search=' . str_repeat('a', 3000)] as $q) {
            $status = $this->withHeaders($headers)->getJson("/api/servicios/lookup/products?{$q}")->getStatusCode();
            $this->assertLessThan(500, $status, "search hostil: {$q}");
        }
    }

    public function test_no_hidrata_mas_que_el_tope_con_un_catalogo_grande(): void
    {
        $table = (new CatalogProduct())->getTable();
        $now = now()->toDateTimeString();
        foreach (array_chunk(range(1, 5000), 500) as $chunk) {
            DB::table($table)->insert(array_map(fn (int $i): array => [
                'reference' => 'BULK996S-' . uniqid() . "-{$i}", 'name' => "Bulk producto {$i}", 'description' => "Bulk {$i}",
                'price_full' => 10, 'cost' => 5, 'stock_quantity' => 1, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
            ], $chunk));
        }
        $headers = $this->headers();

        foreach (['', 'search=producto', 'search=zzzqqq'] as $q) {
            memory_reset_peak_usage();
            $before = memory_get_peak_usage(false);
            $response = null;
            $hydrated = $this->countRetrieved(function () use (&$response, $headers, $q): void {
                $response = $this->withHeaders($headers)->getJson('/api/servicios/lookup/products' . ($q !== '' ? "?{$q}" : ''));
            });
            $peakMb = (memory_get_peak_usage(false) - $before) / 1048576;

            $response->assertStatus(200);
            $this->assertLessThanOrEqual(100, $hydrated, "no debe hidratar más que el tope ({$q})");
            $this->assertLessThan(64, $peakMb, "pico de memoria < 64 MB ({$q})");
        }
    }
}
