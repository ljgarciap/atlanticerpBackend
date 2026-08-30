<?php

declare(strict_types=1);

namespace Tests\Feature\Legacy;

use App\Modules\Legacy\Models\Cliente;
use App\Modules\Legacy\Services\ClienteMatcher;
use App\Modules\VentasDiseno\Models\MasterClient;
use App\Modules\VentasDiseno\Models\SubClient;
use App\Modules\VentasDiseno\Models\SubClientContact;
use App\Shared\Multitenancy\Tenant;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-755 — `ClienteMatcher`, resolución de `sub_client_id`/`match_status` contra
 * `ventas_diseno.sub_clients`/`sub_client_contacts` ya vivos (Illumination/migracion-icg-diseno.md
 * §5). Clientes ya sembrados directamente en `legacy.clientes` (sin pasar por el Extractor real —
 * eso es responsabilidad de `ClienteExtractorTest`/`ClienteStagingLoaderTest`).
 */
final class ClienteMatcherTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    public function test_matchea_por_cif_normalizado(): void
    {
        $subClient = self::makeSubClient('8.123-456');
        $cliente   = self::makeCliente(1001, cif: '8-123-456');

        $result = (new ClienteMatcher())->match([1001]);

        $this->assertSame(1, $result['matched']);
        $this->assertSame(0, $result['unmatched']);
        $this->assertSame(0, $result['ambiguous']);

        $cliente->refresh();
        $this->assertSame(Cliente::MATCH_MATCHED, $cliente->match_status);
        $this->assertSame($subClient->id, $cliente->sub_client_id);
    }

    public function test_fallback_por_email_exacto_cuando_no_matchea_por_cif(): void
    {
        $subClient = self::makeSubClient('9999999'); // CIF distinto, no matchea
        SubClientContact::query()->create([
            'sub_client_id' => $subClient->id, 'name' => 'Juan', 'role' => 'client', 'email' => 'juan@cliente.com',
        ]);

        $cliente = self::makeCliente(1002, cif: '1-000-000', email: 'Juan@Cliente.com');

        $result = (new ClienteMatcher())->match([1002]);

        $this->assertSame(1, $result['matched']);

        $cliente->refresh();
        $this->assertSame(Cliente::MATCH_MATCHED, $cliente->match_status);
        $this->assertSame($subClient->id, $cliente->sub_client_id);
    }

    public function test_sin_cif_ni_email_matcheable_queda_unmatched(): void
    {
        self::makeSubClient('7777777');
        $cliente = self::makeCliente(1003, cif: '1-234-567', email: 'nadie@cliente.com');

        $result = (new ClienteMatcher())->match([1003]);

        $this->assertSame(0, $result['matched']);
        $this->assertSame(1, $result['unmatched']);

        $cliente->refresh();
        $this->assertSame(Cliente::MATCH_UNMATCHED, $cliente->match_status);
        $this->assertNull($cliente->sub_client_id);
    }

    public function test_sin_cif_ni_email_en_icg_queda_unmatched(): void
    {
        $cliente = self::makeCliente(1004, cif: null, email: null);

        $result = (new ClienteMatcher())->match([1004]);

        $this->assertSame(1, $result['unmatched']);
        $cliente->refresh();
        $this->assertSame(Cliente::MATCH_UNMATCHED, $cliente->match_status);
    }

    public function test_cif_duplicado_entre_2_sub_clients_es_ambiguous_y_nunca_elige_al_azar(): void
    {
        self::makeSubClient('8123456');
        self::makeSubClient('8123456'); // mismo CIF normalizado, otro sub_client
        $cliente = self::makeCliente(1005, cif: '8-123-456');

        $result = (new ClienteMatcher())->match([1005]);

        $this->assertSame(0, $result['matched']);
        $this->assertSame(1, $result['ambiguous']);

        $cliente->refresh();
        $this->assertSame(Cliente::MATCH_AMBIGUOUS, $cliente->match_status);
        $this->assertNull($cliente->sub_client_id);
    }

    public function test_email_duplicado_entre_2_sub_clients_es_ambiguous(): void
    {
        $subA = self::makeSubClient('1111111');
        $subB = self::makeSubClient('2222222');
        SubClientContact::query()->create(['sub_client_id' => $subA->id, 'name' => 'A', 'role' => 'client', 'email' => 'compartido@cliente.com']);
        SubClientContact::query()->create(['sub_client_id' => $subB->id, 'name' => 'B', 'role' => 'client', 'email' => 'compartido@cliente.com']);

        $cliente = self::makeCliente(1006, cif: '9-999-999', email: 'compartido@cliente.com');

        $result = (new ClienteMatcher())->match([1006]);

        $this->assertSame(1, $result['ambiguous']);
        $cliente->refresh();
        $this->assertSame(Cliente::MATCH_AMBIGUOUS, $cliente->match_status);
    }

    public function test_scopeado_a_la_lista_de_icg_codclientes_pasada(): void
    {
        self::makeSubClient('8123456');
        self::makeCliente(1007, cif: '8-123-456');
        self::makeCliente(1008, cif: '8-123-456');

        $result = (new ClienteMatcher())->match([1007]); // solo 1007, no 1008

        $this->assertSame(1, $result['matched'] + $result['unmatched'] + $result['ambiguous']);

        $cliente1008 = Cliente::query()->where('icg_codcliente', 1008)->firstOrFail();
        $this->assertSame(Cliente::MATCH_UNMATCHED, $cliente1008->match_status); // default de la migración, nunca tocado
    }

    public function test_re_correr_match_es_idempotente(): void
    {
        $subClient = self::makeSubClient('8123456');
        $cliente   = self::makeCliente(1009, cif: '8-123-456');

        (new ClienteMatcher())->match([1009]);
        (new ClienteMatcher())->match([1009]);

        $cliente->refresh();
        $this->assertSame(Cliente::MATCH_MATCHED, $cliente->match_status);
        $this->assertSame($subClient->id, $cliente->sub_client_id);
    }

    public function test_lista_vacia_no_hace_nada(): void
    {
        $result = (new ClienteMatcher())->match([]);

        $this->assertSame(['matched' => 0, 'unmatched' => 0, 'ambiguous' => 0, 'errores' => 0, 'details' => []], $result);
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

    private static function makeCliente(int $icgCodcliente, ?string $cif, ?string $email = null): Cliente
    {
        return Cliente::query()->create([
            'icg_codcliente' => $icgCodcliente,
            'razon_social'   => "Cliente {$icgCodcliente}",
            'cif'            => $cif,
            'email'          => $email,
            'bloqueado'      => false,
        ]);
    }
}
