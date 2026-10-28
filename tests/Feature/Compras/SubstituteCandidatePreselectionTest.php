<?php

declare(strict_types=1);

namespace Tests\Feature\Compras;

use App\Modules\Compras\Models\ComprasSettings;
use App\Modules\Compras\Models\Provider;
use App\Modules\Compras\Services\SubstituteCandidatePreselector;
use App\Modules\Compras\Services\SubstituteSimilarityService;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-997 (auditoría SCRUM-995, sitio F; docs/specs/scrum997-substitute-candidates-cap.md) — "Buscar Sustituto" sin texto de
 * búsqueda rankeaba TODOS los productos del proveedor (Lafayette: ~26K, 3,4 s y 146 MB). Ahora `SubstituteCandidatePreselector`
 * elige en SQL los 500 mejores candidatos (categoría, precio y margen, luego el puntaje combinado con texto) y
 * `SubstituteSimilarityService::rank()` los puntúa con su fórmula exacta.
 *
 * El oráculo de paridad es `rank()` sobre TODOS los candidatos (el comportamiento de antes): los mejores deben seguir apareciendo,
 * en el mismo orden.
 */
class SubstituteCandidatePreselectionTest extends FeatureTestCase
{
    private Provider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $this->provider = Provider::create(['name' => 'Preselección Corp', 'currency' => 'USD']);
    }

    /** El cliente HTTP de pruebas resetea el tenant "actual" al terminar cada petición. */
    public function json($method, $uri, array $data = [], array $headers = [], $options = 0)
    {
        $response = parent::json($method, $uri, $data, $headers, $options);
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();

        return $response;
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');
        DB::table("{$slug}_auth.users")->where('email', 'vendedor@atlantic.test')->update(['extra_permissions' => json_encode(['compras.read'])]);

        return $this->authHeaders($this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer'));
    }

    /** @param array<string, mixed> $overrides */
    private function product(array $overrides = []): CatalogProduct
    {
        return CatalogProduct::create([
            'reference' => 'SUB-' . uniqid(), 'name' => 'Vástago colgante XL 12W 3000K', 'description' => 'Vástago colgante XL 12W 3000K',
            'price_full' => 100, 'cost' => 40, 'category' => 'VASTAGOS', 'provider_id' => $this->provider->id, 'is_active' => true,
            ...$overrides,
        ]);
    }

    /** @return array{0: array<string, mixed>, 1: list<int>} */
    private function search(CatalogProduct $original, string $extra = ''): array
    {
        $response = $this->withHeaders($this->headers())
            ->getJson("/api/compras/products?provider_id={$this->provider->id}&original_catalog_product_id={$original->id}{$extra}")
            ->assertStatus(200);

        return [$response->json(), array_column($response->json('data'), 'id')];
    }

    /** @return list<int> los ids del ranking completo de siempre (rank sobre TODOS los candidatos del proveedor), en orden */
    private function fullRanking(CatalogProduct $original, int $take): array
    {
        $all = array_values(CatalogProduct::where('is_active', true)->where('provider_id', $this->provider->id)->get()->all());
        $ranked = app(SubstituteSimilarityService::class)->rank($original, $all);

        return array_map(static fn (array $r): int => $r['product']->id, array_slice($ranked, 0, $take));
    }

    private function bulk(int $n, string $prefix, callable $attrs): void
    {
        $table = (new CatalogProduct())->getTable();
        $now = now()->toDateTimeString();
        foreach (array_chunk(range(1, $n), 500) as $chunk) {
            DB::table($table)->insert(array_map(fn (int $i): array => [
                'reference' => "{$prefix}-{$i}", 'provider_id' => $this->provider->id, 'is_active' => true,
                'created_at' => $now, 'updated_at' => $now, ...$attrs($i),
            ], $chunk));
        }
    }

    // ── Paridad con el ranking completo ──────────────────────────────────────────────────────

    public function test_los_mejores_del_ranking_completo_siguen_apareciendo_en_el_mismo_orden_con_el_tope_activo(): void
    {
        $original = $this->product(['reference' => 'ORIG-1']);
        // 700 candidatos "de relleno" (más que el tope de 500) con nombres, precios y márgenes dispares…
        $this->bulk(700, 'RELL', fn (int $i): array => [
            'name' => "Perfil de aluminio genérico {$i} " . ['negro', 'blanco', 'gris', 'plata'][$i % 4], 'description' => 'Accesorio de instalación ' . ($i % 13),
            'price_full' => 20 + ($i % 97) * 7, 'cost' => 5 + ($i % 31), 'category' => 'VASTAGOS',
        ]);
        // …y 8 sustitutos genuinos repartidos entre ellos (nombre casi igual, precio y margen cercanos).
        $good = [];
        foreach ([[100, 40, ''], [98, 39, ' B'], [103, 41, ' C'], [95, 38, ' D'], [110, 45, ' E'], [90, 36, ' F'], [105, 42, ' G'], [99, 40, ' H']] as [$price, $cost, $suffix]) {
            $good[] = $this->product(['name' => "Vástago colgante XL 12W 3000K{$suffix}", 'description' => 'Vástago colgante XL 12W 3000K', 'price_full' => $price, 'cost' => $cost])->id;
        }

        [, $ids] = $this->search($original);

        $this->assertLessThanOrEqual(SubstituteCandidatePreselector::MAX_CANDIDATES, count($ids), 'el tope de candidatos se respeta');
        $this->assertSame($this->fullRanking($original, 10), array_slice($ids, 0, 10), 'top-10 idéntico al del ranking completo de antes');
        foreach ($good as $id) {
            $this->assertContains($id, $ids, 'todos los sustitutos genuinos entran');
        }
    }

    public function test_con_mas_candidatos_que_el_pool_por_precio_y_margen_no_pierde_a_los_mejores(): void
    {
        $original = $this->product(['reference' => 'ORIG-2']);
        // 4.500 candidatos de relleno (más que POOL_BY_PRICE_MARGIN): precios muy lejos del original, así que el generador de precio/margen recorta de verdad.
        $this->bulk(4500, 'BIG', fn (int $i): array => [
            'name' => "Accesorio de montaje número {$i}", 'description' => 'Pieza ' . ($i % 50),
            'price_full' => 400 + ($i % 700), 'cost' => 300 + ($i % 90), 'category' => 'VASTAGOS',
        ]);
        $good = [];
        foreach ([[100, 40], [97, 39], [104, 42], [93, 37], [108, 44], [101, 41]] as $k => [$price, $cost]) {
            $good[] = $this->product(['name' => 'Vástago colgante XL 12W 3000K' . str_repeat('!', $k % 2), 'price_full' => $price, 'cost' => $cost])->id;
        }

        [, $ids] = $this->search($original);

        $this->assertLessThanOrEqual(SubstituteCandidatePreselector::MAX_CANDIDATES, count($ids));
        $this->assertSame($this->fullRanking($original, 6), array_slice($ids, 0, 6), 'los 6 mejores, idénticos y en el mismo orden que el ranking completo');
        foreach ($good as $id) {
            $this->assertContains($id, $ids);
        }
    }

    public function test_un_candidato_casi_identico_en_nombre_entra_aunque_su_precio_y_margen_esten_lejos(): void
    {
        // Es el fallo real medido en dev: un producto con el nombre casi idéntico al original (puesto 4 del ranking completo) quedó en el puesto
        // 6.279 por precio/margen y la preselección solo-por-precio lo dejaba fuera. 3.500 rellenos con precio y margen CERCANOS pero nombre sin
        // relación llenan el pool de precio/margen; el casi-idéntico (precio 9× el del original) solo entra por el generador de texto.
        $original = $this->product(['reference' => 'ORIG-9', 'price_full' => 100, 'cost' => 40]);
        $this->bulk(3500, 'TXT', fn (int $i): array => [
            'name' => "Accesorio de montaje número {$i}", 'description' => 'Pieza ' . ($i % 20),
            'price_full' => 130 + ($i % 5), 'cost' => 26 + ($i % 3), 'category' => 'VASTAGOS',
        ]);
        $near = $this->product(['reference' => 'CASI-1', 'name' => 'Vástago colgante XL 12W 3000K', 'price_full' => 900, 'cost' => 300])->id;

        [, $ids] = $this->search($original);

        $this->assertContains($near, $ids, 'entra por el generador de texto');
        $this->assertSame($this->fullRanking($original, 5), array_slice($ids, 0, 5), 'y el top-5 sigue idéntico al ranking completo');
        $this->assertContains($near, $this->fullRanking($original, 5), 'el escenario es válido: el casi-idéntico es de los mejores del ranking completo');
    }

    // ── Reglas heredadas de rank() ───────────────────────────────────────────────────────────

    public function test_respeta_la_regla_de_categoria_misma_categoria_si_hay(): void
    {
        $original = $this->product(['reference' => 'ORIG-3', 'category' => 'VASTAGOS']);
        $mismaCategoria = $this->product(['name' => 'Vástago colgante XL 12W 3000K B', 'category' => 'VASTAGOS'])->id;
        $otraCategoria = $this->product(['name' => 'Vástago colgante XL 12W 3000K C', 'category' => 'DRIVERS'])->id;

        [, $ids] = $this->search($original);

        $this->assertContains($mismaCategoria, $ids);
        $this->assertNotContains($otraCategoria, $ids, 'con candidatos de la misma categoría, las demás no entran (como en rank())');
    }

    public function test_sin_candidatos_de_la_categoria_amplia_a_todas_con_penalizacion(): void
    {
        $original = $this->product(['reference' => 'ORIG-4', 'category' => 'SOLA']);
        $otra = $this->product(['category' => 'DRIVERS'])->id;   // idéntico al original en texto, precio y margen: solo cambia la categoría

        [$json, $ids] = $this->search($original);

        $this->assertContains($otra, $ids);
        $this->assertSame(70.0, (float) $json['data'][0]['similarity_percent'], '100 % menos la penalización de 30 de otra categoría');
    }

    public function test_original_sin_precio_ni_categoria_no_rompe(): void
    {
        $original = $this->product(['reference' => 'ORIG-5', 'category' => null, 'price_full' => 0, 'cost' => null]);
        $this->product(['name' => 'Vástago colgante XL 12W 3000K B', 'category' => 'VASTAGOS', 'price_full' => 100, 'cost' => 40]);

        [$json] = $this->search($original);

        $this->assertArrayHasKey('data', $json);
        $this->assertSame(['fuzzy', 'data'], array_keys($json));
    }

    public function test_los_pesos_de_la_preseleccion_salen_de_la_configuracion_no_de_constantes(): void
    {
        $original = $this->product(['reference' => 'ORIG-6', 'price_full' => 100, 'cost' => 40]);
        $this->bulk(30, 'PESO', fn (int $i): array => [
            'name' => 'Vástago colgante XL 12W 3000K ' . $i, 'description' => 'x', 'price_full' => 200 + $i * 10, 'cost' => 80, 'category' => 'VASTAGOS',
        ]);
        // El más parecido en TEXTO tiene un precio lejano; el más cercano en PRECIO tiene un nombre sin relación.
        $texto = $this->product(['name' => 'Vástago colgante XL 12W 3000K', 'price_full' => 900, 'cost' => 300])->id;
        $precio = $this->product(['name' => 'Zócalo de porcelana E27', 'description' => 'zzz', 'price_full' => 101, 'cost' => 40])->id;
        ComprasSettings::current()->forceFill(['similarity_weight_text' => 0, 'similarity_weight_price' => 100, 'similarity_weight_margin' => 0, 'similarity_min_threshold' => 0])->save();

        [$json, $ids] = $this->search($original);

        $this->assertSame($precio, $ids[0], 'con todo el peso en el precio, gana el de precio más cercano');
        $this->assertNotSame($texto, $ids[0]);
        // Con todo el peso en el precio hay empates reales entre candidatos: se compara el PUNTAJE de los 5 mejores contra el ranking completo
        // (los ids empatados pueden salir en otro orden, sus porcentajes no).
        $all = array_values(CatalogProduct::where('is_active', true)->where('provider_id', $this->provider->id)->get()->all());
        $expected = array_map(static fn (array $r): float => $r['similarity_percent'], array_slice(app(SubstituteSimilarityService::class)->rank($original, $all), 0, 5));
        $this->assertSame($expected, array_map('floatval', array_slice(array_column($json['data'], 'similarity_percent'), 0, 5)), 'mismos puntajes que el ranking completo con esos pesos');
    }

    // ── Sin cambios donde no debe haberlos ───────────────────────────────────────────────────

    public function test_con_texto_de_busqueda_el_camino_no_cambia(): void
    {
        $original = $this->product(['reference' => 'ORIG-7']);
        $match = $this->product(['name' => 'Vástago colgante XL 12W 3000K clon', 'reference' => 'CLON-1'])->id;
        $this->product(['name' => 'Otro producto sin relación', 'reference' => 'OTRO-1']);

        [$json, $ids] = $this->search($original, '&search=clon');

        $this->assertSame([$match], $ids, 'con search solo entran los que coinciden con el texto');
        $this->assertArrayHasKey('similarity_percent', $json['data'][0]);
    }

    public function test_sin_sustituto_sigue_acotado_a_100_y_sin_similarity_percent(): void
    {
        $this->bulk(130, 'SIN', fn (int $i): array => ['name' => "Producto {$i}", 'description' => 'x', 'price_full' => 10, 'cost' => 5, 'category' => 'VASTAGOS']);

        $response = $this->withHeaders($this->headers())
            ->getJson("/api/compras/products?provider_id={$this->provider->id}")
            ->assertStatus(200);

        $this->assertCount(100, $response->json('data'));
        $this->assertNull($response->json('data.0.similarity_percent'), 'sin sustituto no hay porcentaje de similitud');
    }

    // ── Regresión de hidratación y memoria (el problema original) ────────────────────────────

    public function test_no_hidrata_mas_de_500_productos_ni_gasta_decenas_de_mb_con_un_proveedor_grande(): void
    {
        $original = $this->product(['reference' => 'ORIG-8']);
        $this->bulk(6000, 'HYD', fn (int $i): array => [
            'name' => "Pieza de prueba modelo {$i}", 'description' => 'Descripción ' . ($i % 40), 'price_full' => 30 + ($i % 300), 'cost' => 10 + ($i % 60), 'category' => 'VASTAGOS',
        ]);
        $headers = $this->headers();
        $hydrated = 0;
        $active = false;
        CatalogProduct::retrieved(function () use (&$hydrated, &$active): void {
            if ($active) {
                $hydrated++;
            }
        });

        memory_reset_peak_usage();
        $before = memory_get_peak_usage(false);
        $active = true;
        $response = $this->withHeaders($headers)
            ->getJson("/api/compras/products?provider_id={$this->provider->id}&original_catalog_product_id={$original->id}");
        $active = false;
        $peakMb = (memory_get_peak_usage(false) - $before) / 1048576;

        $response->assertStatus(200);
        $this->assertLessThanOrEqual(SubstituteCandidatePreselector::MAX_CANDIDATES + 1, $hydrated, 'candidatos (<= 500) + el original; nunca los 6.000');
        $this->assertLessThan(64, $peakMb, 'pico de memoria < 64 MB (antes ~146 MB con ~26K)');
        $this->assertLessThanOrEqual(SubstituteCandidatePreselector::MAX_CANDIDATES, count($response->json('data')));
    }
}
