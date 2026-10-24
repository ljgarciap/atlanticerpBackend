<?php

declare(strict_types=1);

namespace Tests\Feature\Compras;

use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Shared\Multitenancy\Tenant;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-992 (Pre-QA H8, preexistente) — un parámetro de query de tipo array (`?search[]=x`) hacía
 * `(string) $request->query(...)` y terminaba en 500 "Array to string conversion". Convención única
 * (`App\Shared\Http\QueryParams`): un parámetro no escalar se trata como AUSENTE. Este test lo
 * verifica en los 4 buscadores de catálogo de SCRUM-992 más `check-reference`.
 *
 * H6 (`page` gigante) se verifica en cada uno: 200, sin filas de la página 1 ni `current_page` absurdo.
 */
class SearchQueryParamRobustnessTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();

        foreach (range(1, 25) as $i) {
            CatalogProduct::create([
                'reference' => sprintf('ROB-%02d', $i), 'name' => "Producto robustez {$i}", 'description' => 'x',
                'price_full' => 10, 'stock_quantity' => 5, 'is_active' => true,
            ]);
        }
    }

    /** @return array<string, array{0: string}> */
    public static function endpoints(): array
    {
        return [
            'inventario de Compras'    => ['/api/compras/inventory'],
            'Nueva Orden (products)'   => ['/api/compras/products'],
            'buscador Ventas&Diseño'   => ['/api/ventas-diseno/catalog-products'],
            'inventario de Bodega'     => ['/api/bodega/inventory'],
            'check-reference'          => ['/api/compras/inventory/check-reference'],
        ];
    }

    private function token(): string
    {
        return $this->loginAs('superadmin@atlantic.test', 'Password123!');
    }

    #[DataProvider('endpoints')]
    public function test_parametros_array_no_dan_500_y_equivalen_a_no_mandarlos(string $path): void
    {
        $token = $this->token();

        $sinParams = $this->withHeaders($this->authHeaders($token))->getJson($path)->assertStatus(200);
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();

        foreach (['search', 'chip', 'reference', 'per_page', 'page', 'sort_by', 'sort_dir', 'exclude_id'] as $param) {
            $res = $this->withHeaders($this->authHeaders($token))->getJson("{$path}?{$param}[]=x&{$param}[]=y");
            Tenant::where('slug', 'atlantic')->first()?->makeCurrent();

            $this->assertSame(200, $res->getStatusCode(), "{$path}?{$param}[]=... devolvió {$res->getStatusCode()}");
            $this->assertSame($sinParams->json('data'), $res->json('data'), "{$path}?{$param}[]=... debe equivaler a no mandar {$param}");
        }
    }

    #[DataProvider('endpoints')]
    public function test_page_gigante_no_devuelve_filas_de_la_pagina_1(string $path): void
    {
        if (str_contains($path, 'check-reference')) {
            $this->markTestSkipped('sin paginación');
        }

        $res = $this->withHeaders($this->authHeaders($this->token()))
            ->getJson("{$path}?page=9223372036854775808&per_page=10");

        $this->assertSame(200, $res->getStatusCode());
        if ($res->json('meta') !== null) {
            $this->assertSame([], $res->json('data'), 'antes devolvía las filas de la página 1');
            $this->assertLessThanOrEqual(1_000_000, $res->json('meta.current_page'));
        }
    }
}
