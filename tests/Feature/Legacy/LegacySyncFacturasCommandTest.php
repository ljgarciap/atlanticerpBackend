<?php

declare(strict_types=1);

namespace Tests\Feature\Legacy;

use App\Modules\Legacy\Contracts\FacturaExtractorContract;
use App\Modules\Legacy\Data\ExtractedFacturas;
use App\Modules\Legacy\Data\FacturaRow;
use App\Modules\Legacy\Models\Cliente;
use App\Modules\Legacy\Models\Factura;
use App\Modules\Legacy\Models\SyncRun;
use App\Modules\Legacy\Models\SyncRunDetail;
use App\Shared\Multitenancy\Tenant;
use RuntimeException;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-760 — pipeline completo del comando `legacy:sync-facturas`, con `FacturaExtractorContract`
 * fakeado (sin SQL Server real, ver `FakeFacturaExtractor`). Mismo patrón de test que
 * `LegacySyncAlbaranesCommandTest` (SCRUM-759).
 *
 * El comando hace `Tenant::forgetCurrent()` en su `finally` — cada `artisan(...)` de este test deja
 * el tenant "current" apagado, así que `reactivateTenant()` lo vuelve a prender antes de cualquier
 * assert que dependa de `Model::getTable()`.
 */
final class LegacySyncFacturasCommandTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->reactivateTenant();
    }

    private function reactivateTenant(): void
    {
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    public function test_dry_run_stagea_y_matchea_pero_no_registra_sync_run(): void
    {
        $cliente = Cliente::query()->create(['icg_codcliente' => 555, 'razon_social' => 'Cliente Legal SA']);
        $this->app->instance(FacturaExtractorContract::class, new FakeFacturaExtractor(self::sampleExtraction()));

        $this->artisan('legacy:sync-facturas', ['--tenant' => 'atlantic', '--dry-run' => true])
            ->assertExitCode(0);
        $this->reactivateTenant();

        $factura = Factura::query()->where('icg_numfactura', 9001)->firstOrFail();
        $this->assertSame($cliente->id, $factura->legacy_cliente_id);
        $this->assertFalse($factura->orphan);
        $this->assertSame(0, SyncRun::query()->count());
    }

    public function test_corrida_real_stagea_matchea_y_registra_sync_run(): void
    {
        Cliente::query()->create(['icg_codcliente' => 555, 'razon_social' => 'Cliente Legal SA']);
        $this->app->instance(FacturaExtractorContract::class, new FakeFacturaExtractor(self::sampleExtraction()));

        $this->artisan('legacy:sync-facturas', ['--tenant' => 'atlantic'])
            ->assertExitCode(0);
        $this->reactivateTenant();

        $run = SyncRun::query()->firstOrFail();
        $this->assertSame('facturas', $run->entidad);
        $this->assertSame(SyncRun::STATUS_SUCCESS, $run->status);
        $this->assertSame(1, $run->extraidos);
        $this->assertSame(1, $run->staged);
        $this->assertSame(1, $run->mapeados);
        $this->assertSame(1, $run->cargados);
        $this->assertSame(0, $run->excluidos);
        $this->assertSame(0, $run->errores);
    }

    public function test_re_correr_el_comando_dos_veces_no_duplica_facturas(): void
    {
        Cliente::query()->create(['icg_codcliente' => 555, 'razon_social' => 'Cliente Legal SA']);
        $this->app->instance(FacturaExtractorContract::class, new FakeFacturaExtractor(self::sampleExtraction()));

        $this->artisan('legacy:sync-facturas', ['--tenant' => 'atlantic'])->assertExitCode(0);
        $this->reactivateTenant();
        $this->artisan('legacy:sync-facturas', ['--tenant' => 'atlantic'])->assertExitCode(0);
        $this->reactivateTenant();

        $this->assertSame(1, Factura::query()->where('icg_numfactura', 9001)->count());
        $this->assertSame(2, SyncRun::query()->count());

        $factura = Factura::query()->where('icg_numfactura', 9001)->firstOrFail();
        $this->assertFalse($factura->orphan);
    }

    public function test_factura_sin_match_se_reporta_como_excluida_y_no_bloquea_el_resto(): void
    {
        Cliente::query()->create(['icg_codcliente' => 9999999, 'razon_social' => 'Otro Cliente']); // no matchea
        $extraction = new ExtractedFacturas(facturas: [
            self::facturaRow(icgNumfactura: 9001, icgCodcliente: 555), // no matchea
            self::facturaRow(icgNumfactura: 9002, icgCodcliente: 9999999), // matchea
        ]);
        $this->app->instance(FacturaExtractorContract::class, new FakeFacturaExtractor($extraction));

        $this->artisan('legacy:sync-facturas', ['--tenant' => 'atlantic'])->assertExitCode(0);
        $this->reactivateTenant();

        $run = SyncRun::query()->firstOrFail();
        $this->assertSame(1, $run->cargados);
        $this->assertSame(1, $run->excluidos);
        $this->assertSame(SyncRun::STATUS_SUCCESS, $run->status);

        $orphanFactura = Factura::query()->where('icg_numfactura', 9001)->firstOrFail();
        $this->assertTrue($orphanFactura->orphan);

        // SCRUM-816 — el motivo de la exclusión queda persistido para el dashboard, no solo
        // impreso en consola.
        $detail = SyncRunDetail::query()->where('sync_run_id', $run->id)->firstOrFail();
        $this->assertSame('orphan', $detail->status);
        $this->assertStringContainsString('555', (string) $detail->motivo);
    }

    public function test_falla_de_extraccion_registra_sync_run_failed_y_no_rompe_la_suite(): void
    {
        $this->app->instance(
            FacturaExtractorContract::class,
            new FakeFacturaExtractor(throws: new RuntimeException('SQL Server no disponible')),
        );

        $this->artisan('legacy:sync-facturas', ['--tenant' => 'atlantic'])
            ->assertExitCode(1);
        $this->reactivateTenant();

        $run = SyncRun::query()->firstOrFail();
        $this->assertSame(SyncRun::STATUS_FAILED, $run->status);
        $this->assertSame(1, $run->errores);
    }

    public function test_falla_de_extraccion_en_dry_run_no_registra_sync_run(): void
    {
        $this->app->instance(
            FacturaExtractorContract::class,
            new FakeFacturaExtractor(throws: new RuntimeException('SQL Server no disponible')),
        );

        $this->artisan('legacy:sync-facturas', ['--tenant' => 'atlantic', '--dry-run' => true])
            ->assertExitCode(1);
        $this->reactivateTenant();

        $this->assertSame(0, SyncRun::query()->count());
    }

    public function test_falla_de_staging_o_matching_registra_sync_run_failed(): void
    {
        $extraction = new ExtractedFacturas(facturas: [
            self::facturaRow(icgNumserie: str_repeat('X', 300)),
        ]);
        $this->app->instance(FacturaExtractorContract::class, new FakeFacturaExtractor($extraction));

        $this->artisan('legacy:sync-facturas', ['--tenant' => 'atlantic'])
            ->assertExitCode(1);
        $this->reactivateTenant();

        $run = SyncRun::query()->firstOrFail();
        $this->assertSame(SyncRun::STATUS_FAILED, $run->status);
        $this->assertSame(1, $run->errores);
    }

    /** SCRUM-841 — los campos de Facturación Electrónica llegan hasta legacy.facturas. */
    public function test_campos_de_facturacion_electronica_se_persisten(): void
    {
        Cliente::query()->create(['icg_codcliente' => 555, 'razon_social' => 'Cliente Legal SA']);
        $row = self::facturaRow();
        $extraction = new ExtractedFacturas(facturas: [new FacturaRow(
            icgNumserie: $row->icgNumserie, icgNumfactura: $row->icgNumfactura, icgN: $row->icgN,
            icgCodcliente: $row->icgCodcliente, icgCodvendedor: $row->icgCodvendedor,
            fecha: null, totalNeto: $row->totalNeto, totalImpuestos: $row->totalImpuestos,
            totalBruto: $row->totalBruto, sufactura: $row->sufactura,
            feQr: 'https://dgi.gob.pa/qr/abc123', feAuthNumber: 'AUTH-0001',
            feSerie: '0001', feNumero: '000123',
            feFechaEmision: '2026-01-15T10:30:00', feFechaRecepcion: '2026-01-15T10:31:00',
            feError: null, feEstado: 'aceptado',
        )]);
        $this->app->instance(FacturaExtractorContract::class, new FakeFacturaExtractor($extraction));

        $this->artisan('legacy:sync-facturas', ['--tenant' => 'atlantic'])->assertExitCode(0);
        $this->reactivateTenant();

        $factura = Factura::query()->where('icg_numfactura', 9001)->firstOrFail();
        $this->assertSame('https://dgi.gob.pa/qr/abc123', $factura->fe_qr);
        $this->assertSame('AUTH-0001', $factura->fe_auth_number);
        $this->assertSame('aceptado', $factura->fe_estado);
    }

    public function test_tenant_inexistente_falla_sin_tocar_nada(): void
    {
        $this->app->instance(FacturaExtractorContract::class, new FakeFacturaExtractor(self::sampleExtraction()));

        $this->artisan('legacy:sync-facturas', ['--tenant' => 'no-existe'])
            ->assertExitCode(1);
    }

    private static function facturaRow(
        int $icgNumfactura = 9001,
        ?int $icgCodcliente = 555,
        string $icgNumserie = 'A',
    ): FacturaRow {
        return new FacturaRow(
            icgNumserie: $icgNumserie,
            icgNumfactura: $icgNumfactura,
            icgN: 'B',
            icgCodcliente: $icgCodcliente,
            icgCodvendedor: 77,
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
