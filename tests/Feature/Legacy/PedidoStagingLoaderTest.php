<?php

declare(strict_types=1);

namespace Tests\Feature\Legacy;

use App\Modules\Legacy\Data\ExtractedPedidos;
use App\Modules\Legacy\Data\PedidoItemRow;
use App\Modules\Legacy\Data\PedidoRow;
use App\Modules\Legacy\Models\Pedido;
use App\Modules\Legacy\Models\PedidoItem;
use App\Modules\Legacy\Services\PedidoStagingLoader;
use App\Shared\Multitenancy\Tenant;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-758 — "Loader-A" (staging). Re-correr el mismo extracto dos veces nunca duplica ni pisa
 * mal, y una segunda corrida nunca resetea `legacy_cliente_id`/`orphan` (los pone `PedidoMatcher`,
 * este Loader nunca los toca). Cubre también el patrón delete-then-insert de
 * `legacy.pedido_items` (PEDVENTALIN no declara llave natural propia, ver docblock de la
 * migración) y el gotcha de escala real (173,764 líneas reales, §13.3 del diseño). Mismo patrón
 * de test que `CotizacionStagingLoaderTest`.
 */
final class PedidoStagingLoaderTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'illuminations')->first()?->makeCurrent();
    }

    public function test_load_stagea_pedidos_y_items(): void
    {
        $loader = new PedidoStagingLoader();

        $counts = $loader->load(self::sampleExtraction());

        $this->assertSame(1, $counts['pedidos']);
        $this->assertSame(1, $counts['items']);
        $this->assertCount(1, $counts['pedido_ids']);
        $this->assertDatabaseHas('illuminations_legacy.pedidos', [
            'icg_numserie' => 'A', 'icg_numpedido' => 2001, 'icg_n' => 1,
        ]);
        $this->assertSame(1, PedidoItem::query()->where('icg_codarticulo', 4321)->count());
    }

    public function test_item_referencia_el_id_interno_de_la_cabecera(): void
    {
        $loader = new PedidoStagingLoader();
        $loader->load(self::sampleExtraction());

        $pedido = Pedido::query()
            ->where('icg_numserie', 'A')->where('icg_numpedido', 2001)->where('icg_n', 1)
            ->firstOrFail();
        $item = PedidoItem::query()->where('icg_codarticulo', 4321)->firstOrFail();

        $this->assertSame($pedido->id, $item->legacy_pedido_id);
    }

    public function test_re_correr_el_mismo_extracto_no_duplica_cabeceras_ni_items(): void
    {
        $loader = new PedidoStagingLoader();

        $loader->load(self::sampleExtraction());
        $loader->load(self::sampleExtraction());

        $this->assertSame(1, Pedido::query()->count());
        $this->assertSame(1, PedidoItem::query()->count());
    }

    public function test_re_correr_actualiza_valores_cambiados_en_la_cabecera(): void
    {
        $loader = new PedidoStagingLoader();
        $loader->load(self::sampleExtraction());

        $updated = new ExtractedPedidos(
            pedidos: [self::pedidoRow(icgCodvendedor: 999)],
            items: [],
        );
        $loader->load($updated);

        $pedido = Pedido::query()->where('icg_numpedido', 2001)->firstOrFail();
        $this->assertSame(999, $pedido->icg_codvendedor);
    }

    public function test_re_correr_reemplaza_items_de_la_misma_cabecera_sin_duplicar(): void
    {
        $loader = new PedidoStagingLoader();
        $loader->load(self::sampleExtraction());

        // Mismo header, línea distinta (otro artículo) — el set de items debe reemplazarse, no
        // acumularse.
        $replaced = new ExtractedPedidos(
            pedidos: [self::pedidoRow()],
            items: [self::itemRow(icgCodarticulo: 9999)],
        );
        $loader->load($replaced);

        $this->assertSame(1, PedidoItem::query()->count());
        $this->assertSame(0, PedidoItem::query()->where('icg_codarticulo', 4321)->count());
        $this->assertSame(1, PedidoItem::query()->where('icg_codarticulo', 9999)->count());
    }

    public function test_re_correr_nunca_resetea_legacy_cliente_id_ni_orphan_ya_seteados(): void
    {
        $loader = new PedidoStagingLoader();
        $loader->load(self::sampleExtraction());

        // Simula que PedidoMatcher ya resolvió esta cabecera en una corrida anterior.
        $cliente = \App\Modules\Legacy\Models\Cliente::query()->create([
            'icg_codcliente' => 555, 'razon_social' => 'Cliente Legal SA',
        ]);
        $pedido = Pedido::query()->where('icg_numpedido', 2001)->firstOrFail();
        $pedido->legacy_cliente_id = $cliente->id;
        $pedido->orphan            = false;
        $pedido->save();

        $loader->load(self::sampleExtraction());

        $pedido->refresh();
        $this->assertSame($cliente->id, $pedido->legacy_cliente_id);
        $this->assertFalse($pedido->orphan);
    }

    public function test_item_huerfano_dentro_de_la_corrida_se_descarta_sin_romper_el_resto(): void
    {
        // El item apunta a una cabecera (NUMPEDIDO 9999) que no está en el extracto actual.
        $extraction = new ExtractedPedidos(
            pedidos: [self::pedidoRow()],
            items: [
                self::itemRow(),
                new PedidoItemRow('A', 9999, 1, 1111, null, null, null, null, null),
            ],
        );

        $loader = new PedidoStagingLoader();
        $counts = $loader->load($extraction);

        $this->assertSame(1, $counts['items']);
        $this->assertSame(1, PedidoItem::query()->count());
    }

    /**
     * Mismo gotcha de escala que `CotizacionStagingLoaderTest` (Postgres: máximo 65535 parámetros
     * bindeados por statement) — 600 cabeceras ya superan un solo chunk
     * (`PedidoStagingLoader::CHUNK_SIZE = 500`) sin acercarse al límite real.
     */
    public function test_load_con_mas_cabeceras_que_un_chunk_no_pierde_ni_duplica(): void
    {
        $pedidos = [];
        for ($i = 1; $i <= 600; $i++) {
            $pedidos[] = self::pedidoRow(icgNumpedido: $i);
        }

        $loader = new PedidoStagingLoader();
        $counts = $loader->load(new ExtractedPedidos($pedidos, []));

        $this->assertSame(600, $counts['pedidos']);
        $this->assertCount(600, $counts['pedido_ids']);
        $this->assertSame(600, Pedido::query()->count());
        $this->assertDatabaseHas('illuminations_legacy.pedidos', ['icg_numpedido' => 1]);
        $this->assertDatabaseHas('illuminations_legacy.pedidos', ['icg_numpedido' => 600]);
    }

    /**
     * Chunk-boundary de ITEMS específicamente (no de cabeceras) — 1 sola cabecera con 600 líneas,
     * confirma que el chunking del `insert()`/`delete()` de `legacy.pedido_items` funciona
     * independiente del de `legacy.pedidos`.
     */
    public function test_load_con_mas_items_que_un_chunk_en_una_sola_cabecera_no_pierde_ni_duplica(): void
    {
        $items = [];
        for ($i = 1; $i <= 600; $i++) {
            $items[] = self::itemRow(icgCodarticulo: $i);
        }

        $loader = new PedidoStagingLoader();
        $counts = $loader->load(new ExtractedPedidos([self::pedidoRow()], $items));

        $this->assertSame(600, $counts['items']);
        $this->assertSame(600, PedidoItem::query()->count());
        $this->assertSame(1, PedidoItem::query()->where('icg_codarticulo', 1)->count());
        $this->assertSame(1, PedidoItem::query()->where('icg_codarticulo', 600)->count());
    }

    /**
     * Escala real (§13.3 del diseño) — varios miles de items repartidos en varias cabeceras, bien
     * por encima del límite de un chunk (500) y suficientemente grande para confirmar que el
     * chunking realmente se activa (varias iteraciones de `array_chunk`) sin reventar el límite de
     * 65535 parámetros de Postgres. No es solo el borde de 500/501 — es volumen real simulado.
     */
    public function test_load_a_escala_miles_de_items_no_revienta_limite_de_parametros(): void
    {
        $pedidos = [];
        $items   = [];
        for ($header = 1; $header <= 50; $header++) {
            $pedidos[] = self::pedidoRow(icgNumpedido: $header);
            for ($line = 1; $line <= 100; $line++) {
                $items[] = self::itemRow(icgNumpedido: $header, icgCodarticulo: ($header * 1000) + $line);
            }
        }

        $this->assertCount(5000, $items);

        $loader = new PedidoStagingLoader();
        $counts = $loader->load(new ExtractedPedidos($pedidos, $items));

        $this->assertSame(50, $counts['pedidos']);
        $this->assertSame(5000, $counts['items']);
        $this->assertSame(5000, PedidoItem::query()->count());

        // Verifica que cada línea quedó vinculada a la cabecera correcta, no mezclada entre sí.
        $pedido1  = Pedido::query()->where('icg_numpedido', 1)->firstOrFail();
        $pedido50 = Pedido::query()->where('icg_numpedido', 50)->firstOrFail();
        $this->assertSame(100, PedidoItem::query()->where('legacy_pedido_id', $pedido1->id)->count());
        $this->assertSame(100, PedidoItem::query()->where('legacy_pedido_id', $pedido50->id)->count());
    }

    // ── loadPaginated() — fix preventivo 2026-08-21 (mismo hallazgo de CotizacionExtractor) ────

    public function test_load_paginated_stagea_items_repartidos_en_varias_paginas_sin_perder_ni_duplicar(): void
    {
        $pedidos = [self::pedidoRow()];
        $items   = [];
        for ($i = 1; $i <= 250; $i++) {
            $items[] = self::itemRow(icgCodarticulo: $i);
        }

        $pageSize = 100;
        $fetcher  = static fn (int $offset, int $limit): array => array_values(array_slice($items, $offset, $limit));

        $loader = new PedidoStagingLoader();
        $counts = $loader->loadPaginated($pedidos, $fetcher, $pageSize);

        $this->assertSame(1, $counts['pedidos']);
        $this->assertSame(250, $counts['items']);
        $this->assertSame(250, PedidoItem::query()->count());
        $this->assertSame(1, PedidoItem::query()->where('icg_codarticulo', 1)->count());
        $this->assertSame(1, PedidoItem::query()->where('icg_codarticulo', 250)->count());
    }

    public function test_load_paginated_re_correr_no_duplica_cabeceras_ni_items(): void
    {
        $pedidos = [self::pedidoRow()];
        $items   = [self::itemRow(icgCodarticulo: 4321)];
        $fetcher = static fn (int $offset, int $limit): array => array_values(array_slice($items, $offset, $limit));

        $loader = new PedidoStagingLoader();
        $loader->loadPaginated($pedidos, $fetcher);
        $loader->loadPaginated($pedidos, $fetcher);

        $this->assertSame(1, Pedido::query()->count());
        $this->assertSame(1, PedidoItem::query()->count());
    }

    public function test_load_paginated_item_huerfano_dentro_de_la_corrida_se_descarta_sin_romper_el_resto(): void
    {
        $pedidos = [self::pedidoRow()];
        $items = [
            self::itemRow(),
            new PedidoItemRow('A', 9999, 1, 1111, null, null, null, null, null),
        ];
        $fetcher = static fn (int $offset, int $limit): array => array_values(array_slice($items, $offset, $limit));

        $loader = new PedidoStagingLoader();
        $counts = $loader->loadPaginated($pedidos, $fetcher);

        $this->assertSame(1, $counts['items']);
        $this->assertSame(1, PedidoItem::query()->count());
    }

    private static function pedidoRow(
        int $icgNumpedido = 2001,
        ?int $icgCodvendedor = 77,
    ): PedidoRow {
        return new PedidoRow(
            icgNumserie: 'A',
            icgNumpedido: $icgNumpedido,
            icgN: 1,
            icgCodcliente: 555,
            fechapedido: null,
            fechaentrega: null,
            totneto: 1500.50,
            icgCodvendedor: $icgCodvendedor,
            idestado: 'P',
            icgSeriealbaran: null,
            icgNumeroalbaran: null,
        );
    }

    private static function itemRow(
        int $icgNumpedido = 2001,
        int $icgCodarticulo = 4321,
    ): PedidoItemRow {
        return new PedidoItemRow(
            icgNumserie: 'A',
            icgNumpedido: $icgNumpedido,
            icgN: 1,
            icgCodarticulo: $icgCodarticulo,
            referencia: 'REF-001',
            descripcion: 'Lámpara LED',
            cantidad: 3.0,
            precio: 100.0,
            total: 300.0,
        );
    }

    private static function sampleExtraction(): ExtractedPedidos
    {
        return new ExtractedPedidos(
            pedidos: [self::pedidoRow()],
            items: [self::itemRow()],
        );
    }
}
