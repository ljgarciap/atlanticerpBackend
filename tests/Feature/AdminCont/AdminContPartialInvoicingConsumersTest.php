<?php

declare(strict_types=1);

namespace Tests\Feature\AdminCont;

use App\Modules\AdminCont\Models\AdminContAbono;
use App\Modules\AdminCont\Models\AdminContBankAccount;
use App\Modules\AdminCont\Models\AdminContCreditNote;
use App\Modules\AdminCont\Models\AdminContInvoice;
use App\Modules\AdminCont\Models\AdminContItbmsRate;
use App\Modules\AdminCont\Services\ArchitectCommissionService;
use App\Modules\AdminCont\Services\CommissionOrderService;
use App\Modules\AdminCont\Services\InvoiceService;
use App\Modules\Bodega\Models\CustomerReturn;
use App\Modules\Bodega\Models\Order;
use App\Modules\Bodega\Services\OrderBoardService;
use App\Modules\Gerencia\Services\GerenciaHomeService;
use App\Modules\VentasDiseno\Models\Architect;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Feature\AdminCont\Concerns\BuildsPartiallyInvoicedOrders;
use Tests\Feature\AdminCont\Concerns\StubsReceptorGuard;
use Tests\Feature\Bodega\Concerns\BodegaOrderTestHelpers;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-929 (B3a) — los consumidores que CORROMPÍAN datos cuando un pedido tiene varias facturas activas
 * (parcial por entrega + completo, incluso DOS con el mismo `order_id` en la raíz): Bodega
 * (`invoice_ready`), incobrable, notas de crédito (devoluciones, tratamiento ITBMS, listado por cliente),
 * Corrección de datos (la factura de REEMPLAZO conserva `tipo`/`order_id`/líneas), Estado de Cuenta
 * (guía por entrega) y el panel de Gerencia.
 *
 * Todos comparten UNA regla para "la factura de una entrega" (`InvoiceService::pickInvoiceForDelivery()`):
 * la parcial vigente anclada a esa entrega; si no, el completo vigente de la familia; si no, ninguna.
 */
class AdminContPartialInvoicingConsumersTest extends FeatureTestCase
{
    use BodegaOrderTestHelpers;
    use BuildsPartiallyInvoicedOrders;
    use StubsReceptorGuard;

    private const MARK_EMAIL = 'gerencia3@test.com';

    protected function setUp(): void
    {
        parent::setUp();
        $this->reTenant();
        $this->stubReceptorGuard();
    }

    /** Pedido de 3 entregas de 3 unidades (9 × 100.00 + ITBMS = 963.00), todas en Por Despachar. */
    private function familia(): array
    {
        return $this->makePedido([['desc' => 'Panel LED', 'qty' => 9, 'price' => 100.00]], [[3], [3], [3]]);
    }

    private function bodegaHeaders(): array
    {
        $this->grantBodegaPermissions('liderbodega@test.com', ['bodega.read']);
        $token = $this->loginAs('liderbodega@test.com', 'liderbodega@test.com', 'lider_bodega');
        $this->reTenant();

        return $this->authHeaders($token);
    }

    /** @return array<string, mixed> */
    private function facturaDeBodega(array $headers, Order $entrega): array
    {
        $r = $this->withHeaders($headers)->getJson("/api/bodega/orders/{$entrega->id}/invoice")->assertStatus(200)->json();
        $this->reTenant();

        return $r;
    }

    private function parcialDe(Order $root, Order $entrega): AdminContInvoice
    {
        return AdminContInvoice::forOrder($root)->whereNull('anulada_at')
            ->where('order_id', $entrega->id)->where('tipo', AdminContInvoice::TIPO_PARCIAL)->firstOrFail();
    }

    private function completoDe(Order $root): AdminContInvoice
    {
        return AdminContInvoice::forOrder($root)->whereNull('anulada_at')->where('tipo', AdminContInvoice::TIPO_COMPLETO)->firstOrFail();
    }

    // ---------------------------------------------------------------------------------------------
    // 1. Bodega — `invoice_ready` / `invoice_numero` por ENTREGA
    // ---------------------------------------------------------------------------------------------

    public function test_b3a_una_entrega_sin_factura_no_muestra_la_factura_de_otra_y_una_cubierta_por_el_completo_muestra_el_completo(): void
    {
        ['root' => $root, 'entregas' => $entregas] = $this->familia();
        $token   = $this->token();
        $headers = $this->bodegaHeaders();

        $this->facturar($token, [$entregas[1]->id], 'entrega')->assertStatus(201);
        $parcial = $this->parcialDe($root, $entregas[1]);

        $this->assertFalse($this->facturaDeBodega($headers, $entregas[0])['invoice_ready'], 'E1 no tiene factura.');
        $sinFactura = $this->facturaDeBodega($headers, $entregas[0]);
        $this->assertNull($sinFactura['invoice_numero'], 'E1 no debe mostrar el número de la factura de E2.');
        $e2 = $this->facturaDeBodega($headers, $entregas[1]);
        $this->assertTrue($e2['invoice_ready']);
        $this->assertSame($parcial->numero, $e2['invoice_numero']);
        $this->assertFalse($this->facturaDeBodega($headers, $entregas[2])['invoice_ready'], 'E3 no tiene factura.');

        // El completo cubre el resto: E1 y E3 pasan a mostrarlo; E2 conserva SU parcial.
        $this->facturar($token, [$root->id], 'completo')->assertStatus(201);
        $completo = $this->completoDe($root);
        $this->assertSame($completo->numero, $this->facturaDeBodega($headers, $entregas[0])['invoice_numero']);
        $this->assertSame($completo->numero, $this->facturaDeBodega($headers, $entregas[2])['invoice_numero']);
        $this->assertSame($parcial->numero, $this->facturaDeBodega($headers, $entregas[1])['invoice_numero']);
    }

    public function test_b3a_la_raiz_con_su_parcial_y_el_completo_muestra_la_parcial_y_las_demas_el_completo(): void
    {
        ['root' => $root, 'entregas' => $entregas] = $this->familia();
        $token   = $this->token();
        $headers = $this->bodegaHeaders();
        $this->facturar($token, [$root->id], 'entrega')->assertStatus(201);
        $this->facturar($token, [$root->id], 'completo')->assertStatus(201);
        $parcial  = $this->parcialDe($root, $root);
        $completo = $this->completoDe($root);

        $this->assertSame($parcial->numero, $this->facturaDeBodega($headers, $root)['invoice_numero'], 'Regla central: la parcial de SU entrega primero.');
        $this->assertSame($completo->numero, $this->facturaDeBodega($headers, $entregas[1])['invoice_numero']);
    }

    public function test_b3a_el_tablero_por_lote_y_el_detalle_de_una_tarjeta_dan_lo_mismo(): void
    {
        ['root' => $root, 'entregas' => $entregas] = $this->familia();
        $token   = $this->token();
        $headers = $this->bodegaHeaders();
        $this->facturar($token, [$entregas[1]->id], 'entrega')->assertStatus(201);

        $cards = collect($this->withHeaders($headers)->getJson('/api/bodega/orders')->assertStatus(200)->json('cards'))->keyBy('id');
        $this->reTenant();
        $board = app(OrderBoardService::class);

        foreach ($entregas as $i => $entrega) {
            $esperado = $i === 1;
            $this->assertSame($esperado, $cards[$entrega->id]['invoice_ready'], "Tablero (lote) — entrega {$entrega->id}");
            $this->assertSame($esperado, $board->detail($entrega)['invoice_ready'], "Detalle (una tarjeta) — entrega {$entrega->id}");
        }

        // Con un completo vigente, ambos caminos marcan TODA la familia.
        $this->facturar($token, [$root->id], 'completo')->assertStatus(201);
        $cards = collect($this->withHeaders($headers)->getJson('/api/bodega/orders')->json('cards'))->keyBy('id');
        $this->reTenant();
        foreach ($entregas as $entrega) {
            $this->assertTrue($cards[$entrega->id]['invoice_ready']);
            $this->assertTrue($board->detail($entrega)['invoice_ready']);
        }
    }

    // ---------------------------------------------------------------------------------------------
    // 2. Incobrable — por FACTURA
    // ---------------------------------------------------------------------------------------------

