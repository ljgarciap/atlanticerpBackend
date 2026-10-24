<?php

declare(strict_types=1);

namespace Tests\Feature\Legacy;

use App\Modules\Legacy\Extractors\AlmacenExtractor;
use App\Modules\Legacy\Extractors\CostoAlmacenExtractor;
use App\Modules\Legacy\Extractors\InventarioCabExtractor;
use App\Modules\Legacy\Extractors\TraspasoCabExtractor;
use App\Modules\Legacy\Extractors\StockAlmacenExtractor;
use App\Modules\Legacy\Models\AlmacenEquivalencia;
use App\Modules\Legacy\Services\AlmacenStagingLoader;
use App\Modules\Legacy\Services\AlmacenStagingVerifier;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-1136 F1 — Loader-A (staging) de almacenes/stock/costos/traspasos/inventarios: upsert idempotente,
 * paginación, `stock_corregido`, huérfanos (caso A1) y tabla de equivalencias.
 */
final class AlmacenStagingLoaderTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    /** @return array<string, mixed> */
    private static function stockRow(string $art, string $alm, float|string|null $stock = 5): array
    {
        return StockAlmacenExtractor::mapRow([
            'CODARTICULO' => $art, 'TALLA' => '.', 'COLOR' => '.', 'CODALMACEN' => $alm, 'STOCK' => $stock,
        ]);
    }

    /** @param list<array<string, mixed>> $rows */
    private static function fetcher(array $rows, int &$calls = 0): callable
    {
        return static function (int $offset, int $limit) use ($rows, &$calls): array {
            $calls++;

            return array_values(array_slice($rows, $offset, $limit));
        };
    }

    public function test_upsert_es_idempotente_y_actualiza_valores(): void
    {
        $loader = new AlmacenStagingLoader();
        $rows   = [self::stockRow('1', 'AL4', 5), self::stockRow('2', 'AL4', -2)];

        $first = $loader->loadPaginated('stock_almacen', self::fetcher($rows));
        $loader->loadPaginated('stock_almacen', self::fetcher($rows));

        $this->assertSame(['extraidos' => 2, 'cargados' => 2, 'paginas' => 1], $first);
        $this->assertSame(2, DB::table('atlantic_legacy.stock_almacen')->count());

        $loader->loadPaginated('stock_almacen', self::fetcher([self::stockRow('1', 'AL4', 9)]));
        $this->assertSame(9.0, DB::table('atlantic_legacy.stock_almacen')->where('codarticulo', '1')->value('stock') + 0.0);
    }

    public function test_stock_corregido_se_persiste_con_negativos_en_cero(): void
    {
        (new AlmacenStagingLoader())->loadPaginated('stock_almacen', self::fetcher([
            self::stockRow('1', 'AL4', -7), self::stockRow('2', 'AL4', 3),
        ]));

        $neg = DB::table('atlantic_legacy.stock_almacen')->where('codarticulo', '1')->first();
        $pos = DB::table('atlantic_legacy.stock_almacen')->where('codarticulo', '2')->first();
        $this->assertSame(-7.0, (float) $neg->stock);
        $this->assertSame(0.0, (float) $neg->stock_corregido);
        $this->assertSame(3.0, (float) $pos->stock_corregido);
    }

    public function test_pagina_hasta_agotar_y_confirma_cada_pagina(): void
    {
        $rows = [];
        for ($i = 1; $i <= 7; $i++) {
            $rows[] = self::stockRow((string) $i, 'AL4');
        }
        $calls  = 0;
        $seen   = [];
        $result = (new AlmacenStagingLoader())->loadPaginated(
            'stock_almacen',
            self::fetcher($rows, $calls),
            3,
            static function (int $e, int $c) use (&$seen): void {
                $seen[] = [$e, $c];
            },
        );

        $this->assertSame(['extraidos' => 7, 'cargados' => 7, 'paginas' => 3], $result);
        $this->assertSame(3, $calls); // la última página (1 fila < 3) corta sin pedir una cuarta
        $this->assertSame([[3, 3], [6, 6], [7, 7]], $seen);
        $this->assertSame(7, DB::table('atlantic_legacy.stock_almacen')->count());
    }

    public function test_pagina_exacta_pide_una_pagina_vacia_final(): void
    {
        $rows  = [self::stockRow('1', 'AL4'), self::stockRow('2', 'AL4')];
        $calls = 0;

        $result = (new AlmacenStagingLoader())->loadPaginated('stock_almacen', self::fetcher($rows, $calls), 2);

        $this->assertSame(2, $result['cargados']);
        $this->assertSame(2, $calls);
    }

    public function test_filas_con_la_misma_llave_en_una_pagina_no_rompen_el_upsert(): void
    {
        $result = (new AlmacenStagingLoader())->loadPaginated('stock_almacen', self::fetcher([
            self::stockRow('1', 'AL4', 1), self::stockRow('1', 'AL4', 2),
        ]));

        $this->assertSame(2, $result['extraidos']);
        $this->assertSame(1, $result['cargados']);
        $this->assertSame(2.0, DB::table('atlantic_legacy.stock_almacen')->value('stock') + 0.0);
    }

    public function test_pagina_vacia_no_escribe_nada(): void
    {
        $result = (new AlmacenStagingLoader())->loadPaginated('almacenes', self::fetcher([]));

        $this->assertSame(['extraidos' => 0, 'cargados' => 0, 'paginas' => 0], $result);
    }

    public function test_almacenes_e_inventarios_cargan_con_sus_llaves(): void
    {
        $loader = new AlmacenStagingLoader();
        $loader->loadPaginated('almacenes', self::fetcher([
            AlmacenExtractor::mapRow(['CODALMACEN' => 'AL4', 'NOTAS' => 'central', 'NOMBREALMACEN' => 'Central']),
        ]));
        $loader->loadPaginated('inventarios', self::fetcher([
            InventarioCabExtractor::mapRow(['FECHA' => '2025-12-31 00:00:00', 'CODALMACEN' => 'AL4']),
        ]));

        $this->assertSame('central', DB::table('atlantic_legacy.almacenes')->where('codalmacen', 'AL4')->value('notas'));
        $this->assertSame(1, DB::table('atlantic_legacy.inventarios_cab')->count());
    }

    public function test_costos_y_traspasos_persisten_columnas_tipadas(): void
    {
        $loader = new AlmacenStagingLoader();
        $loader->loadPaginated('costo_almacen', self::fetcher([CostoAlmacenExtractor::mapRow([
            'CODARTICULO' => 9, 'TALLA' => '.', 'COLOR' => '.', 'CODALMACEN' => 'AL4',
            'COSTEMEDIO' => 10.5, 'COSTEMEDIODMN' => 10.5, 'FECHAULTCOMPRA' => '2025-01-02 03:04:05', 'CODMONEDA' => 1,
        ])]));
        $loader->loadPaginated('traspasos', self::fetcher([TraspasoCabExtractor::mapRow([
            'SERIE' => 'T1', 'CAJA' => 'C1', 'NUMERO' => 5, 'TOTAL' => 99.5, 'ANULADO' => 'T',
            'OBSERVACIONES' => 'x', 'CODALMACENORIGEN' => 'AL4', 'CODALMACENDESTINO' => 'AL2', 'IMPRESIONES' => 2,
        ])]));
        $loader->loadPaginated('inventarios', self::fetcher([InventarioCabExtractor::mapRow([
            'FECHA' => '2025-12-31 00:00:00', 'CODALMACEN' => 'AL4', 'ESTADO' => 2, 'ESCIERRE' => 1,
        ])]));

        $costo = DB::table('atlantic_legacy.costo_almacen')->first();
        $this->assertSame(9, $costo->codarticulo);
        $this->assertSame(10.5, (float) $costo->costemedio);
        $this->assertSame(1, $costo->codmoneda);
        $tras = DB::table('atlantic_legacy.traspasos_cab')->first();
        $this->assertSame(5, $tras->numero);
        $this->assertSame(99.5, (float) $tras->total);
        $this->assertSame('AL2', $tras->codalmacendestino);
        $inv = DB::table('atlantic_legacy.inventarios_cab')->first();
        $this->assertSame(2, $inv->estado);
        $this->assertTrue($inv->escierre);
    }

    public function test_huerfanos_lista_almacenes_con_stock_sin_fila_en_almacenes(): void
    {
        $loader = new AlmacenStagingLoader();
        $loader->loadPaginated('almacenes', self::fetcher([AlmacenExtractor::mapRow(['CODALMACEN' => 'AL4'])]));
        $loader->loadPaginated('stock_almacen', self::fetcher([
            self::stockRow('1', 'AL4'), self::stockRow('2', 'A1'), self::stockRow('3', 'A1'),
        ]));

        $this->assertSame(['A1' => 2], (new AlmacenStagingVerifier())->huerfanos());
    }

    public function test_compare_detecta_diferencias_de_conteo_y_de_stock(): void
    {
        $ok = AlmacenStagingVerifier::compare(
            ['AL4' => ['filas' => 2, 'stock' => 10.0]],
            ['AL4' => ['filas' => 2, 'stock' => 10.004]],
            ['almacenes' => 1],
            ['almacenes' => 1],
        );
        $this->assertTrue($ok['ok']);

        $bad = AlmacenStagingVerifier::compare(
            ['AL4' => ['filas' => 2, 'stock' => 10.0], 'AL2' => ['filas' => 1, 'stock' => 1.0]],
            ['AL4' => ['filas' => 2, 'stock' => 12.0]],
            ['almacenes' => 11],
            ['almacenes' => 10],
        );
        $this->assertFalse($bad['ok']);
        $this->assertSame([false, false], array_column($bad['stock'], 'ok'));
        $this->assertFalse($bad['conteos'][0]['ok']);
    }

    public function test_equivalencias_permite_warehouse_nulo_y_estado_propuesto_por_defecto(): void
    {
        $eq = AlmacenEquivalencia::query()->create(['codalmacen_icg' => 'AL2']);

        $fresh = AlmacenEquivalencia::query()->findOrFail($eq->id);
        $this->assertNull($fresh->warehouse_id);
        $this->assertSame('propuesto', $fresh->estado);
    }
}
