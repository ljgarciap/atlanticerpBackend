<?php

declare(strict_types=1);

namespace Tests\Feature\AdminCont\Concerns;

use App\Modules\AdminCont\Models\AdminContInvoice;
use App\Modules\AdminCont\Models\AdminContItbmsRate;
use App\Modules\AdminCont\Services\ItbmsRateResolver;
use App\Modules\Bodega\Models\Order;
use App\Modules\VentasDiseno\Models\MasterClient;
use App\Modules\VentasDiseno\Models\PipelineCard;
use App\Modules\VentasDiseno\Models\Quote;
use App\Modules\VentasDiseno\Models\QuoteItem;
use App\Modules\VentasDiseno\Models\QuotePart;
use App\Modules\VentasDiseno\Models\SalesProject;
use App\Modules\VentasDiseno\Models\SubClient;
use App\Shared\Multitenancy\Tenant;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

/**
 * SCRUM-929 — fixtures compartidas por los tests de facturación por entrega/pedido completo
 * (`AdminContPartialInvoicingTest`) y por los de sus consumidores (`AdminContPartialInvoicingConsumersTest`):
 * un pedido con cotización real, tarjeta con `amount = grandTotal()` y familia de entregas cuyos
 * `OrderItem` están vinculados a los ítems cotizados. Ver `makePedido()`.
 */
trait BuildsPartiallyInvoicedOrders
{
    private const CONTABILIDAD_EMAIL = 'contabilidad@test.com';

