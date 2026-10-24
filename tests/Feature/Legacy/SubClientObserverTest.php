<?php

declare(strict_types=1);

namespace Tests\Feature\Legacy;

use App\Modules\Legacy\Models\Cliente;
use App\Modules\VentasDiseno\Models\MasterClient;
use App\Modules\VentasDiseno\Models\SubClient;
use App\Shared\Multitenancy\Tenant;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-763 — al borrar un SubClient ya matcheado, legacy.clientes.match_status pasa a
 * unmatched en el mismo momento (no se queda "matched" con sub_client_id=NULL hasta la próxima
 * corrida semanal de legacy:sync-clientes). Ver App\Modules\Legacy\Observers\SubClientObserver.
 */
final class SubClientObserverTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    public function test_borrar_sub_client_matcheado_deja_match_status_unmatched(): void
    {
        $subClient = self::makeSubClient('8123456');
        $cliente   = self::makeCliente(2001, $subClient->id);

        $subClient->delete();

        $cliente->refresh();
        $this->assertSame(Cliente::MATCH_UNMATCHED, $cliente->match_status);
        $this->assertNull($cliente->sub_client_id);
    }

    public function test_borrar_sub_client_sin_ningun_cliente_vinculado_no_hace_nada(): void
    {
        $subClient = self::makeSubClient('9999999');

        $subClient->delete();

        $this->assertTrue(true); // no debe lanzar excepción sin filas que actualizar
    }

    public function test_borrar_sub_client_no_afecta_clientes_vinculados_a_otro_sub_client(): void
    {
        $subClientA = self::makeSubClient('1111111');
        $subClientB = self::makeSubClient('2222222');
        $clienteA   = self::makeCliente(2002, $subClientA->id);
        $clienteB   = self::makeCliente(2003, $subClientB->id);

        $subClientA->delete();

        $clienteA->refresh();
        $clienteB->refresh();
        $this->assertSame(Cliente::MATCH_UNMATCHED, $clienteA->match_status);
        $this->assertSame(Cliente::MATCH_MATCHED, $clienteB->match_status);
        $this->assertSame($subClientB->id, $clienteB->sub_client_id);
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

    private static function makeCliente(int $icgCodcliente, int $subClientId): Cliente
    {
        return Cliente::query()->create([
            'icg_codcliente' => $icgCodcliente,
            'razon_social'   => "Cliente {$icgCodcliente}",
            'sub_client_id'  => $subClientId,
            'match_status'   => Cliente::MATCH_MATCHED,
            'bloqueado'      => false,
        ]);
    }
}
