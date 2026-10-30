<?php

declare(strict_types=1);

namespace Tests\Feature\AdminCont\Concurrency;

use App\Models\User;
use App\Modules\AdminCont\Models\AdminContPaymentMovement;
use App\Modules\AdminCont\Models\AdminContBankAccount;
use App\Modules\AdminCont\Models\AdminContBankMovement;
use App\Modules\AdminCont\Models\AdminContInvoice;
use App\Modules\AdminCont\Models\AdminContPayment;
use App\Modules\AdminCont\Services\PaymentService;
use App\Modules\VentasDiseno\Models\MasterClient;
use App\Shared\Multitenancy\Tenant;
use Tests\Feature\Bodega\Concerns\RunsConcurrentBodegaRequests;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-1168 F0 — concurrencia REAL (procesos y conexiones separados, ver `RunsConcurrentBodegaRequests`) de la confirmación de cobros:
 * dos cobros «esperando confirmación» sobre la MISMA factura que se confirman a la vez nunca pueden dejarla con más cobrado que su valor.
 * Los tests secuenciales de `AdminContCobrosIntegridadF0Test` prueban la lógica; este ejercita la ventana de carrera real.
 */
class CobroConfirmacionConcurrencyTest extends FeatureTestCase
{
    use RunsConcurrentBodegaRequests;

    /** @var array<string, mixed> */
    protected $connectionsToTransact = [];

    private ?int $masterId = null;
    private ?int $accountId = null;
    /** @var list<int> */
    private array $paymentIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    protected function tearDown(): void
    {
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();

        /** @var list<callable(): void> $pasos */
        $pasos = [
            // El libro de movimientos referencia cobros y facturas: va primero.
            function (): void {
                if ($this->masterId !== null) {
                    AdminContPaymentMovement::where('master_client_id', $this->masterId)->delete();
                }
            },
            function (): void {
                if ($this->paymentIds === []) {
                    return;
                }
                AdminContBankMovement::where('source_type', AdminContBankMovement::SOURCE_PAYMENT)->whereIn('source_id', $this->paymentIds)->delete();
                AdminContPayment::whereIn('id', $this->paymentIds)->get()->each(function (AdminContPayment $p): void {
                    $p->confirmation()->delete();
                    $p->invoiceLines()->delete();
                    $p->delete();
                });
            },
            function (): void {
                if ($this->masterId !== null) {
                    AdminContInvoice::where('master_client_id', $this->masterId)->delete();
                }
            },
            function (): void {
                if ($this->accountId !== null) {
                    AdminContBankAccount::whereKey($this->accountId)->delete();
                }
            },
            function (): void {
                if ($this->masterId !== null) {
                    MasterClient::whereKey($this->masterId)->delete();
                }
            },
        ];

        $primerError = null;
        foreach ($pasos as $paso) {
            try {
                $paso();
            } catch (\Throwable $e) {
                $primerError ??= $e;
            }
        }

        parent::tearDown();

        if ($primerError !== null) {
            throw $primerError;
        }
    }

    public function test_dos_cobros_pendientes_de_la_misma_factura_confirmados_a_la_vez_solo_uno_se_aplica(): void
    {
        $actor  = User::where('email', 'contabilidad@test.com')->firstOrFail();
        $master = MasterClient::create(['name' => 'Cliente Concurrencia 1168 '.uniqid()]);
        $this->masterId = $master->id;
        $cuenta = AdminContBankAccount::create(['banco' => 'Banco Demo A', 'tipo_cuenta' => AdminContBankAccount::TIPO_CORRIENTE, 'ultimos_4_digitos' => '4242']);
        $this->accountId = $cuenta->id;
        $factura = AdminContInvoice::create([
            'numero' => 'F-CONC-1168-'.random_int(1000, 999999), 'order_id' => random_int(100000, 999999),
            'master_client_id' => $master->id, 'cliente' => $master->name, 'monto' => 500, 'saldo_aplicado' => 0,
        ]);

        $service = app(PaymentService::class);
        // Dos cobros por transferencia (esperan confirmación) que apuntan a la MISMA factura de $500.
        $a = $service->registerV2($master->id, 500.0, 'transferencia', $cuenta->id, null, null, 'TRF-A', null, [['invoice_id' => $factura->id, 'monto' => 500.0]], $actor);
        $b = $service->registerV2($master->id, 500.0, 'transferencia', $cuenta->id, null, null, 'TRF-B', null, [['invoice_id' => $factura->id, 'monto' => 500.0]], $actor);
        $this->paymentIds = [$a->id, $b->id];

        $plantilla = <<<'PHP'
$actor = \App\Models\User::where('email', 'contabilidad@test.com')->first();
try {
    $r = app(\App\Modules\AdminCont\Services\PaymentService::class)->confirmWithAdjustments(__PAYMENT_ID__, $actor);
    file_put_contents($outFile, 'OK:'.($r['ajustes']['excedente_a_saldo_favor'] ?? 0));
} catch (\App\Modules\AdminCont\Exceptions\PaymentStateException $e) {
    file_put_contents($outFile, 'REJECTED:'.$e->getMessage());
}
PHP;

        $results = $this->runConcurrently([
            str_replace('__PAYMENT_ID__', (string) $a->id, $plantilla),
            str_replace('__PAYMENT_ID__', (string) $b->id, $plantilla),
        ]);

        // Confirmar nunca se rechaza: AMBOS cobros se confirman (el dinero entró); exactamente uno aplica la factura y el otro
        // manda sus $500 a saldo a favor. Cuál gana el bloqueo es no determinístico; el invariante es que la factura no se sobrecobra.
        $this->assertSame(['OK:0', 'OK:500'], (function (array $r): array { sort($r); return $r; })($results), 'Resultados: '.implode(' | ', $results));

        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $this->assertEqualsWithDelta(500.0, (float) $factura->refresh()->saldo_aplicado, 0.001, 'La factura no puede quedar con más cobrado que su valor.');
        $this->assertSame(2, AdminContPayment::whereIn('id', $this->paymentIds)->where('estado', AdminContPayment::ESTADO_CONFIRMADO)->count());
        // Con el concepto único el excedente vive en el libro: entraron $1000 (dos cobros), se aplicaron $500 a la factura y quedan $500 a favor.
        $disponible = (float) AdminContPaymentMovement::where('master_client_id', $this->masterId)->sum('delta_disponible');
        $this->assertEqualsWithDelta(500.0, $disponible, 0.001, 'Exactamente un excedente de $500 a saldo a favor.');
    }
}
