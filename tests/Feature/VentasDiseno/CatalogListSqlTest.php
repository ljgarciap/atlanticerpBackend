<?php

declare(strict_types=1);

namespace Tests\Feature\VentasDiseno;

use App\Modules\Bodega\Models\Order;
use App\Modules\Bodega\Models\ProductWarehouseStock;
use App\Modules\Bodega\Models\Warehouse;
use App\Modules\Bodega\Services\OrderCommitmentService;
use App\Modules\Compras\Services\InventoryKpiService;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Modules\VentasDiseno\Models\CatalogProductFamily;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Bodega\Concerns\BodegaOrderTestHelpers;
use Tests\Feature\Concerns\DemoModeTestHelpers;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-992 (Pre-QA H1) — `GET /api/ventas-diseno/catalog` (`CatalogService::list`) en SQL.
 *
 * Antes cargaba los ~30K productos con `families`, 3 mapas por ID y filtraba el texto en PHP
 * (2,6–4,3 s y ~176 MB de pico). Este test compara el resultado nuevo contra la lógica anterior,
 * reproducida abajo en `legacyIds()`/`legacyDropdowns()` con los mismos servicios (`committedMap`,
 * `enBodegaExcluidaMap`, `disponibleParaVenta`), y falla si el endpoint vuelve a hidratar el
 * catálogo completo.
 *
 * Diferencias INTENCIONALES respecto a la lógica anterior, cubiertas por tests aparte:
 * el texto ignora acentos y varias palabras se buscan en cualquier orden (antes: frase contigua y
 * sensible a acentos), y el orden por nombre desempata por id.
 */
class CatalogListSqlTest extends FeatureTestCase
{
    use BodegaOrderTestHelpers;
    use DemoModeTestHelpers;

    private const EMAIL = 'designer@atlantic.test';

    /** @var array<string, CatalogProduct> */
    private array $p = [];

    private CatalogProductFamily $famA;
    private CatalogProductFamily $famB;
    private CatalogProductFamily $famEmpty;

    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();

        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');
        DB::table("{$slug}_auth.users")
            ->where('email', self::EMAIL)
            ->update(['extra_permissions' => json_encode(['ventas_diseno.read'])]);

