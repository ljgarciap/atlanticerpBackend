<?php

declare(strict_types=1);

namespace Tests\Feature\AdminCont;

use App\Modules\AdminCont\Models\AdminContInvoice;
use App\Modules\AdminCont\Models\AdminContItbmsRate;
use App\Modules\AdminCont\Services\InvoiceService;
use App\Modules\AdminCont\Services\ItbmsRateResolver;
use App\Modules\Auth\Models\AuditLog;
use App\Modules\Bodega\Models\Order;
use App\Modules\VentasDiseno\Models\MasterClient;
use App\Modules\VentasDiseno\Models\PipelineCard;
use App\Modules\VentasDiseno\Models\Quote;
use App\Modules\VentasDiseno\Models\QuotePart;
use App\Modules\VentasDiseno\Models\SalesProject;
use App\Modules\VentasDiseno\Models\SubClient;
use App\Shared\Multitenancy\Tenant;
use App\Shared\Services\AuditService;
use Carbon\Carbon;
use Tests\Feature\AdminCont\Concerns\BuildsPartiallyInvoicedOrders;
use Tests\Feature\AdminCont\Concerns\StubsReceptorGuard;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-1166 — clientes exentos de ITBMS. La exención se marca en el cliente fiscal (sub-cliente) con el documento que la respalda y solo con el
 * permiso angosto `admin_contab.edit.client_itbms_exemption`. La cotización (y de ahí la factura) deja de cobrar ITBMS; lo confirmado y lo emitido
 * no cambia nunca. Los importes esperados se derivan de la tasa que devuelve `ItbmsRateResolver`, no de un 7% escrito acá.
 */
class AdminContItbmsExencionTest extends FeatureTestCase
{
    use BuildsPartiallyInvoicedOrders;
    use StubsReceptorGuard;

    protected function setUp(): void
    {
        parent::setUp();
        $this->reTenant();
        $this->stubReceptorGuard();
    }

    private function loginAsMark(): string
    {
        $token = $this->loginAs('gerencia3@test.com', 'gerencia3@test.com', 'management');
        $this->reTenant();

        return $token;
    }

    private function subCliente(bool $exento = false): SubClient
    {
        $master = MasterClient::create(['name' => 'Cliente 1166 '.uniqid()]);
        $sub    = SubClient::create(['master_client_id' => $master->id, 'business_name' => 'Subcliente 1166 '.uniqid(), 'tax_id' => '155-0000-1-'.self::uniqueTestNumber()]);
        if ($exento) {
            $sub->forceFill(['itbms_exento' => true, 'itbms_exencion_documento' => 'Resolución 201-1234'])->save();
        }

        return $sub;
    }