    private function reTenant(): void
    {
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    private function token(): string
    {
        $token = $this->loginAs(self::CONTABILIDAD_EMAIL, self::CONTABILIDAD_EMAIL, 'lider_admin_contab');
        $this->reTenant();

        return $token;
    }

    private function ownerId(): int
    {
        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');

        return (int) DB::table("{$slug}_auth.users")->where('email', self::CONTABILIDAD_EMAIL)->value('id');
    }

    /** @param array<string, mixed> $data */
    private function api(string $token, string $method, string $uri, array $data = []): TestResponse
    {
        $response = $this->withHeaders($this->authHeaders($token))->json($method, $uri, $data);
        $this->reTenant();

        return $response;
    }

    /** @return TestResponse */
    private function facturar(string $token, array $ids, ?string $modo = null): TestResponse
    {
        return $this->api($token, 'POST', '/api/admin-contab/invoices', ['order_ids' => $ids] + ($modo !== null ? ['modo' => $modo] : []));
    }

    /**
     * Pedido con cotización real (partidas + ítems con precio), tarjeta con `amount = grandTotal()` y
     * una familia de entregas cuyos `OrderItem` están vinculados a los ítems cotizados por
     * `quote_item_id`. `$entregas[k][i]` = cantidad ENTREGADA de `$items[i]` en la entrega k (la 0 es
     * la raíz); una cantidad 0 no crea el `OrderItem`.
     *
     * @param list<array{desc: string, qty: int|float, price: float, discount?: float, part?: int, custom?: bool, cost?: float}> $items
     * @param list<list<int|float>> $entregas
     * @param array<string, mixed>  $quoteOverrides
     * @param array<int, string>    $stages  etapa por índice de entrega (default Por Despachar)
     * @return array{root: Order, entregas: list<Order>, quote: Quote, items: list<QuoteItem>, card: PipelineCard}
     */
    private function makePedido(array $items, array $entregas, array $quoteOverrides = [], array $stages = [], bool $vincular = true): array
    {
        $ownerId = $this->ownerId();
        $master  = MasterClient::create(['name' => 'Cliente 929 '.uniqid()]);
        $sub     = SubClient::create(['master_client_id' => $master->id, 'business_name' => 'Subcliente 929 '.uniqid(), 'tax_id' => '155-0000-1-'.self::uniqueTestNumber()]);
        $project = SalesProject::create(['sub_client_id' => $sub->id, 'name' => 'Proyecto 929 '.uniqid()]);

        $quote = Quote::create([
            'owner_id' => $ownerId, 'sales_project_id' => $project->id, 'master_client_id' => $master->id,
            'folio' => 'COT-2026-'.self::uniqueTestNumber(), 'generated_at' => Carbon::now(),
        ] + $quoteOverrides);

        $parts      = [];
        $quoteItems = [];
        foreach ($items as $spec) {
            $partNo = $spec['part'] ?? 1;
            $parts[$partNo] ??= QuotePart::create(['quote_id' => $quote->id, 'name' => "Partida {$partNo}", 'position' => $partNo]);
            $quoteItems[] = $parts[$partNo]->items()->create([
                'catalog_product_id' => null, 'is_custom' => $spec['custom'] ?? true, 'reference' => 'REF-'.self::uniqueTestNumber(),
                'description' => $spec['desc'], 'quantity' => $spec['qty'], 'unit_price' => $spec['price'],
                'discount_percent' => $spec['discount'] ?? 0, 'cost' => $spec['cost'] ?? null,
            ]);
        }

        $quote = Quote::with('parts.items')->findOrFail($quote->id);
        $card  = PipelineCard::create([
            'sales_project_id' => $project->id, 'stage' => 'approved', 'master_client_id' => $master->id,
            'sub_client_id' => $sub->id, 'owner_id' => $ownerId, 'amount' => $quote->grandTotal(), 'stage_changed_at' => Carbon::now(),
        ]);

        $total = count($entregas);
        $ords  = [];
        foreach ($entregas as $k => $cantidades) {
            $ords[$k] = Order::create([
                'order_number'       => $k === 0 ? 'PED-2026-'.self::uniqueTestNumber() : $ords[0]->order_number,
                'pipeline_card_id'   => $card->id,
                'order_type'         => Order::ORDER_TYPE_PEDIDO,
                'stage'              => $stages[$k] ?? Order::STAGE_POR_DESPACHAR,
                'customer_name'      => $sub->business_name,
                'family_id'          => $k === 0 ? null : $ords[0]->id,
                'sequence_in_family' => $total > 1 ? $k + 1 : null,
                'total_in_family'    => $total > 1 ? $total : null,
            ]);
            foreach ($cantidades as $i => $qty) {
                if ($qty <= 0) {
                    continue;
                }
                $ords[$k]->items()->create([
                    'quote_item_id'        => $vincular ? $quoteItems[$i]->id : null,
                    'description_snapshot' => $items[$i]['desc'],
                    'qty_requested'        => (int) $qty, 'qty_picked' => (int) $qty, 'qty_delivered' => (int) $qty,
                ]);
            }
        }

        return ['root' => $ords[0]->fresh() ?? $ords[0], 'entregas' => array_values($ords), 'quote' => $quote, 'items' => $quoteItems, 'card' => $card];
    }

    /** @return list<AdminContInvoice> */
    private function activas(Order $root): array
    {
        return AdminContInvoice::forOrder($root)->whereNull('anulada_at')->orderBy('id')->get()->all();
    }

    private function sumaActivas(Order $root): float
    {
        return round((float) AdminContInvoice::forOrder($root)->whereNull('anulada_at')->sum('monto'), 2);
    }

    private function pct(): float
    {
        return ItbmsRateResolver::defaultPercentage();
    }

    /** Nueva versión (con folio, id mayor) de la cotización del proyecto, con ítems de ids distintos. */
    private function nuevaVersionDeLaCotizacion(Quote $quote): Quote
    {
        $v2   = Quote::create([
            'owner_id' => $quote->owner_id, 'sales_project_id' => $quote->sales_project_id, 'master_client_id' => $quote->master_client_id,
            'folio' => 'COT-2026-'.self::uniqueTestNumber(), 'generated_at' => Carbon::now()->addMinute(),
        ]);
        $part = QuotePart::create(['quote_id' => $v2->id, 'name' => 'Partida v2', 'position' => 1]);
        $part->items()->create(['catalog_product_id' => null, 'is_custom' => true, 'reference' => 'REF-'.self::uniqueTestNumber(), 'description' => 'Ítem v2', 'quantity' => 9, 'unit_price' => 100.00, 'discount_percent' => 0]);

        return $v2;
    }

    /** Cambia la tasa de ITBMS por defecto (la que usan cotización y facturas), como haría Configuración Fiscal. */
    private function cambiarTasaDeItbms(float $porcentaje): void
    {
        $tasa = ItbmsRateResolver::defaultRate();
        DB::table((new AdminContItbmsRate())->getTable())->where('id', $tasa->id)->update(['porcentaje' => $porcentaje]);
    }

    /** @return list<list<string>> filas (sin encabezado) del export a Excel de Facturación: A..E */
    private function filasDelExport(string $token): array
    {
        $response = $this->withHeaders($this->authHeaders($token))->get('/api/admin-contab/invoices/export?format=excel');
        $response->assertStatus(200);
        $this->reTenant();

        $tmp = tempnam(sys_get_temp_dir(), 'inv_export_');
        file_put_contents((string) $tmp, $response->streamedContent());
        $hoja = \PhpOffice\PhpSpreadsheet\IOFactory::load((string) $tmp)->getActiveSheet();
        @unlink((string) $tmp);

        $filas = [];
        for ($r = 2; $r <= $hoja->getHighestRow(); $r++) {
            $filas[] = array_map(fn (string $c): string => (string) $hoja->getCell("{$c}{$r}")->getValue(), ['A', 'D', 'E', 'F', 'G']); // SCRUM-1175: Cliente (B) y Vendedor (C) se saltan; las pruebas leen Entrega, N° Factura, N° Cotización, Monto, Estado
        }

        return $filas;
    }

    /** Pedido "estándar" para los casos de dinero: 3 ítems con descuento de línea y de Totales. */
    private function pedidoConDescuentos(): array
    {
        return $this->makePedido(
            [
                ['desc' => 'Producto A', 'qty' => 10, 'price' => 33.33, 'discount' => 5],
                ['desc' => 'Producto B', 'qty' => 7, 'price' => 12.49],
                ['desc' => 'Producto C', 'qty' => 3, 'price' => 99.99, 'discount' => 12.5],
            ],
            [[4, 2, 1], [3, 2, 1], [3, 3, 1]],
            ['discount_totals_type' => 'percent', 'discount_totals_value' => 3.5],
        );
    }
}
