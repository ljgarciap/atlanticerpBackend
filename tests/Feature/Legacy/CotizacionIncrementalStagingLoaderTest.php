<?php

declare(strict_types=1);

namespace Tests\Feature\Legacy;

use App\Modules\Legacy\Data\CotizacionItemRow;
use App\Modules\Legacy\Data\CotizacionRow;
use App\Modules\Legacy\Models\Cotizacion;
use App\Modules\Legacy\Models\CotizacionItem;
use App\Modules\Legacy\Services\CotizacionIncrementalStagingLoader;
use App\Shared\Multitenancy\Tenant;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-838 — variante incremental (hash-compare, con fold XOR de items) de
 * CotizacionStagingLoader. Igual que ProductoIncrementalStagingLoaderTest, el criterio central es
 * que una segunda corrida sin cambios reales no reprocesa nada.
 */
final class CotizacionIncrementalStagingLoaderTest extends FeatureTestCase
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
        $loader = new CotizacionIncrementalStagingLoader();

        $result = $loader->load([self::sampleCotizacion()], $this->fetcherFor(self::sampleItems()));

        $this->assertSame(1, $result['cotizaciones']['nuevos']);
        $this->assertSame(2, $result['items_staged']);
        $this->assertCount(1, $result['changed_ids']);
        $this->assertSame(2, CotizacionItem::query()->count());
    }

    public function test_re_correr_sin_cambios_no_reprocesa_nada(): void
    {
        $loader = new CotizacionIncrementalStagingLoader();
        $loader->load([self::sampleCotizacion()], $this->fetcherFor(self::sampleItems()));

        $result = $loader->load([self::sampleCotizacion()], $this->fetcherFor(self::sampleItems()));

        $this->assertSame(0, $result['cotizaciones']['nuevos']);
        $this->assertSame(0, $result['cotizaciones']['cambiados']);
        $this->assertSame(1, $result['cotizaciones']['sin_cambios']);
        $this->assertSame(0, $result['items_staged']);
        $this->assertSame([], $result['changed_ids']);
        $this->assertSame(2, CotizacionItem::query()->count()); // no se duplicaron ni se borraron
    }

    public function test_reordenar_las_mismas_lineas_no_cuenta_como_cambio(): void
    {
        // El XOR es orden-independiente a propósito — PRESUPUESTOSLIN no garantiza el mismo orden
        // de líneas entre corridas para la misma cabecera (sin columna de secuencia propia).
        $loader = new CotizacionIncrementalStagingLoader();
        $loader->load([self::sampleCotizacion()], $this->fetcherFor(self::sampleItems()));

        $reordenados = array_reverse(self::sampleItems());
        $result = $loader->load([self::sampleCotizacion()], $this->fetcherFor($reordenados));

        $this->assertSame(0, $result['cotizaciones']['cambiados']);
        $this->assertSame([], $result['changed_ids']);
    }

    public function test_un_cambio_solo_en_una_linea_se_detecta_aunque_la_cabecera_no_cambie(): void
    {
        $loader = new CotizacionIncrementalStagingLoader();
        $loader->load([self::sampleCotizacion()], $this->fetcherFor(self::sampleItems()));

        $itemsModificados = self::sampleItems();
        $itemsModificados[0] = new CotizacionItemRow('A', 1, 1, 1, 501, 'REF-1', 'Item 1', 2.0, 999.0, 0.0, 1998.0, 500.0);

        $result = $loader->load([self::sampleCotizacion()], $this->fetcherFor($itemsModificados));

        $this->assertSame(0, $result['cotizaciones']['nuevos']);
        $this->assertSame(1, $result['cotizaciones']['cambiados']); // el fold del XOR cambió
        $this->assertSame(2, $result['items_staged']); // re-stagea las 2 líneas de esa cabecera
        $this->assertCount(1, $result['changed_ids']);

        $precios = CotizacionItem::query()->pluck('precio')->map(fn ($v) => (float) $v)->sort()->values();
        $this->assertTrue($precios->contains(999.0));
    }

    public function test_un_cambio_en_la_cabecera_sin_tocar_items_reinserta_las_mismas_lineas(): void
    {
        $loader = new CotizacionIncrementalStagingLoader();
        $loader->load([self::sampleCotizacion()], $this->fetcherFor(self::sampleItems()));

        $cabeceraModificada = new CotizacionRow('A', 1, 1, 1, 2001, null, 5000.0, 5500.0, 10, 'C', null);
        $result = $loader->load([$cabeceraModificada], $this->fetcherFor(self::sampleItems()));

        $this->assertSame(1, $result['cotizaciones']['cambiados']);
        $this->assertSame(2, $result['items_staged']);
        $this->assertSame(2, CotizacionItem::query()->count()); // no se duplicaron

        $cotizacion = Cotizacion::query()->firstOrFail();
        $this->assertSame(2001, $cotizacion->icg_codcliente);
    }

    private static function sampleCotizacion(): CotizacionRow
    {
        return new CotizacionRow('A', 1, 1, 1, 1001, null, 1000.0, 1100.0, 5, 'P', null);
    }

    /** @return list<CotizacionItemRow> */
    private static function sampleItems(): array
    {
        return [
            new CotizacionItemRow('A', 1, 1, 1, 501, 'REF-1', 'Item 1', 2.0, 100.0, 0.0, 200.0, 50.0),
            new CotizacionItemRow('A', 1, 1, 1, 502, 'REF-2', 'Item 2', 1.0, 300.0, 0.0, 300.0, 90.0),
        ];
    }
}