    /**
     * Cotización real (una partida de $200 netos) de un cliente, con su proyecto, tarjeta y pedido de una sola entrega.
     *
     * @return array{quote: Quote, card: PipelineCard, order: Order, project: SalesProject}
     */
    private function pedidoDe(SubClient $sub, bool $confirmada = false, ?SalesProject $project = null): array
    {
        $ownerId = $this->ownerId();
        $project ??= SalesProject::create(['sub_client_id' => $sub->id, 'name' => 'Proyecto 1166 '.uniqid()]);

        $quote = Quote::create([
            'owner_id' => $ownerId, 'sales_project_id' => $project->id, 'master_client_id' => $sub->master_client_id, 'sub_client_id' => $sub->id,
            'folio' => 'COT-2026-'.self::uniqueTestNumber(), 'generated_at' => Carbon::now(), 'confirmed_at' => $confirmada ? Carbon::now() : null,
        ]);
        if ($confirmada && $sub->itbms_exento) {
            // Una cotización confirmada de un cliente exento llegó así desde borrador (el hook solo copia mientras no está confirmada).
            $quote->forceFill(['itbms_exento' => true])->save();
        }
        $parte = QuotePart::create(['quote_id' => $quote->id, 'name' => 'Partida 1', 'position' => 1]);
        $item  = $parte->items()->create([
            'catalog_product_id' => null, 'is_custom' => true, 'reference' => 'REF-'.self::uniqueTestNumber(),
            'description' => 'Producto', 'quantity' => 2, 'unit_price' => 100.0, 'discount_percent' => 0, 'cost' => null,
        ]);

        $quote = Quote::with('parts.items')->findOrFail($quote->id);
        $card  = PipelineCard::query()->where('sales_project_id', $project->id)->first() ?? PipelineCard::create([
            'sales_project_id' => $project->id, 'stage' => 'approved', 'master_client_id' => $sub->master_client_id,
            'sub_client_id' => $sub->id, 'owner_id' => $ownerId, 'amount' => $quote->grandTotal(), 'stage_changed_at' => Carbon::now(),
        ]);
        $order = Order::create([
            'order_number' => 'PED-2026-'.self::uniqueTestNumber(), 'pipeline_card_id' => $card->id, 'order_type' => Order::ORDER_TYPE_PEDIDO,
            'stage' => Order::STAGE_POR_DESPACHAR, 'customer_name' => $sub->business_name,
        ]);
        $order->items()->create(['quote_item_id' => $item->id, 'description_snapshot' => 'Producto', 'qty_requested' => 2, 'qty_picked' => 2, 'qty_delivered' => 2]);

        return ['quote' => $quote, 'card' => $card, 'order' => $order->fresh() ?? $order, 'project' => $project];
    }

    private function marcar(string $token, SubClient $sub, array $data): \Illuminate\Testing\TestResponse
    {
        return $this->api($token, 'PUT', "/api/admin-contab/clientes/{$sub->id}/exencion-itbms", $data);
    }

    // ── la cotización ─────────────────────────────────────────────────────────────────────────

    public function test_una_cotizacion_de_un_cliente_exento_no_cobra_itbms_y_una_normal_si(): void
    {
        $exenta = $this->pedidoDe($this->subCliente(exento: true))['quote'];
        $normal = $this->pedidoDe($this->subCliente())['quote'];

        $this->assertTrue($exenta->isItbmsExempt());
        $this->assertEqualsWithDelta(0.0, $exenta->itbms(), 0.001);
        $this->assertEqualsWithDelta(200.0, $exenta->grandTotal(), 0.001, 'Total = neto.');
        $this->assertEqualsWithDelta(0.0, $exenta->itbmsPercentage(), 0.001);

        $pct = ItbmsRateResolver::defaultPercentage();
        $this->assertFalse($normal->isItbmsExempt());
        $this->assertEqualsWithDelta(round(200 * $pct / 100, 2), $normal->itbms(), 0.001, 'El comportamiento de siempre no cambia.');
    }

    public function test_una_cotizacion_sin_cliente_o_la_de_un_cliente_sin_marca_nace_no_exenta(): void
    {
        $sinCliente = Quote::create(['owner_id' => $this->ownerId(), 'folio' => 'COT-2026-'.self::uniqueTestNumber(), 'generated_at' => Carbon::now()]);

        $this->assertFalse($sinCliente->fresh()->isItbmsExempt());
    }

    public function test_un_borrador_que_cambia_de_cliente_toma_la_marca_del_cliente_nuevo_y_una_confirmada_no(): void
    {
        $exento = $this->subCliente(exento: true);
        $normal = $this->subCliente();

        $borrador = $this->pedidoDe($normal)['quote'];
        $this->assertFalse($borrador->isItbmsExempt());
        $borrador->update(['sub_client_id' => $exento->id]);
        $this->assertTrue($borrador->fresh()->isItbmsExempt(), 'Al elegir un cliente exento el borrador deja de cobrar ITBMS.');
        $borrador->update(['sub_client_id' => $normal->id]);
        $this->assertFalse($borrador->fresh()->isItbmsExempt());

        $confirmada = $this->pedidoDe($normal, confirmada: true)['quote'];
        $confirmada->update(['sub_client_id' => $exento->id]);
        $this->assertFalse($confirmada->fresh()->isItbmsExempt(), 'Una cotización confirmada no cambia de marca.');
    }

