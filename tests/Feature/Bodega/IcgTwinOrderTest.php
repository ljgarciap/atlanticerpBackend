<?php

declare(strict_types=1);

namespace Tests\Feature\Bodega;

use App\Modules\Bodega\Models\Order;
use App\Modules\VentasDiseno\Models\MasterClient;
use App\Modules\VentasDiseno\Models\Quote;
use App\Modules\VentasDiseno\Models\SubClient;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Feature\FeatureTestCase;

/**
 * Pedidos gemelos de ICG (diseño `docs/architecture/icg-pedidos-gemelos-diseno.md`, T1 y T2): el esquema exige la llave
 * de ICG y la cotización gemela, la cotización gemela queda oculta de TODA consulta salvo las que la piden
 * explícitamente, y `Order::linkedQuote()` la resuelve sin tarjeta de pipeline.
 */
class IcgTwinOrderTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    private function ownerId(): int
    {
        $slug = (string) Tenant::current()?->slug;

        return (int) DB::table("{$slug}_auth.users")->value('id');
    }

    private function quote(string $origin): Quote
    {
        $master = MasterClient::create(['name' => 'Cliente gemelo '.uniqid()]);
        $sub    = SubClient::create(['master_client_id' => $master->id, 'business_name' => 'Sub '.uniqid(), 'tax_id' => '8-123-456']);

        $id = DB::table((new Quote())->getTable())->insertGetId([
            'origin' => $origin, 'owner_id' => $this->ownerId(),
            'master_client_id' => $master->id, 'sub_client_id' => $sub->id,
            'status' => 'draft', 'price_type' => 'public', 'discount_mode' => 'line',
            'global_discount_percent' => 0, 'discount_totals_type' => 'percent', 'discount_totals_value' => 0,
            'includes_installation' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return Quote::withIcgLegacy()->findOrFail($id);
    }

    /** @return array<string, mixed> */
    private function twinAttrs(Quote $quote, int $numero = 46234): array
    {
        return [
            'order_number' => "ICG-V11P-{$numero}", 'origin' => 'icg_legacy', 'icg_serie' => 'V11P', 'icg_numero' => $numero,
            'icg_facturado' => 0, 'icg_total_neto' => 400.01, 'quote_id' => $quote->id, 'sub_client_id' => $quote->sub_client_id,
            'stage' => Order::STAGE_ENTREGADO,
        ];
    }

    public function test_la_cotizacion_gemela_no_aparece_en_ninguna_consulta_normal(): void
    {
        $gemela   = $this->quote('icg_legacy');
        $comercial = $this->quote('comercial');

        $this->assertNull(Quote::find($gemela->id));
        $this->assertFalse(Quote::query()->whereKey($gemela->id)->exists());
        $this->assertSame(0, Quote::query()->where('origin', 'icg_legacy')->count());
        $this->assertNotNull(Quote::find($comercial->id));
        $this->assertNotNull(Quote::withIcgLegacy()->find($gemela->id));
    }

    public function test_linked_quote_resuelve_la_cotizacion_gemela_sin_tarjeta(): void
    {
        $gemela = $this->quote('icg_legacy');
        $order  = Order::create($this->twinAttrs($gemela));

        $this->assertTrue($order->isIcgLegacy());
        $this->assertNull($order->pipeline_card_id);
        $this->assertSame($gemela->id, $order->linkedQuote()?->id);
        $this->assertSame($gemela->id, $order->quote?->id);
    }

    public function test_un_pedido_normal_conserva_su_origen_y_no_lleva_datos_de_icg(): void
    {
        $order = Order::create(['order_number' => 'PED-NORMAL-1']);

        $this->assertSame('app', $order->fresh()?->origin);
        $this->assertFalse($order->isIcgLegacy());
        $this->assertNull($order->icg_serie);
    }

    public function test_el_esquema_exige_llave_de_icg_y_cotizacion_en_un_gemelo(): void
    {
        $this->expectException(QueryException::class);

        Order::create(['order_number' => 'ICG-SIN-LLAVE', 'origin' => 'icg_legacy']);
    }

    public function test_un_pedido_normal_no_puede_llevar_llave_de_icg(): void
    {
        $this->expectException(QueryException::class);

        Order::create(['order_number' => 'PED-CON-LLAVE', 'icg_serie' => 'V11P', 'icg_numero' => 1]);
    }

    public function test_la_llave_de_icg_es_unica_entre_gemelos(): void
    {
        $quote = $this->quote('icg_legacy');
        Order::create($this->twinAttrs($quote, 46234));

        $this->expectException(QueryException::class);

        Order::create(['order_number' => 'ICG-V11P-46234-DUP'] + $this->twinAttrs($quote, 46234));
    }

    public function test_el_tablero_de_bodega_oculta_los_gemelos_entregados_y_muestra_los_que_bodega_debe_despachar(): void
    {
        $quote = $this->quote('icg_legacy');
        $entregado = Order::create($this->twinAttrs($quote, 50001));
        $asignado  = Order::create(['stage' => Order::STAGE_ASIGNADO, 'order_number' => 'ICG-V11P-50002', 'icg_numero' => 50002] + $this->twinAttrs($quote, 50002));
        $normal    = Order::create(['order_number' => 'PED-NORMAL-TABLERO', 'stage' => Order::STAGE_ENTREGADO]);

        $token = $this->loginAs('superadmin@atlantic.test', 'Password123!', 'superadmin');
        $response = $this->withHeaders($this->authHeaders($token))->getJson('/api/bodega/orders')->assertStatus(200);
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();

        $ids = collect($response->json('cards'))->pluck('id')->all();
        $this->assertNotContains($entregado->id, $ids, 'Un gemelo parcial (ya entregado en ICG) no es trabajo de Bodega.');
        $this->assertContains($asignado->id, $ids, 'Un gemelo de la hoja Completo sí lo despacha Bodega.');
        $this->assertContains($normal->id, $ids, 'Control: un pedido normal entregado sigue en el tablero.');
        $this->assertSame(1, $response->json('counts_by_stage.'.Order::STAGE_ASIGNADO));
    }
}
