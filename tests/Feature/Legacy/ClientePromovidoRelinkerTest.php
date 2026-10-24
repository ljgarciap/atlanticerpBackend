<?php

declare(strict_types=1);

namespace Tests\Feature\Legacy;

use App\Modules\Auth\Models\AuditLog;
use App\Modules\Legacy\Models\Cliente;
use App\Modules\Legacy\Services\ClientePromovidoRelinker;
use App\Modules\VentasDiseno\Models\MasterClient;
use App\Modules\VentasDiseno\Models\SubClient;
use App\Shared\Multitenancy\Tenant;
use App\Shared\Services\AuditService;
use Illuminate\Support\Facades\Artisan;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-1113 — reenlace de `legacy.clientes.sub_client_id` de los clientes ya promovidos cuyo enlace se perdió. Cubre las reglas (código, CIF, CIF repetido
 * desambiguado por nombre), lo que NO se toca (ambiguos, solo-por-nombre, sub_clients ya enlazados), la idempotencia y la reversa en la auditoría.
 */
final class ClientePromovidoRelinkerTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    public function test_reenlaza_por_codigo_por_cif_y_por_cif_mas_nombre_y_no_toca_lo_ambiguo_ni_lo_solo_por_nombre(): void
    {
        // Regla 1: SIN-CIF-<código>.
        $subA = $this->sub('SIN-CIF-5001', 'Cliente Uno');
        $cA   = $this->cliente(5001, null, 'Cliente Uno', Cliente::MATCH_UNMATCHED);

        // Regla 2: CIF único.
        $subB = $this->sub('8-123-456', 'Cliente Dos');
        $cB   = $this->cliente(5002, '8-123-456', 'Cliente Dos', Cliente::MATCH_AMBIGUOUS);

        // Regla 2 con CIF repetido: dos clientes y dos sub_clients con el mismo CIF, se emparejan por nombre.
        $subC1 = $this->sub('9-999-999', 'Tres A');
        $subC2 = $this->sub('9-999-999', 'Tres B');
        $cC1   = $this->cliente(5003, '9-999-999', 'Tres A', Cliente::MATCH_AMBIGUOUS);
        $cC2   = $this->cliente(5004, '9-999-999', 'Tres B', Cliente::MATCH_AMBIGUOUS);

        // CIF repetido con el MISMO nombre: no se puede identificar el par, queda ambiguo.
        $this->sub('7-777-777', 'Gemelo');
        $this->sub('7-777-777', 'Gemelo');
        $cD1 = $this->cliente(5005, '7-777-777', 'Gemelo', Cliente::MATCH_AMBIGUOUS);
        $cD2 = $this->cliente(5006, '7-777-777', 'Gemelo', Cliente::MATCH_AMBIGUOUS);

        // Solo coincide el nombre (sin código ni CIF): se reporta, no se aplica.
        $subE = $this->sub('OTRO-TAX', 'Solo Nombre SA');
        $cE   = $this->cliente(5007, null, 'Solo Nombre SA', Cliente::MATCH_UNMATCHED);

        // sub_client que ya tiene un cliente ICG enlazado: nunca se reutiliza.
        $subF = $this->sub('SIN-CIF-5008', 'Ya Enlazado');
        $cF1  = $this->cliente(5008, null, 'Ya Enlazado', Cliente::MATCH_MATCHED);
        $cF1->sub_client_id = $subF->id;
        $cF1->save();

        $relinker = new ClientePromovidoRelinker();
        $plan     = $relinker->plan();

        $porCliente = [];
        foreach ($plan['pares'] as $p) {
            $porCliente[$p['icg_codcliente']] = $p;
        }
        $this->assertSame($subA->id, $porCliente[5001]['sub_client_id']);
        $this->assertSame('codigo', $porCliente[5001]['regla']);
        $this->assertSame($subB->id, $porCliente[5002]['sub_client_id']);
        $this->assertSame('cif', $porCliente[5002]['regla']);
        $this->assertSame($subC1->id, $porCliente[5003]['sub_client_id']);
        $this->assertSame($subC2->id, $porCliente[5004]['sub_client_id']);
        $this->assertSame('cif+nombre', $porCliente[5003]['regla']);
        $this->assertArrayNotHasKey(5005, $porCliente);
        $this->assertArrayNotHasKey(5006, $porCliente);
        $this->assertArrayNotHasKey(5007, $porCliente);
        $this->assertCount(4, $plan['pares']);
        $this->assertEqualsCanonicalizing([5005, 5006], array_column($plan['ambiguos'], 'icg_codcliente'));
        $this->assertSame([['icg_codcliente' => 5007, 'sub_client_id' => $subE->id]], $plan['solo_por_nombre']);

        $res = $relinker->aplicar($plan, 'test', 'testing');
        $this->assertSame(4, $res['reenlazados']);

        foreach ([[$cA, $subA], [$cB, $subB], [$cC1, $subC1], [$cC2, $subC2]] as [$c, $s]) {
            $c->refresh();
            $this->assertSame($s->id, $c->sub_client_id);
            $this->assertSame(Cliente::MATCH_PROMOTED, $c->match_status);
        }
        foreach ([$cD1, $cD2, $cE] as $c) {
            $c->refresh();
            $this->assertNull($c->sub_client_id, 'Lo ambiguo y lo solo-por-nombre no se toca.');
        }

        // Idempotente: una segunda corrida no encuentra nada que reenlazar.
        $this->assertSame([], $relinker->plan()['pares']);

        // Reversa: el evento guarda el valor anterior de cada fila tocada.
        $evento = AuditLog::where('action', AuditService::LEGACY_RELINK_CLIENTES_PROMOVIDOS)->latest('id')->first();
        $this->assertNotNull($evento);
        $this->assertCount(4, $evento->metadata['anteriores']);
        $this->assertContains([$cA->id, null, Cliente::MATCH_UNMATCHED], $evento->metadata['anteriores']);
    }

    public function test_aplicar_aborta_si_el_estado_cambio_despues_del_dry_run(): void
    {
        $this->sub('SIN-CIF-5101', 'Uno');
        $this->cliente(5101, null, 'Uno', Cliente::MATCH_UNMATCHED);
        $relinker = new ClientePromovidoRelinker();
        $plan     = $relinker->plan();

        $this->sub('SIN-CIF-5102', 'Dos');
        $this->cliente(5102, null, 'Dos', Cliente::MATCH_UNMATCHED);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('huella distinta');
        $relinker->aplicar($plan, 'test', 'testing');
    }

    public function test_comando_exige_operador_con_apply_y_el_dry_run_no_escribe(): void
    {
        $sub = $this->sub('SIN-CIF-5201', 'Uno');
        $c   = $this->cliente(5201, null, 'Uno', Cliente::MATCH_UNMATCHED);

        $this->assertSame(1, Artisan::call('legacy:relink-clientes-promovidos', ['--apply' => true, '--yes' => true]));
        $this->assertStringContainsString('--operador es obligatorio', Artisan::output());

        $this->assertSame(0, Artisan::call('legacy:relink-clientes-promovidos'));
        $this->assertStringContainsString('Modo dry-run', Artisan::output());
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent(); // el comando libera el tenant al terminar
        $this->assertNull($c->refresh()->sub_client_id);

        $this->assertSame(0, Artisan::call('legacy:relink-clientes-promovidos', ['--apply' => true, '--yes' => true, '--operador' => 'test']));
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $this->assertSame($sub->id, $c->refresh()->sub_client_id);
    }

    private function sub(string $taxId, string $nombre): SubClient
    {
        $master = MasterClient::query()->create(['name' => $nombre]);

        return SubClient::query()->create(['master_client_id' => $master->id, 'business_name' => $nombre, 'tax_id' => $taxId]);
    }

    private function cliente(int $codigo, ?string $cif, string $nombre, string $status): Cliente
    {
        return Cliente::query()->create([
            'icg_codcliente'   => $codigo,
            'nombre_comercial' => $nombre,
            'razon_social'     => $nombre.' SA',
            'cif'              => $cif,
            'match_status'     => $status,
            'bloqueado'        => false,
        ]);
    }
}
