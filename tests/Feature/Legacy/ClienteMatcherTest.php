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

    /** SCRUM-1113: un cliente `promoted` conserva su enlace aunque el matcher recorra su fila (antes quedaba `unmatched` con `sub_client_id` nulo). */
    public function test_no_pisa_el_enlace_de_un_cliente_promovido(): void
    {
        $subClient = self::makeSubClient('SIN-CIF-1099');
        $cliente   = self::makeCliente(1099, cif: null);
        $cliente->sub_client_id = $subClient->id;
        $cliente->match_status  = Cliente::MATCH_PROMOTED;
        $cliente->save();

        $result = (new ClienteMatcher())->match([1099]);

        $this->assertSame(0, $result['matched'] + $result['unmatched'] + $result['ambiguous']);
        $cliente->refresh();
        $this->assertSame(Cliente::MATCH_PROMOTED, $cliente->match_status);
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

    /** SCRUM-1137: el RUC completo (de NIF20) manda sobre el CIF truncado. */
    public function test_matchea_por_ruc_completo_antes_que_por_cif_truncado(): void
    {
        $completo  = self::makeSubClient('155716554-2-2021');
        $truncado  = self::makeSubClient('155716554-2');
        $cliente   = self::makeCliente(1201, cif: '155716554-2', ruc: '155716554-2-2021');

        $result = (new ClienteMatcher())->match([1201]);

        $this->assertSame(1, $result['matched']);
        $this->assertSame('ruc', $result['details'][0]['via']);
        $cliente->refresh();
        $this->assertSame($completo->id, $cliente->sub_client_id);
        $this->assertNotSame($truncado->id, $cliente->sub_client_id);
    }

    /** Un sub_client que ya guarda el RUC truncado sigue matcheando (por CIF) mientras no se corrija. */
    public function test_sin_match_por_ruc_cae_al_cif_truncado(): void
    {
        $truncado = self::makeSubClient('155716554-2');
        $cliente  = self::makeCliente(1202, cif: '155716554-2', ruc: '155716554-2-2021');

        $result = (new ClienteMatcher())->match([1202]);

        $this->assertSame('cif', $result['details'][0]['via']);
        $cliente->refresh();
        $this->assertSame($truncado->id, $cliente->sub_client_id);
    }

    /** `ruc_estado = revisar` no es confiable: el RUC no participa y se usa el CIF. */
    /** Si el RUC contradice al CIF (otro RUC en NIF20), el RUC se ignora y manda el CIF, como antes. */
    public function test_ruc_que_contradice_al_cif_no_cambia_el_vinculo(): void
    {
        $propio = self::makeSubClient('8-123-456');
        $ajeno  = self::makeSubClient('9-999-999-37');
        $cliente = self::makeCliente(1206, cif: '8-123-456', ruc: '9-999-999-37');

        $result = (new ClienteMatcher())->match([1206]);

        $this->assertSame('cif', $result['details'][0]['via']);
        $cliente->refresh();
        $this->assertSame($propio->id, $cliente->sub_client_id);
        $this->assertNotSame($ajeno->id, $cliente->sub_client_id);
        $this->assertSame(1, (new ClienteMatcher())->preview()['conflictos_ruc_cif']);
    }

    public function test_ruc_marcado_revisar_se_ignora(): void
    {
        $otro    = self::makeSubClient('RUC 1671600-');
        $cliente = self::makeCliente(1203, cif: null, ruc: 'RUC 1671600-', rucEstado: 'revisar');

        $result = (new ClienteMatcher())->match([1203]);

        $this->assertSame(0, $result['matched']);
        $cliente->refresh();
        $this->assertSame(Cliente::MATCH_UNMATCHED, $cliente->match_status);
        $this->assertNull($cliente->sub_client_id);
        $this->assertNotNull($otro->id);
    }

    public function test_preview_no_escribe_y_cuenta_las_transiciones(): void
    {
        $completo = self::makeSubClient('155716554-2-2021');
        $cliente  = self::makeCliente(1204, cif: '155716554-2', ruc: '155716554-2-2021');
        $sin      = self::makeCliente(1205, cif: null);

        $r = (new ClienteMatcher())->preview();

        $this->assertSame(2, $r['total']);
        $this->assertSame(1, $r['transiciones']['unmatched -> matched']);
        $this->assertSame(1, $r['transiciones']['unmatched -> unmatched']);
        $this->assertSame(['ninguna' => 1, 'ruc' => 1], $r['via']);
        $cliente->refresh();
        $this->assertNull($cliente->sub_client_id, 'preview no debe escribir');
        $this->assertNotNull($completo->id);
        $this->assertNotNull($sin->id);
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

    private static function makeCliente(int $icgCodcliente, ?string $cif, ?string $email = null, ?string $ruc = null, ?string $rucEstado = null): Cliente
    {
        return Cliente::query()->create([
            'icg_codcliente' => $icgCodcliente,
            'razon_social'   => "Cliente {$icgCodcliente}",
            'cif'            => $cif,
            'email'          => $email,
            'ruc'            => $ruc,
            'ruc_estado'     => $rucEstado ?? ($ruc !== null ? 'ok' : null),
            'bloqueado'      => false,
        ]);
    }
}