    public function test_una_cotizacion_confirmada_queda_fija_aunque_despues_se_marque_o_se_quite_la_exencion_del_cliente(): void
    {
        $sub       = $this->subCliente();
        $confirmada = $this->pedidoDe($sub, confirmada: true)['quote'];
        $this->assertFalse($confirmada->isItbmsExempt());

        $token = $this->token();
        $this->marcar($token, $sub, ['exento' => true, 'documento' => 'Resolución 201-1234'])->assertStatus(200);
        $this->assertFalse($confirmada->fresh()->isItbmsExempt(), 'Marcar al cliente no mueve una cotización ya confirmada.');
        $this->assertGreaterThan(0.0, $confirmada->fresh()->itbms());

        // Y al revés: una confirmada exenta sigue exenta aunque se quite la marca.
        $sub2 = $this->subCliente(exento: true);
        $confirmada2 = $this->pedidoDe($sub2, confirmada: true)['quote'];
        $this->assertTrue($confirmada2->fresh()->isItbmsExempt());
        $this->marcar($token, $sub2, ['exento' => false])->assertStatus(200);
        $this->assertTrue($confirmada2->fresh()->isItbmsExempt());
        $this->assertEqualsWithDelta(0.0, $confirmada2->fresh()->itbms(), 0.001);
    }

    // ── marcar y quitar ───────────────────────────────────────────────────────────────────────

    public function test_marcar_exige_el_documento_que_respalda_la_exencion(): void
    {
        $sub   = $this->subCliente();
        $token = $this->token();

        $this->marcar($token, $sub, ['exento' => true])->assertStatus(422);
        $this->marcar($token, $sub, ['exento' => true, 'documento' => '   '])->assertStatus(422);
        $this->assertFalse($sub->fresh()->itbms_exento);

        $this->marcar($token, $sub, ['exento' => true, 'documento' => 'Resolución 201-1234'])->assertStatus(200)
            ->assertJson(['itbms_exento' => true, 'itbms_exencion_documento' => 'Resolución 201-1234']);
        $this->assertTrue($sub->fresh()->itbms_exento);
        $this->assertNotNull($sub->fresh()->itbms_exento_by);
    }

    public function test_solo_quien_tiene_el_permiso_angosto_puede_marcar(): void
    {
        $sub      = $this->subCliente();
        $vendedor = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');
        $this->reTenant();

        $this->marcar($vendedor, $sub, ['exento' => true, 'documento' => 'Resolución 201-1234'])->assertStatus(403);
        $this->assertFalse($sub->fresh()->itbms_exento);
    }

    public function test_marcar_y_quitar_quedan_en_auditoria_con_el_documento(): void
    {
        $sub   = $this->subCliente();
        $token = $this->token();

        $this->marcar($token, $sub, ['exento' => true, 'documento' => 'Ley 8 de 2010'])->assertStatus(200);
        $this->marcar($token, $sub, ['exento' => false])->assertStatus(200);

        $marcado = AuditLog::where('action', AuditService::ADMIN_CONT_SUB_CLIENT_ITBMS_EXENTO)->where('entity_id', $sub->id)->latest('id')->first();
        $this->assertNotNull($marcado);
        $this->assertSame('Ley 8 de 2010', $marcado->new_values['documento']);
        $this->assertFalse($marcado->old_values['itbms_exento']);

        $quitado = AuditLog::where('action', AuditService::ADMIN_CONT_SUB_CLIENT_ITBMS_EXENTO_QUITADO)->where('entity_id', $sub->id)->latest('id')->first();
        $this->assertNotNull($quitado);
        $this->assertSame('Ley 8 de 2010', $quitado->old_values['documento']);
        $this->assertNull($sub->fresh()->itbms_exencion_documento, 'Quitar la exención borra el documento vigente.');
    }

