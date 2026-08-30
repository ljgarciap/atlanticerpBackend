<?php

declare(strict_types=1);

namespace Tests\Feature\Legacy;

use App\Modules\Legacy\Data\AlbaranItemRow;
use App\Modules\Legacy\Data\AlbaranRow;
use App\Modules\Legacy\Data\ExtractedAlbaranes;
use App\Modules\Legacy\Models\Albaran;
use App\Modules\Legacy\Models\AlbaranItem;
use App\Modules\Legacy\Services\AlbaranStagingLoader;
use App\Shared\Multitenancy\Tenant;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-759 — "Loader-A" (staging). Re-correr el mismo extracto dos veces nunca duplica ni pisa
 * mal, y una segunda corrida nunca resetea `legacy_cliente_id`/`orphan` (los pone `AlbaranMatcher`,
 * este Loader nunca los toca). Cubre también el patrón delete-then-insert de
 * `legacy.albaran_items` (ALBVENTALIN no declara llave natural propia, ver docblock de la
 * migración) y el gotcha de escala real (§13.3 del diseño). Mismo patrón de test que
 * `PedidoStagingLoaderTest`.
 */
final class AlbaranStagingLoaderTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'illuminations')->first()?->makeCurrent();
    }

    public function test_load_stagea_albaranes_y_items(): void
    {
        $loader = new AlbaranStagingLoader();

        $counts = $loader->load(self::sampleExtraction());

        $this->assertSame(1, $counts['albaranes']);
        $this->assertSame(1, $counts['items']);
        $this->assertCount(1, $counts['albaran_ids']);
        $this->assertDatabaseHas('illuminations_legacy.albaranes', [
            'icg_numserie' => 'A', 'icg_numalbaran' => 5001, 'icg_n' => 'B',
        ]);
        $this->assertSame(1, AlbaranItem::query()->where('icg_codarticulo', 4321)->count());
    }

    public function test_item_referencia_el_id_interno_de_la_cabecera(): void
    {
        $loader = new AlbaranStagingLoader();
        $loader->load(self::sampleExtraction());

        $albaran = Albaran::query()
            ->where('icg_numserie', 'A')->where('icg_numalbaran', 5001)->where('icg_n', 'B')
            ->firstOrFail();
        $item = AlbaranItem::query()->where('icg_codarticulo', 4321)->firstOrFail();

        $this->assertSame($albaran->id, $item->legacy_albaran_id);
    }

    public function test_re_correr_el_mismo_extracto_no_duplica_cabeceras_ni_items(): void
    {
        $loader = new AlbaranStagingLoader();

        $loader->load(self::sampleExtraction());
        $loader->load(self::sampleExtraction());

        $this->assertSame(1, Albaran::query()->count());
        $this->assertSame(1, AlbaranItem::query()->count());
    }

    public function test_re_correr_actualiza_valores_cambiados_en_la_cabecera(): void
    {
        $loader = new AlbaranStagingLoader();
        $loader->load(self::sampleExtraction());

        $updated = new ExtractedAlbaranes(
            albaranes: [self::albaranRow(icgCodvendedor: 999)],
            items: [],
        );
        $loader->load($updated);

        $albaran = Albaran::query()->where('icg_numalbaran', 5001)->firstOrFail();
        $this->assertSame(999, $albaran->icg_codvendedor);
    }

    public function test_re_correr_reemplaza_items_de_la_misma_cabecera_sin_duplicar(): void
    {
        $loader = new AlbaranStagingLoader();
        $loader->load(self::sampleExtraction());

        // Mismo header, línea distinta (otro artículo) — el set de items debe reemplazarse, no
        // acumularse.
        $replaced = new ExtractedAlbaranes(
            albaranes: [self::albaranRow()],
            items: [self::itemRow(icgCodarticulo: 9999)],
        );
        $loader->load($replaced);

        $this->assertSame(1, AlbaranItem::query()->count());
        $this->assertSame(0, AlbaranItem::query()->where('icg_codarticulo', 4321)->count());
        $this->assertSame(1, AlbaranItem::query()->where('icg_codarticulo', 9999)->count());
    }

    public function test_re_correr_nunca_resetea_legacy_cliente_id_ni_orphan_ya_seteados(): void
    {
        $loader = new AlbaranStagingLoader();
        $loader->load(self::sampleExtraction());

        // Simula que AlbaranMatcher ya resolvió esta cabecera en una corrida anterior.
        $cliente = \App\Modules\Legacy\Models\Cliente::query()->create([
            'icg_codcliente' => 555, 'razon_social' => 'Cliente Legal SA',
        ]);
        $albaran = Albaran::query()->where('icg_numalbaran', 5001)->firstOrFail();
        $albaran->legacy_cliente_id = $cliente->id;
        $albaran->orphan            = false;
        $albaran->save();

        $loader->load(self::sampleExtraction());

        $albaran->refresh();
        $this->assertSame($cliente->id, $albaran->legacy_cliente_id);
        $this->assertFalse($albaran->orphan);
    }

    public function test_item_huerfano_dentro_de_la_corrida_se_descarta_sin_romper_el_resto(): void
    {
        // El item apunta a una cabecera (NUMALBARAN 9999) que no está en el extracto actual.
        $extraction = new ExtractedAlbaranes(
            albaranes: [self::albaranRow()],
            items: [
                self::itemRow(),
                new AlbaranItemRow('A', 9999, 'B', 1111, null, null, null, null, null),
            ],
        );

        $loader = new AlbaranStagingLoader();
        $counts = $loader->load($extraction);

        $this->assertSame(1, $counts['items']);
        $this->assertSame(1, AlbaranItem::query()->count());
    }

    public function test_facturado_se_persiste_correctamente(): void
    {
        $loader = new AlbaranStagingLoader();
        $loader->load(new ExtractedAlbaranes(
            albaranes: [self::albaranRow(facturado: true)],
            items: [],
        ));

        $albaran = Albaran::query()->where('icg_numalbaran', 5001)->firstOrFail();
        $this->assertTrue($albaran->facturado);
    }

    /**
     * Mismo gotcha de escala que `PedidoStagingLoaderTest` (Postgres: máximo 65535 parámetros
     * bindeados por statement) — 600 cabeceras ya superan un solo chunk
     * (`AlbaranStagingLoader::CHUNK_SIZE = 500`) sin acercarse al límite real.
     */
    public function test_load_con_mas_cabeceras_que_un_chunk_no_pierde_ni_duplica(): void
    {
        $albaranes = [];
        for ($i = 1; $i <= 600; $i++) {
            $albaranes[] = self::albaranRow(icgNumalbaran: $i);
        }

        $loader = new AlbaranStagingLoader();
        $counts = $loader->load(new ExtractedAlbaranes($albaranes, []));

        $this->assertSame(600, $counts['albaranes']);
        $this->assertCount(600, $counts['albaran_ids']);
        $this->assertSame(600, Albaran::query()->count());
        $this->assertDatabaseHas('illuminations_legacy.albaranes', ['icg_numalbaran' => 1]);
        $this->assertDatabaseHas('illuminations_legacy.albaranes', ['icg_numalbaran' => 600]);
    }

    /**
     * Chunk-boundary de ITEMS específicamente (no de cabeceras) — 1 sola cabecera con 600 líneas,
     * confirma que el chunking del `insert()`/`delete()` de `legacy.albaran_items` funciona
     * independiente del de `legacy.albaranes`.
     */
    public function test_load_con_mas_items_que_un_chunk_en_una_sola_cabecera_no_pierde_ni_duplica(): void
    {
        $items = [];
        for ($i = 1; $i <= 600; $i++) {
            $items[] = self::itemRow(icgCodarticulo: $i);
        }

        $loader = new AlbaranStagingLoader();
        $counts = $loader->load(new ExtractedAlbaranes([self::albaranRow()], $items));

        $this->assertSame(600, $counts['items']);
        $this->assertSame(600, AlbaranItem::query()->count());
        $this->assertSame(1, AlbaranItem::query()->where('icg_codarticulo', 1)->count());
        $this->assertSame(1, AlbaranItem::query()->where('icg_codarticulo', 600)->count());
    }

    /**
     * Escala real (§13.3 del diseño) — varios miles de items repartidos en varias cabeceras, bien
     * por encima del límite de un chunk (500) y suficientemente grande para confirmar que el
     * chunking realmente se activa (varias iteraciones de `array_chunk`) sin reventar el límite de
     * 65535 parámetros de Postgres. No es solo el borde de 500/501 — es volumen real simulado.
     */
    public function test_load_a_escala_miles_de_items_no_revienta_limite_de_parametros(): void
    {
        $albaranes = [];
        $items     = [];
        for ($header = 1; $header <= 50; $header++) {
            $albaranes[] = self::albaranRow(icgNumalbaran: $header);
            for ($line = 1; $line <= 100; $line++) {
                $items[] = self::itemRow(icgNumalbaran: $header, icgCodarticulo: ($header * 1000) + $line);
            }
        }

        $this->assertCount(5000, $items);

        $loader = new AlbaranStagingLoader();
        $counts = $loader->load(new ExtractedAlbaranes($albaranes, $items));

        $this->assertSame(50, $counts['albaranes']);
        $this->assertSame(5000, $counts['items']);
        $this->assertSame(5000, AlbaranItem::query()->count());

        // Verifica que cada línea quedó vinculada a la cabecera correcta, no mezclada entre sí.
        $albaran1  = Albaran::query()->where('icg_numalbaran', 1)->firstOrFail();
        $albaran50 = Albaran::query()->where('icg_numalbaran', 50)->firstOrFail();
        $this->assertSame(100, AlbaranItem::query()->where('legacy_albaran_id', $albaran1->id)->count());
        $this->assertSame(100, AlbaranItem::query()->where('legacy_albaran_id', $albaran50->id)->count());
    }

    // ── loadPaginated() — hallazgo real 2026-08-27 (ver docblock de AlbaranExtractor) ──────────

    public function test_load_paginated_stagea_items_repartidos_en_varias_paginas_sin_perder_ni_duplicar(): void
    {
        $albaranes = [self::albaranRow()];
        $items     = [];
        for ($i = 1; $i <= 250; $i++) {
            $items[] = self::itemRow(icgCodarticulo: $i);
        }

        // Páginas de 100 sobre 250 items — 3 llamadas al fetcher (100/100/50), simula lo que hace
        // el Command real contra AlbaranExtractor::extractItemsPage() paginado.
        $pageSize = 100;
        $fetcher  = static function (int $offset, int $limit) use ($items): array {
            return array_values(array_slice($items, $offset, $limit));
        };

        $loader = new AlbaranStagingLoader();
        $counts = $loader->loadPaginated($albaranes, $fetcher, $pageSize);

        $this->assertSame(1, $counts['albaranes']);
        $this->assertSame(250, $counts['items']);
        $this->assertSame(250, AlbaranItem::query()->count());
        $this->assertSame(1, AlbaranItem::query()->where('icg_codarticulo', 1)->count());
        $this->assertSame(1, AlbaranItem::query()->where('icg_codarticulo', 250)->count());
    }

    public function test_load_paginated_el_fetcher_se_llama_con_offsets_crecientes_hasta_pagina_vacia(): void
    {
        $items = [self::itemRow(icgCodarticulo: 1), self::itemRow(icgCodarticulo: 2)];

        $callsOffsets = [];
        $fetcher      = static function (int $offset, int $limit) use ($items, &$callsOffsets): array {
            $callsOffsets[] = $offset;

            return array_values(array_slice($items, $offset, $limit));
        };

        $loader = new AlbaranStagingLoader();
        $loader->loadPaginated([self::albaranRow()], $fetcher, pageSize: 1);

        // 2 items con pageSize=1 → offsets 0 y 1 devuelven 1 fila cada uno (llenan la página, hay
        // que seguir pidiendo), offset 2 devuelve vacío y corta el loop.
        $this->assertSame([0, 1, 2], $callsOffsets);
    }

    public function test_load_paginated_re_correr_no_duplica_cabeceras_ni_items(): void
    {
        $albaranes = [self::albaranRow()];
        $items     = [self::itemRow(icgCodarticulo: 4321)];
        $fetcher   = static fn (int $offset, int $limit): array => array_values(array_slice($items, $offset, $limit));

        $loader = new AlbaranStagingLoader();
        $loader->loadPaginated($albaranes, $fetcher);
        $loader->loadPaginated($albaranes, $fetcher);

        $this->assertSame(1, Albaran::query()->count());
        $this->assertSame(1, AlbaranItem::query()->count());
    }

    public function test_load_paginated_item_huerfano_dentro_de_la_corrida_se_descarta_sin_romper_el_resto(): void
    {
        $albaranes = [self::albaranRow()];
        $items = [
            self::itemRow(),
            new AlbaranItemRow('A', 9999, 'B', 1111, null, null, null, null, null),
        ];
        $fetcher = static fn (int $offset, int $limit): array => array_values(array_slice($items, $offset, $limit));

        $loader = new AlbaranStagingLoader();
        $counts = $loader->loadPaginated($albaranes, $fetcher);

        $this->assertSame(1, $counts['items']);
        $this->assertSame(1, AlbaranItem::query()->count());
    }

    private static function albaranRow(
        int $icgNumalbaran = 5001,
        ?int $icgCodvendedor = 77,
        bool $facturado = false,
    ): AlbaranRow {
        return new AlbaranRow(
            icgNumserie: 'A',
            icgNumalbaran: $icgNumalbaran,
            icgN: 'B',
            icgCodcliente: 555,
            fecha: null,
            totalneto: 1500.50,
            facturado: $facturado,
            icgCodvendedor: $icgCodvendedor,
            icgNumseriefac: null,
            icgNumfac: null,
        );
    }

    private static function itemRow(
        int $icgNumalbaran = 5001,
        int $icgCodarticulo = 4321,
    ): AlbaranItemRow {
        return new AlbaranItemRow(
            icgNumserie: 'A',
            icgNumalbaran: $icgNumalbaran,
            icgN: 'B',
            icgCodarticulo: $icgCodarticulo,
            referencia: 'REF-001',
            descripcion: 'Lámpara LED',
            cantidad: 3.0,
            precio: 100.0,
            total: 300.0,
        );
    }

    private static function sampleExtraction(): ExtractedAlbaranes
    {
        return new ExtractedAlbaranes(
            albaranes: [self::albaranRow()],
            items: [self::itemRow()],
        );
    }
}
