<?php

declare(strict_types=1);

namespace Tests\Feature\Legacy;

use App\Modules\Legacy\Data\AlbaranItemRow;
use App\Modules\Legacy\Data\AlbaranRow;
use App\Modules\Legacy\Models\Albaran;
use App\Modules\Legacy\Models\AlbaranItem;
use App\Modules\Legacy\Services\AlbaranIncrementalStagingLoader;
use App\Shared\Multitenancy\Tenant;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-838 — variante incremental de AlbaranStagingLoader. El hash es SOLO de cabecera (ver
 * docblock de AlbaranIncrementalStagingLoader) — un cambio SOLO en un item, sin tocar ningún campo
 * de la cabecera, NO se detecta por este mecanismo (trade-off documentado y aceptado, no es un
 * bug): evitarlo requeriría materializar todas las líneas de cada cabecera en memoria antes de
 * poder hashear, reintroduciendo el mismo OOM real que la paginación de items ya resolvió.
 */
final class AlbaranIncrementalStagingLoaderTest extends FeatureTestCase
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

    public function test_primera_corrida_cuenta_todo_como_nuevo_y_cambiado(): void
    {
        $loader = new AlbaranIncrementalStagingLoader();
        $result = $loader->loadPaginated([self::albaranRow()], $this->fetcher([self::itemRow()]));

        $this->assertSame(1, $result['albaranes']['nuevos']);
        $this->assertSame(0, $result['albaranes']['cambiados']);
        $this->assertSame(1, $result['items']);
        $this->assertCount(1, $result['changed_albaran_ids']);
        $this->assertSame(1, AlbaranItem::query()->count());
    }

    public function test_re_correr_sin_cambios_no_escribe_ni_reprocesa_items(): void
    {
        $loader = new AlbaranIncrementalStagingLoader();
        $loader->loadPaginated([self::albaranRow()], $this->fetcher([self::itemRow()]));

        $albaran = Albaran::query()->where('icg_numalbaran', 5001)->firstOrFail();
        $updatedAtAntes = $albaran->created_at; // legacy.albaranes no tiene updated_at

        $result = $loader->loadPaginated([self::albaranRow()], $this->fetcher([self::itemRow()]));

        $this->assertSame(0, $result['albaranes']['nuevos']);
        $this->assertSame(0, $result['albaranes']['cambiados']);
        $this->assertSame(1, $result['albaranes']['sin_cambios']);
        $this->assertSame(0, $result['items']); // no se reinsertó nada
        $this->assertSame([], $result['changed_albaran_ids']);
        $this->assertSame(1, AlbaranItem::query()->count()); // el item original sigue ahí, intacto
    }

    public function test_cambio_en_la_cabecera_dispara_reproceso_de_items(): void
    {
        $loader = new AlbaranIncrementalStagingLoader();
        $loader->loadPaginated([self::albaranRow()], $this->fetcher([self::itemRow()]));

        $cambiado = self::albaranRow(icgCodvendedor: 999);
        $result   = $loader->loadPaginated([$cambiado], $this->fetcher([self::itemRow(icgCodarticulo: 4321)]));

        $this->assertSame(1, $result['albaranes']['cambiados']);
        $this->assertCount(1, $result['changed_albaran_ids']);
        $albaran = Albaran::query()->where('icg_numalbaran', 5001)->firstOrFail();
        $this->assertSame(999, $albaran->icg_codvendedor);
    }

    /**
     * CORREGIDO 2026-09-14 — la primera versión de esta clase hasheaba solo la cabecera y este test
     * documentaba la limitación como aceptada. El fold XOR de items (ver docblock de la clase)
     * resuelve exactamente este caso: un item que cambia SOLO su propio contenido, sin que la
     * cabecera cambie ningún campo propio, SÍ dispara reproceso vía el `items_xor` incluido en el
     * hash de la cabecera.
     */
    public function test_cambio_solo_en_un_item_sin_tocar_la_cabecera_si_se_detecta(): void
    {
        $loader = new AlbaranIncrementalStagingLoader();
        $loader->loadPaginated([self::albaranRow()], $this->fetcher([self::itemRow(icgCodarticulo: 4321)]));

        // Mismo header exacto, pero el item ahora es un artículo distinto — el header en sí no
        // cambió ningún campo propio, pero el items_xor sí cambia.
        $result = $loader->loadPaginated([self::albaranRow()], $this->fetcher([self::itemRow(icgCodarticulo: 9999)]));

        $this->assertSame(1, $result['albaranes']['cambiados']);
        $this->assertCount(1, $result['changed_albaran_ids']);
        // El item viejo se reemplazó por el nuevo — el reproceso sí se disparó.
        $this->assertSame(0, AlbaranItem::query()->where('icg_codarticulo', 4321)->count());
        $this->assertSame(1, AlbaranItem::query()->where('icg_codarticulo', 9999)->count());
    }

    public function test_item_de_cabecera_sin_cambios_se_descarta_sin_tocar_postgres_aunque_llegue_en_el_extracto(): void
    {
        $loader = new AlbaranIncrementalStagingLoader();
        $loader->loadPaginated([self::albaranRow(icgNumalbaran: 1), self::albaranRow(icgNumalbaran: 2)], $this->fetcher([
            self::itemRow(icgNumalbaran: 1, icgCodarticulo: 111),
            self::itemRow(icgNumalbaran: 2, icgCodarticulo: 222),
        ]));

        // Solo la cabecera 2 cambia en la segunda corrida.
        $result = $loader->loadPaginated(
            [self::albaranRow(icgNumalbaran: 1), self::albaranRow(icgNumalbaran: 2, icgCodvendedor: 999)],
            $this->fetcher([
                self::itemRow(icgNumalbaran: 1, icgCodarticulo: 111),
                self::itemRow(icgNumalbaran: 2, icgCodarticulo: 333),
            ]),
        );

        $this->assertCount(1, $result['changed_albaran_ids']);
        $this->assertSame(1, $result['items']); // solo se insertó la línea de la cabecera 2

        $albaran1 = Albaran::query()->where('icg_numalbaran', 1)->firstOrFail();
        $albaran2 = Albaran::query()->where('icg_numalbaran', 2)->firstOrFail();
        $this->assertSame(1, AlbaranItem::query()->where('legacy_albaran_id', $albaran1->id)->where('icg_codarticulo', 111)->count());
        $this->assertSame(1, AlbaranItem::query()->where('legacy_albaran_id', $albaran2->id)->where('icg_codarticulo', 333)->count());
    }

    public function test_load_con_mas_cabeceras_que_un_chunk_no_pierde_ni_duplica(): void
    {
        $albaranes = [];
        for ($i = 1; $i <= 600; $i++) {
            $albaranes[] = self::albaranRow(icgNumalbaran: $i);
        }

        $loader = new AlbaranIncrementalStagingLoader();
        $result = $loader->loadPaginated($albaranes, $this->fetcher([]));

        $this->assertSame(600, $result['albaranes']['nuevos']);
        $this->assertSame(600, Albaran::query()->count());
        $this->assertCount(600, $result['changed_albaran_ids']);
    }

    private static function albaranRow(int $icgNumalbaran = 5001, ?int $icgCodvendedor = 77): AlbaranRow
    {
        return new AlbaranRow(
            icgNumserie: 'A', icgNumalbaran: $icgNumalbaran, icgN: 'B', icgCodcliente: 555,
            fecha: null, totalneto: 1500.50, facturado: false, icgCodvendedor: $icgCodvendedor,
            icgNumseriefac: null, icgNumfac: null,
        );
    }

    private static function itemRow(int $icgNumalbaran = 5001, int $icgCodarticulo = 4321): AlbaranItemRow
    {
        return new AlbaranItemRow(
            icgNumserie: 'A', icgNumalbaran: $icgNumalbaran, icgN: 'B', icgCodarticulo: $icgCodarticulo,
            referencia: 'REF-001', descripcion: 'Lámpara LED', cantidad: 3.0, precio: 100.0, total: 300.0,
        );
    }
}