    public function test_no_se_marca_si_configuracion_fiscal_desactivo_la_tasa_exenta(): void
    {
        $sub   = $this->subCliente();
        $token = $this->token();
        AdminContItbmsRate::query()->where('es_base', true)->where('porcentaje', 0)->update(['activa' => false]);

        $r = $this->marcar($token, $sub, ['exento' => true, 'documento' => 'Resolución 201-1234'])->assertStatus(422);
        $this->assertStringContainsString('Exento', (string) $r->json('message'));
        $this->assertFalse($sub->fresh()->itbms_exento);
    }

    // ── borradores ────────────────────────────────────────────────────────────────────────────

    public function test_marcar_al_cliente_recalcula_sus_borradores_y_el_monto_de_la_tarjeta_pero_no_toca_las_confirmadas(): void
    {
        $sub      = $this->subCliente();
        $borrador = $this->pedidoDe($sub);                               // sin confirmar, la tarjeta tomó su total con ITBMS
        $confirmada = $this->pedidoDe($sub, confirmada: true);           // otra cotización, ya confirmada
        $totalConItbms = $borrador['quote']->grandTotal();
        $this->assertGreaterThan(200.0, $totalConItbms);
        $this->assertEqualsWithDelta($totalConItbms, $borrador['card']->fresh()->amount, 0.001);

        $token = $this->token();
        $r = $this->marcar($token, $sub, ['exento' => true, 'documento' => 'Resolución 201-1234'])->assertStatus(200);

        $this->assertSame(1, $r->json('borradores_actualizados'));
        $this->assertTrue($borrador['quote']->fresh()->isItbmsExempt());
        $this->assertEqualsWithDelta(200.0, $borrador['quote']->fresh()->grandTotal(), 0.001);
        $this->assertEqualsWithDelta(200.0, $borrador['card']->fresh()->amount, 0.001, 'La tarjeta que tenía el monto del borrador se recalcula.');
        $this->assertFalse($confirmada['quote']->fresh()->isItbmsExempt(), 'La confirmada no cambia.');
        $this->assertEqualsWithDelta($totalConItbms, $confirmada['card']->fresh()->amount, 0.001, 'Su tarjeta tampoco.');
    }

    public function test_no_se_pisa_una_tarjeta_cuyo_monto_es_el_de_otra_version(): void
    {
        $sub      = $this->subCliente();
        $borrador = $this->pedidoDe($sub);
        $borrador['card']->update(['amount' => 999.99]);   // la tarjeta guarda el monto de otra versión confirmada

        $this->marcar($this->token(), $sub, ['exento' => true, 'documento' => 'Resolución 201-1234'])->assertStatus(200);

        $this->assertTrue($borrador['quote']->fresh()->isItbmsExempt());
        $this->assertEqualsWithDelta(999.99, $borrador['card']->fresh()->amount, 0.001);
    }

    public function test_quitar_la_exencion_devuelve_el_itbms_a_los_borradores(): void
    {
        $sub      = $this->subCliente(exento: true);
        $borrador = $this->pedidoDe($sub);
        $this->assertEqualsWithDelta(200.0, $borrador['card']->fresh()->amount, 0.001);

        $this->marcar($this->token(), $sub, ['exento' => false])->assertStatus(200);

        $this->assertFalse($borrador['quote']->fresh()->isItbmsExempt());
        $this->assertGreaterThan(200.0, $borrador['card']->fresh()->amount);
    }

    // ── la factura ────────────────────────────────────────────────────────────────────────────

