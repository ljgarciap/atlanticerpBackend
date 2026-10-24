<?php

declare(strict_types=1);

namespace Tests\Feature\Legacy;

use App\Modules\Legacy\Data\PedidoItemRow;
use App\Modules\Legacy\Data\PedidoRow;
use App\Modules\Legacy\Models\Pedido;
use App\Modules\Legacy\Models\PedidoItem;
use App\Modules\Legacy\Services\PedidoIncrementalStagingLoader;
use App\Shared\Multitenancy\Tenant;
use Tests\Feature\FeatureTestCase;

final class PedidoIncrementalStagingLoaderTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    private function fetcherFor(array $items): callable
    {
        return static fn (int $offset, int $limit): array => array_values(array_slice($items, $offset, $limit));
    }

    public function test_primera_corrida_cuenta_todo_como_nuevo_y_stagea_items(): void
    {
        $loader = new PedidoIncrementalStagingLoader();

        $result = $loader->load([self::samplePedido()], $this->fetcherFor(self::sampleItems()));

        $this->assertSame(1, $result['pedidos']['nuevos']);
        $this->assertSame(2, $result['items_staged']);
        $this->assertCount(1, $result['changed_ids']);
        $this->assertSame(2, PedidoItem::query()->count());
    }

    public function test_re_correr_sin_cambios_no_reprocesa_nada(): void
    {
        $loader = new PedidoIncrementalStagingLoader();
        $loader->load([self::samplePedido()], $this->fetcherFor(self::sampleItems()));

        $result = $loader->load([self::samplePedido()], $this->fetcherFor(self::sampleItems()));

        $this->assertSame(0, $result['pedidos']['nuevos']);
        $this->assertSame(0, $result['pedidos']['cambiados']);
        $this->assertSame(1, $result['pedidos']['sin_cambios']);
        $this->assertSame(0, $result['items_staged']);
        $this->assertSame([], $result['changed_ids']);
        $this->assertSame(2, PedidoItem::query()->count());
    }

    public function test_un_cambio_solo_en_una_linea_se_detecta_aunque_la_cabecera_no_cambie(): void
    {
        $loader = new PedidoIncrementalStagingLoader();
        $loader->load([self::samplePedido()], $this->fetcherFor(self::sampleItems()));

        $itemsModificados = self::sampleItems();
        $itemsModificados[0] = new PedidoItemRow('A', 1, 1, 501, 'REF-1', 'Item 1', 2.0, 999.0, 1998.0);

        $result = $loader->load([self::samplePedido()], $this->fetcherFor($itemsModificados));

        $this->assertSame(0, $result['pedidos']['nuevos']);
        $this->assertSame(1, $result['pedidos']['cambiados']);
        $this->assertSame(2, $result['items_staged']);

        $precios = PedidoItem::query()->pluck('precio')->map(fn ($v) => (float) $v)->sort()->values();
        $this->assertTrue($precios->contains(999.0));
    }

    public function test_circuit_breaker_baseline_no_se_evalua_en_dry_run_ni_en_primera_carga(): void
    {
        // No hay circuit breaker en el Loader (vive en el comando) — este test solo confirma que
        // el Loader nunca frena nada por su cuenta, es puramente de staging.
        // array_fill() evalúa su argumento $value UNA sola vez y copia esa misma referencia en
        // las 5 posiciones — no llama a samplePedidoWithSuffix() 5 veces (bug real de fixture
        // encontrado en esta sesión, 2026-09-14: las 5 filas terminaban con la MISMA llave natural,
        // lo que Postgres correctamente rechaza como "ON CONFLICT DO UPDATE command cannot affect
        // row a second time"). array_map() sí invoca la función una vez por elemento.
        $loader = new PedidoIncrementalStagingLoader();
        $result = $loader->load(
            array_map(static fn (): PedidoRow => self::samplePedidoWithSuffix(), range(1, 5)),
            $this->fetcherFor([]),
        );

        // 5 pedidos "iguales" en memoria pero con distinta llave natural (ver samplePedidoWithSuffix)
        // deben contarse todos como nuevos, el Loader no impone ningún umbral.
        $this->assertSame(5, $result['pedidos']['nuevos']);
    }

    private static int $suffixCounter = 0;

    private static function samplePedidoWithSuffix(): PedidoRow
    {
        self::$suffixCounter++;

        return new PedidoRow('A', self::$suffixCounter, 1, 1001, null, null, 500.0, 5, 'P', null, null);
    }

    private static function samplePedido(): PedidoRow
    {
        return new PedidoRow('A', 1, 1, 1001, null, null, 500.0, 5, 'P', null, null);
    }

    /** @return list<PedidoItemRow> */
    private static function sampleItems(): array
    {
        return [
            new PedidoItemRow('A', 1, 1, 501, 'REF-1', 'Item 1', 2.0, 100.0, 200.0),
            new PedidoItemRow('A', 1, 1, 502, 'REF-2', 'Item 2', 1.0, 300.0, 300.0),
        ];
    }
}
