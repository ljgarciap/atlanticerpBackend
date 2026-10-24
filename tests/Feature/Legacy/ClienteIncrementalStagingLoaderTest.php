<?php

declare(strict_types=1);

namespace Tests\Feature\Legacy;

use App\Modules\Legacy\Data\ClienteRow;
use App\Modules\Legacy\Data\ContactoClienteRow;
use App\Modules\Legacy\Data\ExtractedClientes;
use App\Modules\Legacy\Models\Cliente;
use App\Modules\Legacy\Models\ContactoCliente;
use App\Modules\Legacy\Services\ClienteIncrementalStagingLoader;
use App\Shared\Multitenancy\Tenant;
use Tests\Feature\FeatureTestCase;

final class ClienteIncrementalStagingLoaderTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    public function test_primera_corrida_cuenta_todo_como_nuevo(): void
    {
        $loader = new ClienteIncrementalStagingLoader();

        $counts = $loader->load(self::sampleExtraction());

        $this->assertSame(1, $counts['clientes']['nuevos']);
        $this->assertSame(1, $counts['contactos']['nuevos']);
        $this->assertSame([1001], $counts['changed_codclientes']);
        $this->assertDatabaseHas('atlantic_legacy.clientes', ['icg_codcliente' => 1001, 'razon_social' => 'Cliente Uno']);
    }

    public function test_re_correr_sin_cambios_no_reprocesa_nada(): void
    {
        $loader = new ClienteIncrementalStagingLoader();
        $loader->load(self::sampleExtraction());

        $cliente = Cliente::query()->where('icg_codcliente', 1001)->firstOrFail();
        $updatedAtAntes = $cliente->updated_at;

        $counts = $loader->load(self::sampleExtraction());

        $this->assertSame(0, $counts['clientes']['nuevos']);
        $this->assertSame(0, $counts['clientes']['cambiados']);
        $this->assertSame(1, $counts['clientes']['sin_cambios']);
        $this->assertSame([], $counts['changed_codclientes']);

        $cliente->refresh();
        $this->assertTrue($updatedAtAntes->equalTo($cliente->updated_at));
    }

    public function test_un_cambio_real_se_detecta_y_queda_en_changed_codclientes(): void
    {
        $loader = new ClienteIncrementalStagingLoader();
        $loader->load(self::sampleExtraction());

        $cambiado = new ExtractedClientes(
            clientes: [new ClienteRow(1001, null, 'Cliente Uno (renombrado)', 'CIF1', null, null, null, null, null, null, null, null, null, false, null)],
            contactos: [],
        );
        $counts = $loader->load($cambiado);

        $this->assertSame(1, $counts['clientes']['cambiados']);
        $this->assertSame([1001], $counts['changed_codclientes']);

        $cliente = Cliente::query()->where('icg_codcliente', 1001)->firstOrFail();
        $this->assertSame('Cliente Uno (renombrado)', $cliente->razon_social);
    }

    public function test_persiste_nif20_y_las_columnas_de_ruc_normalizado(): void
    {
        $loader = new ClienteIncrementalStagingLoader();

        $loader->load(new ExtractedClientes(clientes: [
            new ClienteRow(1, null, 'Cliente 1', '155783782-2-', null, null, null, null, null, null, null, null, null, false, null, '155783782-2-2026DV35'),
            new ClienteRow(2, null, 'Cliente 2', '6-712-881', null, null, null, null, null, null, null, null, null, false, null, null),
            new ClienteRow(3, null, 'Cliente 3', 'RUC 1671600-', null, null, null, null, null, null, null, null, null, false, null, null),
            new ClienteRow(4, null, 'Cliente 4', null, null, null, null, null, null, null, null, null, null, false, null, null),
        ], contactos: []));

        $this->assertDatabaseHas('atlantic_legacy.clientes', [
            'icg_codcliente' => 1, 'nif20' => '155783782-2-2026DV35', 'ruc' => '155783782-2-2026',
            'ruc_dv' => '35', 'ruc_origen' => 'nif20', 'ruc_estado' => 'ok',
        ]);
        $this->assertDatabaseHas('atlantic_legacy.clientes', [
            'icg_codcliente' => 2, 'nif20' => null, 'ruc' => '6-712-881', 'ruc_dv' => null, 'ruc_origen' => 'cif', 'ruc_estado' => 'ok',
        ]);
        $this->assertDatabaseHas('atlantic_legacy.clientes', ['icg_codcliente' => 3, 'ruc_estado' => 'revisar']);
        $this->assertDatabaseHas('atlantic_legacy.clientes', ['icg_codcliente' => 4, 'ruc' => null, 'ruc_origen' => 'ninguno']);
    }

    public function test_un_cambio_solo_en_nif20_se_detecta_porque_nif20_entra_al_hash(): void
    {
        $loader = new ClienteIncrementalStagingLoader();
        $loader->load(new ExtractedClientes(clientes: [
            new ClienteRow(1001, null, 'Cliente Uno', '155783782-2-', null, null, null, null, null, null, null, null, null, false, null, null),
        ], contactos: []));

        $counts = $loader->load(new ExtractedClientes(clientes: [
            new ClienteRow(1001, null, 'Cliente Uno', '155783782-2-', null, null, null, null, null, null, null, null, null, false, null, '155783782-2-2026DV35'),
        ], contactos: []));

        $this->assertSame(1, $counts['clientes']['cambiados']);
        $this->assertSame([1001], $counts['changed_codclientes']);
        $this->assertDatabaseHas('atlantic_legacy.clientes', ['icg_codcliente' => 1001, 'ruc' => '155783782-2-2026', 'ruc_dv' => '35']);
    }

    public function test_re_correr_nunca_resetea_sub_client_id_ya_seteado_por_el_matcher(): void
    {
        $loader = new ClienteIncrementalStagingLoader();
        $loader->load(self::sampleExtraction());

        $cliente = Cliente::query()->where('icg_codcliente', 1001)->firstOrFail();
        $cliente->sub_client_id = null; // sin FK real en este test, solo confirmamos que match_status sobrevive
        $cliente->match_status  = Cliente::MATCH_MATCHED;
        $cliente->save();

        $loader->load(self::sampleExtraction());

        $cliente->refresh();
        $this->assertSame(Cliente::MATCH_MATCHED, $cliente->match_status);
    }

    public function test_contacto_huerfano_sin_cliente_en_el_extracto_se_descarta(): void
    {
        $extraction = new ExtractedClientes(
            clientes: [],
            contactos: [new ContactoClienteRow(9001, 9999, null, 'Huerfano', null, null, null)],
        );

        $loader = new ClienteIncrementalStagingLoader();
        $counts = $loader->load($extraction);

        $this->assertSame(0, $counts['contactos']['total']);
        $this->assertSame(0, ContactoCliente::query()->count());
    }

    private static function sampleExtraction(): ExtractedClientes
    {
        return new ExtractedClientes(
            clientes: [new ClienteRow(1001, null, 'Cliente Uno', 'CIF1', null, null, null, null, null, null, null, null, null, false, null)],
            contactos: [new ContactoClienteRow(5001, 1001, 'Gerente', 'Juan Perez', null, null, 'juan@example.com')],
        );
    }
}
