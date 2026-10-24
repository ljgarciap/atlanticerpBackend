<?php

declare(strict_types=1);

namespace Tests\Feature\Legacy;

use App\Modules\Legacy\Data\AlbaranCompraItemRow;
use App\Modules\Legacy\Data\AlbaranCompraRow;
use App\Modules\Legacy\Models\AlbaranCompra;
use App\Modules\Legacy\Models\AlbaranCompraItem;
use App\Modules\Legacy\Services\AlbaranCompraIncrementalStagingLoader;
use App\Shared\Multitenancy\Tenant;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-838 — mismo patrón que AlbaranIncrementalStagingLoaderTest: fold XOR de items plegado en
 * el hash de la cabecera (ver docblock de AlbaranCompraIncrementalStagingLoader).
 */
final class AlbaranCompraIncrementalStagingLoaderTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    private function fetcher(array $items): callable
    {
        return static fn (int $offset, int $limit): array => array_values(array_slice($items, $offset, $limit));
    }

    public function test_primera_corrida_cuenta_todo_como_nuevo(): void
    {
        $loader = new AlbaranCompraIncrementalStagingLoader();
        $result = $loader->loadPaginated([self::albaranRow()], $this->fetcher([self::itemRow()]));

        $this->assertSame(1, $result['albaranes']['nuevos']);
        $this->assertCount(1, $result['changed_albaran_ids']);
        $this->assertSame(1, AlbaranCompraItem::query()->count());
    }

    public function test_re_correr_sin_cambios_no_escribe_ni_reprocesa_items(): void
    {
        $loader = new AlbaranCompraIncrementalStagingLoader();
        $loader->loadPaginated([self::albaranRow()], $this->fetcher([self::itemRow()]));

        $result = $loader->loadPaginated([self::albaranRow()], $this->fetcher([self::itemRow()]));

        $this->assertSame(0, $result['albaranes']['nuevos']);
        $this->assertSame(0, $result['albaranes']['cambiados']);
        $this->assertSame(1, $result['albaranes']['sin_cambios']);
        $this->assertSame(0, $result['items']);
        $this->assertSame([], $result['changed_albaran_ids']);
        $this->assertSame(1, AlbaranCompraItem::query()->count());
    }

    public function test_cambio_en_la_cabecera_dispara_reproceso_de_items(): void
    {
        $loader = new AlbaranCompraIncrementalStagingLoader();
        $loader->loadPaginated([self::albaranRow()], $this->fetcher([self::itemRow()]));

        $cambiado = self::albaranRow(facturado: true);
        $result   = $loader->loadPaginated([$cambiado], $this->fetcher([self::itemRow()]));

        $this->assertSame(1, $result['albaranes']['cambiados']);
        $this->assertCount(1, $result['changed_albaran_ids']);
        $albaran = AlbaranCompra::query()->where('icg_numalbaran', 6001)->firstOrFail();
        $this->assertTrue($albaran->facturado);
    }

    public function test_cambio_solo_en_un_item_sin_tocar_la_cabecera_si_se_detecta(): void
    {
        $loader = new AlbaranCompraIncrementalStagingLoader();
        $loader->loadPaginated([self::albaranRow()], $this->fetcher([self::itemRow(icgCodarticulo: 4321)]));

        // Mismo header exacto, solo cambia el artículo de la línea.
        $result = $loader->loadPaginated([self::albaranRow()], $this->fetcher([self::itemRow(icgCodarticulo: 9999)]));

        $this->assertSame(1, $result['albaranes']['cambiados']);
        $this->assertCount(1, $result['changed_albaran_ids']);
        $this->assertSame(0, AlbaranCompraItem::query()->where('icg_codarticulo', 4321)->count());
        $this->assertSame(1, AlbaranCompraItem::query()->where('icg_codarticulo', 9999)->count());
    }

    public function test_load_con_mas_cabeceras_que_un_chunk_no_pierde_ni_duplica(): void
    {
        $albaranes = [];
        for ($i = 1; $i <= 600; $i++) {
            $albaranes[] = self::albaranRow(icgNumalbaran: $i);
        }

        $loader = new AlbaranCompraIncrementalStagingLoader();
        $result = $loader->loadPaginated($albaranes, $this->fetcher([]));

        $this->assertSame(600, $result['albaranes']['nuevos']);
        $this->assertSame(600, AlbaranCompra::query()->count());
        $this->assertCount(600, $result['changed_albaran_ids']);
    }

    private static function albaranRow(int $icgNumalbaran = 6001, bool $facturado = false): AlbaranCompraRow
    {
        return new AlbaranCompraRow(
            icgNumserie: 'C', icgNumalbaran: $icgNumalbaran, icgN: 'B', icgCodproveedor: 42,
            fecha: null, totalneto: 2500.0, facturado: $facturado, icgNumseriefac: null, icgNumfac: null,
        );
    }

    private static function itemRow(int $icgNumalbaran = 6001, int $icgCodarticulo = 4321): AlbaranCompraItemRow
    {
        return new AlbaranCompraItemRow(
            icgNumserie: 'C', icgNumalbaran: $icgNumalbaran, icgN: 'B', icgCodarticulo: $icgCodarticulo,
            referencia: 'REF-001', descripcion: 'Lámpara LED', cantidad: 3.0, precio: 100.0, total: 300.0,
        );
    }
}
