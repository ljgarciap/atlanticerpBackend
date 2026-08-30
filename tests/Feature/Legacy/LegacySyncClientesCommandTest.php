<?php

declare(strict_types=1);

namespace Tests\Feature\Legacy;

use App\Modules\Legacy\Contracts\ClienteExtractorContract;
use App\Modules\Legacy\Data\ClienteRow;
use App\Modules\Legacy\Data\ContactoClienteRow;
use App\Modules\Legacy\Data\ExtractedClientes;
use App\Modules\Legacy\Models\Cliente;
use App\Modules\Legacy\Models\ContactoCliente;
use App\Modules\Legacy\Models\SyncRun;
use App\Modules\VentasDiseno\Models\MasterClient;
use App\Modules\VentasDiseno\Models\SubClient;
use App\Shared\Multitenancy\Tenant;
use RuntimeException;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-755 — pipeline completo del comando `legacy:sync-clientes`, con
 * `ClienteExtractorContract` fakeado (sin SQL Server real, ver `FakeClienteExtractor`). Mismo
 * patrón de test que `LegacySyncProductosCommandTest` (SCRUM-750).
 *
 * El comando hace `Tenant::forgetCurrent()` en su `finally` — cada `artisan(...)` de este test
 * deja el tenant "current" apagado, así que `reactivateTenant()` lo vuelve a prender antes de
 * cualquier assert que dependa de `Model::getTable()`.
 */
final class LegacySyncClientesCommandTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->reactivateTenant();
    }

    private function reactivateTenant(): void
    {
        Tenant::where('slug', 'illuminations')->first()?->makeCurrent();
    }

    public function test_dry_run_stagea_y_matchea_pero_no_registra_sync_run(): void
    {
        $subClient = self::makeSubClient('8123456');
        $this->app->instance(ClienteExtractorContract::class, new FakeClienteExtractor(self::sampleExtraction()));

        $this->artisan('legacy:sync-clientes', ['--tenant' => 'illuminations', '--dry-run' => true])
            ->assertExitCode(0);
        $this->reactivateTenant();

        $this->assertDatabaseHas('illuminations_legacy.clientes', ['icg_codcliente' => 1001]);
        $cliente = Cliente::query()->where('icg_codcliente', 1001)->firstOrFail();
        // El matching SÍ corre y escribe dentro de legacy.* incluso en dry-run (mismo criterio
        // que el staging de Productos: es un upsert idempotente sobre tablas de referencia).
        $this->assertSame(Cliente::MATCH_MATCHED, $cliente->match_status);
        $this->assertSame($subClient->id, $cliente->sub_client_id);
        $this->assertSame(0, SyncRun::query()->count());
    }

    public function test_corrida_real_stagea_matchea_y_registra_sync_run(): void
    {
        self::makeSubClient('8123456');
        $this->app->instance(ClienteExtractorContract::class, new FakeClienteExtractor(self::sampleExtraction()));

        $this->artisan('legacy:sync-clientes', ['--tenant' => 'illuminations'])
            ->assertExitCode(0);
        $this->reactivateTenant();

        $run = SyncRun::query()->firstOrFail();
        $this->assertSame('clientes', $run->entidad);
        $this->assertSame(SyncRun::STATUS_SUCCESS, $run->status);
        $this->assertSame(1, $run->extraidos);
        $this->assertSame(1, $run->staged);
        $this->assertSame(1, $run->mapeados);
        $this->assertSame(1, $run->cargados);
        $this->assertSame(0, $run->excluidos);
        $this->assertSame(0, $run->errores);

        $cliente = Cliente::query()->where('icg_codcliente', 1001)->firstOrFail();
        $this->assertSame(Cliente::MATCH_MATCHED, $cliente->match_status);
        $this->assertSame(1, ContactoCliente::query()->where('icg_id', 501)->count());
    }

    /**
     * Idempotencia real, no solo declarada — mismo criterio pedido por el runbook §12.2 paso 3.
     * Correr el comando DOS VECES seguidas contra el mismo dataset fake no debe duplicar filas en
     * ninguna de las 3 tablas (`clientes`, `contactos_cliente`, `sync_runs` es la única que crece
     * por diseño — una fila POR corrida real, eso no es duplicación).
     */
    public function test_re_correr_el_comando_dos_veces_no_duplica_clientes_ni_contactos(): void
    {
        self::makeSubClient('8123456');
        $this->app->instance(ClienteExtractorContract::class, new FakeClienteExtractor(self::sampleExtraction()));

        $this->artisan('legacy:sync-clientes', ['--tenant' => 'illuminations'])->assertExitCode(0);
        $this->reactivateTenant();
        $this->artisan('legacy:sync-clientes', ['--tenant' => 'illuminations'])->assertExitCode(0);
        $this->reactivateTenant();

        $this->assertSame(1, Cliente::query()->where('icg_codcliente', 1001)->count());
        $this->assertSame(1, ContactoCliente::query()->where('icg_id', 501)->count());
        $this->assertSame(2, SyncRun::query()->count()); // 2 corridas reales, cada una registrada

        $cliente = Cliente::query()->where('icg_codcliente', 1001)->firstOrFail();
        $this->assertSame(Cliente::MATCH_MATCHED, $cliente->match_status); // el segundo run no lo revierte
    }

    public function test_cliente_sin_match_se_reporta_como_excluido_y_no_bloquea_el_resto(): void
    {
        self::makeSubClient('9999999'); // no matchea con ninguno de los 2 clientes de abajo
        $extraction = new ExtractedClientes(
            clientes: [
                self::clienteRow(1001, cif: '9-999-999'), // matchea
                self::clienteRow(1002, cif: '1-000-000'), // no matchea
            ],
            contactos: [],
        );
        $this->app->instance(ClienteExtractorContract::class, new FakeClienteExtractor($extraction));

        $this->artisan('legacy:sync-clientes', ['--tenant' => 'illuminations'])->assertExitCode(0);
        $this->reactivateTenant();

        $run = SyncRun::query()->firstOrFail();
        $this->assertSame(1, $run->cargados);
        $this->assertSame(1, $run->excluidos);
        $this->assertSame(SyncRun::STATUS_SUCCESS, $run->status);
    }

    public function test_falla_de_extraccion_registra_sync_run_failed_y_no_rompe_la_suite(): void
    {
        $this->app->instance(
            ClienteExtractorContract::class,
            new FakeClienteExtractor(throws: new RuntimeException('SQL Server no disponible')),
        );

        $this->artisan('legacy:sync-clientes', ['--tenant' => 'illuminations'])
            ->assertExitCode(1);
        $this->reactivateTenant();

        $run = SyncRun::query()->firstOrFail();
        $this->assertSame(SyncRun::STATUS_FAILED, $run->status);
        $this->assertSame(1, $run->errores);
    }

    public function test_falla_de_extraccion_en_dry_run_no_registra_sync_run(): void
    {
        $this->app->instance(
            ClienteExtractorContract::class,
            new FakeClienteExtractor(throws: new RuntimeException('SQL Server no disponible')),
        );

        $this->artisan('legacy:sync-clientes', ['--tenant' => 'illuminations', '--dry-run' => true])
            ->assertExitCode(1);
        $this->reactivateTenant();

        $this->assertSame(0, SyncRun::query()->count());
    }

    /**
     * Regresión — mismo patrón que `LegacySyncProductosCommandTest::
     * test_falla_de_staging_o_mapeo_registra_sync_run_failed_y_no_deja_stack_trace_crudo`: una
     * excepción durante staging/matching (acá forzada con una `razon_social` de 300 chars contra
     * la columna `varchar(255)` de `legacy.clientes`) tiene que quedar registrada en
     * `legacy.sync_runs`, nunca escapar sin rastro.
     */
    public function test_falla_de_staging_o_matching_registra_sync_run_failed(): void
    {
        $extraction = new ExtractedClientes(
            clientes: [new ClienteRow(9001, null, str_repeat('X', 300), null, null, null, null, null, null, null, null, null, null, false, null)],
            contactos: [],
        );
        $this->app->instance(ClienteExtractorContract::class, new FakeClienteExtractor($extraction));

        $this->artisan('legacy:sync-clientes', ['--tenant' => 'illuminations'])
            ->assertExitCode(1);
        $this->reactivateTenant();

        $run = SyncRun::query()->firstOrFail();
        $this->assertSame(SyncRun::STATUS_FAILED, $run->status);
        $this->assertSame(1, $run->errores);
    }

    public function test_tenant_inexistente_falla_sin_tocar_nada(): void
    {
        $this->app->instance(ClienteExtractorContract::class, new FakeClienteExtractor(self::sampleExtraction()));

        $this->artisan('legacy:sync-clientes', ['--tenant' => 'no-existe'])
            ->assertExitCode(1);
    }

    private static function makeSubClient(string $taxId): SubClient
    {
        $master = MasterClient::query()->create(['name' => "Master {$taxId}"]);

        return SubClient::query()->create([
            'master_client_id' => $master->id,
            'business_name'    => "Sub {$taxId}",
            'tax_id'           => $taxId,
        ]);
    }

    private static function clienteRow(int $icgCodcliente, ?string $cif): ClienteRow
    {
        return new ClienteRow(
            $icgCodcliente, 'Comercial', "Cliente {$icgCodcliente} SA", $cif, null, null, null, null,
            null, null, null, null, null, false, null,
        );
    }

    private static function sampleExtraction(): ExtractedClientes
    {
        return new ExtractedClientes(
            clientes: [self::clienteRow(1001, '8-123-456')],
            contactos: [
                new ContactoClienteRow(501, 1001, 'Gerente de Compras', 'Juan Pérez', '2345678', '61234567', 'juan.perez@cliente.com'),
            ],
        );
    }
}
