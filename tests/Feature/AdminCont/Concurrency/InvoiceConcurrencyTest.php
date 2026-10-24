<?php

declare(strict_types=1);

namespace Tests\Feature\AdminCont\Concurrency;

use App\Modules\AdminCont\Models\AdminContInvoice;
use App\Modules\Bodega\Models\Order;
use App\Modules\VentasDiseno\Models\MasterClient;
use App\Modules\VentasDiseno\Models\PipelineCard;
use App\Modules\VentasDiseno\Models\Quote;
use App\Modules\VentasDiseno\Models\QuotePart;
use App\Modules\VentasDiseno\Models\SalesProject;
use App\Modules\VentasDiseno\Models\SubClient;
use App\Shared\Multitenancy\Tenant;
use Carbon\Carbon;
use Tests\Feature\Bodega\Concerns\RunsConcurrentBodegaRequests;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-929 (B5) — concurrencia REAL (procesos/conexiones separados, ver `RunsConcurrentBodegaRequests`)
 * para el guard transaccional de `InvoiceService::confirm()` (ADR §3): dos requests que llegan casi al
 * mismo tiempo a facturar el MISMO pedido nunca deben poder duplicar el cobro, sin importar la
 * combinación de `modo`. Los tests secuenciales de `AdminContPartialInvoicingTest`/
 * `AdminContPartialInvoicingConsumersTest` prueban la lógica del guard, pero corren en un solo proceso
 * PHPUnit síncrono — nunca ejercitan la ventana de carrera real (dos requests que leen el pedido ANTES
 * de que cualquiera de las dos tome el `lockForUpdate()`).
 */
class InvoiceConcurrencyTest extends FeatureTestCase
{
    use RunsConcurrentBodegaRequests;

    /** @var array<string, mixed> */
    protected $connectionsToTransact = [];

