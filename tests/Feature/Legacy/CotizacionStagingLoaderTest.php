<?php

declare(strict_types=1);

namespace Tests\Feature\Legacy;

use App\Modules\Legacy\Data\CotizacionItemRow;
use App\Modules\Legacy\Data\CotizacionRow;
use App\Modules\Legacy\Data\ExtractedCotizaciones;
use App\Modules\Legacy\Models\Cotizacion;
use App\Modules\Legacy\Models\CotizacionItem;
use App\Modules\Legacy\Services\CotizacionStagingLoader;
use App\Shared\Multitenancy\Tenant;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-757 — "Loader-A" (staging). Re-correr el mismo extracto dos veces nunca duplica ni pisa
 * mal, y una segunda corrida nunca resetea `legacy_cliente_id`/`orphan` (los pone
 * `CotizacionMatcher`, este Loader nunca los toca). Cubre también el patrón delete-then-insert de
 * `legacy.cotizacion_items` (PRESUPUESTOSLIN no declara llave natural propia, ver docblock de la
 * migración) y el gotcha de escala real (715,530 líneas reales, §13.3 del diseño).
 */
final class CotizacionStagingLoaderTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    public function test_load_stagea_cotizaciones_y_items(): void
    {
        $loader = new CotizacionStagingLoader();

        $counts = $loader->load(self::sampleExtraction());

        $this->assertSame(1, $counts['cotizaciones']);
        $this->assertSame(1, $counts['items']);
        $this->assertCount(1, $counts['cotizacion_ids']);
        $this->assertDatabaseHas('atlantic_legacy.cotizaciones', [
            'icg_numserie' => 'A', 'icg_numpresupuesto' => 1001, 'icg_n' => 1, 'icg_version' => 2,
        ]);
        $this->assertSame(1, CotizacionItem::query()->where('icg_codarticulo', 4321)->count());
    }

    public function test_item_referencia_el_id_interno_de_la_cabecera(): void
    {
        $loader = new CotizacionStagingLoader();
        $loader->load(self::sampleExtraction());

        $cotizacion = Cotizacion::query()
            ->where('icg_numserie', 'A')->where('icg_numpresupuesto', 1001)
            ->where('icg_n', 1)->where('icg_version', 2)
            ->firstOrFail();
        $item = CotizacionItem::query()->where('icg_codarticulo', 4321)->firstOrFail();

        $this->assertSame($cotizacion->id, $item->legacy_cotizacion_id);
    }

    public function test_re_correr_el_mismo_extracto_no_duplica_cabeceras_ni_items(): void
    {
        $loader = new CotizacionStagingLoader();

        $loader->load(self::sampleExtraction());
        $loader->load(self::sampleExtraction());

        $this->assertSame(1, Cotizacion::query()->count());
        $this->assertSame(1, CotizacionItem::query()->count());
    }

    public function test_re_correr_actualiza_valores_cambiados_en_la_cabecera(): void
    {
        $loader = new CotizacionStagingLoader();
        $loader->load(self::sampleExtraction());

        $updated = new ExtractedCotizaciones(
            cotizaciones: [self::cotizacionRow(icgCodvendedor: 999)],
            items: [],
        );
        $loader->load($updated);

        $cotizacion = Cotizacion::query()->where('icg_numpresupuesto', 1001)->firstOrFail();
        $this->assertSame(999, $cotizacion->icg_codvendedor);
    }

    public function test_re_correr_reemplaza_items_de_la_misma_cabecera_sin_duplicar(): void
    {
        $loader = new CotizacionStagingLoader();
        $loader->load(self::sampleExtraction());

        // Mismo header, línea distinta (otro artículo) — el set de items debe reemplazarse, no
        // acumularse.
        $replaced = new ExtractedCotizaciones(
            cotizaciones: [self::cotizacionRow()],
            items: [self::itemRow(icgCodarticulo: 9999)],
        );
        $loader->load($replaced);

        $this->assertSame(1, CotizacionItem::query()->count());
        $this->assertSame(0, CotizacionItem::query()->where('icg_codarticulo', 4321)->count());
        $this->assertSame(1, CotizacionItem::query()->where('icg_codarticulo', 9999)->count());
    }

    public function test_re_correr_nunca_resetea_legacy_cliente_id_ni_orphan_ya_seteados(): void
    {
        $loader = new CotizacionStagingLoader();
        $loader->load(self::sampleExtraction());

        // Simula que CotizacionMatcher ya resolvió esta cabecera en una corrida anterior.
        $cliente = \App\Modules\Legacy\Models\Cliente::query()->create([
            'icg_codcliente' => 555, 'razon_social' => 'Cliente Legal SA',
        ]);
        $cotizacion = Cotizacion::query()->where('icg_numpresupuesto', 1001)->firstOrFail();
        $cotizacion->legacy_cliente_id = $cliente->id;
        $cotizacion->orphan            = false;
        $cotizacion->save();

        $loader->load(self::sampleExtraction());

        $cotizacion->refresh();
        $this->assertSame($cliente->id, $cotizacion->legacy_cliente_id);
        $this->assertFalse($cotizacion->orphan);
    }

    public function test_item_huerfano_dentro_de_la_corrida_se_descarta_sin_romper_el_resto(): void
    {
        // El item apunta a una cabecera (NUMPRESUPUESTO 9999) que no está en el extracto actual.
        $extraction = new ExtractedCotizaciones(
            cotizaciones: [self::cotizacionRow()],
            items: [
                self::itemRow(),
                new CotizacionItemRow('A', 9999, 1, 1, 1111, null, null, null, null, null, null, null),
            ],
        );

        $loader = new CotizacionStagingLoader();
        $counts = $loader->load($extraction);

        $this->assertSame(1, $counts['items']);
        $this->assertSame(1, CotizacionItem::query()->count());
    }

    /**
     * Mismo gotcha de escala que `ClienteStagingLoaderTest`/`ProductoStagingLoaderTest` (Postgres:
     * máximo 65535 parámetros bindeados por statement) — 600 cabeceras ya superan un solo chunk
     * (`CotizacionStagingLoader::CHUNK_SIZE = 500`) sin acercarse al límite real.
     */
    public function test_load_con_mas_cabeceras_que_un_chunk_no_pierde_ni_duplica(): void
    {
        $cotizaciones = [];
        for ($i = 1; $i <= 600; $i++) {
            $cotizaciones[] = self::cotizacionRow(icgNumpresupuesto: $i);
        }

        $loader = new CotizacionStagingLoader();
        $counts = $loader->load(new ExtractedCotizaciones($cotizaciones, []));

        $this->assertSame(600, $counts['cotizaciones']);
        $this->assertCount(600, $counts['cotizacion_ids']);
        $this->assertSame(600, Cotizacion::query()->count());
        $this->assertDatabaseHas('atlantic_legacy.cotizaciones', ['icg_numpresupuesto' => 1]);
        $this->assertDatabaseHas('atlantic_legacy.cotizaciones', ['icg_numpresupuesto' => 600]);
    }

    /**
     * Chunk-boundary de ITEMS específicamente (no de cabeceras) — 1 sola cabecera con 600 líneas,
     * confirma que el chunking del `insert()`/`delete()` de `legacy.cotizacion_items` funciona
     * independiente del de `legacy.cotizaciones`.
     */
    public function test_load_con_mas_items_que_un_chunk_en_una_sola_cabecera_no_pierde_ni_duplica(): void
    {
        $items = [];
        for ($i = 1; $i <= 600; $i++) {
            $items[] = self::itemRow(icgCodarticulo: $i);
        }

        $loader = new CotizacionStagingLoader();
        $counts = $loader->load(new ExtractedCotizaciones([self::cotizacionRow()], $items));

        $this->assertSame(600, $counts['items']);
        $this->assertSame(600, CotizacionItem::query()->count());
        $this->assertSame(1, CotizacionItem::query()->where('icg_codarticulo', 1)->count());
        $this->assertSame(1, CotizacionItem::query()->where('icg_codarticulo', 600)->count());
    }

    /**
     * Escala real (§13.3 del diseño) — varios miles de items repartidos en varias cabeceras,
     * bien por encima del límite de un chunk (500) y suficientemente grande para confirmar que el
     * chunking realmente se activa (varias iteraciones de `array_chunk`) sin reventar el límite de
     * 65535 parámetros de Postgres. No es solo el borde de 500/501 — es volumen real simulado.
     */
    public function test_load_a_escala_miles_de_items_no_revienta_limite_de_parametros(): void
    {
        $cotizaciones = [];
        $items        = [];
        for ($header = 1; $header <= 50; $header++) {
            $cotizaciones[] = self::cotizacionRow(icgNumpresupuesto: $header);
            for ($line = 1; $line <= 100; $line++) {
                $items[] = self::itemRow(icgNumpresupuesto: $header, icgCodarticulo: ($header * 1000) + $line);
            }
        }

        $this->assertCount(5000, $items);

        $loader = new CotizacionStagingLoader();
        $counts = $loader->load(new ExtractedCotizaciones($cotizaciones, $items));

        $this->assertSame(50, $counts['cotizaciones']);
        $this->assertSame(5000, $counts['items']);
        $this->assertSame(5000, CotizacionItem::query()->count());

        // Verifica que cada línea quedó vinculada a la cabecera correcta, no mezclada entre sí.
        $cotizacion1  = Cotizacion::query()->where('icg_numpresupuesto', 1)->firstOrFail();
        $cotizacion50 = Cotizacion::query()->where('icg_numpresupuesto', 50)->firstOrFail();
        $this->assertSame(100, CotizacionItem::query()->where('legacy_cotizacion_id', $cotizacion1->id)->count());
        $this->assertSame(100, CotizacionItem::query()->where('legacy_cotizacion_id', $cotizacion50->id)->count());
    }

    // ── loadPaginated() — hallazgo real 2026-08-21 (ver docblock de CotizacionExtractor) ───────

    public function test_load_paginated_stagea_items_repartidos_en_varias_paginas_sin_perder_ni_duplicar(): void
    {
        $cotizaciones = [self::cotizacionRow()];
        $items        = [];
        for ($i = 1; $i <= 250; $i++) {
            $items[] = self::itemRow(icgCodarticulo: $i);
        }

        // Páginas de 100 sobre 250 items — 3 llamadas al fetcher (100/100/50), simula lo que hace
        // el Command real contra CotizacionExtractor::extractItemsPage() paginado.
        $pageSize = 100;
        $fetcher  = static function (int $offset, int $limit) use ($items): array {
            return array_values(array_slice($items, $offset, $limit));
        };

        $loader = new CotizacionStagingLoader();
        $counts = $loader->loadPaginated($cotizaciones, $fetcher, $pageSize);

        $this->assertSame(1, $counts['cotizaciones']);
        $this->assertSame(250, $counts['items']);
        $this->assertSame(250, CotizacionItem::query()->count());
        $this->assertSame(1, CotizacionItem::query()->where('icg_codarticulo', 1)->count());
        $this->assertSame(1, CotizacionItem::query()->where('icg_codarticulo', 250)->count());
    }

    public function test_load_paginated_el_fetcher_se_llama_con_offsets_crecientes_hasta_pagina_vacia(): void
    {
        $items = [self::itemRow(icgCodarticulo: 1), self::itemRow(icgCodarticulo: 2)];

        $callsOffsets = [];
        $fetcher      = static function (int $offset, int $limit) use ($items, &$callsOffsets): array {
            $callsOffsets[] = $offset;

            return array_values(array_slice($items, $offset, $limit));
        };

        $loader = new CotizacionStagingLoader();
        $loader->loadPaginated([self::cotizacionRow()], $fetcher, pageSize: 1);

        // 2 items con pageSize=1 → offsets 0 y 1 devuelven 1 fila cada uno (llenan la página, hay
        // que seguir pidiendo), offset 2 devuelve vacío y corta el loop.
        $this->assertSame([0, 1, 2], $callsOffsets);
    }

    public function test_load_paginated_re_correr_no_duplica_cabeceras_ni_items(): void
    {
        $cotizaciones = [self::cotizacionRow()];
        $items        = [self::itemRow(icgCodarticulo: 4321)];
        $fetcher      = static fn (int $offset, int $limit): array => array_values(array_slice($items, $offset, $limit));

        $loader = new CotizacionStagingLoader();
        $loader->loadPaginated($cotizaciones, $fetcher);
        $loader->loadPaginated($cotizaciones, $fetcher);

        $this->assertSame(1, Cotizacion::query()->count());
        $this->assertSame(1, CotizacionItem::query()->count());
    }

    public function test_load_paginated_item_huerfano_dentro_de_la_corrida_se_descarta_sin_romper_el_resto(): void
    {
        $cotizaciones = [self::cotizacionRow()];
        $items = [
            self::itemRow(),
            new CotizacionItemRow('A', 9999, 1, 1, 1111, null, null, null, null, null, null, null),
        ];
        $fetcher = static fn (int $offset, int $limit): array => array_values(array_slice($items, $offset, $limit));

        $loader = new CotizacionStagingLoader();
        $counts = $loader->loadPaginated($cotizaciones, $fetcher);

        $this->assertSame(1, $counts['items']);
        $this->assertSame(1, CotizacionItem::query()->count());
    }

    private static function cotizacionRow(
        int $icgNumpresupuesto = 1001,
        ?int $icgCodvendedor = 77,
    ): CotizacionRow {
        return new CotizacionRow(
            icgNumserie: 'A',
            icgNumpresupuesto: $icgNumpresupuesto,
            icgN: 1,
            icgVersion: 2,
            icgCodcliente: 555,
            fecha: null,
            totalNeto: 1500.50,
            totalBruto: 1650.75,
            icgCodvendedor: $icgCodvendedor,
            idestado: 'P',
            supresupuesto: null,
        );
    }

    private static function itemRow(
        int $icgNumpresupuesto = 1001,
        int $icgCodarticulo = 4321,
    ): CotizacionItemRow {
        return new CotizacionItemRow(
            icgNumserie: 'A',
            icgNumpresupuesto: $icgNumpresupuesto,
            icgN: 1,
            icgVersion: 2,
            icgCodarticulo: $icgCodarticulo,
            referencia: 'REF-001',
            descripcion: 'Lámpara LED',
            cantidad: 3.0,
            precio: 100.0,
            descuento: 10.0,
            total: 270.0,
            costo: 60.0,
        );
    }

    private static function sampleExtraction(): ExtractedCotizaciones
    {
        return new ExtractedCotizaciones(
            cotizaciones: [self::cotizacionRow()],
            items: [self::itemRow()],
        );
    }
}