    public function test_la_factura_de_una_cotizacion_exenta_sale_a_tasa_cero_con_el_monto_neto(): void
    {
        $pedido = $this->pedidoDe($this->subCliente(exento: true), confirmada: true);
        $token = $this->token();

        $this->facturar($token, [$pedido['order']->id])->assertStatus(201);

        $factura = AdminContInvoice::query()->where('order_id', $pedido['order']->id)->with('itbmsRate', 'lines')->firstOrFail();
        $this->assertEqualsWithDelta(0.0, (float) $factura->itbmsRate->porcentaje, 0.001);
        $this->assertEqualsWithDelta(200.0, (float) $factura->monto, 0.001, 'Monto = neto: sin ITBMS.');
        $this->assertNotEmpty($factura->lines);
        foreach ($factura->lines as $linea) {
            $this->assertEqualsWithDelta(0.0, (float) $linea->itbms, 0.001);
            $this->assertEqualsWithDelta(0.0, (float) $linea->itbmsRate->porcentaje, 0.001);
        }

        $detalle = app(InvoiceService::class)->detail($pedido['order']->id);
        $this->assertTrue($detalle['itbms_exento']);
        $this->assertEqualsWithDelta(0.0, (float) $detalle['itbms'], 0.001);
    }

    public function test_la_factura_de_un_cliente_normal_sigue_cobrando_el_itbms_de_siempre(): void
    {
        $pedido = $this->pedidoDe($this->subCliente(), confirmada: true);
        $token  = $this->token();

        $this->facturar($token, [$pedido['order']->id])->assertStatus(201);

        $factura = AdminContInvoice::query()->where('order_id', $pedido['order']->id)->with('itbmsRate')->firstOrFail();
        $pct     = ItbmsRateResolver::defaultPercentage();
        $this->assertEqualsWithDelta($pct, (float) $factura->itbmsRate->porcentaje, 0.001);
        $this->assertEqualsWithDelta(round(200 * (1 + $pct / 100), 2), (float) $factura->monto, 0.001);
        $this->assertFalse(app(InvoiceService::class)->detail($pedido['order']->id)['itbms_exento']);
    }

    public function test_una_factura_ya_emitida_no_cambia_si_despues_se_marca_al_cliente(): void
    {
        $sub    = $this->subCliente();
        $pedido = $this->pedidoDe($sub, confirmada: true);
        $token  = $this->token();
        $this->facturar($token, [$pedido['order']->id])->assertStatus(201);
        $montoEmitido = (float) AdminContInvoice::query()->where('order_id', $pedido['order']->id)->value('monto');

        $this->marcar($token, $sub, ['exento' => true, 'documento' => 'Resolución 201-1234'])->assertStatus(200);

        $factura = AdminContInvoice::query()->where('order_id', $pedido['order']->id)->with('itbmsRate')->firstOrFail();
        $this->assertEqualsWithDelta($montoEmitido, (float) $factura->monto, 0.001);
        $this->assertGreaterThan(0.0, (float) $factura->itbmsRate->porcentaje);
    }

    // ── lectura ───────────────────────────────────────────────────────────────────────────────

    public function test_el_directorio_fiscal_expone_la_marca_y_el_documento(): void
    {
        $sub = $this->subCliente(exento: true);
        $r   = $this->api($this->token(), 'GET', '/api/ventas-diseno/clients/fiscal-directory?search='.urlencode($sub->business_name));

        $r->assertStatus(200);
        $fila = collect($r->json('data'))->firstWhere('id', $sub->id);
        $this->assertTrue($fila['itbms_exento']);
        $this->assertSame('Resolución 201-1234', $fila['itbms_exencion_documento']);
    }

    // ── avisos del árbitro de facturación electrónica ─────────────────────────────────────────

    public function test_al_marcar_se_avisa_cuantas_cotizaciones_confirmadas_sin_facturar_conservan_su_itbms(): void
    {
        $sub        = $this->subCliente();
        $sinFacturar = $this->pedidoDe($sub, confirmada: true);
        $facturada   = $this->pedidoDe($sub, confirmada: true);
        $token       = $this->token();
        $this->facturar($token, [$facturada['order']->id])->assertStatus(201);

        $r = $this->marcar($token, $sub, ['exento' => true, 'documento' => 'Resolución 201-1234'])->assertStatus(200);

        $this->assertSame(1, $r->json('confirmadas_sin_facturar'), 'Solo la confirmada que aún no tiene factura vigente.');
        $this->assertFalse($sinFacturar['quote']->fresh()->isItbmsExempt());
    }