    /** @var list<int> */
    private array $orderIdsToClean = [];
    private ?int $quoteIdToClean = null;
    private ?int $cardIdToClean = null;
    private ?int $masterIdToClean = null;

    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    /**
     * Limpieza de lo que crearon los subprocesos concurrentes (esos commits son reales: no hay rollback).
     *
     * Dos reglas, aprendidas de una falla intermitente en el CI (2026-10-02):
     *
     *  1. Las ENTREGAS HIJAS (`family_id` ≠ null) se borran ANTES que la raíz. `orders.family_id` es
     *     `ON DELETE SET NULL`: borrar la raíz primero deja a sus hijas como "raíces" con el mismo
     *     `pipeline_card_id` y viola el índice único parcial `orders_pipeline_card_root_uq`. El orden en
     *     que Postgres devolvía las filas dependía del layout físico del heap, por eso fallaba solo a veces.
     *  2. Cada paso de limpieza es independiente: si uno falla, los demás igual se ejecutan. Antes, un
     *     fallo a mitad dejaba pedidos/tarjetas/cotizaciones sueltos en la base y rompía los tests
     *     siguientes (conteos duplicados en Bodega, duplicados en Legacy). El primer error se relanza al final.
     */
    protected function tearDown(): void
    {
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();

        $deleteOrder = function (Order $o): void {
            $o->items()->delete();
            $o->delete();
        };

        /** @var list<callable(): void> $pasos */
        $pasos = [
            function (): void {
                if ($this->orderIdsToClean === []) {
                    return;
                }
                AdminContInvoice::whereIn('family_root_id', $this->orderIdsToClean)->get()->each(function (AdminContInvoice $i): void {
                    $i->lines()->delete();
                    $i->delete();
                });
            },
            // Hijas primero…
            function () use ($deleteOrder): void {
                if ($this->orderIdsToClean !== []) {
                    Order::whereIn('family_id', $this->orderIdsToClean)->get()->each($deleteOrder);
                }
            },
            // …y recién después las raíces.
            function () use ($deleteOrder): void {
                if ($this->orderIdsToClean !== []) {
                    Order::whereIn('id', $this->orderIdsToClean)->get()->each($deleteOrder);
                }
            },
            function (): void {
                if ($this->cardIdToClean !== null) {
                    PipelineCard::whereKey($this->cardIdToClean)->delete();
                }
            },
            function (): void {
                if ($this->quoteIdToClean === null) {
                    return;
                }
                $quote = Quote::with('parts.items')->find($this->quoteIdToClean);
                $quote?->parts->each(function (QuotePart $p): void { $p->items()->delete(); $p->delete(); });
                $quote?->delete();
            },
            function (): void {
                if ($this->masterIdToClean === null) {
                    return;
                }
                $master = MasterClient::find($this->masterIdToClean);
                SubClient::where('master_client_id', $this->masterIdToClean)->get()->each(function (SubClient $s): void {
                    SalesProject::where('sub_client_id', $s->id)->delete();
                    $s->delete();
                });
                $master?->delete();
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

    /**
     * @return array{root: Order, entregas: list<Order>, cardId: int}
     */
    private function makeFamiliaDe963(): array
    {
        $ownerId = (int) \App\Models\User::where('email', 'contabilidad@test.com')->value('id');
        $master  = MasterClient::create(['name' => 'Cliente Concurrencia 929 '.uniqid()]);
        $this->masterIdToClean = $master->id;
        $sub     = SubClient::create(['master_client_id' => $master->id, 'business_name' => 'Sub Concurrencia 929 '.uniqid(), 'tax_id' => '155-9291-1-'.random_int(1000, 9999)]);
        $project = SalesProject::create(['sub_client_id' => $sub->id, 'name' => 'Proyecto Concurrencia 929']);

        $quote = Quote::create([
            'owner_id' => $ownerId, 'sales_project_id' => $project->id, 'master_client_id' => $master->id,
            'folio' => 'COT-CONC-929-'.random_int(1000, 999999), 'generated_at' => Carbon::now(),
        ]);
        $this->quoteIdToClean = $quote->id;
        $part = QuotePart::create(['quote_id' => $quote->id, 'name' => 'Partida 1', 'position' => 1]);
        $item = $part->items()->create([
            'catalog_product_id' => null, 'is_custom' => true, 'reference' => 'REF-CONC-929',
            'description' => 'Panel LED (concurrencia 929)', 'quantity' => 9, 'unit_price' => 100.00, 'discount_percent' => 0,
        ]);
        $quote = Quote::with('parts.items')->findOrFail($quote->id);

        $card = PipelineCard::create([
            'sales_project_id' => $project->id, 'stage' => 'approved', 'master_client_id' => $master->id,
            'sub_client_id' => $sub->id, 'owner_id' => $ownerId, 'amount' => $quote->grandTotal(), 'stage_changed_at' => Carbon::now(),
        ]);
        $this->cardIdToClean = $card->id;

        $root = Order::create([
            'order_number' => 'PED-CONC-929-'.random_int(10000, 999999), 'pipeline_card_id' => $card->id,
            'order_type' => Order::ORDER_TYPE_PEDIDO, 'stage' => Order::STAGE_POR_DESPACHAR,
            'customer_name' => $sub->business_name,
        ]);
        $root->items()->create(['quote_item_id' => $item->id, 'description_snapshot' => $item->description, 'qty_requested' => 3, 'qty_picked' => 3, 'qty_delivered' => 3]);

        $entregas = [$root];
        for ($i = 2; $i <= 3; $i++) {
            $e = Order::create([
                'order_number' => $root->order_number, 'pipeline_card_id' => $card->id,
                'order_type' => Order::ORDER_TYPE_PEDIDO, 'stage' => Order::STAGE_POR_DESPACHAR,
                'customer_name' => $sub->business_name, 'family_id' => $root->id,
                'sequence_in_family' => $i, 'total_in_family' => 3,
            ]);
            $e->items()->create(['quote_item_id' => $item->id, 'description_snapshot' => $item->description, 'qty_requested' => 3, 'qty_picked' => 3, 'qty_delivered' => 3]);
            $entregas[] = $e;
        }
        $root->sequence_in_family = 1;
        $root->total_in_family = 3;
        $root->save();

        $this->orderIdsToClean[] = $root->id;

        return ['root' => $root, 'entregas' => $entregas, 'cardId' => $card->id];
    }

    public function test_dos_facturaciones_parciales_concurrentes_sobre_la_misma_entrega_solo_una_gana(): void
    {
        ['entregas' => $entregas] = $this->makeFamiliaDe963();
        $entregaId = $entregas[0]->id;

        $snippet = <<<'PHP'
$entrega = \App\Modules\Bodega\Models\Order::find(__ENTREGA_ID__);
$actor = \App\Models\User::where('email', 'contabilidad@test.com')->first();
try {
    $invoice = app(\App\Modules\AdminCont\Services\InvoiceService::class)->confirm($entrega, $actor, 'entrega');
    file_put_contents($outFile, 'OK:' . $invoice->monto);
} catch (\App\Modules\AdminCont\Exceptions\InvoicePreviewException $e) {
    file_put_contents($outFile, 'REJECTED:' . implode('|', $e->missing()));
}
PHP;
        $snippet = str_replace('__ENTREGA_ID__', (string) $entregaId, $snippet);

        $results = $this->runConcurrently([$snippet, $snippet]);

        $okCount       = count(array_filter($results, fn (string $r) => str_starts_with($r, 'OK:')));
        $rejectedCount = count(array_filter($results, fn (string $r) => str_starts_with($r, 'REJECTED:')));

        $this->assertSame(1, $okCount, 'Exactamente 1 de las 2 facturaciones concurrentes de la MISMA entrega debe tener éxito. Resultados: '.implode(' | ', $results));
        $this->assertSame(1, $rejectedCount, 'La otra debe ver la parcial ya activa y rechazar (nunca duplicar el cobro). Resultados: '.implode(' | ', $results));

        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $activas = AdminContInvoice::forOrder(Order::findOrFail($entregaId))->whereNull('anulada_at')->get();
        $this->assertCount(1, $activas, 'Solo debe quedar UNA factura activa para esta entrega, no dos.');
        $this->assertEqualsWithDelta(321.0, (float) $activas->firstOrFail()->monto, 0.01);
    }

    /**
     * Arquitecto, 2026-09-20 — reescrita: la aserción original ("exactamente 1 de las 2 gana")
     * asumía mutua exclusión entre `parcial` y `completo`, pero el ADR de SCRUM-929 (§4.1) documenta
     * "completo por el resto" como feature intencional — si el `parcial` gana la carrera y commitea
     * primero, el `completo` relee el estado fresco bajo el lock y factura legítimamente lo que
     * falta (963 − 321 = 642), sin exceder nunca el total. Cuál de los dos gana el lock es
     * no-determinístico (timing del SO) — el test debe aceptar AMBOS desenlaces válidos y verificar
     * el invariante real (nunca se duplica ni se excede el cobro), no forzar un ganador único.
     */
    public function test_facturacion_parcial_y_completo_concurrentes_sobre_el_mismo_pedido_nunca_duplica_ni_excede_el_cobro(): void
    {
        ['root' => $root, 'entregas' => $entregas] = $this->makeFamiliaDe963();
        $entregaId = $entregas[0]->id;
        $rootId    = $root->id;

        $snippetEntrega = <<<'PHP'
$entrega = \App\Modules\Bodega\Models\Order::find(__ENTREGA_ID__);
$actor = \App\Models\User::where('email', 'contabilidad@test.com')->first();
try {
    $invoice = app(\App\Modules\AdminCont\Services\InvoiceService::class)->confirm($entrega, $actor, 'entrega');
    file_put_contents($outFile, 'OK:parcial:' . $invoice->monto);
} catch (\App\Modules\AdminCont\Exceptions\InvoicePreviewException $e) {
    file_put_contents($outFile, 'REJECTED:parcial:' . implode('|', $e->missing()));
}
PHP;
        $snippetEntrega = str_replace('__ENTREGA_ID__', (string) $entregaId, $snippetEntrega);

        $snippetCompleto = <<<'PHP'
$root = \App\Modules\Bodega\Models\Order::find(__ROOT_ID__);
$actor = \App\Models\User::where('email', 'contabilidad@test.com')->first();
try {
    $invoice = app(\App\Modules\AdminCont\Services\InvoiceService::class)->confirm($root, $actor, 'completo');
    file_put_contents($outFile, 'OK:completo:' . $invoice->monto);
} catch (\App\Modules\AdminCont\Exceptions\InvoicePreviewException $e) {
    file_put_contents($outFile, 'REJECTED:completo:' . implode('|', $e->missing()));
}
PHP;
        $snippetCompleto = str_replace('__ROOT_ID__', (string) $rootId, $snippetCompleto);

        $results = $this->runConcurrently([$snippetEntrega, $snippetCompleto]);

        $okCount = count(array_filter($results, fn (string $r) => str_starts_with($r, 'OK:')));
        // Nunca las 2 rechazadas — con saldo disponible y sin facturas previas, alguna de las 2
        // siempre debe poder facturarse, gane quien gane la carrera por el lock.
        $this->assertGreaterThanOrEqual(1, $okCount, 'Al menos una de las 2 (parcial vs completo concurrentes) debe tener éxito. Resultados: '.implode(' | ', $results));

        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $activas = AdminContInvoice::forOrder($root)->whereNull('anulada_at')->get();

        if ($okCount === 1) {
            // El `completo` ganó el lock primero (cubre TODO el pedido) → la `parcial`, al releer
            // bajo el lock, ve el completo ya activo y se rechaza (guard §3.1).
            $this->assertCount(1, $activas, 'Resultados: '.implode(' | ', $results));
            $this->assertEqualsWithDelta(963.0, (float) $activas->firstOrFail()->monto, 0.01, 'Resultados: '.implode(' | ', $results));
        } else {
            // La `parcial` ganó el lock primero (cubre su entrega) → el `completo`, al releer bajo
            // el lock, no ve ningún completo activo (solo la parcial) y factura legítimamente el
            // resto — comportamiento intencional documentado en ADR-SCRUM929 §4.1, no un duplicado.
            $this->assertSame(2, $okCount, 'Resultados: '.implode(' | ', $results));
            $this->assertCount(2, $activas, 'Resultados: '.implode(' | ', $results));
            $porTipo = $activas->keyBy('tipo');
            $this->assertEqualsWithDelta(321.0, (float) $porTipo->get(AdminContInvoice::TIPO_PARCIAL)?->monto, 0.01, 'Resultados: '.implode(' | ', $results));
            $this->assertEqualsWithDelta(642.0, (float) $porTipo->get(AdminContInvoice::TIPO_COMPLETO)?->monto, 0.01, 'Resultados: '.implode(' | ', $results));
        }

        // Invariante real, sin importar quién ganó la carrera: nunca se cobra de más.
        $this->assertLessThanOrEqual(963.0 + 0.01, (float) $activas->sum('monto'), 'Σ activas nunca excede el total del pedido.');
    }
}