    public function test_b3a_marcar_incobrable_con_varias_facturas_activas_exige_invoice_id(): void
    {
        ['root' => $root, 'entregas' => $entregas] = $this->familia();
        $token = $this->token();
        $this->facturar($token, [$entregas[1]->id], 'entrega')->assertStatus(201);
        $this->facturar($token, [$root->id], 'completo')->assertStatus(201);
        $parcial  = $this->parcialDe($root, $entregas[1]);
        $completo = $this->completoDe($root);

        $this->api($token, 'POST', "/api/admin-contab/invoices/{$root->id}/mark-uncollectible", ['motivo' => 'x'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Este pedido tiene varias facturas activas: indique sobre cuál operar con invoice_id.');
        $this->assertFalse((bool) $parcial->fresh()->incobrable_pendiente);
        $this->assertFalse((bool) $completo->fresh()->incobrable_pendiente);

        $this->api($token, 'POST', "/api/admin-contab/invoices/{$root->id}/mark-uncollectible", ['motivo' => 'Cliente no paga', 'invoice_id' => $parcial->id])
            ->assertStatus(200);
        $this->assertTrue((bool) $parcial->fresh()->incobrable_pendiente, 'Solo la factura elegida.');
        $this->assertFalse((bool) $completo->fresh()->incobrable_pendiente, 'El completo no se toca.');
    }

    public function test_b3a_marcar_incobrable_con_un_invoice_id_de_otro_pedido_devuelve_404_y_uno_invalido_422(): void
    {
        ['root' => $root, 'entregas' => $entregas] = $this->familia();
        ['root' => $otra] = $this->makePedido([['desc' => 'Panel', 'qty' => 2, 'price' => 10.00]], [[2]]);
        $token = $this->token();
        $this->facturar($token, [$entregas[1]->id], 'entrega')->assertStatus(201);
        $this->facturar($token, [$otra->id])->assertStatus(201);
        $ajena = AdminContInvoice::forOrder($otra)->firstOrFail();

        $this->api($token, 'POST', "/api/admin-contab/invoices/{$root->id}/mark-uncollectible", ['motivo' => 'x', 'invoice_id' => $ajena->id])->assertStatus(404);
        $this->assertFalse((bool) $ajena->fresh()->incobrable_pendiente);
        $this->api($token, 'POST', "/api/admin-contab/invoices/{$root->id}/mark-uncollectible", ['motivo' => 'x', 'invoice_id' => 0])->assertStatus(422);
    }

    public function test_b3a_con_una_sola_factura_activa_no_hace_falta_invoice_id_compatibilidad_con_el_frontend_actual(): void
    {
        ['root' => $root, 'entregas' => $entregas] = $this->familia();
        $token = $this->token();
        $this->facturar($token, [$entregas[1]->id], 'entrega')->assertStatus(201);
        $parcial = $this->parcialDe($root, $entregas[1]);

        $this->api($token, 'POST', "/api/admin-contab/invoices/{$root->id}/mark-uncollectible", ['motivo' => 'x'])->assertStatus(200);

        $this->assertTrue((bool) $parcial->fresh()->incobrable_pendiente);
    }

    public function test_b3a_decidir_incobrable_opera_sobre_la_factura_elegida(): void
    {
        ['root' => $root, 'entregas' => $entregas] = $this->familia();
        $felix = $this->token();
        $this->facturar($felix, [$entregas[1]->id], 'entrega')->assertStatus(201);
        $this->facturar($felix, [$root->id], 'completo')->assertStatus(201);
        $parcial  = $this->parcialDe($root, $entregas[1]);
        $completo = $this->completoDe($root);
        $this->api($felix, 'POST', "/api/admin-contab/invoices/{$root->id}/mark-uncollectible", ['motivo' => 'x', 'invoice_id' => $parcial->id])->assertStatus(200);
        $mark = $this->loginAs(self::MARK_EMAIL, self::MARK_EMAIL, 'management');
        $this->reTenant();

        $this->api($mark, 'PUT', "/api/admin-contab/invoices/{$root->id}/uncollectible-decision", ['approve' => true])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Este pedido tiene varias facturas activas: indique sobre cuál operar con invoice_id.');

        $this->api($mark, 'PUT', "/api/admin-contab/invoices/{$root->id}/uncollectible-decision", ['approve' => true, 'invoice_id' => $parcial->id])->assertStatus(200);
        $this->assertTrue((bool) $parcial->fresh()->es_incobrable);
        $this->assertFalse((bool) $completo->fresh()->es_incobrable);
    }

    // ---------------------------------------------------------------------------------------------
    // 3. Notas de crédito — devoluciones, tratamiento ITBMS y listado por cliente
    // ---------------------------------------------------------------------------------------------

    public function test_b3a_el_tratamiento_itbms_y_la_devolucion_apuntan_a_la_factura_de_su_entrega(): void
    {
        ['root' => $root, 'entregas' => $entregas] = $this->familia();
        $token = $this->token();
        $this->facturar($token, [$entregas[1]->id], 'entrega')->assertStatus(201);
        $this->facturar($token, [$root->id], 'completo')->assertStatus(201);
        $parcial  = $this->parcialDe($root, $entregas[1]);
        $completo = $this->completoDe($root);
        // La parcial se emitió con otra tasa que el completo: así se distingue cuál factura devuelve el consumidor.
        $otraTasa = AdminContItbmsRate::whereKeyNot($completo->itbms_rate_id)->firstOrFail();
        AdminContInvoice::whereKey($parcial->id)->update(['itbms_rate_id' => $otraTasa->id]);

        $this->api($token, 'GET', "/api/admin-contab/notas-credito/facturas/{$entregas[1]->id}/itbms-treatment")
            ->assertStatus(200)->assertJsonPath('tratamiento_actual.id', $otraTasa->id);
        $this->api($token, 'GET', "/api/admin-contab/notas-credito/facturas/{$entregas[2]->id}/itbms-treatment")
            ->assertStatus(200)->assertJsonPath('tratamiento_actual.id', $completo->itbms_rate_id);

        // Devolución de la entrega 2 → su parcial; de la entrega 3 → el completo que la cubre.
        $devE2 = $this->devolucion($entregas[1]);
        $devE3 = $this->devolucion($entregas[2]);
        $this->api($token, 'GET', "/api/admin-contab/notas-credito/devoluciones/{$devE2->id}")->assertStatus(200)->assertJsonPath('factura_origen_id', $parcial->id);
        $this->api($token, 'GET', "/api/admin-contab/notas-credito/devoluciones/{$devE3->id}")->assertStatus(200)->assertJsonPath('factura_origen_id', $completo->id);

        // La cola de devoluciones por generar (listado en lote) resuelve igual: nada de `keyBy('order_id')`.
        $filas = collect($this->api($token, 'GET', '/api/admin-contab/notas-credito')->assertStatus(200)->json('data'))
            ->where('tipo', 'pendiente_generar_nota')->keyBy('customer_return_id');
        $this->assertSame($parcial->id, $filas[$devE2->id]['factura_origen_id']);
        $this->assertSame($completo->id, $filas[$devE3->id]['factura_origen_id']);
    }

    private function devolucion(Order $entrega): CustomerReturn
    {
        return CustomerReturn::create([
            'return_number' => 'DEV-2026-'.self::uniqueTestNumber(), 'order_id' => $entrega->id,
            'status' => CustomerReturn::STATUS_ESPERANDO_NOTA_CREDITO, 'created_by' => $this->ownerId(),
            'contact_name' => 'Ana Pérez', 'contact_phone' => '6000-1234',
        ]);
    }

    public function test_b3a_el_listado_de_facturas_del_cliente_muestra_cada_factura_del_pedido(): void
    {
        ['root' => $root, 'entregas' => $entregas, 'card' => $card] = $this->familia();
        $token = $this->token();
        $this->facturar($token, [$entregas[1]->id], 'entrega')->assertStatus(201);
        $this->facturar($token, [$root->id], 'completo')->assertStatus(201);

        $facturas = collect($this->api($token, 'GET', "/api/admin-contab/notas-credito/clientes/{$card->master_client_id}/facturas")->assertStatus(200)->json('data'));

        $this->assertCount(2, $facturas, 'Parcial y completo son dos filas propias.');
        $this->assertEqualsCanonicalizing(['parcial', 'completo'], $facturas->pluck('tipo_factura')->all());
        $this->assertEqualsWithDelta((float) $card->amount, (float) $facturas->sum('monto'), 0.001);
    }

    // ---------------------------------------------------------------------------------------------
    // 4. Corrección de datos — el reemplazo conserva tipo / order_id / líneas
    // ---------------------------------------------------------------------------------------------

    private function corregir(string $token, AdminContInvoice $factura, string $motivoCorreccion, array $extra = []): TestResponse
    {
        Storage::fake('s3-private');
        $payload = [
            'master_client_id'  => $factura->master_client_id,
            'factura_origen_id' => $factura->id,
            'motivo_correccion' => $motivoCorreccion,
            'motivo'            => 'Corrección de prueba B3a',
            'comprobante'       => UploadedFile::fake()->create('correccion.pdf', 100, 'application/pdf'),
        ] + $extra;
        $response = $this->withHeaders($this->authHeaders($token))->post('/api/admin-contab/notas-credito/correccion', $payload);
        $this->reTenant();

        return $response;
    }

    private function reemplazoDe(AdminContInvoice $original): AdminContInvoice
    {
        $nota = AdminContCreditNote::where('factura_origen_id', $original->id)->firstOrFail();

        return AdminContInvoice::findOrFail($nota->factura_nueva_id);
    }

    private function corregirPorFecha(string $token, AdminContInvoice $factura): AdminContInvoice
    {
        $this->corregir($token, $factura, AdminContCreditNote::MOTIVO_CORRECCION_FECHA, ['nueva_fecha' => now()->toDateString()])->assertStatus(201);

        return $this->reemplazoDe($factura);
    }

    public function test_b3a_corregir_la_parcial_de_una_entrega_hija_conserva_tipo_order_id_lineas_y_el_total_del_pedido(): void
    {
        ['root' => $root, 'entregas' => $entregas, 'card' => $card] = $this->familia();
        $token = $this->token();
        $this->facturar($token, [$entregas[1]->id], 'entrega')->assertStatus(201);
        $original = $this->parcialDe($root, $entregas[1]);
        $this->assertSame(round((float) $card->amount, 2) - 642.0, $this->sumaActivas($root));

        $reemplazo = $this->corregirPorFecha($token, $original);

        $this->assertNotNull($original->fresh()->anulada_at, 'La original queda anulada.');
        $this->assertSame(AdminContInvoice::TIPO_PARCIAL, $reemplazo->tipo, 'El reemplazo de una parcial sigue siendo parcial.');
        $this->assertSame($entregas[1]->id, $reemplazo->order_id, 'Sigue colgando de SU entrega.');
        $this->assertSame($root->id, $reemplazo->family_root_id);
        $this->assertSame($original->monto, $reemplazo->monto);
        $this->assertCount(1, $reemplazo->lines, 'Conserva sus líneas.');
        $this->assertSame(round((float) $original->lines()->sum('total'), 2), round((float) $reemplazo->lines()->sum('total'), 2));
        $this->assertSame($original->lines->first()->quote_item_id, $reemplazo->lines->first()->quote_item_id);

        // El pedido sigue facturable: las otras dos entregas cierran el total exacto, sin advertencias.
        $this->facturar($token, [$entregas[0]->id], 'entrega')->assertStatus(201);
        $cierre = $this->facturar($token, [$entregas[2]->id], 'entrega')->assertStatus(201);
        $this->assertSame([], $cierre->json('invoices.0.advertencias'));
        $this->assertSame(round((float) $card->amount, 2), $this->sumaActivas($root));
    }

    public function test_1060_detalle_de_nota_de_correccion_expone_el_id_de_la_factura_de_reemplazo_distinto_del_de_origen(): void
    {
        ['root' => $root, 'entregas' => $entregas] = $this->familia();
        $token = $this->token();
        $this->facturar($token, [$entregas[1]->id], 'entrega')->assertStatus(201);
        $original = $this->parcialDe($root, $entregas[1]);
        $reemplazo = $this->corregirPorFecha($token, $original);
        $nota = AdminContCreditNote::where('factura_origen_id', $original->id)->firstOrFail();

        $this->assertSame($original->order_id, $reemplazo->order_id, 'Premisa: comparten order_id, por eso no alcanza con él.');
        $this->api($token, 'GET', "/api/admin-contab/notas-credito/{$nota->id}")
            ->assertStatus(200)
            ->assertJsonPath('factura_origen_id', $original->id)
            ->assertJsonPath('factura_nueva_id', $reemplazo->id)
            ->assertJsonPath('factura_nueva_numero', $reemplazo->numero)
            ->assertJsonPath('factura_nueva_order_id', $reemplazo->order_id);

        // Lo que hace el modal con esos ids: el detalle de Facturación con `invoice_id` devuelve CADA
        // factura por separado aunque compartan order_id (sin él, no se puede garantizar cuál).
        $this->api($token, 'GET', "/api/admin-contab/invoices/{$reemplazo->order_id}/detail?invoice_id={$reemplazo->id}")
            ->assertStatus(200)->assertJsonPath('invoice_id', $reemplazo->id);
        $this->api($token, 'GET', "/api/admin-contab/invoices/{$original->order_id}/detail?invoice_id={$original->id}")
            ->assertStatus(200)->assertJsonPath('invoice_id', $original->id);
    }

    public function test_b3a_corregir_el_completo_conserva_tipo_lineas_y_el_total_del_pedido(): void
    {
        ['root' => $root, 'card' => $card] = $this->familia();
        $token = $this->token();
        $this->facturar($token, [$root->id], 'completo')->assertStatus(201);
        $original = $this->completoDe($root);

        $reemplazo = $this->corregirPorFecha($token, $original);

        $this->assertSame(AdminContInvoice::TIPO_COMPLETO, $reemplazo->tipo);
        $this->assertSame($root->id, $reemplazo->order_id);
        $this->assertNotEmpty($reemplazo->lines, 'El completo con líneas se reemplaza con sus líneas.');
        $this->assertSame(round((float) $reemplazo->lines()->sum('total'), 2), $reemplazo->monto);
        $this->assertSame(round((float) $card->amount, 2), $this->sumaActivas($root));
        // Con el completo vigente el pedido sigue sin admitir otra factura (el reemplazo ES el completo).
        $this->facturar($token, [$root->id], 'completo')->assertStatus(422);
    }

    /** El caso que hoy daba 500: la raíz con su parcial Y el completo (dos activas con el mismo `order_id`). */
    public function test_b3a_corregir_la_parcial_de_la_raiz_cuando_ya_existe_el_completo_no_rompe_por_el_indice(): void
    {
        ['root' => $root, 'card' => $card] = $this->familia();
        $token = $this->token();
        $this->facturar($token, [$root->id], 'entrega')->assertStatus(201);
        $this->facturar($token, [$root->id], 'completo')->assertStatus(201);
        $parcialOriginal = $this->parcialDe($root, $root);
        $completo        = $this->completoDe($root);

        $reemplazo = $this->corregirPorFecha($token, $parcialOriginal);

        $this->assertSame(AdminContInvoice::TIPO_PARCIAL, $reemplazo->tipo, 'Sigue siendo la parcial (con el tipo del completo habría chocado con el índice).');
        $this->assertSame($root->id, $reemplazo->order_id);
        $activas = AdminContInvoice::forOrder($root)->whereNull('anulada_at')->get();
        $this->assertEqualsCanonicalizing([$reemplazo->id, $completo->id], $activas->pluck('id')->all(), 'La parcial corregida y el completo conviven.');
        $this->assertSame(round((float) $card->amount, 2), $this->sumaActivas($root));
    }

    /**
     * SCRUM-929 (B3b Punto 4, decisión de Luis 2026-09-19): corregir la TASA de ITBMS de una `parcial`
     * ya NO se rechaza — el pedido "recalcula el total automáticamente" vía `ajuste_itbms_delta`
     * (ver migración `add_ajuste_itbms_delta_...` y `InvoiceBillingPlanner`). Familia de 963 (3 × 321
     * @ 7%): se corrige la entrega 2 a 3.5% (300 de base × 3.5% = 10.50 de ITBMS → 310.50, delta
     * −10.50 contra los 321 originales). La entrega que CIERRA el pedido (la 3, facturada al final)
     * debe cobrar exactamente lo que le corresponde a 7% (321.00) — NO 331.50 (que sería recobrar en
     * silencio la diferencia que la corrección acababa de quitar) — y la Σ final del pedido debe ser
     * 952.50 (963 − 10.50), no 963.
     */
    public function test_b3b_corregir_la_tasa_de_itbms_de_una_parcial_ajusta_el_total_del_pedido_y_el_cierre_no_recobra_en_silencio(): void
    {
        ['root' => $root, 'entregas' => $entregas, 'card' => $card] = $this->familia();
        $token = $this->token();
        $this->facturar($token, [$entregas[1]->id], 'entrega')->assertStatus(201);
        $original = $this->parcialDe($root, $entregas[1]);
        $this->assertSame(0.0, $original->ajuste_itbms_delta);
        $tasa = AdminContItbmsRate::where('nombre', '3.5% — Retención de impuesto')->firstOrFail();

        $payload = [
            'master_client_id' => $original->master_client_id, 'factura_origen_id' => $original->id,
            'motivo_correccion' => AdminContCreditNote::MOTIVO_CORRECCION_ITBMS, 'motivo' => 'ITBMS mal aplicado',
            'nuevo_tratamiento_itbms_rate_id' => $tasa->id, 'nueva_fecha' => now()->toDateString(),
        ];
        $preview = $this->api($token, 'POST', '/api/admin-contab/notas-credito/correccion/preview', $payload)->assertStatus(200);
        $this->assertEqualsWithDelta(310.50, (float) $preview->json('monto'), 0.001);

        $this->corregir($token, $original, AdminContCreditNote::MOTIVO_CORRECCION_ITBMS, ['nuevo_tratamiento_itbms_rate_id' => $tasa->id, 'nueva_fecha' => now()->toDateString()])
            ->assertStatus(201);
        $reemplazo = $this->reemplazoDe($original);

        $this->assertSame(AdminContInvoice::TIPO_PARCIAL, $reemplazo->tipo, 'Sigue siendo la parcial de SU entrega.');
        $this->assertSame($entregas[1]->id, $reemplazo->order_id);
        $this->assertSame(310.50, $reemplazo->monto);
        $this->assertSame(-10.50, $reemplazo->ajuste_itbms_delta, 'Delta = nuevo monto (310.50) − original (321).');
        $this->assertSame(310.50, $this->sumaActivas($root), 'Σ activas ya refleja la corrección: no queda "flotando" nada por cobrar.');

        // Se factura E1 (normal, no cierra) y luego E3 (SÍ cierra: quedan todas las cantidades facturadas).
        $this->facturar($token, [$entregas[0]->id], 'entrega')->assertStatus(201);
        $cierre = $this->facturar($token, [$entregas[2]->id], 'entrega')->assertStatus(201);

        $this->assertEqualsWithDelta(321.00, (float) $cierre->json('invoices.0.monto'), 0.001, 'El cierre cobra lo que le corresponde a 7%, no 331.50 (no recobra en silencio).');
        $this->assertSame([], $cierre->json('invoices.0.advertencias'), 'Sin advertencia espuria de "descuadre contra la cotización".');
        $this->assertEqualsWithDelta((float) $card->amount - 10.50, $this->sumaActivas($root), 0.001, 'Σ final = 963 − 10.50 = 952.50, el ajuste no se pierde ni se recobra.');
    }

    /**
     * Senior Review (2026-09-19, "correcciones seguidas" sin reproducir) — `ajuste_itbms_delta` se ACUMULA a
     * través de una cadena de correcciones (F → F' → F''), no se recalcula desde cero cada vez. Cadena: fecha
     * (delta 0) → 7%→3.5% (delta −10.50) → 3.5%→7% de vuelta (delta +10.50) — el acumulado vuelve a 0 y el
     * pedido queda exactamente como si nunca se hubiera tocado nada.
     */
    public function test_b3b_correcciones_encadenadas_acumulan_el_delta_de_itbms_correctamente(): void
    {
        ['root' => $root, 'entregas' => $entregas, 'card' => $card] = $this->familia();
        $token = $this->token();
        $this->facturar($token, [$entregas[1]->id], 'entrega')->assertStatus(201);
        $f0 = $this->parcialDe($root, $entregas[1]);

        $f1 = $this->corregirPorFecha($token, $f0);
        $this->assertSame(0.0, $f1->ajuste_itbms_delta, 'Corrección por fecha: sin cambio de tasa, delta 0.');

        $tasa35 = AdminContItbmsRate::where('nombre', '3.5% — Retención de impuesto')->firstOrFail();
        $this->corregir($token, $f1, AdminContCreditNote::MOTIVO_CORRECCION_ITBMS, ['nuevo_tratamiento_itbms_rate_id' => $tasa35->id, 'nueva_fecha' => now()->toDateString()])
            ->assertStatus(201);
        $f2 = $this->reemplazoDe($f1);
        $this->assertSame(310.50, $f2->monto);
        $this->assertSame(-10.50, $f2->ajuste_itbms_delta);

        $tasa7 = AdminContItbmsRate::where('porcentaje', 7.0)->where('activa', true)->firstOrFail();
        $this->corregir($token, $f2, AdminContCreditNote::MOTIVO_CORRECCION_ITBMS, ['nuevo_tratamiento_itbms_rate_id' => $tasa7->id, 'nueva_fecha' => now()->toDateString()])
            ->assertStatus(201);
        $f3 = $this->reemplazoDe($f2);

        $this->assertSame(321.00, $f3->monto, 'De vuelta a 7%: el mismo monto original.');
        $this->assertSame(0.0, $f3->ajuste_itbms_delta, 'Acumulado −10.50 + 10.50 = 0: el pedido queda como si nunca se hubiera corregido nada.');
        $this->assertSame(321.00, $this->sumaActivas($root));

        // El resto del pedido se factura normal (7%, sin ajuste alguno) — round-trip completo, sin residuo.
        $this->facturar($token, [$entregas[0]->id], 'entrega')->assertStatus(201);
        $cierre = $this->facturar($token, [$entregas[2]->id], 'entrega')->assertStatus(201);
        $this->assertEqualsWithDelta(321.00, (float) $cierre->json('invoices.0.monto'), 0.001);
        $this->assertSame([], $cierre->json('invoices.0.advertencias'));
        $this->assertEqualsWithDelta((float) $card->amount, $this->sumaActivas($root), 0.001, 'Σ final = 963 exacto, sin residuo del round-trip.');
    }

    /** Lo permitido sigue funcionando: la FECHA sobre una parcial (cubierto arriba) y la TASA sobre un completo. */
    public function test_b3b_corregir_la_tasa_de_itbms_de_un_completo_recalcula_desde_sus_lineas(): void
    {
        ['root' => $root, 'card' => $card] = $this->familia();
        $token = $this->token();
        $this->facturar($token, [$root->id], 'completo')->assertStatus(201);
        $original = $this->completoDe($root);
        $tasa     = AdminContItbmsRate::where('nombre', '3.5% — Retención de impuesto')->firstOrFail();
        $payload  = ['nuevo_tratamiento_itbms_rate_id' => $tasa->id];

        // 900 de base a 3.5 % = 31.50 → 931.50.
        $preview = $this->api($token, 'POST', '/api/admin-contab/notas-credito/correccion/preview', [
            'master_client_id' => $original->master_client_id, 'factura_origen_id' => $original->id,
            'motivo_correccion' => AdminContCreditNote::MOTIVO_CORRECCION_ITBMS, 'motivo' => 'ITBMS mal aplicado',
        ] + $payload)->assertStatus(200);
        $this->assertEqualsWithDelta(931.50, (float) $preview->json('monto'), 0.001);
        $this->assertEqualsWithDelta(31.50, (float) $preview->json('factura_preview.itbms'), 0.001);
        $this->assertEquals(9, $preview->json('factura_preview.items.0.cantidad'));

        $this->corregir($token, $original, AdminContCreditNote::MOTIVO_CORRECCION_ITBMS, $payload)->assertStatus(201);
        $reemplazo = $this->reemplazoDe($original);

        $this->assertSame(AdminContInvoice::TIPO_COMPLETO, $reemplazo->tipo);
        $this->assertSame($root->id, $reemplazo->order_id);
        $this->assertSame(931.50, $reemplazo->monto);
        $linea = $reemplazo->lines->first();
        $this->assertSame(900.00, $linea->subtotal, 'La base de la línea no cambia.');
        $this->assertSame(31.50, $linea->itbms);
        $this->assertSame($tasa->id, $reemplazo->itbms_rate_id);
        $this->assertLessThan((float) $card->amount, $this->sumaActivas($root));
    }

    /**
     * Senior Review B3a-1 (2026-09-19) — no se admite una segunda corrección mientras la primera sigue
     * pendiente de aprobación de Mark sobre la MISMA factura: esa segunda nota quedaría condenada a
     * fallar (cuando la primera se aprueba, la factura de origen se anula y nace su reemplazo). Se corta
     * en el REGISTRO, no se espera a que Mark intente aprobarla para descubrirlo.
     */
    public function test_b3a_1_registrar_una_segunda_correccion_mientras_la_primera_sigue_pendiente_se_rechaza(): void
    {
        // Ítem caro para superar el umbral de aprobación de Mark (5000 por defecto) y quedar pendiente.
        ['root' => $root] = $this->makePedido([['desc' => 'Ítem caro', 'qty' => 1, 'price' => 6000.00]], [[1]]);
        $token = $this->token();
        $this->facturar($token, [$root->id], 'completo')->assertStatus(201);
        $original = $this->completoDe($root);

        $this->corregir($token, $original, AdminContCreditNote::MOTIVO_CORRECCION_FECHA, ['nueva_fecha' => now()->toDateString()])->assertStatus(201);
        $n1 = AdminContCreditNote::where('factura_origen_id', $original->id)->firstOrFail();
        $this->assertTrue((bool) $n1->correccion_pendiente_aprobacion, 'Monto > umbral: queda pendiente de Mark.');

        // Preview y registro rechazan la 2ª mientras N1 sigue sin resolver — ANTES de tocar nada.
        $this->api($token, 'POST', '/api/admin-contab/notas-credito/correccion/preview', [
            'master_client_id' => $original->master_client_id, 'factura_origen_id' => $original->id,
            'motivo_correccion' => AdminContCreditNote::MOTIVO_CORRECCION_FECHA, 'motivo' => 'Otra corrección',
            'nueva_fecha' => now()->addDay()->toDateString(),
        ])->assertStatus(422);
        $this->corregir($token, $original, AdminContCreditNote::MOTIVO_CORRECCION_FECHA, ['nueva_fecha' => now()->addDay()->toDateString()])
            ->assertStatus(422);
        $this->assertSame(1, AdminContCreditNote::where('factura_origen_id', $original->id)->count(), 'Solo N1 existe.');
        $this->assertNull($original->fresh()->anulada_at, 'La factura sigue vigente, nada se tocó.');
    }

    /**
     * Senior Review B3b (2026-09-19, hallazgo ALTO) — defensa en profundidad en `decide()`: aunque el
     * guard de registro de arriba cubre el camino normal, una segunda nota pendiente sobre una factura
     * que YA se anuló podría llegar a existir por otra vía (dato migrado, condición de carrera entre dos
     * registros simultáneos). `decide()` NUNCA debe ejecutar `executeCorreccion()` sobre una factura de
     * origen ya anulada — se fuerza el escenario creando la 2ª nota directo por Eloquent (bypaseando el
     * guard de registro a propósito, para probar el guard de APROBACIÓN en aislamiento).
     */
    public function test_b3b_aprobar_una_correccion_cuya_factura_de_origen_ya_fue_anulada_da_422_no_500(): void
    {
        ['root' => $root] = $this->makePedido([['desc' => 'Ítem caro', 'qty' => 1, 'price' => 6000.00]], [[1]]);
        $token = $this->token();
        $this->facturar($token, [$root->id], 'completo')->assertStatus(201);
        $original = $this->completoDe($root);

        $this->corregir($token, $original, AdminContCreditNote::MOTIVO_CORRECCION_FECHA, ['nueva_fecha' => now()->toDateString()])->assertStatus(201);
        $n1 = AdminContCreditNote::where('factura_origen_id', $original->id)->firstOrFail();

        // Segunda nota creada directo (el guard de registro normal ya la impediría vía la API real).
        $n2 = AdminContCreditNote::create([
            'numero' => 'NC-B3B-'.self::uniqueTestNumber(), 'master_client_id' => $original->master_client_id,
            'cliente' => $original->cliente, 'tipo' => AdminContCreditNote::TIPO_ANULACION_COMPLETA,
            'subtipo_anulacion' => AdminContCreditNote::SUBTIPO_CORRECCION, 'factura_origen_id' => $original->id,
            'monto' => $original->monto, 'subtotal' => 6000.0, 'itbms' => 420.0,
            'motivo' => 'Segunda corrección forzada', 'motivo_correccion' => AdminContCreditNote::MOTIVO_CORRECCION_FECHA,
            'nueva_fecha_factura' => now()->addDay()->toDateString(), 'estado' => AdminContCreditNote::ESTADO_PENDIENTE_APROBACION,
            'correccion_pendiente_aprobacion' => true, 'registrado_por' => $this->ownerId(), 'fecha' => now()->toDateString(),
        ]);

        $mark = $this->loginAs(self::MARK_EMAIL, self::MARK_EMAIL, 'management');
        $this->reTenant();

        $this->api($mark, 'PUT', "/api/admin-contab/notas-credito/{$n1->id}/decision", ['approve' => true])->assertStatus(200);
        $reemplazo1 = AdminContInvoice::findOrFail($n1->fresh()->factura_nueva_id);

        // Antes del fix: 500 (violación del índice único al re-anular F1 e intentar crear F3). Ahora: 422.
        $this->api($mark, 'PUT', "/api/admin-contab/notas-credito/{$n2->id}/decision", ['approve' => true])->assertStatus(422);
        $this->assertSame(AdminContCreditNote::ESTADO_PENDIENTE_APROBACION, $n2->fresh()->estado, 'N2 sigue pendiente, no se rompió nada.');
        $this->assertNotNull($reemplazo1->fresh(), 'El reemplazo de N1 sigue intacto.');
        $this->assertCount(1, $this->activas($root));
    }

    /**
     * Senior Review B3a-3 (2026-09-19) — una `parcial` sin líneas (solo alcanzable con datos manuales, nunca vía
     * la API real: `confirm()` siempre crea líneas para una parcial) caía a la ruta legada de
     * `correctionBreakdown()` y se "corregía" con el monto de la cotización ENTERA, inflando Σ del pedido.
     */
    public function test_b3a_3_corregir_una_parcial_sin_lineas_se_rechaza_en_vez_de_usar_la_cotizacion_entera(): void
    {
        ['root' => $root, 'entregas' => $entregas] = $this->familia();
        $token = $this->token();
        $this->facturar($token, [$entregas[1]->id], 'entrega')->assertStatus(201);
        $parcial = $this->parcialDe($root, $entregas[1]);
        $parcial->lines()->delete(); // simula el dato manual/seeder que el hallazgo describe.

        $this->corregir($token, $parcial->fresh(), AdminContCreditNote::MOTIVO_CORRECCION_FECHA, ['nueva_fecha' => now()->toDateString()])
            ->assertStatus(422);
        $this->assertNull($parcial->fresh()->anulada_at, 'La parcial sigue vigente, nada se tocó.');
        $this->assertSame(0, AdminContCreditNote::where('factura_origen_id', $parcial->id)->count());
    }

    // ---------------------------------------------------------------------------------------------
    // 4b. Libro de Facturas / Ventas por medio de pago — SCRUM-929 B3b Punto 1 (`InvoiceTaxBreakdown`
    //     lee `invoice_lines`, no la cotización entera: una parcial no puede sobrestimar ×N).
    // ---------------------------------------------------------------------------------------------

    private function marcarCobrada(AdminContInvoice $factura): void
    {
        AdminContInvoice::whereKey($factura->id)->update(['saldo_aplicado' => $factura->monto]);
    }

    /** @return array<string, mixed>|null */
    private function documentoDelLibro(string $token, string $numero): ?array
    {
        $docs = collect($this->api($token, 'GET', '/api/admin-contab/reports/libro-facturas')->assertStatus(200)->json('documentos'));

        return $docs->firstWhere('documento', $numero);
    }

    public function test_b3b_libro_de_facturas_de_3_parciales_suma_igual_a_la_cotizacion_y_no_triplica(): void
    {
        ['root' => $root, 'entregas' => $entregas, 'card' => $card] = $this->familia();
        $token = $this->token();
        foreach ($entregas as $entrega) {
            $this->facturar($token, [$entrega->id], 'entrega')->assertStatus(201);
        }

        $numeros = collect($this->activas($root))->pluck('numero');
        $docs    = collect($this->api($token, 'GET', '/api/admin-contab/reports/libro-facturas')->assertStatus(200)->json('documentos'))
            ->whereIn('documento', $numeros);

        $this->assertCount(3, $docs, 'Una fila del libro por cada parcial.');
        // 900 de base + 63 de ITBMS (7 %) = 963, la MISMA base/ITBMS que la cotización entera — no ×3
        // (una regresión al cálculo viejo, sobre la cotización completa por cada fila, daría 2700/189).
        $this->assertEqualsWithDelta(900.0, $docs->sum('base_imponible'), 0.01);
        $this->assertEqualsWithDelta(63.0, $docs->sum('itbms'), 0.01);
        $this->assertEqualsWithDelta((float) $card->amount, $docs->sum('total'), 0.01);
    }

    public function test_b3b_libro_de_facturas_con_parcial_y_completo_no_duplica(): void
    {
        ['root' => $root, 'entregas' => $entregas, 'card' => $card] = $this->familia();
        $token = $this->token();
        $this->facturar($token, [$entregas[0]->id], 'entrega')->assertStatus(201); // 321
        $this->facturar($token, [$root->id], 'completo')->assertStatus(201);       // resto: 642

        $numeros = collect($this->activas($root))->pluck('numero');
        $docs    = collect($this->api($token, 'GET', '/api/admin-contab/reports/libro-facturas')->assertStatus(200)->json('documentos'))
            ->whereIn('documento', $numeros);

        $this->assertCount(2, $docs, 'Una fila por la parcial y una por el completo, no una tercera por el pedido entero.');
        $this->assertEqualsWithDelta(900.0, $docs->sum('base_imponible'), 0.01);
        $this->assertEqualsWithDelta(63.0, $docs->sum('itbms'), 0.01);
        $this->assertEqualsWithDelta((float) $card->amount, $docs->sum('total'), 0.01);
    }

    public function test_b3b_libro_de_facturas_entrega_unica_sin_cambio(): void
    {
        ['root' => $root] = $this->makePedido([['desc' => 'Ítem único', 'qty' => 1, 'price' => 13.50]], [[1]]);
        $token = $this->token();
        $this->facturar($token, [$root->id], 'completo')->assertStatus(201);
        $factura = $this->completoDe($root);

        $doc = $this->documentoDelLibro($token, $factura->numero);

        $this->assertNotNull($doc, 'La factura de entrega única aparece en el libro.');
        $this->assertEqualsWithDelta(13.50, $doc['base_imponible'], 0.001);
        $this->assertEqualsWithDelta(0.95, $doc['itbms'], 0.001);
        $this->assertEqualsWithDelta(14.45, $doc['total'], 0.001);
    }

    public function test_b3b_libro_de_facturas_tras_correccion_por_fecha_conserva_base_e_itbms(): void
    {
        ['root' => $root, 'entregas' => $entregas] = $this->familia();
        $token = $this->token();
        $this->facturar($token, [$entregas[1]->id], 'entrega')->assertStatus(201);
        $original = $this->parcialDe($root, $entregas[1]);

        $reemplazo = $this->corregirPorFecha($token, $original);

        $docOriginal  = $this->documentoDelLibro($token, $original->numero);
        $docReemplazo = $this->documentoDelLibro($token, $reemplazo->numero);
        $this->assertNull($docOriginal, 'La original anulada no aparece.');
        $this->assertNotNull($docReemplazo);
        $this->assertEqualsWithDelta(300.0, $docReemplazo['base_imponible'], 0.01, '3 unidades × 100 = 300 de base, no la cotización entera (900).');
        $this->assertEqualsWithDelta(21.0, $docReemplazo['itbms'], 0.01);
    }

    public function test_b3b_ventas_por_medio_de_pago_de_una_parcial_no_sobrestima_la_base_del_pedido_entero(): void
    {
        ['root' => $root, 'entregas' => $entregas] = $this->familia();
        $token = $this->token();
        $this->facturar($token, [$entregas[0]->id], 'entrega')->assertStatus(201);
        $parcial = $this->parcialDe($root, $entregas[0]);
        $this->marcarCobrada($parcial);

        $filas = collect($this->api($token, 'GET', '/api/admin-contab/reports/ventas-medio-pago')->assertStatus(200)->json('filas'))
            ->firstWhere('documento', $parcial->numero);

        $this->assertNotNull($filas, 'La parcial cobrada aparece como fila.');
        // 3 unidades × 100 = 300 de base + 21 de ITBMS = 321, NO 900/63/963 (la cotización entera).
        $this->assertEqualsWithDelta(300.0, $filas['base_imponible'], 0.01);
        $this->assertEqualsWithDelta(21.0, $filas['itbms'], 0.01);
        $this->assertEqualsWithDelta(321.0, $filas['total'], 0.01);
    }

    public function test_b3b_nota_de_credito_sobre_una_parcial_no_se_ve_afectada_por_invoice_lines(): void
    {
        ['root' => $root, 'entregas' => $entregas] = $this->familia();
        $token = $this->token();
        $this->facturar($token, [$entregas[0]->id], 'entrega')->assertStatus(201); // 321
        $parcial = $this->parcialDe($root, $entregas[0]);

        $nota = AdminContCreditNote::create([
            'numero' => 'NC-929-'.self::uniqueTestNumber(), 'master_client_id' => $parcial->master_client_id,
            'cliente' => $parcial->cliente, 'tipo' => AdminContCreditNote::TIPO_DESCUENTO_COMERCIAL,
            'factura_origen_id' => $parcial->id, 'monto' => 50.0, 'subtotal' => 46.73, 'itbms' => 3.27,
            'motivo' => 'Descuento sobre la parcial', 'estado' => AdminContCreditNote::ESTADO_APLICADA,
            'registrado_por' => $this->ownerId(), 'fecha' => now()->toDateString(),
        ]);

        $doc = $this->documentoDelLibro($token, $nota->numero);

        $this->assertNotNull($doc);
        // Las filas de NC salen de sus propios `monto`/`subtotal`/`itbms` (independientes de invoice_lines),
        // en negativo — no se ven afectadas por el cambio de fuente del Libro para las facturas.
        $this->assertEqualsWithDelta(-46.73, $doc['base_imponible'], 0.01);
        $this->assertEqualsWithDelta(-3.27, $doc['itbms'], 0.01);
        $this->assertEqualsWithDelta(-50.0, $doc['total'], 0.01);
    }

    // ---------------------------------------------------------------------------------------------
    // 5. Estado de cuenta — la guía es la de la entrega de la factura parcial
    // ---------------------------------------------------------------------------------------------

    public function test_b3a_la_guia_de_una_factura_parcial_es_la_de_su_entrega_y_la_del_completo_la_de_la_familia(): void
    {
        ['root' => $root, 'entregas' => $entregas, 'card' => $card] = $this->familia();
        $token = $this->token();
        $this->facturar($token, [$entregas[1]->id], 'entrega')->assertStatus(201);
        $parcial = $this->parcialDe($root, $entregas[1]);
        // Solo la entrega 1 (raíz) produjo su guía; la entrega 2 (la de la parcial) no.
        $entregas[0]->documents()->create(['tipo' => \App\Modules\Bodega\Models\OrderDocument::TIPO_GUIA_ENTREGA, 'storage_key' => 'guia-e1.pdf']);

        // SCRUM-930 — `guia_entrega` (bool) se reemplazó por `guia_entrega_numero`/`guia_entrega_order_id`
        // (el sistema no tiene folio propio, se sintetiza "{order_number}-G" del pedido real que tiene el
        // documento); mismo criterio de "tiene guía o no" que antes, solo cambia la forma del dato.
        $filas = fn () => collect($this->api($token, 'GET', "/api/admin-contab/account-statement?master_client_id={$card->master_client_id}")->assertStatus(200)->json('movimientos'));
        $delParcial = $filas()->firstWhere('numero_factura', $parcial->numero);
        $this->assertNull($delParcial['guia_entrega_numero'], 'La guía de OTRA entrega no cuenta para la parcial de la entrega 2.');
        $this->assertNull($delParcial['guia_entrega_order_id']);

        // Ahora la entrega 2 produce su propia guía: la parcial pasa a tenerla.
        $entregas[1]->documents()->create(['tipo' => \App\Modules\Bodega\Models\OrderDocument::TIPO_GUIA_ENTREGA, 'storage_key' => 'guia-e2.pdf']);
        $filaParcialConGuia = $filas()->firstWhere('numero_factura', $parcial->numero);
        $this->assertSame("{$entregas[1]->order_number}-G", $filaParcialConGuia['guia_entrega_numero']);
        $this->assertSame($entregas[1]->id, $filaParcialConGuia['guia_entrega_order_id']);

        // Un completo (cubre el resto) mira la familia: con la guía de CUALQUIER entrega basta —
        // en este punto entregas[0] y entregas[1] ya tienen guía, así que no importa cuál matchee.
        $this->facturar($token, [$root->id], 'completo')->assertStatus(201);
        $completo = $this->completoDe($root);
        $filaCompleto = $filas()->firstWhere('numero_factura', $completo->numero);
        $numeroPorOrderId = [
            $entregas[0]->id => "{$entregas[0]->order_number}-G",
            $entregas[1]->id => "{$entregas[1]->order_number}-G",
        ];
        $this->assertContains($filaCompleto['guia_entrega_order_id'], array_keys($numeroPorOrderId));
        $this->assertSame($numeroPorOrderId[$filaCompleto['guia_entrega_order_id']], $filaCompleto['guia_entrega_numero']);
    }

    /**
     * SCRUM-930 (F4 backend) — los ids nuevos que el frontend usa para abrir cada modal de detalle
     * desde Estado de Cuenta, y el bucket de antigüedad por fecha de CREACIÓN (no vencimiento, a
     * diferencia de `InvoiceService::agingBuckets()`).
     */
    public function test_930_movimientos_incluyen_ids_para_enlazar_documentos_y_antiguedad_por_creacion(): void
    {
        ['root' => $root, 'entregas' => $entregas, 'card' => $card] = $this->familia();
        $token = $this->token();
        $this->facturar($token, [$entregas[1]->id], 'entrega')->assertStatus(201);
        $parcial = $this->parcialDe($root, $entregas[1]);
        // Fecha de creación fija y vieja a propósito: cae inequívocamente en el bucket "91+" sin
        // depender de qué día corra el test (sería frágil contra "0-30" con `now()`).
        $parcial->forceFill(['created_at' => now()->subDays(120)])->save();

        $quote = $entregas[1]->linkedQuote();
        $nota = AdminContCreditNote::create([
            'numero' => 'NC-930-'.self::uniqueTestNumber(), 'master_client_id' => $parcial->master_client_id,
            'cliente' => $parcial->cliente, 'tipo' => AdminContCreditNote::TIPO_DESCUENTO_COMERCIAL,
            'factura_origen_id' => $parcial->id, 'monto' => 10.0, 'subtotal' => 9.35, 'itbms' => 0.65,
            'motivo' => 'Descuento para probar nota_id en Estado de Cuenta', 'estado' => AdminContCreditNote::ESTADO_APLICADA,
            'registrado_por' => $this->ownerId(), 'fecha' => now()->toDateString(),
        ]);

        $filas = collect($this->api($token, 'GET', "/api/admin-contab/account-statement?master_client_id={$card->master_client_id}")->assertStatus(200)->json('movimientos'));

        $filaFactura = $filas->firstWhere('numero_factura', $parcial->numero);
        $this->assertSame($parcial->id, $filaFactura['invoice_id']);
        $this->assertSame($entregas[1]->id, $filaFactura['order_id']);
        $this->assertNotNull($quote, 'La entrega tiene una cotización vinculada real (fixture de la familia).');
        $this->assertSame($quote->id, $filaFactura['quote_id']);
        $this->assertSame('91+', $filaFactura['antiguedad_cartera']);

        $filaDescuento = $filas->where('numero_factura', $parcial->numero)->firstWhere('tipo', 'descuento');
        $this->assertNotNull($filaDescuento, 'La nota de descuento aparece como su propia fila.');
        $this->assertSame($nota->id, $filaDescuento['nota_id']);
        // Antigüedad solo se calcula en filas `tipo=factura` — el resto va null, no heredan el
        // bucket de la factura de origen.
        $this->assertNull($filaDescuento['antiguedad_cartera']);

        // Filas que no son de factura (cobro, en este caso no hay, pero la de descuento ya alcanza)
        // tampoco enlazan guía — no es su documento.
        $this->assertNull($filaDescuento['guia_entrega_numero']);
    }

    /**
     * SCRUM-931 (Cobros) — `PaymentService::openInvoicesForClient()` expone `sales_project_id`/
     * `proyecto`/`quote_id`/`cotizacion_folio` por factura (punto 1, filtro cruzado en "Registrar
     * Cobro"), y `AdminContPaymentController::presentDetail()` expone Cotización/Guía de
     * Entrega/Abonos/Notas de Crédito del cobro (punto 2) — con datos reales, no solo "no vacío".
     */
    public function test_931_open_invoices_y_detalle_de_cobro_incluyen_proyecto_cotizacion_guia_abonos_y_notas(): void
    {
        ['root' => $root, 'entregas' => $entregas, 'card' => $card] = $this->familia();
        $token = $this->token();
        $this->facturar($token, [$entregas[0]->id], 'entrega')->assertStatus(201);
        $parcial = $this->parcialDe($root, $entregas[0]);
        $entregas[0]->documents()->create(['tipo' => \App\Modules\Bodega\Models\OrderDocument::TIPO_GUIA_ENTREGA, 'storage_key' => 'guia-931.pdf']);
        $quote = $entregas[0]->linkedQuote();
        $this->assertNotNull($quote, 'La entrega tiene una cotización vinculada real (fixture de la familia).');

        // Punto 1 — open-invoices expone Proyecto/Cotización de la factura ANTES de cobrarla.
        $filaAbierta = collect($this->api($token, 'GET', "/api/admin-contab/payments/open-invoices?master_client_id={$card->master_client_id}")->assertStatus(200)->json('invoices'))
            ->firstWhere('id', $parcial->id);
        $this->assertNotNull($filaAbierta);
        $this->assertSame($parcial->sales_project_id, $filaAbierta['sales_project_id']);
        $this->assertSame($quote->id, $filaAbierta['quote_id']);
        $this->assertSame($quote->folio, $filaAbierta['cotizacion_folio']);
        $this->assertNotNull($filaAbierta['proyecto']);

        // Registrar el cobro completo de esa factura.
        $bankAccount = AdminContBankAccount::create(['banco' => 'Banco General', 'tipo_cuenta' => AdminContBankAccount::TIPO_CORRIENTE, 'ultimos_4_digitos' => '9310']);
        $payment = $this->api($token, 'POST', '/api/admin-contab/payments', [
            'master_client_id' => $card->master_client_id, 'invoice_ids' => [$parcial->id],
            'metodo_pago' => 'efectivo', 'bank_account_id' => $bankAccount->id,
            'monto_recibido_estimado' => $parcial->monto,
        ])->assertStatus(201)->json();
        $this->reTenant();

        // Abono — el pivote payment_invoices lo referencia por línea, no hay FK directa del cobro.
        $abono = AdminContAbono::create([
            'master_client_id' => $card->master_client_id, 'monto_original' => 50, 'monto_usado' => 0,
            'metodo_pago' => 'transferencia', 'cuenta_bancaria_id' => $bankAccount->id, 'referencia' => 'TRF-931-TEST',
            'estado' => AdminContAbono::ESTADO_CONFIRMADO, 'registrado_por' => $this->ownerId(), 'fecha_registro' => now(),
        ]);
        \App\Modules\AdminCont\Models\AdminContPaymentInvoice::where('payment_id', $payment['id'])->update(['abono_id' => $abono->id]);

        // Nota de crédito — relación siempre transitiva vía factura, nunca directa al cobro.
        $nota = AdminContCreditNote::create([
            'numero' => 'NC-931-'.self::uniqueTestNumber(), 'master_client_id' => $card->master_client_id,
            'cliente' => $parcial->cliente, 'tipo' => AdminContCreditNote::TIPO_DESCUENTO_COMERCIAL,
            'factura_origen_id' => $parcial->id, 'monto' => 10, 'subtotal' => 9.35, 'itbms' => 0.65,
            'motivo' => 'Descuento para probar notas_credito del detalle de cobro', 'estado' => AdminContCreditNote::ESTADO_APLICADA,
            'registrado_por' => $this->ownerId(), 'fecha' => now()->toDateString(),
        ]);

        // Punto 2 — detalle del cobro trae todo lo anterior, con los ids/valores REALES.
        $detalle = $this->api($token, 'GET', "/api/admin-contab/payments/{$payment['id']}")->assertStatus(200)->json();
        $filaFactura = collect($detalle['facturas'])->firstWhere('invoice_id', $parcial->id);
        $this->assertNotNull($filaFactura);
        $this->assertSame($quote->id, $filaFactura['quote_id']);
        $this->assertSame($quote->folio, $filaFactura['cotizacion_folio']);
        $this->assertSame($entregas[0]->id, $filaFactura['order_id']);
        $this->assertSame("{$entregas[0]->order_number}-G", $filaFactura['guia_entrega_numero']);
        $this->assertSame($entregas[0]->id, $filaFactura['guia_entrega_order_id']);
        $this->assertSame([$abono->id], $detalle['abonos']);
        $this->assertCount(1, $detalle['notas_credito']);
        $this->assertSame($nota->id, $detalle['notas_credito'][0]['id']);
        $this->assertSame($nota->numero, $detalle['notas_credito'][0]['numero']);
    }

    /**
     * SCRUM-932 (Notas Crédito y Devoluciones) — `CreditNoteService::invoicesForClient()` expone
     * `sales_project_id`/`proyecto`/`quote_id`/`cotizacion_folio` por factura (punto 1, filtro
     * cruzado en el modal de creación) SIN dejar de traer facturas ya pagadas (REQ-483/484, regla
     * de negocio propia de este método, distinta de Cobros); y `CreditNoteService::show()` expone
     * Cotización/Guía de Entrega de la factura de origen (punto 2) con datos reales.
     */
    public function test_932_invoices_for_client_y_detalle_de_nota_incluyen_proyecto_cotizacion_y_guia(): void
    {
        ['root' => $root, 'entregas' => $entregas, 'card' => $card] = $this->familia();
        $token = $this->token();
        $this->facturar($token, [$entregas[0]->id], 'entrega')->assertStatus(201);
        $parcial = $this->parcialDe($root, $entregas[0]);
        $entregas[0]->documents()->create(['tipo' => \App\Modules\Bodega\Models\OrderDocument::TIPO_GUIA_ENTREGA, 'storage_key' => 'guia-932.pdf']);
        $quote = $entregas[0]->linkedQuote();
        $this->assertNotNull($quote, 'La entrega tiene una cotización vinculada real (fixture de la familia).');

        // Punto 1 — invoicesForClient expone Proyecto/Cotización, y SIGUE trayendo una factura ya
        // pagada por completo (a diferencia de Cobros, que la descarta — REQ-483/484).
        $parcial->forceFill(['saldo_aplicado' => $parcial->monto])->save();
        $filas = collect($this->api($token, 'GET', "/api/admin-contab/notas-credito/clientes/{$card->master_client_id}/facturas")->assertStatus(200)->json('data'));
        $filaFactura = $filas->firstWhere('id', $parcial->id);
        $this->assertNotNull($filaFactura, 'La factura ya pagada sigue apareciendo — comportamiento existente, no debe romperse.');
        $this->assertSame($parcial->sales_project_id, $filaFactura['sales_project_id']);
        $this->assertSame($quote->id, $filaFactura['quote_id']);
        $this->assertSame($quote->folio, $filaFactura['cotizacion_folio']);
        $this->assertNotNull($filaFactura['proyecto']);
        $this->assertSame(0.0, (float) $filaFactura['saldo_pendiente']);

        // Punto 2 — detalle de una nota real sobre esta factura trae Cotización/Guía correctas.
        $nota = AdminContCreditNote::create([
            'numero' => 'NC-932-'.self::uniqueTestNumber(), 'master_client_id' => $card->master_client_id,
            'cliente' => $parcial->cliente, 'tipo' => AdminContCreditNote::TIPO_DESCUENTO_COMERCIAL,
            'factura_origen_id' => $parcial->id, 'monto' => 15, 'subtotal' => 14.02, 'itbms' => 0.98,
            'motivo' => 'Descuento para probar Cotización/Guía en el detalle de la nota', 'estado' => AdminContCreditNote::ESTADO_APLICADA,
            'registrado_por' => $this->ownerId(), 'fecha' => now()->toDateString(),
        ]);

        $detalle = $this->api($token, 'GET', "/api/admin-contab/notas-credito/{$nota->id}")->assertStatus(200)->json();
        $this->assertSame($quote->id, $detalle['quote_id']);
        $this->assertSame($quote->folio, $detalle['cotizacion_folio']);
        $this->assertSame("{$entregas[0]->order_number}-G", $detalle['guia_entrega_numero']);
        $this->assertSame($entregas[0]->id, $detalle['guia_entrega_order_id']);
        // "Factura de origen" (existente) no debe cambiar con este ticket.
        $this->assertSame($parcial->id, $detalle['factura_origen_id']);
        $this->assertSame($parcial->numero, $detalle['factura_origen_numero']);
    }

    /**
     * SCRUM-933 (Registrar Abonos) — `AbonoService::invoicesForSubClient()` trae las facturas del
     * SUB-CLIENTE correcto (con Proyecto/Cotización resueltos), y NUNCA las de otro sub-cliente
     * (ni del mismo Cliente Master ni de otro) — caso negativo explícito, ver ADR-SCRUM933.
     */
    public function test_933_invoices_for_sub_client_incluye_proyecto_cotizacion_y_excluye_otros_sub_clientes(): void
    {
        ['root' => $root, 'entregas' => $entregas, 'card' => $card, 'quote' => $quote] = $this->familia();
        $token = $this->token();
        $this->facturar($token, [$entregas[0]->id], 'entrega')->assertStatus(201);
        $parcial = $this->parcialDe($root, $entregas[0]);

        // Sub-cliente ajeno — otra familia completa (otro Cliente Master, otro Sub-cliente, otra
        // cotización), con su propia factura, para probar que NO se filtra.
        ['entregas' => $entregasAjenas] = $this->familia();
        $this->facturar($token, [$entregasAjenas[0]->id], 'entrega')->assertStatus(201);

        $filas = collect($this->api($token, 'GET', "/api/admin-contab/abonos/cascada/facturas-por-subcliente?sub_client_id={$card->sub_client_id}")->assertStatus(200)->json('data'));

        $filaPropia = $filas->firstWhere('id', $parcial->id);
        $this->assertNotNull($filaPropia, 'La factura del sub-cliente consultado aparece en la lista.');
        $this->assertSame($parcial->sales_project_id, $filaPropia['sales_project_id']);
        $this->assertNotNull($filaPropia['proyecto']);
        $this->assertSame($quote->id, $filaPropia['quote_id']);
        $this->assertSame($quote->folio, $filaPropia['cotizacion_folio']);

        // Caso negativo — ninguna factura de la familia ajena (otro sub-cliente) aparece acá.
        $this->assertCount(1, $filas, 'Solo la factura del sub-cliente consultado, ninguna de la familia ajena.');
    }

    // ---------------------------------------------------------------------------------------------
    // 5b. Comisiones (externas/internas) — SCRUM-929 B3b Punto 2, agregado por `family_root_id`,
    //     "todo o nada" por PEDIDO COMPLETO (decisión de Luis 2026-09-19).
    // ---------------------------------------------------------------------------------------------

    private function makeArchitect(): Architect
    {
        return Architect::create(['name' => 'Arq. 929 '.uniqid(), 'email' => uniqid().'@arq929.test']);
    }

    public function test_b3b_comision_externa_de_una_parcial_cobrada_no_se_genera_ni_duplica_el_total_facturado(): void
    {
        $architect = $this->makeArchitect();
        ['root' => $root, 'entregas' => $entregas, 'card' => $card] = $this->makePedido(
            [['desc' => 'Panel LED', 'qty' => 9, 'price' => 100.00]], [[3], [3], [3]],
            ['architect_id' => $architect->id],
        );
        $token = $this->token();

        // Solo la entrega 1 (321) facturada Y cobrada — el caso real del proyecto de 963 con 10%.
        $this->facturar($token, [$entregas[0]->id], 'entrega')->assertStatus(201);
        $this->marcarCobrada($this->parcialDe($root, $entregas[0]));

        $fila = app(ArchitectCommissionService::class)->allProjects()->firstWhere('pipeline_card_id', $card->id);
        $this->assertNotNull($fila);
        $this->assertEqualsWithDelta(321.0, $fila['total_facturado'], 0.01, 'Sin fan-out: una sola fila, el total de la parcial.');
        $this->assertEqualsWithDelta(321.0, $fila['total_cobrado'], 0.01);
        $this->assertSame(
            ArchitectCommissionService::ESTADO_AUN_NO_GENERADA,
            $fila['estado'],
            'El pedido no está completamente facturado: la comisión NO se genera con 1/3 cobrado.',
        );

        // Se factura y cobra el resto: ahora sí, Σ = 963 exacto (no más, no menos) y comisión generada.
        $this->facturar($token, [$entregas[1]->id], 'entrega')->assertStatus(201);
        $this->marcarCobrada($this->parcialDe($root, $entregas[1]));
        $this->facturar($token, [$root->id], 'completo')->assertStatus(201);
        $this->marcarCobrada($this->completoDe($root));

        $filaFinal = app(ArchitectCommissionService::class)->allProjects()->firstWhere('pipeline_card_id', $card->id);
        $this->assertEqualsWithDelta((float) $card->amount, $filaFinal['total_facturado'], 0.01);
        $this->assertEqualsWithDelta((float) $card->amount, $filaFinal['total_cobrado'], 0.01);
        $this->assertSame(ArchitectCommissionService::ESTADO_PENDIENTE_FACTURA, $filaFinal['estado']);
    }

    public function test_b3b_comision_interna_de_una_parcial_cobrada_permanece_pendiente_de_cobro_del_pedido_completo(): void
    {
        ['root' => $root, 'entregas' => $entregas, 'card' => $card] = $this->familia();
        $token = $this->token();
        $this->facturar($token, [$entregas[0]->id], 'entrega')->assertStatus(201);
        $this->marcarCobrada($this->parcialDe($root, $entregas[0]));

        $service = app(CommissionOrderService::class);
        $fila    = $service->ordersForVendor($card->owner_id)->firstWhere('pedido_id', $card->id);

        $this->assertNotNull($fila);
        $this->assertEqualsWithDelta(321.0, $fila['total_facturado'], 0.01, 'Sin fan-out.');
        $this->assertSame(CommissionOrderService::ESTADO_PENDIENTE_COBRO, $fila['estado']);

        $this->facturar($token, [$root->id], 'completo')->assertStatus(201);
        $this->marcarCobrada($this->completoDe($root));

        $service2   = app(CommissionOrderService::class); // instancia nueva: sin el caché de allOrdersCache de la anterior.
        $filaFinal = $service2->ordersForVendor($card->owner_id)->firstWhere('pedido_id', $card->id);
        $this->assertEqualsWithDelta((float) $card->amount, $filaFinal['total_facturado'], 0.01);
        $this->assertContains($filaFinal['estado'], [CommissionOrderService::ESTADO_POR_PAGAR, CommissionOrderService::ESTADO_PAGADO]);
    }

    // ---------------------------------------------------------------------------------------------
    // 6. Gerencia — sin doble conteo con facturas parciales
    // ---------------------------------------------------------------------------------------------

    public function test_b3a_el_panel_de_gerencia_no_cuenta_dos_veces_el_pedido_facturado_en_partes(): void
    {
        // Pedido A (margen 40 %): 1 factura completa. Pedido B (margen 10 %): 3 parciales.
        ['root' => $rootA, 'card' => $cardA] = $this->makePedido([['desc' => 'A', 'qty' => 9, 'price' => 100.00, 'cost' => 60.00]], [[3], [3], [3]]);
        ['root' => $rootB, 'entregas' => $entregasB, 'card' => $cardB] = $this->makePedido([['desc' => 'B', 'qty' => 9, 'price' => 100.00, 'cost' => 90.00]], [[3], [3], [3]]);
        $token = $this->token();
        $this->facturar($token, [$rootA->id], 'completo')->assertStatus(201);
        foreach ($entregasB as $entrega) {
            $this->facturar($token, [$entrega->id], 'entrega')->assertStatus(201);
        }

        $kpis = app(GerenciaHomeService::class)->home([], null)['facturacion']['kpis'];

        $this->assertEqualsWithDelta((float) $cardA->amount + (float) $cardB->amount, $kpis['facturado_mes'], 0.001, 'Σ de montos: cada peso una vez.');
        $this->assertSame(4, $kpis['facturas_count'], 'Cuenta facturas emitidas (1 + 3).');
        // Margen ponderado por COTIZACIÓN (no por factura): (1800 − 1350) / 1800 = 25 %. Si B contara 3 veces daría 17.5 %.
        $this->assertEqualsWithDelta(25.0, $kpis['margen_mes_pct'], 0.01);
        $this->assertNotNull($rootB);
    }
}