    public function test_sin_cotizaciones_confirmadas_el_aviso_es_cero(): void
    {
        $sub = $this->subCliente();
        $this->pedidoDe($sub);   // solo un borrador

        $r = $this->marcar($this->token(), $sub, ['exento' => true, 'documento' => 'Resolución 201-1234'])->assertStatus(200);

        $this->assertSame(0, $r->json('confirmadas_sin_facturar'));
    }

    public function test_la_tasa_base_exenta_no_se_desactiva_mientras_haya_clientes_exentos(): void
    {
        $tasa  = AdminContItbmsRate::query()->where('es_base', true)->where('porcentaje', 0)->firstOrFail();
        $sub   = $this->subCliente(exento: true);
        $token = $this->loginAsMark();

        $r = $this->api($token, 'PUT', "/api/admin-contab/itbms-rates/{$tasa->id}", ['activa' => false])->assertStatus(422);
        $this->assertStringContainsString('exento', (string) $r->json('message'));
        $this->assertTrue($tasa->fresh()->activa);

        // Sin clientes exentos sí se puede desactivar (y volver a activar).
        $sub->forceFill(['itbms_exento' => false])->save();
        $this->api($token, 'PUT', "/api/admin-contab/itbms-rates/{$tasa->id}", ['activa' => false])->assertStatus(200);
        $this->assertFalse($tasa->fresh()->activa);
    }

    public function test_las_otras_tasas_base_se_desactivan_aunque_haya_clientes_exentos(): void
    {
        $general = AdminContItbmsRate::query()->where('es_base', true)->where('porcentaje', '>', 0)->orderByDesc('porcentaje')->firstOrFail();
        $this->subCliente(exento: true);

        $this->api($this->loginAsMark(), 'PUT', "/api/admin-contab/itbms-rates/{$general->id}", ['activa' => false])->assertStatus(200);
    }

    public function test_cambiar_el_ruc_o_el_tipo_de_un_cliente_exento_avisa_que_hay_que_revisar_la_exencion_y_queda_en_auditoria(): void
    {
        $sub   = $this->subCliente(exento: true);
        $token = $this->token();
        $ruta  = "/api/ventas-diseno/clients/sub-clients/{$sub->id}/tax-data";
        $base  = ['business_name' => $sub->business_name, 'receptor_tipo' => '01', 'tax_id' => $sub->tax_id, 'tax_id_dv' => '81'];

        // Cambia el RUC: la exención se conserva pero se avisa y queda registrada para revisar.
        $r = $this->api($token, 'PATCH', $ruta, ['tax_id' => '155-9999-1-2026'] + $base)->assertStatus(200);
        $this->assertTrue($r->json('exencion_a_revisar'));
        $this->assertTrue($sub->fresh()->itbms_exento, 'La exención NO se quita sola.');
        $evento = AuditLog::where('action', AuditService::ADMIN_CONT_SUB_CLIENT_ITBMS_EXENTO_REVISAR)->where('entity_id', $sub->id)->latest('id')->first();
        $this->assertNotNull($evento);
        $this->assertSame('155-9999-1-2026', $evento->new_values['tax_id']);
        $this->assertSame('Resolución 201-1234', $evento->metadata['documento']);

        // Cambia solo la razón social: nada que revisar.
        $r = $this->api($token, 'PATCH', $ruta, ['business_name' => 'Otra Razón Social S.A.', 'tax_id' => '155-9999-1-2026', 'tax_id_dv' => '81', 'receptor_tipo' => '01'])->assertStatus(200);
        $this->assertFalse($r->json('exencion_a_revisar'));
    }

    public function test_cambiar_el_ruc_de_un_cliente_que_no_es_exento_no_avisa_nada(): void
    {
        $sub = $this->subCliente();
        $r   = $this->api($this->token(), 'PATCH', "/api/ventas-diseno/clients/sub-clients/{$sub->id}/tax-data", [
            'business_name' => $sub->business_name, 'receptor_tipo' => '01', 'tax_id' => '155-9999-1-2026', 'tax_id_dv' => '81',
        ])->assertStatus(200);

        $this->assertFalse($r->json('exencion_a_revisar'));
        $this->assertSame(0, AuditLog::where('action', AuditService::ADMIN_CONT_SUB_CLIENT_ITBMS_EXENTO_REVISAR)->where('entity_id', $sub->id)->count());
    }

