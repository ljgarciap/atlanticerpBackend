<?php

declare(strict_types=1);

namespace Tests\Feature\VentasDiseno;

use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-992 — `CatalogProduct::applySearch()`/`orderByRelevance()`/`scopeSearch()` sobre la columna
 * generada `search_text` (pg_trgm + unaccent). Spec: docs/specs/catalog-search-performance-trgm.md.
 */
class CatalogProductSearchScopeTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    /** @param array<string, mixed> $o */
    private function product(string $reference, string $name, array $o = []): CatalogProduct
    {
        return CatalogProduct::create([
            'reference' => $reference, 'name' => $name, 'description' => $name,
            'price_full' => 10, 'is_active' => true, ...$o,
        ]);
    }

    /** @return list<string> */
    private function refs(string $term, bool $fallback = false, ?bool &$usedFallback = null): array
    {
        $q = CatalogProduct::query();
        $usedFallback = CatalogProduct::applySearch($q, $term, $fallback);

        return $q->orderBy('reference')->limit(50)->pluck('reference')->all();
    }

    private function searchText(int $id): string
    {
        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');

        return (string) DB::table("{$slug}_ventas_diseno.catalog_products")->where('id', $id)->value('search_text');
    }

    public function test_busqueda_ignora_acentos_y_mayusculas(): void
    {
        $this->product('AC-1', 'Vástago Colgante Catalogación');

        $this->assertSame(['AC-1'], $this->refs('vastago'));
        $this->assertSame(['AC-1'], $this->refs('CATALOGACION'));
        $this->assertSame(['AC-1'], $this->refs('Vástago'));
        $this->assertSame(['AC-1'], $this->refs('catalogación colgante'));
    }

    public function test_busca_en_referencia_referencia_de_fabrica_nombre_y_marca(): void
    {
        $this->product('REF-77', 'Nada', ['factory_reference' => 'FAB-9X', 'brand' => 'Philips']);

        foreach (['ref-77', 'fab-9x', 'nada', 'philips'] as $term) {
            $this->assertSame(['REF-77'], $this->refs($term), $term);
        }
    }

    public function test_todas_las_palabras_deben_aparecer_en_cualquier_orden(): void
    {
        $this->product('W-1', 'Vástago colgante negra');
        $this->product('W-2', 'Vástago de mesa');

        $this->assertSame(['W-1'], $this->refs('negra vastago'));
        $this->assertSame(['W-1', 'W-2'], $this->refs('vastago'));
        $this->assertSame([], $this->refs('vastago azul'));
    }

    public function test_los_comodines_del_termino_se_escapan(): void
    {
        $this->product('P-1', 'Panel 100% XL');
        $this->product('P-2', 'Panel 1000 XL');

        $this->assertSame(['P-1'], $this->refs('100%'));
        $this->assertSame([], $this->refs('%%%'));
    }

    public function test_termino_corto_degrada_a_prefijo_de_palabra(): void
    {
        $this->product('S-1', 'Panel XL');
        $this->product('S-2', 'Vastago');
        $this->product('S-3', 'Par 30');

        // "pa" = prefijo de "Panel" y "Par", pero NO está al inicio de ninguna palabra de "Vastago".
        $this->assertSame(['S-1', 'S-3'], $this->refs('pa'));
    }

    public function test_termino_vacio_no_aplica_ningun_filtro(): void
    {
        $this->product('E-1', 'Uno');
        $q = CatalogProduct::query();

        $this->assertFalse(CatalogProduct::applySearch($q, '   ', true));
        $this->assertGreaterThanOrEqual(1, $q->count());
    }

    public function test_respaldo_tolerante_a_typos_solo_cuando_la_parcial_da_cero(): void
    {
        $this->product('F-1', 'Catalogación exterior');

        // Parcial con resultados: nunca usa el respaldo, aunque se pida.
        $this->assertSame(['F-1'], $this->refs('catalo', true, $used));
        $this->assertFalse($used);

        // Typo: la parcial da 0 -> con respaldo lo encuentra y avisa; sin respaldo, vacío.
        $this->assertSame(['F-1'], $this->refs('catalogacon', true, $used));
        $this->assertTrue($used);

        $this->assertSame([], $this->refs('catalogacon', false, $used));
        $this->assertFalse($used);
    }

    public function test_respaldo_no_devuelve_productos_sin_relacion(): void
    {
        $this->product('N-1', 'Catalogación exterior');

        $this->assertSame([], $this->refs('zzqqxx', true));
    }

    public function test_orden_de_relevancia_exacta_luego_prefijo_luego_resto(): void
    {
        $this->product('XL-100', 'Reflector');
        $this->product('AAA-1', 'Panel XL-10 grande');
        $this->product('XL-10', 'Panel');
        $this->product('XL-10B', 'Otro');

        $q = CatalogProduct::query();
        CatalogProduct::applySearch($q, 'xl-10');
        CatalogProduct::orderByRelevance($q, 'xl-10');
        $refs = $q->orderBy('name')->orderBy('id')->pluck('reference')->all();

        $this->assertSame('XL-10', $refs[0], 'coincidencia exacta primero');
        $this->assertSame(['XL-10B', 'XL-100'], array_slice($refs, 1, 2), 'luego prefijo de referencia');
        $this->assertSame('AAA-1', $refs[3], 'resto al final');
    }

    public function test_scope_search_encadena_con_limit(): void
    {
        foreach (range(1, 8) as $i) {
            $this->product("L-{$i}", 'Vástago mesa');
        }

        $this->assertCount(3, CatalogProduct::query()->search('vastago')->limit(3)->get());
    }

    public function test_search_text_se_mantiene_sola_al_insertar_y_editar(): void
    {
        $p = $this->product('MNT-1', 'Vástago', ['brand' => 'Ñandú']);
        $this->assertSame('mnt-1  vastago nandu', $this->searchText($p->id));

        $p->update(['name' => 'Aplique Cálido', 'factory_reference' => 'FÁB-2']);
        $this->assertSame('mnt-1 fab-2 aplique calido nandu', $this->searchText($p->id));
        $this->assertSame(['MNT-1'], $this->refs('aplique calido'));
        $this->assertSame([], $this->refs('vastago'));
    }

    public function test_el_indice_trigram_se_usa_con_un_catalogo_de_30k(): void
    {
        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');
        $table = "{$slug}_ventas_diseno.catalog_products";
        DB::statement("
            INSERT INTO {$table} (reference, name, description, price_full, is_active, created_at, updated_at)
            SELECT 'SYN-' || g, 'Producto sintético ' || (g % 977) || ' Vástago', 'x', 10, true, now(), now()
            FROM generate_series(1, 30000) g
        ");
        DB::statement("ANALYZE {$table}");
        // Con 30K filas el planner puede preferir Seq Scan por costo; lo que se prueba acá es que el
        // índice sea APLICABLE a la expresión que genera `applySearch()` (LIKE '%x%' sobre search_text).
        DB::statement('SET LOCAL enable_seqscan = off');

        $q = CatalogProduct::query();
        CatalogProduct::applySearch($q, 'syn-2999');
        $plan = collect(DB::select('EXPLAIN ' . $q->limit(20)->toSql(), $q->getBindings()))
            ->map(fn ($r) => (string) array_values((array) $r)[0])->implode("\n");

        $this->assertStringContainsString('catalog_products_search_text_trgm_idx', $plan);
        $this->assertStringContainsString('Bitmap Index Scan', $plan);
    }

    // ── SCRUM-992 (Pre-QA H3) — reglas del respaldo tolerante a typos ────────────────

    public function test_fuzzy_words_aplica_las_reglas_de_letras_y_digitos(): void
    {
        // símbolos separan palabras y nunca cuentan; menos de 4 letras/dígitos se ignoran
        $this->assertSame([], CatalogProduct::fuzzyWords('100%'));
        $this->assertSame([], CatalogProduct::fuzzyWords('x%y'));
        $this->assertSame([], CatalogProduct::fuzzyWords('%%%'));
        $this->assertSame([], CatalogProduct::fuzzyWords('___'));
        $this->assertSame([], CatalogProduct::fuzzyWords('  -.-  '));
        $this->assertSame([], CatalogProduct::fuzzyWords('de'));
        $this->assertSame(['vastgo'], CatalogProduct::fuzzyWords('vastgo de'));
        $this->assertSame(['vastgo', 'colgnte'], CatalogProduct::fuzzyWords('vastgo% colgnte'));
        $this->assertSame(['ab12', 'zzzz'], CatalogProduct::fuzzyWords('ab12-zzzz'));
        // una palabra de más de 30 caracteres es ruido: sin respaldo
        $this->assertSame([], CatalogProduct::fuzzyWords(str_repeat('a', 39)));
        $this->assertSame([], CatalogProduct::fuzzyWords('vastago ' . str_repeat('a', 31)));
        $this->assertCount(5, CatalogProduct::fuzzyWords('uno1 dos2 tres3 cuat4 cinc5 seis6 siet7'));
    }

    /** @return list<string> refs devueltas por el respaldo y si se usó */
    private function fallbackRefs(string $term, ?bool &$used = null): array
    {
        return $this->refs($term, true, $used);
    }

    public function test_el_respaldo_no_se_activa_con_terminos_sin_letras_suficientes(): void
    {
        foreach (range(1, 12) as $i) {
            $this->product("100-{$i}", "Riel 100 tipo {$i}");
        }
        $this->product('XY-1', 'Conector x y');

        // Sin coincidencia parcial: antes el respaldo devolvía miles de filas "aproximadas".
        foreach (['100%', 'x%y', '%%%', '___', '\\'] as $term) {
            $used = null;
            $this->assertSame([], $this->fallbackRefs($term, $used), "término [{$term}]");
            $this->assertFalse($used, "término [{$term}] no debe marcar fuzzy");
        }

        $used = null;
        $this->assertSame([], $this->fallbackRefs(str_repeat('a', 39), $used));
        $this->assertFalse($used);
    }

    public function test_el_respaldo_sigue_encontrando_typos_reales(): void
    {
        $this->product('COL-1', 'Vástago colgante nórdica');
        $this->product('COL-2', 'Aplique de pared');

        $used = null;
        $this->assertSame(['COL-1'], $this->fallbackRefs('vastgo colgnte', $used));
        $this->assertTrue($used);

        // la coincidencia parcial sigue mandando: con resultados parciales no hay respaldo
        $used = null;
        $this->assertSame(['COL-1'], $this->fallbackRefs('colgante', $used));
        $this->assertFalse($used);
    }

    public function test_el_respaldo_acota_los_candidatos(): void
    {
        foreach (range(1, CatalogProduct::SEARCH_FUZZY_MAX_CANDIDATES + 60) as $i) {
            $this->product(sprintf('CAN-%04d', $i), "Artefacto cilindrica modelo {$i}");
        }

        $q = CatalogProduct::query();
        $used = CatalogProduct::applySearch($q, 'artefcto cilindrca', true);

        $this->assertTrue($used);
        $this->assertSame(CatalogProduct::SEARCH_FUZZY_MAX_CANDIDATES, $q->count());
    }

    public function test_el_respaldo_respeta_las_restricciones_del_llamador(): void
    {
        $this->product('RES-1', 'Vástago colgante nórdica', ['is_active' => false]);
        $this->product('RES-2', 'Vástago colgante minimal');

        $q = CatalogProduct::query()->where('is_active', true);
        $used = CatalogProduct::applySearch($q, 'vastgo colgnte', true);

        $this->assertTrue($used);
        $this->assertSame(['RES-2'], $q->pluck('reference')->all());

        // sin candidatos dentro de las restricciones: 0 filas y fuzzy=false
        $q2 = CatalogProduct::query()->where('reference', 'NO-EXISTE');
        $this->assertFalse(CatalogProduct::applySearch($q2, 'vastgo colgnte', true));
        $this->assertSame(0, $q2->count());
    }

    // ── SCRUM-992 (Pre-QA ronda 3, H18) — el respaldo de typos usa el operador `<%` (indexable) ─────────

    private function seedFuzzyDataset(): void
    {
        foreach ([
            'Vástago colgante nórdica', 'Pendiente cristal', 'Modelo dorado', 'Plafón redondo blanco',
            'Panel XL cuadrado', 'Riel direccionable', 'Aplique de pared', 'Spot empotrable', 'Farola exterior',
        ] as $i => $name) {
            $this->product("FZ-{$i}", $name);
        }
        // >200 productos parecidos a "catalogación": ejercita el tope de candidatos (200) y su desempate.
        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');
        DB::statement("
            INSERT INTO {$slug}_ventas_diseno.catalog_products (reference, name, description, price_full, is_active, created_at, updated_at)
            SELECT 'ILU-' || lpad(g::text, 4, '0'), 'Catalogación nórdica ' || g, 'x', 10, true, now(), now()
            FROM generate_series(1, 260) g
        ");
    }

    /** Oráculo con la FUNCIÓN `word_similarity() >= umbral` (la implementación anterior), ordenada como el servicio. @return list<int> */
    private function fuzzyOracleIds(string $term): array
    {
        $words = CatalogProduct::fuzzyWords($term);
        if ($words === []) {
            return [];
        }
        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');
        $where = implode(' AND ', array_fill(0, count($words), 'public.word_similarity(public.f_unaccent(lower(?)), search_text) >= 0.5'));
        $score = implode(' + ', array_fill(0, count($words), 'public.word_similarity(public.f_unaccent(lower(?)), search_text)'));
        $rows = DB::select(
            "SELECT id FROM {$slug}_ventas_diseno.catalog_products WHERE {$where} ORDER BY ({$score}) DESC, id LIMIT 200",
            [...$words, ...$words],
        );

        return array_map(static fn (object $r): int => (int) $r->id, $rows);
    }

    /** @return array<string, array{0: string}> */
    public static function fuzzyParityTerms(): array
    {
        return [
            'catalgacion (tope de 200)' => ['catalgacion'],
            'vastgo colgnte'           => ['vastgo colgnte'],
            'pendnte'                  => ['pendnte'],
            'artefcto'                => ['artefcto'],
            'plafn'                    => ['plafn'],
            'pnel xl'                 => ['pnel xl'],
            'catalgacion xl'           => ['catalgacion xl'],
            'con acento y typo'        => ['catalgación'],
            'porcentaje'               => ['100%'],
            'x%y'                      => ['x%y'],
            'palabra corta'            => ['de'],
            'palabra de 31 letras'     => ['catalogacioncatalogacioncatalo'],
            '6 palabras'               => ['uno dos tres cuatro cinco seis'],
            'solo símbolos'            => ['%%% ___ ---'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('fuzzyParityTerms')]
    public function test_el_respaldo_con_operador_da_los_mismos_ids_que_word_similarity_h18(string $term): void
    {
        $this->seedFuzzyDataset();
        $expected = $this->fuzzyOracleIds($term);

        $q = CatalogProduct::query();
        $fuzzy = CatalogProduct::applySearch($q, $term, true);
        $partial = CatalogProduct::query();
        CatalogProduct::applySearch($partial, $term, false);

        if ($partial->exists()) {
            $this->assertFalse($fuzzy, "'{$term}' tiene coincidencia parcial: no debe usar el respaldo");

            return;
        }

        $got = $q->orderBy('id')->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        sort($expected);
        $this->assertSame($expected, $got, "'{$term}': el operador <% debe dar los mismos ids que word_similarity() >= 0.5");
        $this->assertSame($expected !== [], $fuzzy);
        $this->assertLessThanOrEqual(CatalogProduct::SEARCH_FUZZY_MAX_CANDIDATES, count($got));
    }

    public function test_el_respaldo_no_deja_cambiados_los_ajustes_de_sesion_h18(): void
    {
        $this->seedFuzzyDataset();
        $threshold = static fn (): string => (string) (DB::selectOne("SELECT current_setting('pg_trgm.word_similarity_threshold', true) AS v")->v ?? '0.6');
        $pageCost = static fn (): string => (string) DB::selectOne("SELECT current_setting('random_page_cost') AS v")->v;
        $before = [$threshold(), $pageCost()];

        // Dentro de la ejecución del respaldo SÍ rigen los valores del respaldo…
        $inside = CatalogProduct::withFuzzySettings(DB::connection(), static fn (): array => [$threshold(), $pageCost()]);
        $this->assertSame(['0.5', '1.1'], $inside);
        // …y después vuelven a los anteriores (aquí hay una transacción externa: la del propio test).
        $this->assertSame($before, [$threshold(), $pageCost()]);

        // También tras una búsqueda real con respaldo…
        $q = CatalogProduct::query();
        $this->assertTrue(CatalogProduct::applySearch($q, 'catalgacion', true));
        $this->assertSame($before, [$threshold(), $pageCost()]);

        // …y tras un error dentro de la consulta (savepoint revertido).
        try {
            CatalogProduct::withFuzzySettings(DB::connection(), static function (): void {
                DB::select('SELECT 1 / 0');
            });
            $this->fail('debía propagar el error de la consulta');
        } catch (\Illuminate\Database\QueryException) {
            // esperado
        }
        $this->assertSame($before, [$threshold(), $pageCost()]);
    }

    public function test_la_consulta_del_respaldo_usa_el_indice_trigram_con_el_planner_por_defecto_h18(): void
    {
        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');
        $table = "{$slug}_ventas_diseno.catalog_products";
        DB::statement("
            INSERT INTO {$table} (reference, name, description, price_full, is_active, created_at, updated_at)
            SELECT 'SYN-' || g,
                   (ARRAY['Catalogación','Vástago','Pendiente','Modelo','Riel','Spot','Aplique','Empotrable','Exterior','Cilindro'])[1 + g % 10]
                   || ' ' || (ARRAY['XL','Nordic','Cálido','Frío','Negro','Blanco'])[1 + (g / 10) % 6] || ' ' || (g % 977) || 'W',
                   'x', 10, true, now(), now()
            FROM generate_series(1, 30000) g
        ");
        DB::statement("ANALYZE {$table}");

        // Se captura la consulta REAL de candidatos que arma applySearch() (no una copia a mano).
        $captured = null;
        DB::listen(function ($query) use (&$captured): void {
            if (str_contains($query->sql, 'OPERATOR(public.<%)')) {
                $captured = [$query->sql, $query->bindings];
            }
        });
        $q = CatalogProduct::query();
        $this->assertTrue(CatalogProduct::applySearch($q, 'catalgacion', true));
        $this->assertNotNull($captured, 'applySearch debe usar el operador <% en la consulta de candidatos');

        // Sin tocar enable_seqscan: el plan por defecto + los ajustes del respaldo deben elegir el índice.
        [$sql, $bindings] = $captured;
        $plan = CatalogProduct::withFuzzySettings(DB::connection(), static fn (): string => collect(DB::select('EXPLAIN ' . $sql, $bindings))
            ->map(static fn ($r) => (string) array_values((array) $r)[0])->implode("\n"));

        $this->assertStringContainsString('Bitmap Index Scan', $plan);
        $this->assertStringContainsString('catalog_products_search_text_trgm_idx', $plan);
        $this->assertStringNotContainsString('Seq Scan', $plan);
    }
}
