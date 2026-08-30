<?php

declare(strict_types=1);

namespace Tests\Feature\Legacy;

use App\Modules\Legacy\Data\ExtractedFacturas;
use App\Modules\Legacy\Data\FacturaRow;
use App\Modules\Legacy\Models\Factura;
use App\Modules\Legacy\Services\FacturaStagingLoader;
use App\Shared\Multitenancy\Tenant;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-760 — "Loader-A" (staging). Re-correr el mismo extracto dos veces nunca duplica ni pisa
 * mal, y una segunda corrida nunca resetea `legacy_cliente_id`/`orphan` (los pone `FacturaMatcher`,
 * este Loader nunca los toca). Sin líneas (a diferencia de `AlbaranStagingLoaderTest`) — mismo
 * patrón de test que `AlbaranStagingLoaderTest`, adaptado a una sola tabla.
 */
final class FacturaStagingLoaderTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'illuminations')->first()?->makeCurrent();
    }

    public function test_load_stagea_facturas(): void
    {
        $loader = new FacturaStagingLoader();

        $counts = $loader->load(self::sampleExtraction());

        $this->assertSame(1, $counts['facturas']);
        $this->assertCount(1, $counts['factura_ids']);
        $this->assertDatabaseHas('illuminations_legacy.facturas', [
            'icg_numserie' => 'A', 'icg_numfactura' => 9001, 'icg_n' => 'B',
        ]);
    }

    public function test_re_correr_el_mismo_extracto_no_duplica_cabeceras(): void
    {
        $loader = new FacturaStagingLoader();

        $loader->load(self::sampleExtraction());
        $loader->load(self::sampleExtraction());

        $this->assertSame(1, Factura::query()->count());
    }

    public function test_re_correr_actualiza_valores_cambiados_en_la_cabecera(): void
    {
        $loader = new FacturaStagingLoader();
        $loader->load(self::sampleExtraction());

        $updated = new ExtractedFacturas(facturas: [self::facturaRow(icgCodvendedor: 999)]);
        $loader->load($updated);

        $factura = Factura::query()->where('icg_numfactura', 9001)->firstOrFail();
        $this->assertSame(999, $factura->icg_codvendedor);
    }

    public function test_re_correr_nunca_resetea_legacy_cliente_id_ni_orphan_ya_seteados(): void
    {
        $loader = new FacturaStagingLoader();
        $loader->load(self::sampleExtraction());

        // Simula que FacturaMatcher ya resolvió esta cabecera en una corrida anterior.
        $cliente = \App\Modules\Legacy\Models\Cliente::query()->create([
            'icg_codcliente' => 555, 'razon_social' => 'Cliente Legal SA',
        ]);
        $factura = Factura::query()->where('icg_numfactura', 9001)->firstOrFail();
        $factura->legacy_cliente_id = $cliente->id;
        $factura->orphan            = false;
        $factura->save();

        $loader->load(self::sampleExtraction());

        $factura->refresh();
        $this->assertSame($cliente->id, $factura->legacy_cliente_id);
        $this->assertFalse($factura->orphan);
    }

    /**
     * Mismo gotcha de escala que `AlbaranStagingLoaderTest` (Postgres: máximo 65535 parámetros
     * bindeados por statement) — 600 cabeceras ya superan un solo chunk
     * (`FacturaStagingLoader::CHUNK_SIZE = 500`) sin acercarse al límite real.
     */
    public function test_load_con_mas_cabeceras_que_un_chunk_no_pierde_ni_duplica(): void
    {
        $facturas = [];
        for ($i = 1; $i <= 600; $i++) {
            $facturas[] = self::facturaRow(icgNumfactura: $i);
        }

        $loader = new FacturaStagingLoader();
        $counts = $loader->load(new ExtractedFacturas($facturas));

        $this->assertSame(600, $counts['facturas']);
        $this->assertCount(600, $counts['factura_ids']);
        $this->assertSame(600, Factura::query()->count());
        $this->assertDatabaseHas('illuminations_legacy.facturas', ['icg_numfactura' => 1]);
        $this->assertDatabaseHas('illuminations_legacy.facturas', ['icg_numfactura' => 600]);
    }

    private static function facturaRow(
        int $icgNumfactura = 9001,
        ?int $icgCodvendedor = 77,
    ): FacturaRow {
        return new FacturaRow(
            icgNumserie: 'A',
            icgNumfactura: $icgNumfactura,
            icgN: 'B',
            icgCodcliente: 555,
            icgCodvendedor: $icgCodvendedor,
            fecha: null,
            totalNeto: 1500.50,
            totalImpuestos: 105.03,
            totalBruto: 1605.53,
            sufactura: 'REF-9001',
        );
    }

    private static function sampleExtraction(): ExtractedFacturas
    {
        return new ExtractedFacturas(facturas: [self::facturaRow()]);
    }
}