    public function test_la_ficha_del_cliente_y_el_detalle_de_la_cotizacion_exponen_si_el_cliente_es_exento(): void
    {
        $sub   = $this->subCliente(exento: true);
        $token = $this->token();

        $ficha = $this->api($token, 'GET', "/api/ventas-diseno/clients/{$sub->master_client_id}")->assertStatus(200);
        $datos = $ficha->json('data') ?? $ficha->json();
        $fila  = collect($datos['sub_clients'])->firstWhere('id', $sub->id);
        $this->assertTrue($fila['itbms_exento']);
        $this->assertSame('Resolución 201-1234', $fila['itbms_exencion_documento']);

        // Cotización ya confirmada con la marca anterior: el detalle dice las dos cosas por separado.
        $confirmada = $this->pedidoDe($this->subCliente(), confirmada: true)['quote'];
        $confirmada->sub_client_id = $sub->id;
        $confirmada->saveQuietly();
        $detalle = $this->api($token, 'GET', "/api/ventas-diseno/quotes/{$confirmada->id}")->assertStatus(200);
        $q = $detalle->json('data') ?? $detalle->json();
        $this->assertFalse($q['itbms_exento'], 'La cotización conserva su marca.');
        $this->assertTrue($q['sub_client']['itbms_exento'], 'El cliente hoy sí es exento.');
    }

    public function test_quien_solo_marca_exentos_lee_el_directorio_fiscal_y_puede_marcar(): void
    {
        $asistente = $this->loginAs('asistenteadministrativa@test.com', 'asistenteadministrativa@test.com', 'asistente_administrativa');
        $this->reTenant();
        $this->api($asistente, 'GET', '/api/ventas-diseno/clients/fiscal-directory')->assertStatus(200);

        // Asistente Administrativa tiene el permiso angosto y puede marcar.
        $sub = $this->subCliente();
        $this->marcar($asistente, $sub, ['exento' => true, 'documento' => 'Resolución 201-1234'])->assertStatus(200);
    }

    public function test_el_detalle_y_el_pdf_de_la_cotizacion_dicen_exento_y_una_normal_no(): void
    {
        $token  = $this->token();
        $exenta = $this->pedidoDe($this->subCliente(exento: true))['quote'];
        $normal = $this->pedidoDe($this->subCliente())['quote'];

        $detalle = $this->api($token, 'GET', "/api/ventas-diseno/quotes/{$exenta->id}")->assertStatus(200);
        $datos   = $detalle->json('data') ?? $detalle->json();
        $this->assertTrue($datos['itbms_exento']);
        $this->assertEqualsWithDelta(0.0, $datos['itbms_percentage'], 0.001);
        $this->assertEqualsWithDelta(0.0, $datos['itbms'], 0.001);
        $html = view('ventas-diseno.quote-pdf', app(\App\Modules\VentasDiseno\Services\QuotePdfService::class)->viewData($datos, false))->render();
        $this->assertStringContainsString('ITBMS (Exento)', $html);

        $detalleN = $this->api($token, 'GET', "/api/ventas-diseno/quotes/{$normal->id}")->assertStatus(200);
        $datosN   = $detalleN->json('data') ?? $detalleN->json();
        $this->assertFalse($datosN['itbms_exento']);
        $this->assertEqualsWithDelta(ItbmsRateResolver::defaultPercentage(), $datosN['itbms_percentage'], 0.001);
        $htmlN = view('ventas-diseno.quote-pdf', app(\App\Modules\VentasDiseno\Services\QuotePdfService::class)->viewData($datosN, false))->render();
        $this->assertStringNotContainsString('ITBMS (Exento)', $htmlN);
    }
}