        $this->seedDataset();
    }

    /** @param array<string, mixed> $o */
    private function make(string $key, string $name, array $o = []): CatalogProduct
    {
        return $this->p[$key] = CatalogProduct::create([
            'reference'      => strtoupper($key),
            'name'           => $name,
            'description'    => null,
            'brand'          => null,
            'price_full'     => 100,
            'stock_quantity' => 10,
            'category'       => null,
            'is_active'      => true,
            'photo_url'      => 'https://cdn.test/p.jpg',
            ...$o,
        ]);
    }

    private function seedDataset(): void
    {
        $this->famA     = CatalogProductFamily::create(['name' => 'Familia Zafiro']);
        $this->famB     = CatalogProductFamily::create(['name' => 'Andamios']);
        $this->famEmpty = CatalogProductFamily::create(['name' => 'Sin productos']);

        $this->make('p01', 'Alfa colgante', ['brand' => 'Acme', 'description' => 'techo alto', 'category' => 'candelabros_colgantes', 'stock_quantity' => 10]);
        $this->make('p02', 'Beta pared', ['factory_reference' => 'FR-XYZ', 'stock_quantity' => 0]);
        $this->make('p03', 'Gamma techo', ['category' => 'lamparas', 'stock_quantity' => 5]);       // comprometido 5 -> 0
        $this->make('p04', 'Delta piso', ['category' => 'lamparas', 'stock_quantity' => 8]);        // 8 en bodega excluida -> 0
        $this->make('p05', 'Epsilon riel', ['stock_quantity' => 20]);                                // pedido despachado: no cuenta
        $this->make('p06', 'Zeta inactivo', ['is_active' => false, 'stock_quantity' => 50]);
        $this->make('p07', 'Eta sin stock declarado', ['stock_quantity' => null]);
        $this->make('p08', 'Theta familia', ['stock_quantity' => 7]);
        $this->make('p09', 'Iota sin foto', ['photo_url' => null, 'photo_key' => null, 'stock_quantity' => 3]);
        $this->make('p10', 'Kappa gratis', ['price_full' => 0, 'stock_quantity' => 3]);
        $this->make('p11', 'Lambda parcial', ['stock_quantity' => 6]);                               // comprometido 4 de 6 pedidos -> 2 restantes
        $this->make('p12', 'Mu sobre comprometido', ['stock_quantity' => 2]);                        // comprometido 9 -> negativo
        $this->make('p13', 'Nu mayúsculas ÁTICO', ['brand' => 'ACME', 'stock_quantity' => 4]);
        $this->make('p14', 'Xi 100% real', ['stock_quantity' => 1]);
        $this->make('p15', 'Omicron guion_bajo', ['stock_quantity' => 1]);

        $this->p['p08']->families()->attach($this->famA->id);
        $this->p['p01']->families()->attach([$this->famA->id, $this->famB->id]);
        $this->p['p06']->families()->attach($this->famEmpty->id); // solo un producto inactivo

        // comprometido: etapas activas cuentan, despachado/entregado no
        $this->makeOrder(['stage' => Order::STAGE_EN_PICKING], ['catalog_product_id' => $this->p['p03']->id, 'qty_requested' => 5]);
        $this->makeOrder(['stage' => Order::STAGE_DESPACHADO], ['catalog_product_id' => $this->p['p05']->id, 'qty_requested' => 100]);
        $this->makeOrder(['stage' => Order::STAGE_PACKING], ['catalog_product_id' => $this->p['p11']->id, 'qty_requested' => 6, 'qty_delivered' => 2]);
        $this->makeOrder(['stage' => Order::STAGE_ASIGNADO], ['catalog_product_id' => $this->p['p12']->id, 'qty_requested' => 9]);
        $this->makeOrder(['stage' => Order::STAGE_ENTREGADO], ['catalog_product_id' => $this->p['p01']->id, 'qty_requested' => 3]);

        $excluida = Warehouse::create(['name' => 'Excluida catálogo', 'excluye_disponible' => true]);
        $normal   = Warehouse::create(['name' => 'Normal catálogo', 'excluye_disponible' => false]);
        ProductWarehouseStock::create(['catalog_product_id' => $this->p['p04']->id, 'warehouse_id' => $excluida->id, 'quantity' => 8]);
        ProductWarehouseStock::create(['catalog_product_id' => $this->p['p01']->id, 'warehouse_id' => $normal->id, 'quantity' => 10]);
    }

    // ── helpers ───────────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $query */
    private function catalog(array $query = []): \Illuminate\Testing\TestResponse
    {
        $token = $this->loginAs(self::EMAIL, 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/catalog?' . http_build_query($query))
            ->assertStatus(200);

        // la petición HTTP deja el tenant sin contexto: los helpers de paridad consultan modelos después
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();

        return $response;
    }

    /**
     * Ids que devolvía la implementación anterior (misma lógica, en PHP sobre el catálogo completo).
     * `search` de una sola palabra: idéntico a "contiene" sobre el texto concatenado.
     *
     * @param array<string, mixed> $q
     * @return list<int>
     */
    private function legacyIds(array $q, bool $demo = false): array
    {
        /** @var list<CatalogProduct> $products */
        $products = CatalogProduct::query()->with('families')->where('is_active', true)
            ->visibleForDemoMode($demo)->orderBy('name')->get()->all();

        $ids = array_map(fn (CatalogProduct $x): int => $x->id, $products);
        $committed = app(OrderCommitmentService::class)->committedMap($ids);
        $excluida  = app(InventoryKpiService::class)->enBodegaExcluidaMap($ids);

        $search = trim((string) ($q['search'] ?? ''));
        if ($search !== '') {
            $needle = mb_strtolower($search);
            $products = array_values(array_filter($products, function (CatalogProduct $x) use ($needle): bool {
                $hay = mb_strtolower(implode(' ', [
                    $x->reference, (string) $x->factory_reference, (string) $x->brand, $x->name, $x->description,
                    $x->category === null ? '' : str_replace('_', ' ', $x->category),
                    implode(' ', $x->families->pluck('name')->all()),
                ]));

                return str_contains($hay, $needle);
            }));
        }
        if (($q['category'] ?? '') !== '') {
            $products = array_values(array_filter($products, fn (CatalogProduct $x): bool => $x->category === $q['category']));
        }
        if (($q['family_id'] ?? '') !== '') {
            $products = array_values(array_filter($products, fn (CatalogProduct $x): bool => $x->families->pluck('id')->contains((int) $q['family_id'])));
        }
        $stock = $q['stock'] ?? '';
        if ($stock === 'con' || $stock === 'sin') {
            $kpi = app(InventoryKpiService::class);
            $products = array_values(array_filter($products, function (CatalogProduct $x) use ($committed, $excluida, $stock, $kpi): bool {
                $disp = $kpi->disponibleParaVenta($x, $committed[$x->id] ?? 0, $excluida[$x->id] ?? 0);

                return $stock === 'con' ? $disp > 0 : $disp <= 0;
            }));
        }

        return array_map(fn (CatalogProduct $x): int => $x->id, $products);
    }

    /** @return array{0: list<array{value: string, count: int}>, 1: list<array{id: int, name: string}>} */
    private function legacyDropdowns(bool $demo = false): array
    {
        $products = CatalogProduct::query()->with('families')->where('is_active', true)->visibleForDemoMode($demo)->get()->all();

        $counts = [];
        $fams = [];
        foreach ($products as $x) {
            if ($x->category !== null) {
                $counts[$x->category] = ($counts[$x->category] ?? 0) + 1;
            }
            foreach ($x->families as $f) {
                $fams[$f->id] = $f->name;
            }
        }
        ksort($counts);
        asort($fams);

        $cats = [];
        foreach ($counts as $v => $c) {
            $cats[] = ['value' => (string) $v, 'count' => $c];
        }
        $famList = [];
        foreach ($fams as $id => $n) {
            $famList[] = ['id' => $id, 'name' => $n];
        }

        return [$cats, $famList];
    }

    // ── paridad ───────────────────────────────────────────────────────────────────

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function filterCases(): array
    {
        return [
            'sin filtros'                       => [[]],
            'texto en referencia'               => [['search' => 'p0']],
            'texto en ref. de fábrica'          => [['search' => 'fr-xyz']],
            'texto en marca (case-insens.)'     => [['search' => 'acme']],
            'texto en nombre'                   => [['search' => 'techo']],
            'texto en descripción'              => [['search' => 'alto']],
            'texto en etiqueta de categoría'    => [['search' => 'candelabros']],
            'texto en nombre de familia'        => [['search' => 'zafiro']],
            'texto de 1 carácter'               => [['search' => 'z']],
            'texto que no existe'               => [['search' => 'nadaquesea']],
            'texto con porcentaje'              => [['search' => '100%']],
            'texto con guion bajo'              => [['search' => 'guion_bajo']],
            'categoría'                         => [['category' => 'lamparas']],
            'categoría inexistente'             => [['category' => 'nada']],
            'familia'                           => [['family_id' => 'FAM_A']],
            'familia sin productos activos'     => [['family_id' => 'FAM_EMPTY']],
            'stock con'                         => [['stock' => 'con']],
            'stock sin'                         => [['stock' => 'sin']],
            'stock con + texto'                 => [['stock' => 'con', 'search' => 'a']],
            'stock sin + categoría'             => [['stock' => 'sin', 'category' => 'lamparas']],
            'familia + stock con'               => [['family_id' => 'FAM_A', 'stock' => 'con']],
            'todos combinados'                  => [['search' => 'a', 'category' => 'lamparas', 'stock' => 'sin']],
            'stock inválido = sin filtro'       => [['stock' => 'xx']],
        ];
    }

    /**
     * @param array<string, mixed> $q
     */
    #[DataProvider('filterCases')]
    public function test_paridad_de_ids_y_orden_con_la_logica_anterior(array $q): void
    {
        $q = array_map(fn ($v) => match ($v) {
            'FAM_A'     => (string) $this->famA->id,
            'FAM_EMPTY' => (string) $this->famEmpty->id,
            default     => $v,
        }, $q);

        $expected = $this->legacyIds($q);
        $res = $this->catalog($q + ['per_page' => 'all']);

        $this->assertSame($expected, array_column($res->json('data'), 'id'), json_encode($q));
        $this->assertSame(count($expected), $res->json('meta.total'));
    }

    public function test_paridad_de_disponible_y_en_camino_por_fila(): void
    {
        $res = $this->catalog(['per_page' => 'all']);
        $ids = array_column($res->json('data'), 'id');
        $committed = app(OrderCommitmentService::class)->committedMap($ids);
        $excluida  = app(InventoryKpiService::class)->enBodegaExcluidaMap($ids);

        foreach ($res->json('data') as $row) {
            $product = CatalogProduct::find($row['id']);
            $esperado = max(0, (int) app(InventoryKpiService::class)->disponibleParaVenta($product, $committed[$row['id']] ?? 0, $excluida[$row['id']] ?? 0));
            $this->assertSame($esperado, $row['disponible'], "disponible de {$row['reference']}");
        }
        $this->assertSame(2, collect($res->json('data'))->firstWhere('reference', 'P11')['disponible']);
        $this->assertSame(0, collect($res->json('data'))->firstWhere('reference', 'P12')['disponible']);
    }

    public function test_el_filtro_stock_usa_la_misma_regla_que_disponible(): void
    {
        $con = array_column($this->catalog(['stock' => 'con', 'per_page' => 'all'])->json('data'), 'reference');
        $sin = array_column($this->catalog(['stock' => 'sin', 'per_page' => 'all'])->json('data'), 'reference');

        // p03 (5-5), p04 (8 en bodega excluida), p12 (2-9), p02 (0) y p07 (NULL) no tienen disponible;
        // p05 tiene el pedido DESPACHADO, que no compromete stock.
        $this->assertEqualsCanonicalizing(['P02', 'P03', 'P04', 'P07', 'P12'], array_values(array_intersect($sin, ['P02', 'P03', 'P04', 'P07', 'P12'])));
        $this->assertContains('P05', $con);
        $this->assertContains('P11', $con);
        $this->assertNotContains('P05', $sin);
        // el universo se reparte sin solaparse ni perderse
        $this->assertSame([], array_values(array_intersect($con, $sin)));
        $this->assertCount(count($this->legacyIds([])), array_merge($con, $sin));
    }

    public function test_dropdowns_de_categorias_y_familias_como_antes(): void
    {
        [$cats, $fams] = $this->legacyDropdowns();
        $res = $this->catalog(['search' => 'techo', 'category' => 'lamparas']); // los filtros no encogen los dropdowns

        $this->assertSame($cats, $res->json('categories'));
        $this->assertSame($fams, $res->json('families'));
        $this->assertNotContains('Sin productos', array_column($res->json('families'), 'name'), 'familia solo con productos inactivos');
        $this->assertNotEmpty($cats);
    }

    public function test_paridad_en_modo_demo(): void
    {
        $this->enableDemoModeGlobally();
        $this->setUserDemoModePreference(self::EMAIL, true);

        $expected = $this->legacyIds([], true);
        $res = $this->catalog(['per_page' => 'all']);

        $this->assertSame($expected, array_column($res->json('data'), 'id'));
        $refs = array_column($res->json('data'), 'reference');
        $this->assertNotContains('P09', $refs, 'sin foto');
        $this->assertNotContains('P10', $refs, 'precio 0');
        $this->assertLessThan(count($this->legacyIds([])), count($refs));

        [$cats, $fams] = $this->legacyDropdowns(true);
        $this->assertSame($cats, $res->json('categories'));
        $this->assertSame($fams, $res->json('families'));
    }

    // ── diferencias intencionales ────────────────────────────────────────────────

    public function test_el_texto_ignora_acentos_y_mayusculas(): void
    {
        $refs = array_column($this->catalog(['search' => 'atico', 'per_page' => 'all'])->json('data'), 'reference');
        $this->assertSame(['P13'], $refs);

        $refs = array_column($this->catalog(['search' => 'ÁTICO', 'per_page' => 'all'])->json('data'), 'reference');
        $this->assertSame(['P13'], $refs);
    }

    public function test_varias_palabras_en_cualquier_orden_y_en_campos_distintos(): void
    {
        // "acme" está en la marca y "techo" en la descripción del mismo producto (P01); "techo"
        // también está en el nombre de P03 pero P03 no tiene "acme".
        $refs = array_column($this->catalog(['search' => 'techo acme', 'per_page' => 'all'])->json('data'), 'reference');
        $this->assertSame(['P01'], $refs);

        $refs = array_column($this->catalog(['search' => 'acme techo', 'per_page' => 'all'])->json('data'), 'reference');
        $this->assertSame(['P01'], $refs);
    }

    public function test_los_comodines_de_like_en_el_texto_no_se_interpretan(): void
    {
        $refs = array_column($this->catalog(['search' => '%', 'per_page' => 'all'])->json('data'), 'reference');
        $this->assertSame(['P14'], $refs, 'solo el nombre con un % literal');

        $refs = array_column($this->catalog(['search' => 'guion_bajo', 'per_page' => 'all'])->json('data'), 'reference');
        $this->assertSame(['P15'], $refs);
    }

    /**
     * SCRUM-992 (Pre-QA ronda 2, H10) — una palabra sin equivalente ASCII (°, ×, ®, emoji…) normaliza a
     * cadena vacía en `Str::ascii()`, y `str_contains($categoria, '')` es siempre true: la palabra
     * coincidía con TODAS las categorías (con el catálogo real, '°' daba 26.003 en vez de 1.224).
     *
     * @return array<string, array{0: string, 1: list<string>}>
     */
    public static function simboloSinEquivalenteAsciiCases(): array
    {
        return [
            'grado suelto'            => ['°', ['Q1', 'Q2']],
            'palabra + grado'         => ['spot °', ['Q1']],
            'número + grado'          => ['30 °', ['Q1']],
            'emoji sin ningún match'  => ['😀', []],
            'marca registrada'        => ['®', ['Q3']],
        ];
    }

    /** @param list<string> $esperadas */
    #[DataProvider('simboloSinEquivalenteAsciiCases')]
    public function test_un_simbolo_sin_equivalente_ascii_no_coincide_con_todas_las_categorias(string $search, array $esperadas): void
    {
        $this->make('q1', 'Spot 30° cálido');
        $this->make('q2', 'Riel ángulo', ['description' => 'giro de 45° en el cabezal']);
        $this->make('q3', 'Marca® pro');

        $refs = array_column($this->catalog(['search' => $search, 'per_page' => 'all'])->json('data'), 'reference');
        sort($refs);

        $this->assertSame($esperadas, $refs, "search='{$search}' no debe traer productos solo por tener categoría");
        // Los dos productos con categoría 'lamparas' (P03, P04) NO tienen ningún símbolo: nunca deben aparecer.
        $this->assertNotContains('P03', $refs);
        $this->assertNotContains('P04', $refs);
    }

    public function test_un_simbolo_en_el_nombre_de_una_familia_solo_trae_los_productos_de_esa_familia(): void
    {
        $fam = CatalogProductFamily::create(['name' => 'Giro 90°']);
        $this->make('q4', 'Producto normal')->families()->attach($fam->id);

        $refs = array_column($this->catalog(['search' => '°', 'per_page' => 'all'])->json('data'), 'reference');

        $this->assertSame(['Q4'], $refs);
    }

    /**
     * SCRUM-992 (Pre-QA ronda 3, H17) — la rama de categorías comparaba en PHP con `Str::ascii()`, que no
     * normaliza igual que `public.f_unaccent()` (el resto de la búsqueda): 'l°' se reducía a 'l' y coincidía
     * con cualquier categoría con una 'l' (25.845 en vez de 0 con el catálogo real de dev).
     *
     * @return array<string, array{0: string}>
     */
    public static function palabraConSimboloYLetrasCases(): array
    {
        return [
            'letras + grado'        => ['lampara°'],
            'una letra + grado'     => ['l°'],
            'a + grado'             => ['a°'],
            'ordinal femenino'      => ['ª'],
            'colgante + grado'      => ['colgante°'],
            'grado + letra'         => ['°C'],
            'categoría sin símbolo' => ['lamparas'],
            'categoría con acento'  => ['LÁMPARAS'],
            'dos palabras'          => ['candelabros colgantes'],
            'multiplicación'        => ['×'],
        ];
    }

    /**
     * Oráculo independiente del servicio: el mismo criterio por palabra (search_text OR descripción OR
     * categoría OR nombre de familia, todo con `public.f_unaccent(lower())`) evaluado con SQL directo sobre
     * la tabla, sin pasar por `CatalogService`.
     *
     * @return list<string> referencias de los productos activos que debe traer /catalog
     */
    private function oracleRefs(string $search): array
    {
        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');
        $words = preg_split('/\s+/u', trim($search), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $where = ['cp.is_active'];
        $bind = [];
        foreach ($words as $w) {
            $n = addcslashes($w, '%_\\');
            $like = "'%' || public.f_unaccent(lower(?)) || '%'";
            $where[] = "(cp.search_text LIKE {$like}"
                . " OR public.f_unaccent(lower(coalesce(cp.description, ''))) LIKE {$like}"
                . " OR public.f_unaccent(lower(replace(coalesce(cp.category, ''), '_', ' '))) LIKE {$like}"
                . " OR EXISTS (SELECT 1 FROM {$slug}_ventas_diseno.catalog_product_family_items fi"
                . "   JOIN {$slug}_ventas_diseno.catalog_product_families f ON f.id = fi.family_id"
                . "  WHERE fi.catalog_product_id = cp.id AND public.f_unaccent(lower(f.name)) LIKE {$like}))";
            array_push($bind, $n, $n, $n, $n);
        }
        $rows = DB::select(
            "SELECT cp.reference FROM {$slug}_ventas_diseno.catalog_products cp WHERE " . implode(' AND ', $where),
            $bind,
        );
        $refs = array_map(static fn (object $r): string => (string) $r->reference, $rows);
        sort($refs);

        return $refs;
    }

    #[DataProvider('palabraConSimboloYLetrasCases')]
    public function test_la_busqueda_de_categorias_normaliza_como_postgres_y_no_como_php_h17(string $search): void
    {
        $this->make('q1', 'Spot 30° cálido');
        $this->make('q5', 'Riela° especial');
        $this->make('q6', 'Temp 25°C exterior');
        $this->make('q7', 'Modelo 2×4');

        $refs = array_column($this->catalog(['search' => $search, 'per_page' => 'all'])->json('data'), 'reference');
        sort($refs);

        $this->assertSame($this->oracleRefs($search), $refs, "search='{$search}' difiere del oráculo SQL independiente");
    }

    public function test_letra_o_palabra_mas_simbolo_no_trae_los_productos_de_una_categoria_entera_h17(): void
    {
        $this->make('q1', 'Spot 30° cálido');

        foreach (['l°', 'lampara°', 'colgante°'] as $search) {
            $refs = array_column($this->catalog(['search' => $search, 'per_page' => 'all'])->json('data'), 'reference');
            $this->assertSame([], $refs, "'{$search}' no aparece en ningún texto: no debe traer P01/P03/P04 solo por su categoría");
        }
    }

    /**
     * SCRUM-992 (Pre-QA ronda 2, H11) — la consulta real de /catalog con texto debe poder usar el índice
     * trigram de la descripción. Un índice de expresión solo lo usa el planner si la expresión de la
     * consulta coincide byte a byte con la del índice: si alguien cambia una de las dos, la búsqueda
     * vuelve a leer todo el catálogo (455–487 ms con 30K productos) sin que ningún otro test lo note.
     * `enable_seqscan = off` porque con pocas filas el planner prefiere Seq Scan por costo aunque el índice sirva.
     */
    public function test_la_consulta_de_texto_usa_los_indices_de_descripcion_y_categoria(): void
    {
        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');
        $indexes = array_map(static fn ($r): string => $r->indexname, DB::select(
            "SELECT indexname FROM pg_indexes WHERE schemaname = ? AND tablename = 'catalog_products'",
            ["{$slug}_ventas_diseno"],
        ));
        $this->assertContains('catalog_products_description_trgm_idx', $indexes);
        $this->assertContains('catalog_products_category_idx', $indexes);

        // una palabra de nombre/descripción ('techo'), una que coincide con una etiqueta de categoría ('candelabros') y una con el nombre de una familia ('zafiro')
        foreach (['techo', 'candelabros', 'zafiro'] as $term) {
            $queries = [];
            DB::listen(static function ($q) use (&$queries): void {
                $queries[] = [$q->sql, $q->bindings];
            });
            $this->catalog(['search' => $term, 'per_page' => 20]);

            $page = collect($queries)->first(static fn (array $q): bool => str_contains($q[0], 'search_text') && str_contains($q[0], 'limit'));
            $this->assertNotNull($page, "no se capturó la consulta de página para '{$term}'");

            DB::statement('SET enable_seqscan = off');
            try {
                $plan = implode("\n", array_map(static fn ($r): string => ((array) $r)['QUERY PLAN'], DB::select('EXPLAIN ' . $page[0], $page[1])));
            } finally {
                DB::statement('SET enable_seqscan = on');
            }

            $this->assertStringContainsString('catalog_products_description_trgm_idx', $plan, "la consulta para '{$term}' no usa el índice de la descripción:\n{$plan}");
            $this->assertStringNotContainsString('Seq Scan on catalog_products', $plan, "la consulta para '{$term}' lee todo el catálogo:\n{$plan}");
        }
    }

    // ── paginación / orden ───────────────────────────────────────────────────────

    public function test_paginacion_recorre_todo_sin_repetir_ni_perder(): void
    {
        $all = $this->legacyIds([]);
        $seen = [];
        $lastPage = $this->catalog(['per_page' => 10])->json('meta.last_page');
        for ($page = 1; $page <= $lastPage; $page++) {
            $res = $this->catalog(['per_page' => 10, 'page' => $page]);
            $this->assertSame($page, $res->json('meta.current_page'));
            $this->assertSame(count($all), $res->json('meta.total'));
            $seen = [...$seen, ...array_column($res->json('data'), 'id')];
        }

        $this->assertSame($all, $seen);
    }

    public function test_pagina_fuera_de_rango_devuelve_vacio_con_meta_coherente(): void
    {
        $res = $this->catalog(['per_page' => 10, 'page' => 99]);
        $this->assertSame([], $res->json('data'));
        $this->assertSame(99, $res->json('meta.current_page'));
        $this->assertSame(count($this->legacyIds([])), $res->json('meta.total'));
    }

    public function test_pagina_gigante_se_acota_y_devuelve_vacio_h6(): void
    {
        $res = $this->catalog(['page' => '9223372036854775808', 'per_page' => 'all']);

        $this->assertSame([], $res->json('data'), 'antes devolvía las filas de la página 1');
        $this->assertSame(1_000_000, $res->json('meta.current_page'));
        $this->assertSame(count($this->legacyIds([])), $res->json('meta.total'));
    }

    public function test_per_page_all_esta_acotado_a_1000(): void
    {
        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');
        DB::statement("
            INSERT INTO {$slug}_ventas_diseno.catalog_products (reference, name, description, price_full, is_active, created_at, updated_at)
            SELECT 'BULK-' || g, 'Bulk ' || g, 'x', 10, true, now(), now() FROM generate_series(1, 1100) g
        ");

        $res = $this->catalog(['per_page' => 'all']);

        $this->assertCount(1000, $res->json('data'));
        $this->assertSame(1000, $res->json('meta.per_page'));
        $this->assertGreaterThan(1000, $res->json('meta.total'));
        $this->assertSame(2, $res->json('meta.last_page'));
    }

    public function test_orden_por_nombre_estable_con_nombres_repetidos(): void
    {
        foreach (range(1, 12) as $i) {
            $this->make("dup{$i}", 'Nombre repetido');
        }

        $seen = [];
        for ($page = 1; $page <= 4; $page++) {
            $seen = [...$seen, ...array_column($this->catalog(['per_page' => 10, 'page' => $page, 'search' => 'repetido'])->json('data'), 'id')];
        }

        $this->assertCount(12, $seen);
        $this->assertSame($seen, array_values(array_unique($seen)), 'sin repetidos entre páginas');
        $sorted = $seen;
        sort($sorted);
        $this->assertSame($sorted, $seen, 'desempate por id');
    }

    // ── regresión de hidratación ─────────────────────────────────────────────────

    public function test_no_hidrata_mas_modelos_que_la_pagina_pedida(): void
    {
        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');
        DB::statement("
            INSERT INTO {$slug}_ventas_diseno.catalog_products (reference, name, description, price_full, is_active, created_at, updated_at)
            SELECT 'HID-' || g, 'Hidratacion ' || g, 'x', 10, true, now(), now() FROM generate_series(1, 400) g
        ");

        $token = $this->loginAs(self::EMAIL, 'Password123!', 'designer');
        $retrieved = 0;
        CatalogProduct::retrieved(function () use (&$retrieved): void {
            $retrieved++;
        });

        foreach ([[], ['search' => 'hidratacion'], ['stock' => 'con'], ['category' => 'lamparas']] as $q) {
            $retrieved = 0;
            $this->withHeaders($this->authHeaders($token))
                ->getJson('/api/ventas-diseno/catalog?' . http_build_query($q + ['per_page' => 10]))
                ->assertStatus(200);

            $this->assertLessThanOrEqual(10, $retrieved, 'modelos CatalogProduct hidratados con ' . json_encode($q));
        }
    }

    // ── H8 ────────────────────────────────────────────────────────────────────────

    public function test_parametros_de_tipo_array_no_dan_500_y_se_ignoran(): void
    {
        $token = $this->loginAs(self::EMAIL, 'Password123!', 'designer');
        $base = array_column($this->catalog(['per_page' => 'all'])->json('data'), 'id');

        $res = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/catalog?search[]=x&category[]=y&stock[]=con&family_id[]=1&per_page[]=5&page[]=2&sort_dir[]=desc&per_page=all')
            ->assertStatus(200);

        $this->assertSame($base, array_column($res->json('data'), 'id'), 'un parámetro array equivale a no mandarlo');
    }
}
