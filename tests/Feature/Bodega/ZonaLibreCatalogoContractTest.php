<?php

declare(strict_types=1);

namespace Tests\Feature\Bodega;

use App\Modules\Bodega\Models\Warehouse;
use App\Modules\Bodega\Services\ZonaLibreCatalogoService;
use App\Modules\Integrations\Lafayette\Data\ExtractedProductos;
use App\Modules\Integrations\Lafayette\Data\LafayetteProductoRow;
use App\Modules\Integrations\Lafayette\Data\MappedLafayetteProducto;
use App\Modules\Integrations\Lafayette\Extractors\ProductoExtractor;
use App\Modules\Integrations\Lafayette\Models\LafayetteProducto;
use App\Modules\Integrations\Lafayette\Models\LafayetteSyncRun;
use App\Modules\Integrations\Lafayette\Services\LafayetteProductoLoader;
use App\Modules\Integrations\Lafayette\Services\ProductoMapper;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-990 — `GET /bodega/zona-libre/productos`: catálogo de Lafayette de SOLO CONSULTA. Fija el CONTRATO que consume el frontend
 * (forma exacta de cada fila y de `meta`), los parámetros, la búsqueda, el orden, la paginación y que no existe ruta de escritura.
 */
final class ZonaLibreCatalogoContractTest extends FeatureTestCase
{
    private const URL = '/api/bodega/zona-libre/productos';

    /** @var list<string> Claves EXACTAS de cada fila de `data` (el frontend las consume tal cual). */
    private const ROW_KEYS = ['id', 'referencia_lafayette', 'referencia', 'descripcion', 'marca', 'categoria', 'url_imagen', 'disponible', 'precio', 'synced_at'];

