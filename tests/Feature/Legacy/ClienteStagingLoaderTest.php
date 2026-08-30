<?php

declare(strict_types=1);

namespace Tests\Feature\Legacy;

use App\Modules\Legacy\Data\ClienteRow;
use App\Modules\Legacy\Data\ContactoClienteRow;
use App\Modules\Legacy\Data\ExtractedClientes;
use App\Modules\Legacy\Models\Cliente;
use App\Modules\Legacy\Models\ContactoCliente;
use App\Modules\Legacy\Services\ClienteStagingLoader;
use App\Shared\Multitenancy\Tenant;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-755 — "Loader-A" (staging). Re-correr el mismo extracto dos veces nunca duplica ni pisa
 * mal, y una segunda corrida nunca resetea `sub_client_id`/`match_status` (los pone
 * `ClienteMatcher`, este Loader nunca los toca).
 */
final class ClienteStagingLoaderTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'illuminations')->first()?->makeCurrent();
    }

    public function test_load_stagea_clientes_y_contactos(): void
    {
        $loader = new ClienteStagingLoader();

        $counts = $loader->load(self::sampleExtraction());

        $this->assertSame(['clientes' => 1, 'contactos' => 1], $counts);
        $this->assertDatabaseHas('illuminations_legacy.clientes', ['icg_codcliente' => 1001, 'razon_social' => 'Cliente Legal SA']);
        $this->assertSame(1, ContactoCliente::query()->where('icg_id', 501)->count());
    }

    public function test_contacto_referencia_el_id_interno_del_cliente(): void
    {
        $loader = new ClienteStagingLoader();
        $loader->load(self::sampleExtraction());

        $cliente  = Cliente::query()->where('icg_codcliente', 1001)->firstOrFail();
        $contacto = ContactoCliente::query()->where('icg_id', 501)->firstOrFail();

        $this->assertSame($cliente->id, $contacto->legacy_cliente_id);
    }

    public function test_re_correr_el_mismo_extracto_no_duplica(): void
    {
        $loader = new ClienteStagingLoader();

        $loader->load(self::sampleExtraction());
        $loader->load(self::sampleExtraction());

        $this->assertSame(1, Cliente::query()->where('icg_codcliente', 1001)->count());
        $this->assertSame(1, ContactoCliente::query()->where('icg_id', 501)->count());
    }

    public function test_re_correr_actualiza_valores_cambiados(): void
    {
        $loader = new ClienteStagingLoader();
        $loader->load(self::sampleExtraction());

        $updated = new ExtractedClientes(
            clientes: [new ClienteRow(1001, 'Comercial (renombrado)', 'Cliente Legal SA', '8-123-456', null, null, null, null, null, null, null, null, 55, false, null)],
            contactos: [],
        );
        $loader->load($updated);

        $cliente = Cliente::query()->where('icg_codcliente', 1001)->firstOrFail();
        $this->assertSame('Comercial (renombrado)', $cliente->nombre_comercial);
    }

    public function test_re_correr_nunca_resetea_sub_client_id_ni_match_status_ya_seteados(): void
    {
        $loader = new ClienteStagingLoader();
        $loader->load(self::sampleExtraction());

        // Simula que ClienteMatcher ya resolvió este cliente en una corrida anterior.
        $master = \App\Modules\VentasDiseno\Models\MasterClient::query()->create(['name' => 'Cliente Legal SA']);
        $subClient = \App\Modules\VentasDiseno\Models\SubClient::query()->create([
            'master_client_id' => $master->id, 'business_name' => 'Cliente Legal SA', 'tax_id' => '8123456',
        ]);

        $cliente = Cliente::query()->where('icg_codcliente', 1001)->firstOrFail();
        $cliente->sub_client_id = $subClient->id;
        $cliente->match_status  = Cliente::MATCH_MATCHED;
        $cliente->save();

        $loader->load(self::sampleExtraction());

        $cliente->refresh();
        $this->assertSame($subClient->id, $cliente->sub_client_id);
        $this->assertSame(Cliente::MATCH_MATCHED, $cliente->match_status);
    }

    public function test_contacto_huerfano_dentro_de_la_corrida_se_descarta_sin_romper_el_resto(): void
    {
        // icg_codcliente 9999 no está entre los clientes de esta extracción — huérfano.
        $extraction = new ExtractedClientes(
            clientes: [self::clienteRow(1001)],
            contactos: [
                new ContactoClienteRow(501, 1001, 'Gerente', 'Juan Pérez', null, null, null),
                new ContactoClienteRow(502, 9999, 'Huérfano', 'Nadie', null, null, null),
            ],
        );

        $loader = new ClienteStagingLoader();
        $counts = $loader->load($extraction);

        $this->assertSame(['clientes' => 1, 'contactos' => 1], $counts);
        $this->assertSame(0, ContactoCliente::query()->where('icg_id', 502)->count());
    }

    /**
     * Mismo gotcha de escala que `ProductoStagingLoaderTest` (Postgres: máximo 65535 parámetros
     * bindeados por statement) — 600 filas ya superan el tamaño de un solo chunk
     * (`ClienteStagingLoader::CHUNK_SIZE = 500`) sin acercarse al límite real.
     */
    public function test_load_con_mas_filas_que_un_chunk_no_pierde_ni_duplica(): void
    {
        $clientes  = [];
        $contactos = [];
        for ($i = 1; $i <= 600; $i++) {
            $clientes[]  = self::clienteRow($i);
            $contactos[] = new ContactoClienteRow(10000 + $i, $i, null, "Contacto {$i}", null, null, null);
        }

        $loader = new ClienteStagingLoader();
        $counts = $loader->load(new ExtractedClientes($clientes, $contactos));

        $this->assertSame(['clientes' => 600, 'contactos' => 600], $counts);
        $this->assertSame(600, Cliente::query()->count());
        $this->assertSame(600, ContactoCliente::query()->count());
        $this->assertDatabaseHas('illuminations_legacy.clientes', ['icg_codcliente' => 1]);
        $this->assertDatabaseHas('illuminations_legacy.clientes', ['icg_codcliente' => 600]);
    }

    private static function clienteRow(int $icgCodcliente): ClienteRow
    {
        return new ClienteRow(
            $icgCodcliente, 'Comercial', "Cliente {$icgCodcliente} SA", null, null, null, null, null,
            null, null, null, null, null, false, null,
        );
    }

    private static function sampleExtraction(): ExtractedClientes
    {
        return new ExtractedClientes(
            clientes: [
                new ClienteRow(
                    icgCodcliente: 1001,
                    nombreComercial: 'Cliente Comercial SA',
                    razonSocial: 'Cliente Legal SA',
                    cif: '8-123-456',
                    direccion: 'Calle 50',
                    poblacion: 'Panamá',
                    provincia: 'Panamá',
                    pais: 'Panamá',
                    telefono1: '2345678',
                    telefono2: null,
                    mobil: '61234567',
                    email: 'contacto@cliente.com',
                    icgCodvendedor: 55,
                    bloqueado: false,
                    fechaModificadoIcg: null,
                ),
            ],
            contactos: [
                new ContactoClienteRow(501, 1001, 'Gerente de Compras', 'Juan Pérez', '2345678', '61234567', 'juan.perez@cliente.com'),
            ],
        );
    }
}