    /** @var list<string> */
    private const META_KEYS = ['total', 'per_page', 'current_page', 'last_page', 'categorias', 'total_catalogo', 'ultima_sincronizacion', 'puede_ver_precio'];

    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        Cache::flush();
    }

    private function cargarMuestra(): void
    {
        /** @var list<array<string, mixed>> $raw */
        $raw = require __DIR__ . '/../../Fixtures/Lafayette/vista_productos_lighting_muestra.php';
        $rows = array_map(static fn (array $r): LafayetteProductoRow => ProductoExtractor::mapRow($r), $raw);
        app(LafayetteProductoLoader::class)->load((new ProductoMapper())->buildPayloads(new ExtractedProductos($rows)));
    }

    /** @param list<string> $perms */
    private function token(array $perms = ['bodega.read', 'compras.read']): string
    {
        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');
        DB::table("{$slug}_auth.users")->where('email', 'designer@atlantic.test')->update(['extra_permissions' => json_encode($perms)]);

        return $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
    }

    /** @param array<string, mixed> $query @return \Illuminate\Testing\TestResponse<\Symfony\Component\HttpFoundation\Response> */
    private function consultar(string $token, array $query = []): \Illuminate\Testing\TestResponse
    {
        return $this->withHeaders($this->authHeaders($token))->getJson(self::URL . ($query === [] ? '' : '?' . http_build_query($query)));
    }

    public function test_contrato_exacto_de_cada_fila_y_de_meta(): void
    {
        $this->cargarMuestra();
        $token = $this->token();

        $json = $this->consultar($token)->assertOk()->json();

        $this->assertSame(['data', 'meta'], array_keys($json));
        $this->assertSame(self::META_KEYS, array_keys($json['meta']));
        $this->assertNotEmpty($json['data']);
        foreach ($json['data'] as $row) {
            $this->assertSame(self::ROW_KEYS, array_keys($row), 'la fila debe tener EXACTAMENTE estas claves, en este orden');
        }

        $row = collect($json['data'])->firstWhere('id', 'LNP0000003');
        $this->assertSame('LNP0000003', $row['referencia_lafayette']);
        $this->assertSame('LED-A60-9W-65', $row['referencia']);
        $this->assertSame('LÁMPARA LED BULBO A60 9W 6500K LUZ FRÍA', $row['descripcion']);
        $this->assertSame('PHILIPS', $row['marca']);
        $this->assertSame('LÁMPARAS LED', $row['categoria']);
        $this->assertSame(1250, $row['disponible']);
        $this->assertSame(2.65, $row['precio'], 'precio = precio_6 (SCRUM-1125), numérico, SIN etiqueta de tipo de precio en el payload');
        $this->assertIsString($row['synced_at']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $row['synced_at'], 'ISO 8601');
        $this->assertArrayNotHasKey('precio_5', $row);
        $this->assertArrayNotHasKey('precio_6', $row);
        $this->assertArrayNotHasKey('label', $row);

        $this->assertTrue($json['meta']['puede_ver_precio']);
        $this->assertIsInt($json['meta']['total']);
        $this->assertIsInt($json['meta']['total_catalogo']);
        $this->assertIsArray($json['meta']['categorias']);
    }

    public function test_sin_compras_read_ni_superadmin_el_precio_es_null_no_se_consulta_y_no_se_puede_ordenar_por_el(): void
    {
        $this->cargarMuestra();
        $token = $this->token(['bodega.read']);

        $json = $this->consultar($token, ['per_page' => 100])->assertOk()->json();
        $this->assertFalse($json['meta']['puede_ver_precio']);
        $this->assertSame(self::META_KEYS, array_keys($json['meta']));
        $this->assertNotEmpty($json['data']);
        foreach ($json['data'] as $row) {
            $this->assertSame(self::ROW_KEYS, array_keys($row), 'la clave `precio` sigue existiendo (contrato), pero vacía');
            $this->assertNull($row['precio']);
        }
        $this->assertStringNotContainsString('2.65', (string) json_encode($json), 'ningún precio real viaja en el payload');

        DB::enableQueryLog();
        $this->consultar($token)->assertOk();
        $consultas = collect(DB::getQueryLog())->pluck('query')->filter(fn (string $q): bool => str_contains($q, 'lafayette_productos'))->implode(' ');
        DB::disableQueryLog();
        $this->assertStringNotContainsString('precio_6', str_replace('NULL AS precio_6', '', $consultas), 'la columna precio_6 no se consulta sin permiso');

        // Ordenar por precio filtraría el orden de los precios.
        $this->consultar($token, ['sort' => 'precio'])->assertStatus(422)->assertJsonValidationErrors(['sort']);
        $this->consultar($token, ['sort' => 'disponible'])->assertOk();
    }

    public function test_superadmin_y_compras_read_ven_el_precio(): void
    {
        $this->cargarMuestra();

        $compras = $this->consultar($this->token(['bodega.read', 'compras.read']), ['per_page' => 100])->assertOk()->json();
        $this->assertTrue($compras['meta']['puede_ver_precio']);
        $this->assertSame(2.65, collect($compras['data'])->firstWhere('id', 'LNP0000003')['precio']);

        $super = $this->loginAs('superadmin@atlantic.test', 'Password123!');
        $json = $this->consultar($super, ['per_page' => 100, 'sort' => 'precio'])->assertOk()->json();
        $this->assertTrue($json['meta']['puede_ver_precio']);
        $this->assertSame(2.65, collect($json['data'])->firstWhere('id', 'LNP0000003')['precio']);
    }

    public function test_requiere_autenticacion_y_permiso_bodega_read(): void
    {
        $this->cargarMuestra();
        $this->getJson(self::URL)->assertStatus(401);

        $token = $this->token(['crm.read']);
        $this->consultar($token)->assertStatus(403);
    }

    public function test_por_defecto_muestra_los_de_stock_cero_ordena_por_referencia_y_pagina_de_25(): void
    {
        $this->cargarMuestra();
        $token = $this->token();

        $json = $this->consultar($token)->assertOk()->json();

        $this->assertSame(25, $json['meta']['per_page']);
        $this->assertSame(1, $json['meta']['current_page']);
        $this->assertSame(19, $json['meta']['total']);
        $this->assertSame(19, $json['meta']['total_catalogo']);
        $this->assertCount(19, $json['data']);
        $this->assertTrue(collect($json['data'])->contains(fn (array $r): bool => $r['disponible'] === 0), 'los de stock 0 se ven por defecto');

        $refs = collect($json['data'])->pluck('referencia');
        $conValor = $refs->filter(fn ($r) => $r !== null)->values();
        $this->assertSame($conValor->sort()->values()->all(), $conValor->all(), 'orden por referencia ascendente');
        $this->assertNull($refs->last(), 'las referencias nulas van al final (NULLS LAST)');
    }

    public function test_solo_disponibles_quita_los_de_stock_cero(): void
    {
        $this->cargarMuestra();
        $token = $this->token();

        $json = $this->consultar($token, ['solo_disponibles' => 'true'])->assertOk()->json();

        $this->assertNotEmpty($json['data']);
        $this->assertSame(0, collect($json['data'])->where('disponible', 0)->count());
        $this->assertLessThan(19, $json['meta']['total']);
        $this->assertSame(19, $json['meta']['total_catalogo'], 'total_catalogo no depende de los filtros');

        // Un cliente JS serializa el booleano como "true"/"false"; 1/0 también valen y "false" NO filtra.
        $this->assertSame($json['meta']['total'], $this->consultar($token, ['solo_disponibles' => '1'])->assertOk()->json('meta.total'));
        $this->assertSame(19, $this->consultar($token, ['solo_disponibles' => 'false'])->assertOk()->json('meta.total'));
        $this->assertSame(19, $this->consultar($token, ['solo_disponibles' => '0'])->assertOk()->json('meta.total'));
        $this->consultar($token, ['solo_disponibles' => 'quizas'])->assertStatus(422)->assertJsonValidationErrors(['solo_disponibles']);
    }

    public function test_filtra_por_categoria_y_expone_las_categorias_distintas_ordenadas(): void
    {
        $this->cargarMuestra();
        $token = $this->token();

        $json = $this->consultar($token, ['categoria' => 'PANELES'])->assertOk()->json();

        $this->assertSame(2, $json['meta']['total']);
        $this->assertSame(['PANELES'], collect($json['data'])->pluck('categoria')->unique()->values()->all());

        $categorias = $json['meta']['categorias'];
        $this->assertContains('PANELES', $categorias);
        $this->assertContains('LÁMPARAS LED', $categorias);
        $this->assertSame(count($categorias), count(array_unique($categorias)), 'distintas');
        $this->assertNotContains('', $categorias);
        $this->assertNotContains(null, $categorias);
    }

    public function test_busqueda_sin_acentos_por_descripcion_y_por_id_de_lafayette(): void
    {
        $this->cargarMuestra();
        $token = $this->token();

        $porDescripcion = $this->consultar($token, ['search' => 'lampara led bulbo'])->assertOk()->json();
        $this->assertSame(2, $porDescripcion['meta']['total']);

        $porId = $this->consultar($token, ['search' => 'LNP0000005'])->assertOk()->json();
        $this->assertSame(['LNP0000005'], collect($porId['data'])->pluck('id')->all());

        $vacio = $this->consultar($token, ['search' => 'zzqxjwv'])->assertOk()->json();
        $this->assertSame(0, $vacio['meta']['total']);
        $this->assertSame([], $vacio['data']);
    }

    public function test_busqueda_con_typo_usa_el_respaldo_tolerante(): void
    {
        $this->cargarMuestra();
        $token = $this->token();

        // "empotar" (falta una "r"): la coincidencia parcial da 0 y entra el respaldo aproximado ("EMPOTRAR" en el panel LED).
        $json = $this->consultar($token, ['search' => 'empotar'])->assertOk()->json();

        $this->assertGreaterThanOrEqual(1, $json['meta']['total']);
        $this->assertContains('LNP0000005', collect($json['data'])->pluck('id')->all());
    }

    public function test_orden_por_columna_permitida_y_nulos_siempre_al_final(): void
    {
        $this->cargarMuestra();
        $token = $this->token();

        $desc = $this->consultar($token, ['sort' => 'precio', 'dir' => 'desc'])->assertOk()->json('data');
        $this->assertSame(901.3, $desc[0]['precio'], 'ordena por precio_6 (precio_5 máximo sería 912.5, de otra fila)');
        $this->assertNull(end($desc)['precio']);

        $asc = $this->consultar($token, ['sort' => 'precio', 'dir' => 'asc'])->assertOk()->json('data');
        $this->assertEquals(0.5, $asc[0]['precio']);
        $this->assertNull(end($asc)['precio'], 'NULLS LAST también en ascendente');

        $porDisponible = $this->consultar($token, ['sort' => 'disponible', 'dir' => 'desc'])->assertOk()->json('data');
        $this->assertSame(1250, $porDisponible[0]['disponible']);
    }

    public function test_parametros_fuera_de_contrato_responden_422(): void
    {
        $this->cargarMuestra();
        $token = $this->token();

        $this->consultar($token, ['search' => 'a'])->assertStatus(422)->assertJsonValidationErrors(['search']);
        $this->consultar($token, ['sort' => 'precio_5; DROP TABLE x'])->assertStatus(422)->assertJsonValidationErrors(['sort']);
        $this->consultar($token, ['sort' => 'id'])->assertStatus(422)->assertJsonValidationErrors(['sort']);
        $this->consultar($token, ['dir' => 'sideways'])->assertStatus(422)->assertJsonValidationErrors(['dir']);
        $this->consultar($token, ['per_page' => 10])->assertStatus(422)->assertJsonValidationErrors(['per_page']);
        $this->consultar($token, ['per_page' => 'all'])->assertStatus(422)->assertJsonValidationErrors(['per_page']);
        $this->consultar($token, ['page' => 0])->assertStatus(422)->assertJsonValidationErrors(['page']);
        $this->consultar($token, ['page' => ZonaLibreCatalogoService::PAGE_MAX + 1])->assertStatus(422)->assertJsonValidationErrors(['page']);
        $this->consultar($token, ['page' => ZonaLibreCatalogoService::PAGE_MAX])->assertOk();
    }

    public function test_la_lectura_esta_limitada_por_usuario_y_no_por_ip(): void
    {
        $this->cargarMuestra();
        $token = $this->token();

        for ($i = 0; $i < 60; $i++) {
            $this->consultar($token)->assertOk();
        }
        $this->consultar($token)->assertStatus(429);

        // Otro usuario desde la misma IP conserva su cupo (el limitador es por sub del JWT).
        DB::table('atlantic_auth.users')->where('email', 'supervisor@atlantic.test')->update(['extra_permissions' => json_encode(['bodega.read'])]);
        $otro = $this->loginAs('supervisor@atlantic.test', 'Password123!', 'supervisor');
        $this->consultar($otro)->assertOk();
    }

    public function test_paginacion_por_25_50_y_100_y_pagina_mas_alla_de_la_ultima(): void
    {
        $this->cargarMuestra();
        $token = $this->token();

        foreach ([25, 50, 100] as $n) {
            $this->assertSame($n, $this->consultar($token, ['per_page' => $n])->assertOk()->json('meta.per_page'));
        }

        $p = $this->consultar($token, ['per_page' => 25, 'page' => 9])->assertOk()->json();
        $this->assertSame([], $p['data']);
        $this->assertSame(19, $p['meta']['total']);
        $this->assertSame(9, $p['meta']['current_page']);
    }

    public function test_los_productos_fuera_de_la_fuente_se_ocultan_y_no_cuentan(): void
    {
        $this->cargarMuestra();
        DB::table((new LafayetteProducto())->getTable())->where('lafayette_id', 'LNP0000005')->update(['fuera_de_fuente_at' => now()]);
        Cache::flush();
        $token = $this->token();

        $json = $this->consultar($token)->assertOk()->json();

        $this->assertSame(18, $json['meta']['total']);
        $this->assertSame(18, $json['meta']['total_catalogo']);
        $this->assertNotContains('LNP0000005', collect($json['data'])->pluck('id')->all());
        $this->assertNotContains('LNP0000005', collect($this->consultar($token, ['search' => 'LNP0000005'])->json('data'))->pluck('id')->all(), 'ni por búsqueda exacta (el respaldo aproximado puede traer ids parecidos, nunca el oculto)');
    }

    public function test_ultima_sincronizacion_es_null_sin_corridas_y_iso_8601_con_corrida_exitosa(): void
    {
        $this->cargarMuestra();
        $token = $this->token();
        $this->assertNull($this->consultar($token)->assertOk()->json('meta.ultima_sincronizacion'));

        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        LafayetteSyncRun::query()->create([
            'extraidos' => 20, 'creados' => 19, 'actualizados' => 0, 'errores' => 0, 'duracion_ms' => 10,
            'status' => LafayetteSyncRun::STATUS_SUCCESS, 'started_at' => now()->subMinute(), 'finished_at' => now()->subSeconds(30), 'created_at' => now(),
        ]);
        LafayetteSyncRun::query()->create([
            'extraidos' => 0, 'creados' => 0, 'actualizados' => 0, 'errores' => 1, 'duracion_ms' => 10,
            'status' => LafayetteSyncRun::STATUS_FAILED, 'started_at' => now(), 'finished_at' => now(), 'created_at' => now(),
        ]);

        $iso = $this->consultar($token)->assertOk()->json('meta.ultima_sincronizacion');
        $this->assertIsString($iso, 'una corrida FALLIDA posterior no reemplaza a la última exitosa');
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $iso);
    }

    public function test_el_resumen_se_cachea_y_se_invalida_al_terminar_una_corrida(): void
    {
        $this->cargarMuestra();
        $token = $this->token();
        $this->assertSame(19, $this->consultar($token)->json('meta.total_catalogo'));

        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        DB::table((new LafayetteProducto())->getTable())->where('lafayette_id', 'LNP0000005')->update(['fuera_de_fuente_at' => now()]);

        $this->assertSame(19, $this->consultar($token)->json('meta.total_catalogo'), 'sigue cacheado hasta que termine el sincronizador');

        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        ZonaLibreCatalogoService::forgetResumen();
        $this->assertSame(18, $this->consultar($token)->json('meta.total_catalogo'));
    }

    public function test_no_existe_ninguna_ruta_de_escritura_sobre_zona_libre_productos(): void
    {
        $rutas = collect(Route::getRoutes()->getRoutes())->filter(fn ($r): bool => str_starts_with($r->uri(), 'api/bodega/zona-libre/productos'));

        $this->assertGreaterThanOrEqual(1, $rutas->count());
        foreach ($rutas as $ruta) {
            $this->assertSame(['GET', 'HEAD'], $ruta->methods(), 'solo lectura: ' . $ruta->uri());
        }
    }

    public function test_vista_bodegas_expone_solo_consulta_y_show_no_consulta_stock(): void
    {
        $this->cargarMuestra();
        $zl = Warehouse::query()->where('name', 'Bodega Zona Libre')->firstOrFail()->id;
        $token = $this->token();

        $index = $this->withHeaders($this->authHeaders($token))->getJson('/api/bodega/warehouses')->assertOk()->json('data');
        $this->assertTrue(collect($index)->firstWhere('name', 'Bodega Zona Libre')['solo_consulta']);
        $this->assertFalse(collect($index)->firstWhere('name', 'Bodega Central')['solo_consulta']);

        $show = $this->withHeaders($this->authHeaders($token))->getJson("/api/bodega/warehouses/{$zl}")->assertOk()->json();
        $this->assertTrue($show['warehouse']['solo_consulta']);
        $this->assertSame(19, $show['kpis']['total_products']);
        $this->assertSame([], $show['data']);
        $this->assertArrayHasKey('last_synced_at', $show['kpis']);
        $this->assertSame(['total', 'per_page', 'current_page', 'last_page'], array_keys($show['meta']));

        // También con el chip "espacio libre": nunca inventa ubicaciones para una bodega de solo consulta.
        $chip = $this->withHeaders($this->authHeaders($token))->getJson("/api/bodega/warehouses/{$zl}?chip=espacio_libre")->assertOk()->json();
        $this->assertSame([], $chip['data']);
    }

    public function test_rendimiento_con_el_catalogo_completo_de_26000_filas(): void
    {
        $batch = [];
        for ($i = 1; $i <= 26000; $i++) {
            $batch[] = new MappedLafayetteProducto(
                sprintf('PERF%07d', $i), "REF-{$i}", "Luminaria de prueba modelo {$i} linea " . ($i % 40), 'MARCA-' . ($i % 25), 'CAT-' . ($i % 60),
                null, $i % 4 === 0 ? 0 : $i % 97, round($i / 7, 4), round($i / 7 + 0.5, 4), null,
            );
        }
        app(LafayetteProductoLoader::class)->load($batch);
        unset($batch);
        $token = $this->token();

        $casos = [
            'sin búsqueda, página 1'       => [],
            'última página'                => ['page' => 260, 'per_page' => 100],
            'búsqueda parcial'             => ['search' => 'luminaria modelo 1234'],
            'búsqueda con typo (fallback)' => ['search' => 'luminria modelo'],
            'sin resultados'               => ['search' => 'zzqxjwvkk'],
            'orden por precio'             => ['sort' => 'precio', 'dir' => 'desc', 'per_page' => 100],
        ];

        $this->consultar($token, [])->assertOk(); // calienta rutas, autenticación y el resumen cacheado

        // El pico se mide SOLO sobre las peticiones (no sobre todo el proceso de PHPUnit, que en la suite completa ya acumula cientos de MB
        // de las pruebas anteriores): se reinicia el pico y se compara contra la memoria en ese instante. Objetivo del proyecto: pico < 64 MB.
        gc_collect_cycles();
        memory_reset_peak_usage();
        $base = memory_get_usage(true);
        $reporte = [];
        foreach ($casos as $nombre => $query) {
            $t0 = microtime(true);
            $this->consultar($token, $query)->assertOk();
            $ms = (microtime(true) - $t0) * 1000;
            $reporte[$nombre] = round($ms);
            $this->assertLessThan(1500, $ms, "'{$nombre}' tardó {$ms} ms (objetivo de producción p95 < 500 ms; la suite corre con margen)");
        }

        $pico = (memory_get_peak_usage(true) - $base) / 1048576;
        fwrite(STDERR, "\n[SCRUM-990 rendimiento 26K filas] ms por caso: " . json_encode($reporte) . sprintf(' | pico sobre la base durante las peticiones: %.1f MB', $pico) . "\n");
        $this->assertLessThan(64, $pico, 'objetivo del proyecto: el pico de memoria del listado (con el catálogo completo) debe quedar por debajo de 64 MB');
    }
}
