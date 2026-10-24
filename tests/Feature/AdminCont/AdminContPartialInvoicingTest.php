<?php

declare(strict_types=1);

namespace Tests\Feature\AdminCont;

use App\Modules\AdminCont\Models\AdminContFiscalSettings;
use App\Modules\AdminCont\Models\AdminContInvoice;
use App\Modules\AdminCont\Models\AdminContInvoiceLine;
use App\Modules\AdminCont\Models\AdminContItbmsRate;
use App\Modules\AdminCont\Services\Billing\InvoiceBillingPlanner;
use App\Modules\AdminCont\Services\InvoiceService;
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
use Tests\Feature\AdminCont\Concerns\BuildsPartiallyInvoicedOrders;
use Tests\Feature\AdminCont\Concerns\StubsReceptorGuard;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-929 (B2) — facturación por entrega parcial o por pedido completo (ADR-SCRUM929). Cubre los
 * casos negativos 1-11 de la spec: la invariante Σ facturas = total al centavo, el guard
 * anti-duplicado (que el rechazo sea del BACKEND aunque el request esté manipulado), la
 * degradación segura por falta de vínculo a la cotización y la compatibilidad con facturas legadas.
 *
 * Concurrencia: PHPUnit no paraleliza, así que el guard se prueba reproduciendo la SECUENCIA
 * peligrosa (parcial + completo sobre el mismo saldo, en ambos órdenes) más un test que asegura que
 * la transacción bloquea la fila raíz. La prueba real con requests simultáneos es de Pre-QA.
 *
 * Los importes esperados se derivan de la tasa de ITBMS que devuelve `ItbmsRateResolver` (no se
 * hardcodea un 7%) y de precios que dan centavos exactos, así el test no repite la fórmula del
 * planner sino que la verifica contra aritmética simple.
 */
class AdminContPartialInvoicingTest extends FeatureTestCase
{
    use BuildsPartiallyInvoicedOrders;
    use StubsReceptorGuard;

    protected function setUp(): void
    {
        parent::setUp();
        $this->reTenant();
        $this->stubReceptorGuard();
    }

    // ---------------------------------------------------------------------------------------------
    // Invariante de suma (casos 6 y 2) y coherencia con la cotización
    // ---------------------------------------------------------------------------------------------

    public function test_929_el_monto_de_la_tarjeta_coincide_con_el_total_fiscal_de_la_cotizacion(): void
    {
        ['quote' => $quote, 'card' => $card] = $this->pedidoConDescuentos();

        $this->assertEqualsWithDelta($card->amount, InvoiceService::fiscalBreakdown($quote)['total'], 0.001);
    }

    public function test_929_tres_entregas_con_itbms_y_descuentos_suman_el_total_exacto_al_centavo(): void
    {
        ['root' => $root, 'entregas' => $entregas, 'card' => $card] = $this->pedidoConDescuentos();
        $token = $this->token();

        foreach ($entregas as $entrega) {
            $this->facturar($token, [$entrega->id], 'entrega')->assertStatus(201);
        }

        $facturas = $this->activas($root);
        $this->assertCount(3, $facturas);
        $this->assertSame(round((float) $card->amount, 2), $this->sumaActivas($root), 'Σ de las 3 facturas = total de la cotización, al centavo.');

        foreach ($facturas as $factura) {
            $this->assertSame(AdminContInvoice::TIPO_PARCIAL, $factura->tipo);
            $this->assertSame($root->id, $factura->family_root_id);
            $this->assertSame($factura->monto, round((float) $factura->lines()->sum('total'), 2), 'Σ líneas = monto de la factura.');
            $this->assertNotEmpty($factura->lines);
        }
        // Cada entrega quedó facturada por SU order_id.
        $this->assertEqualsCanonicalizing(collect($entregas)->pluck('id')->all(), collect($facturas)->pluck('order_id')->all());

        // Ya no hay nada más que facturar: ni otra entrega, ni el pedido completo.
        $this->facturar($token, [$root->id], 'completo')->assertStatus(422)
            ->assertJsonPath('errors.0.missing.0', 'No queda saldo por facturar en este pedido.');
    }

    public function test_929_el_monto_de_una_entrega_parcial_es_proporcional_a_lo_entregado_con_itbms(): void
    {
        // 10 × 100.00 = 1000.00; entregan 4 → base 400.00 + ITBMS. Quedan 6 sin entregar.
        ['root' => $root, 'entregas' => $entregas] = $this->makePedido(
            [['desc' => 'Panel', 'qty' => 10, 'price' => 100.00]],
            [[4], [3]],
        );
        $token = $this->token();

        $this->facturar($token, [$entregas[0]->id], 'entrega')->assertStatus(201);

        $factura = $this->activas($root)[0];
        $itbms   = round(400.00 * $this->pct() / 100, 2);
        $this->assertSame(round(400.00 + $itbms, 2), $factura->monto);
        $linea = $factura->lines->first();
        $this->assertSame(4.0, $linea->cantidad);
        $this->assertSame(400.00, $linea->subtotal);
        $this->assertSame($itbms, $linea->itbms);
        $this->assertSame(100.00, $linea->precio_unitario);
    }

    public function test_929_pedido_completo_con_parciales_previas_factura_solo_el_restante_y_la_suma_es_el_total(): void
    {
        ['root' => $root, 'entregas' => $entregas, 'card' => $card, 'items' => $items] = $this->pedidoConDescuentos();
        $token = $this->token();

        // Se factura la 2ª entrega (hija). La raíz NO tiene factura propia, así que el completo puede colgar de ella.
        $this->facturar($token, [$entregas[1]->id], 'entrega')->assertStatus(201);
        $parcial = $this->activas($root)[0];

        $this->facturar($token, [$root->id], 'completo')->assertStatus(201);

        $facturas = $this->activas($root);
        $this->assertCount(2, $facturas);
        $completo = collect($facturas)->firstWhere('tipo', AdminContInvoice::TIPO_COMPLETO);
        $this->assertNotNull($completo);
        $this->assertSame($root->id, $completo->order_id, 'El completo cuelga de la raíz.');
        $this->assertSame(round((float) $card->amount - $parcial->monto, 2), $completo->monto, 'Completo = total − lo ya facturado.');
        $this->assertSame(round((float) $card->amount, 2), $this->sumaActivas($root));

        // Por ítem: parcial + completo = cantidad cotizada, nada de más.
        foreach ($items as $item) {
            $facturado = (float) DB::table((new \App\Modules\AdminCont\Models\AdminContInvoiceLine())->getTable())
                ->whereIn('invoice_id', collect($facturas)->pluck('id'))->where('quote_item_id', $item->id)->sum('cantidad');
            $this->assertEqualsWithDelta($item->quantity, $facturado, 0.0005, "Ítem {$item->description}");
        }
        $this->assertSame(round((float) $completo->lines()->sum('total'), 2), $completo->monto);
    }

    public function test_929_pedido_completo_sin_parciales_previas_factura_el_total_de_la_tarjeta(): void
    {
        ['root' => $root, 'card' => $card] = $this->pedidoConDescuentos();

        $this->facturar($this->token(), [$root->id], 'completo')->assertStatus(201);

        $facturas = $this->activas($root);
        $this->assertCount(1, $facturas);
        $this->assertSame(round((float) $card->amount, 2), $facturas[0]->monto);
        $this->assertSame(AdminContInvoice::TIPO_COMPLETO, $facturas[0]->tipo);
        $this->assertSame(round((float) $facturas[0]->lines()->sum('total'), 2), $facturas[0]->monto);
    }

    // ---------------------------------------------------------------------------------------------
    // Guard anti-duplicado (caso 3, 1) — el rechazo es del backend
    // ---------------------------------------------------------------------------------------------

    public function test_929_tras_el_pedido_completo_el_api_rechaza_cualquier_factura_adicional(): void
    {
        ['root' => $root, 'entregas' => $entregas] = $this->pedidoConDescuentos();
        $token = $this->token();

        $this->facturar($token, [$root->id], 'completo')->assertStatus(201);

        // Ni otro completo, ni una parcial de cualquier entrega (request "manipulado": la UI no lo ofrece).
        $this->facturar($token, [$root->id], 'completo')->assertStatus(422)
            ->assertJsonPath('errors.0.missing.0', 'Este pedido ya tiene una factura generada.');
        foreach ($entregas as $entrega) {
            $this->facturar($token, [$entrega->id], 'entrega')->assertStatus(422)
                ->assertJsonPath('errors.0.missing.0', 'Este pedido ya tiene una factura generada.');
        }
        $this->assertCount(1, $this->activas($root));
    }

    public function test_929_secuencia_peligrosa_parcial_y_luego_completo_sobre_el_mismo_saldo_no_cobra_dos_veces(): void
    {
        ['root' => $root, 'entregas' => $entregas, 'card' => $card] = $this->pedidoConDescuentos();
        $token = $this->token();

        // Lo que harían dos requests concurrentes serializados por el lock de la raíz: el segundo ve al primero.
        $this->facturar($token, [$entregas[2]->id], 'entrega')->assertStatus(201);
        $this->facturar($token, [$root->id], 'completo')->assertStatus(201);
        $this->facturar($token, [$entregas[1]->id], 'entrega')->assertStatus(422);

        $this->assertSame(round((float) $card->amount, 2), $this->sumaActivas($root));
    }

    public function test_929_secuencia_peligrosa_completo_y_luego_parcial_se_rechaza_la_parcial(): void
    {
        ['root' => $root, 'entregas' => $entregas] = $this->pedidoConDescuentos();
        $token = $this->token();

        $this->facturar($token, [$root->id], 'completo')->assertStatus(201);
        $this->facturar($token, [$entregas[1]->id], 'entrega')->assertStatus(422);

        $this->assertCount(1, $this->activas($root));
    }

    public function test_929_una_entrega_no_se_factura_dos_veces(): void
    {
        ['root' => $root, 'entregas' => $entregas] = $this->pedidoConDescuentos();
        $token = $this->token();

        $this->facturar($token, [$entregas[1]->id], 'entrega')->assertStatus(201);
        $this->facturar($token, [$entregas[1]->id], 'entrega')->assertStatus(422)
            ->assertJsonPath('errors.0.missing.0', 'Esta entrega ya tiene una factura generada.');

        $this->assertCount(1, $this->activas($root));
    }

    public function test_929_la_transaccion_bloquea_la_fila_raiz_antes_de_leer_o_escribir_facturas(): void
    {
        ['root' => $root, 'entregas' => $entregas] = $this->pedidoConDescuentos();
        $hija  = $entregas[1];
        $token = $this->token();

        $consultas    = [];
        $nivelPrevio  = DB::transactionLevel(); // RefreshDatabase ya envuelve el test en 1 nivel
        DB::listen(function ($q) use (&$consultas): void {
            $consultas[] = ['sql' => strtolower($q->sql), 'bindings' => $q->bindings, 'nivel' => $q->connection->transactionLevel()];
        });
        // Se factura una HIJA: el lock tiene que ir a la RAÍZ, no a la tarjeta que se factura.
        $this->facturar($token, [$hija->id], 'entrega')->assertStatus(201);

        $bloqueo = null;
        foreach ($consultas as $i => $c) {
            if (str_contains($c['sql'], 'for update') && str_contains($c['sql'], '_bodega"."orders"')) {
                $bloqueo = $i;
                $this->assertContains($root->id, $c['bindings'], 'El lock debe ser sobre la fila de la RAÍZ.');
                $this->assertNotContains($hija->id, $c['bindings'], 'No se bloquea la fila de la entrega hija.');
                // N1 — el lock corre DENTRO de la transacción propia de confirm(): si alguien quitara el
                // `DB::transaction()` dejando `lockForUpdate()`, en producción (autocommit) el lock se
                // liberaría al instante aunque acá RefreshDatabase lo mantuviera vivo.
                $this->assertGreaterThan($nivelPrevio, $c['nivel'], 'El lock debe ir dentro de un DB::transaction() propio de confirm().');
                break;
            }
        }
        $this->assertNotNull($bloqueo, 'confirm() debe tomar `select … for update` sobre bodega.orders.');

        // Ninguna lectura ni escritura de facturas/líneas ocurre antes del lock: se decide con el contexto releído bajo lock.
        $primeraDeFacturas = null;
        foreach ($consultas as $i => $c) {
            if (str_contains($c['sql'], '_admin_contab"."invoices"') || str_contains($c['sql'], '_admin_contab"."invoice_lines"')) {
                $primeraDeFacturas = $i;
                break;
            }
        }
        $this->assertNotNull($primeraDeFacturas);
        $this->assertLessThan($primeraDeFacturas, $bloqueo, 'El lock debe ocurrir ANTES de leer facturas/líneas o insertar la factura.');
        $insert = array_keys(array_filter($consultas, fn (array $c): bool => str_starts_with($c['sql'], 'insert into') && str_contains($c['sql'], '_admin_contab"."invoices"')));
        $this->assertNotEmpty($insert);
        $this->assertLessThan($insert[0], $bloqueo);
    }

    public function test_929_una_entrega_que_no_llego_a_por_despachar_no_se_factura(): void
    {
        ['root' => $root, 'entregas' => $entregas] = $this->makePedido(
            [['desc' => 'Panel', 'qty' => 6, 'price' => 50.00]],
            [[3], [3]],
            stages: [1 => Order::STAGE_PACKING],
        );

        $this->facturar($this->token(), [$entregas[1]->id], 'entrega')->assertStatus(422)
            ->assertJsonPath('errors.0.missing.0', 'Esta entrega todavía no llegó a la etapa "Por despachar" de Bodega.');
        $this->assertSame([], $this->activas($root));
    }

    public function test_929_una_entrega_sin_cantidades_entregadas_no_se_factura(): void
    {
        ['root' => $root, 'entregas' => $entregas] = $this->makePedido(
            [['desc' => 'Panel', 'qty' => 6, 'price' => 50.00]],
            [[3], [0]],
        );
        // La 2ª entrega existe y está en Por Despachar pero sin ítems entregados (todo quedó como faltante).
        $token = $this->token();

        $this->facturar($token, [$entregas[1]->id], 'entrega')->assertStatus(422)
            ->assertJsonPath('errors.0.missing.0', 'Esta entrega no tiene cantidades entregadas para facturar.');
        $this->assertSame([], $this->activas($root));
    }

    public function test_929_el_tope_por_item_rechaza_una_entrega_que_excede_lo_cotizado(): void
    {
        // Caso legado (SCRUM-909 sin asignación): cada tarjeta nació con TODOS los ítems, 5 y 5 de una
        // cotización de 5. Facturar la 1ª cubre todo lo cotizado; la 2ª excedería el ítem.
        ['root' => $root, 'entregas' => $entregas, 'card' => $card] = $this->makePedido(
            [['desc' => 'Panel', 'qty' => 5, 'price' => 80.00]],
            [[5], [5]],
        );
        $token = $this->token();

        $this->facturar($token, [$entregas[0]->id], 'entrega')->assertStatus(201);
        $r = $this->facturar($token, [$entregas[1]->id], 'entrega')->assertStatus(422);

        $this->assertStringContainsString('excedería lo cotizado', $r->json('errors.0.missing.0'));
        $this->assertSame(round((float) $card->amount, 2), $this->sumaActivas($root), 'Nunca se factura más que el total.');
    }

    /**
     * F5 — guard (4) AISLADO: el MONTO de la 2ª entrega sí cabe en el saldo, pero el ítem A se pasa
     * (5 ya facturadas + 2 > 5). Sin el guard de tope por ítem, esta factura se emitiría.
     */
    public function test_929_el_tope_por_item_rechaza_aunque_el_monto_alcance_y_por_ese_motivo(): void
    {
        ['root' => $root, 'entregas' => $entregas] = $this->makePedido(
            [['desc' => 'Lámpara A', 'qty' => 5, 'price' => 100.00], ['desc' => 'Lámpara B', 'qty' => 5, 'price' => 100.00]],
            [[5, 0], [2, 3]],
        );
        $token = $this->token();

        $this->facturar($token, [$entregas[0]->id], 'entrega')->assertStatus(201);
        $r = $this->facturar($token, [$entregas[1]->id], 'entrega')->assertStatus(422);

        $mensaje = $r->json('errors.0.missing.0');
        $this->assertStringContainsString('El ítem "Lámpara A" excedería lo cotizado', $mensaje);
        $this->assertStringContainsString('7 de 5', $mensaje);
        $this->assertStringNotContainsString('saldo', $mensaje, 'Rechazo por el tope del ítem, no por saldo.');
        $this->assertCount(1, $this->activas($root));
    }

    /**
     * F5 — guard (3) AISLADO: cantidades dentro de lo cotizado pero el monto excede el saldo (la tasa de
     * ITBMS subió después de aprobar: la 1ª entrega ya cobró 780 de 1070 y la 2ª pide 416 > 290).
     */
    public function test_929_el_monto_que_supera_el_saldo_se_rechaza_con_su_propio_mensaje(): void
    {
        ['root' => $root, 'entregas' => $entregas, 'card' => $card] = $this->makePedido(
            [['desc' => 'Lámpara A', 'qty' => 6, 'price' => 100.00], ['desc' => 'Lámpara B', 'qty' => 5, 'price' => 80.00]],
            [[6, 0], [0, 4]],
        );
        $this->assertEqualsWithDelta(1070.0, (float) $card->amount, 0.001, 'Fixture: 1000 + 7 % ITBMS.');
        $this->cambiarTasaDeItbms(30.0);
        $token = $this->token();

        $this->facturar($token, [$entregas[0]->id], 'entrega')->assertStatus(201);
        $this->assertSame(780.00, $this->activas($root)[0]->monto);

        $r = $this->facturar($token, [$entregas[1]->id], 'entrega')->assertStatus(422);
        $this->assertSame(
            'El monto de esta factura (416.00) supera el saldo por facturar del pedido (290.00).',
            $r->json('errors.0.missing.0'),
        );
        $this->assertCount(1, $this->activas($root));
    }

    public function test_929_pedido_completo_cuando_ya_esta_todo_lo_cotizado_facturado_se_rechaza_con_su_mensaje(): void
    {
        ['root' => $root, 'entregas' => $entregas, 'card' => $card, 'items' => $items] = $this->makePedido(
            [['desc' => 'Panel', 'qty' => 6, 'price' => 100.00]],
            [[3], [3]],
        );
        // Factura previa (dato anómalo: cubre TODA la cantidad pero solo cobró 100) construida a mano.
        $previa = AdminContInvoice::create([
            'numero' => 'F-PREV-'.self::uniqueTestNumber(), 'order_id' => $entregas[1]->id, 'tipo' => AdminContInvoice::TIPO_PARCIAL,
            'cliente' => 'X', 'monto' => 100, 'saldo_aplicado' => 0,
        ]);
        $previa->lines()->create([
            'quote_item_id' => $items[0]->id, 'descripcion' => 'Panel', 'cantidad' => 6, 'precio_unitario' => 100,
            'subtotal' => 100, 'itbms' => 0, 'total' => 100,
        ]);
        $this->assertGreaterThan(100.0, (float) $card->amount);

        $this->facturar($this->token(), [$root->id], 'completo')->assertStatus(422)
            ->assertJsonPath('errors.0.missing.0', 'Todas las cantidades de la cotización ya están facturadas.');
        $this->assertCount(1, $this->activas($root));
    }

    // ---------------------------------------------------------------------------------------------
    // Entrega única y compatibilidad de request (caso 4)
    // ---------------------------------------------------------------------------------------------

    public function test_929_entrega_unica_sin_modo_genera_una_factura_por_el_total_como_siempre(): void
    {
        ['root' => $root, 'card' => $card] = $this->makePedido(
            [['desc' => 'Panel', 'qty' => 5, 'price' => 80.00], ['desc' => 'Driver', 'qty' => 2, 'price' => 30.00]],
            [[5, 2]],
        );

        $response = $this->facturar($this->token(), [$root->id])->assertStatus(201);

        $this->assertCount(1, $response->json('invoices'));
        $this->assertEqualsWithDelta((float) $card->amount, (float) $response->json('invoices.0.monto'), 0.001);
        $factura = $this->activas($root)[0];
        $this->assertSame(AdminContInvoice::TIPO_COMPLETO, $factura->tipo);
        $this->assertSame($root->id, $factura->order_id);
        $this->assertCount(2, $factura->lines, 'Todos los productos en una sola factura.');
    }

    public function test_929_entrega_unica_con_modo_entrega_se_trata_como_pedido_completo(): void
    {
        ['root' => $root, 'card' => $card] = $this->makePedido([['desc' => 'Panel', 'qty' => 5, 'price' => 80.00]], [[5]]);

        $this->facturar($this->token(), [$root->id], 'entrega')->assertStatus(201);

        $factura = $this->activas($root)[0];
        $this->assertSame(AdminContInvoice::TIPO_COMPLETO, $factura->tipo);
        $this->assertSame(round((float) $card->amount, 2), $factura->monto);
    }

    public function test_929_request_sin_modo_sobre_un_pedido_con_entregas_factura_el_pedido_completo_como_en_876(): void
    {
        ['root' => $root, 'entregas' => $entregas, 'card' => $card] = $this->pedidoConDescuentos();

        // Frontend anterior a SCRUM-929: solo `order_ids` (incluso con la id de una entrega hermana).
        $response = $this->facturar($this->token(), [$entregas[1]->id])->assertStatus(201);

        $this->assertCount(1, $response->json('invoices'));
        $factura = $this->activas($root)[0];
        $this->assertSame(AdminContInvoice::TIPO_COMPLETO, $factura->tipo);
        $this->assertSame($root->id, $factura->order_id);
        $this->assertSame(round((float) $card->amount, 2), $factura->monto);
    }

    public function test_929_un_modo_invalido_lo_rechaza_la_validacion(): void
    {
        ['root' => $root] = $this->pedidoConDescuentos();

        $this->facturar($this->token(), [$root->id], 'a_la_mitad')->assertStatus(422);
        $this->assertSame([], $this->activas($root));
    }

    // ---------------------------------------------------------------------------------------------
    // Anulación libera saldo (caso 5)
    // ---------------------------------------------------------------------------------------------

    public function test_929_anular_una_factura_parcial_libera_el_saldo_de_esa_entrega(): void
    {
        ['root' => $root, 'entregas' => $entregas, 'card' => $card] = $this->pedidoConDescuentos();
        $token = $this->token();

        $this->facturar($token, [$entregas[1]->id], 'entrega')->assertStatus(201);
        $primera = $this->activas($root)[0];

        AdminContInvoice::whereKey($primera->id)->update(['anulada_at' => now()]);

        $this->assertSame([], $this->activas($root));
        $this->facturar($token, [$entregas[1]->id], 'entrega')->assertStatus(201);
        $this->facturar($token, [$root->id], 'completo')->assertStatus(201);
        $this->assertSame(round((float) $card->amount, 2), $this->sumaActivas($root), 'La anulada no cuenta: Σ activas = total.');
    }

    public function test_929_anular_el_pedido_completo_permite_volver_a_facturar_por_entrega(): void
    {
        ['root' => $root, 'entregas' => $entregas] = $this->pedidoConDescuentos();
        $token = $this->token();

        $this->facturar($token, [$root->id], 'completo')->assertStatus(201);
        AdminContInvoice::whereKey($this->activas($root)[0]->id)->update(['anulada_at' => now()]);

        $this->facturar($token, [$entregas[1]->id], 'entrega')->assertStatus(201);
        $this->assertCount(1, $this->activas($root));
    }

    // ---------------------------------------------------------------------------------------------
    // Faltante / Revisión de Inventario / división mixta (caso 9)
    // ---------------------------------------------------------------------------------------------

    public function test_929_la_entrega_factura_solo_lo_entregado_y_el_remanente_se_factura_con_su_propia_entrega(): void
    {
        // Cotizado 10; la raíz alistó 10 pero entregó 6 → tarjeta de Faltante (4) en la misma familia.
        $data = $this->makePedido([['desc' => 'Panel', 'qty' => 10, 'price' => 100.00]], [[6], [4]], stages: [1 => Order::STAGE_ASIGNADO]);
        ['root' => $root, 'entregas' => $entregas, 'card' => $card] = $data;
        $faltante = $entregas[1];
        $faltante->update(['order_type' => Order::ORDER_TYPE_FALTANTE]);
        $faltante->items()->update(['qty_delivered' => 0]); // todavía no se entrega
        $token = $this->token();

        // La raíz factura 6 de 10 (600.00 + ITBMS), NO el total.
        $this->facturar($token, [$root->id], 'entrega')->assertStatus(201);
        $parcial = $this->activas($root)[0];
        $this->assertSame(round(600.00 + round(600.00 * $this->pct() / 100, 2), 2), $parcial->monto);
        $this->assertLessThan((float) $card->amount, $parcial->monto);

        // El faltante todavía no llegó a Por Despachar: no se factura.
        $this->facturar($token, [$faltante->id], 'entrega')->assertStatus(422);

        // Cuando se despacha (4 unidades), su factura cierra el pedido y la suma da el total exacto.
        $faltante->update(['stage' => Order::STAGE_POR_DESPACHAR]);
        $faltante->items()->update(['qty_delivered' => 4]);
        $this->facturar($token, [$faltante->id], 'entrega')->assertStatus(201);

        $this->assertSame(round((float) $card->amount, 2), $this->sumaActivas($root));
    }

    public function test_929_si_el_remanente_nunca_se_despacha_no_se_cobra(): void
    {
        ['root' => $root, 'card' => $card] = $this->makePedido(
            [['desc' => 'Panel', 'qty' => 10, 'price' => 100.00]],
            [[6], [4]],
            stages: [1 => Order::STAGE_ASIGNADO],
        );

        $this->facturar($this->token(), [$root->id], 'entrega')->assertStatus(201);

        $this->assertLessThan((float) $card->amount, $this->sumaActivas($root), 'Sin residual sobre mercadería que no salió.');
        $this->assertSame(round(600.00 + round(600.00 * $this->pct() / 100, 2), 2), $this->sumaActivas($root));
    }

    public function test_929_un_item_partido_en_dos_tarjetas_con_el_mismo_quote_item_id_suma_lo_cotizado(): void
    {
        // La división "mixta" se arma a mano (la cobertura de `InventoryReviewService` y del vínculo está
        // en los tests de B1a): la mitad de un ítem queda en la tarjeta original y la otra mitad en la
        // nueva, otro `order_item` con el mismo `quote_item_id`. Σ facturado = lo cotizado.
        ['root' => $root, 'entregas' => $entregas, 'card' => $card] = $this->makePedido(
            [['desc' => 'Panel', 'qty' => 10, 'price' => 100.00], ['desc' => 'Driver', 'qty' => 4, 'price' => 25.00]],
            [[5, 4], [5, 0]],
        );
        $token = $this->token();

        $this->facturar($token, [$entregas[0]->id], 'entrega')->assertStatus(201);
        $this->facturar($token, [$entregas[1]->id], 'entrega')->assertStatus(201);

        $this->assertSame(round((float) $card->amount, 2), $this->sumaActivas($root));
        $panel = AdminContInvoiceLine::whereIn('invoice_id', collect($this->activas($root))->pluck('id'))->where('descripcion', 'Panel')->get();
        $this->assertSame(10.0, round((float) $panel->sum('cantidad'), 3));
    }

    // ---------------------------------------------------------------------------------------------
    // Degradación segura (caso 10) y líneas por ítem (caso 11)
    // ---------------------------------------------------------------------------------------------

    public function test_929_un_pedido_sin_vinculo_a_la_cotizacion_solo_admite_pedido_completo(): void
    {
        ['root' => $root, 'entregas' => $entregas, 'card' => $card] = $this->makePedido(
            [['desc' => 'Panel', 'qty' => 6, 'price' => 100.00]],
            [[3], [3]],
            vincular: false,
        );
        $token = $this->token();

        // La API rechaza la facturación por entrega aunque se fuerce el request.
        $this->facturar($token, [$entregas[1]->id], 'entrega')->assertStatus(422)
            ->assertJsonPath('errors.0.missing.0', 'Este pedido solo admite facturación de pedido completo: sus productos no están vinculados a la cotización.');

        // El listado lo refleja: una fila anclada a la raíz, sin opción por entrega.
        $rows = $this->api($token, 'GET', '/api/admin-contab/invoices?view=plana')->json('rows');
        $fila = collect($rows)->where('pedido_id', $root->id)->values();
        $this->assertCount(1, $fila);
        $this->assertFalse($fila[0]['facturacion_por_entrega']);
        $this->assertFalse($fila[0]['puede_facturar_entrega']);
        $this->assertTrue($fila[0]['puede_facturar_completo']);

        // El completo sí funciona: monto = total de la tarjeta, con líneas desde la cotización.
        $this->facturar($token, [$root->id], 'completo')->assertStatus(201);
        $factura = $this->activas($root)[0];
        $this->assertSame(round((float) $card->amount, 2), $factura->monto);
        $this->assertNotEmpty($factura->lines);
    }

    public function test_929_producto_repetido_en_dos_partes_e_item_personalizado_no_se_mezclan(): void
    {
        ['root' => $root, 'entregas' => $entregas, 'card' => $card, 'items' => $items] = $this->makePedido(
            [
                ['desc' => 'Luminaria X', 'qty' => 4, 'price' => 50.00, 'part' => 1, 'custom' => false],
                ['desc' => 'Luminaria X', 'qty' => 6, 'price' => 70.00, 'part' => 2, 'custom' => false],
                ['desc' => 'Pieza a medida', 'qty' => 2, 'price' => 200.00, 'part' => 2, 'custom' => true],
            ],
            [[4, 2, 2], [0, 4, 0]],
        );
        $token = $this->token();

        $this->facturar($token, [$entregas[0]->id], 'entrega')->assertStatus(201);
        $this->facturar($token, [$entregas[1]->id], 'entrega')->assertStatus(201);

        $lineas = \App\Modules\AdminCont\Models\AdminContInvoiceLine::whereIn('invoice_id', collect($this->activas($root))->pluck('id'))->get();
        // Cada línea contra SU ítem: la de 50.00 y la de 70.00 de "Luminaria X" no se suman entre sí.
        $porItem = $lineas->groupBy('quote_item_id');
        $this->assertCount(3, $porItem);
        $x1 = $porItem[$items[0]->id];
        $x2 = $porItem[$items[1]->id];
        $this->assertSame(200.00, round((float) $x1->sum('subtotal'), 2)); // 4 × 50
        $this->assertSame(420.00, round((float) $x2->sum('subtotal'), 2)); // 6 × 70
        $this->assertSame(400.00, round((float) $porItem[$items[2]->id]->sum('subtotal'), 2)); // 2 × 200
        $this->assertSame(round((float) $card->amount, 2), $this->sumaActivas($root));
    }

    // ---------------------------------------------------------------------------------------------
    // La raíz puede tener a la vez su parcial y el completo por el resto (D-2; índice (order_id, tipo))
    // ---------------------------------------------------------------------------------------------

    public function test_929_facturar_la_raiz_como_parcial_y_luego_pedido_completo_factura_solo_el_restante(): void
    {
        ['root' => $root, 'entregas' => $entregas, 'card' => $card, 'items' => $items] = $this->pedidoConDescuentos();
        $token = $this->token();

        $this->facturar($token, [$entregas[0]->id], 'entrega')->assertStatus(201);
        $parcial = $this->activas($root)[0];

        // El completo está disponible y el preview ofrece exactamente el restante.
        $fila = collect($this->api($token, 'GET', '/api/admin-contab/invoices?view=plana')->json('rows'))->firstWhere('order_id', $entregas[1]->id);
        $this->assertTrue($fila['puede_facturar_completo']);
        $this->assertNull($fila['motivo_completo_no_disponible']);
        $this->assertEqualsWithDelta((float) $card->amount - $parcial->monto, (float) $fila['restante'], 0.001);
        $preview = $this->api($token, 'POST', '/api/admin-contab/invoices/preview', ['order_ids' => [$root->id], 'modo' => 'completo'])->json('results.0');
        $this->assertTrue($preview['ok']);
        $this->assertEqualsWithDelta((float) $card->amount - $parcial->monto, (float) $preview['monto'], 0.001);

        $this->facturar($token, [$root->id], 'completo')->assertStatus(201);

        $facturas = $this->activas($root);
        $this->assertCount(2, $facturas);
        $parcial  = collect($facturas)->firstWhere('tipo', AdminContInvoice::TIPO_PARCIAL);
        $completo = collect($facturas)->firstWhere('tipo', AdminContInvoice::TIPO_COMPLETO);
        // Ambas cuelgan de la misma tarjeta (la raíz) y conviven a nivel BD.
        $this->assertSame($root->id, $parcial->order_id);
        $this->assertSame($root->id, $completo->order_id);
        $this->assertSame($root->id, $parcial->family_root_id);
        $this->assertSame($root->id, $completo->family_root_id);
        $this->assertSame(round((float) $card->amount - $parcial->monto, 2), $completo->monto, 'Completo = total − la parcial de la raíz.');
        $this->assertSame(round((float) $card->amount, 2), $this->sumaActivas($root), 'Σ = card->amount al centavo.');
        $this->assertSame(round((float) $completo->lines()->sum('total'), 2), $completo->monto);

        // Por ítem: parcial + completo = lo cotizado, ni más ni menos.
        foreach ($items as $item) {
            $facturado = (float) DB::table((new \App\Modules\AdminCont\Models\AdminContInvoiceLine())->getTable())
                ->whereIn('invoice_id', collect($facturas)->pluck('id'))->where('quote_item_id', $item->id)->sum('cantidad');
            $this->assertEqualsWithDelta($item->quantity, $facturado, 0.0005, "Ítem {$item->description}");
        }

        // (e) Con el completo emitido, el API rechaza cualquier factura adicional, incluida una parcial
        // sobre la propia raíz, aunque se fuerce el request.
        foreach ($entregas as $entrega) {
            $this->facturar($token, [$entrega->id], 'entrega')->assertStatus(422);
        }
        $this->facturar($token, [$root->id], 'completo')->assertStatus(422)
            ->assertJsonPath('errors.0.missing.0', 'Este pedido ya tiene una factura generada.');
        $this->assertCount(2, $this->activas($root));
        $this->assertSame(round((float) $card->amount, 2), $this->sumaActivas($root));

        // Listado: la raíz lleva una fila por cada factura (parcial y completo); las demás entregas,
        // cubiertas por el completo, no repiten fila.
        $rows = collect($this->api($token, 'GET', '/api/admin-contab/invoices?view=plana')->json('rows'))->where('pedido_id', $root->id)->values();
        $this->assertCount(2, $rows);
        $this->assertEqualsCanonicalizing([$parcial->id, $completo->id], $rows->pluck('invoice_id')->all());
        $this->assertSame([$root->id], $rows->pluck('order_id')->unique()->values()->all());
        $this->assertEqualsCanonicalizing(['parcial', 'completo'], $rows->pluck('tipo_factura')->all());

        // Detalle de la raíz: sin `invoice_id` la parcial de la entrega; con él, la que se pida.
        $porDefecto = $this->api($token, 'GET', "/api/admin-contab/invoices/{$root->id}/detail")->assertStatus(200)->json();
        $this->assertSame($parcial->numero, $porDefecto['numero_factura']);
        $delCompleto = $this->api($token, 'GET', "/api/admin-contab/invoices/{$root->id}/detail?invoice_id={$completo->id}")->assertStatus(200)->json();
        $this->assertSame($completo->numero, $delCompleto['numero_factura']);
        $this->assertSame('completo', $delCompleto['tipo_factura']);
        $this->assertEqualsWithDelta($completo->monto, (float) $delCompleto['monto'], 0.001);
        $this->api($token, 'GET', "/api/admin-contab/invoices/{$root->id}/pdf?invoice_id={$completo->id}")->assertStatus(200)->assertHeader('Content-Type', 'application/pdf');

        // Una factura de OTRO pedido no se puede pedir por esta entrega.
        ['root' => $otra] = $this->makePedido([['desc' => 'Panel', 'qty' => 2, 'price' => 10.00]], [[2]]);
        $this->facturar($token, [$otra->id])->assertStatus(201);
        $ajena = $this->activas($otra)[0];
        $this->api($token, 'GET', "/api/admin-contab/invoices/{$root->id}/detail?invoice_id={$ajena->id}")->assertStatus(404);
    }

    public function test_929_facturar_una_entrega_no_raiz_y_luego_pedido_completo_sigue_funcionando(): void
    {
        ['root' => $root, 'entregas' => $entregas, 'card' => $card] = $this->pedidoConDescuentos();
        $token = $this->token();

        $this->facturar($token, [$entregas[2]->id], 'entrega')->assertStatus(201);
        $this->facturar($token, [$root->id], 'completo')->assertStatus(201);

        $facturas = $this->activas($root);
        $parcial  = collect($facturas)->firstWhere('tipo', AdminContInvoice::TIPO_PARCIAL);
        $completo = collect($facturas)->firstWhere('tipo', AdminContInvoice::TIPO_COMPLETO);
        $this->assertSame($entregas[2]->id, $parcial->order_id);
        $this->assertSame($root->id, $completo->order_id);
        $this->assertSame(round((float) $card->amount, 2), $this->sumaActivas($root));
        $this->assertSame(round((float) $card->amount - $parcial->monto, 2), $completo->monto);
    }

    public function test_929_dos_parciales_de_la_raiz_y_de_otra_entrega_mas_el_completo_suman_el_total(): void
    {
        ['root' => $root, 'entregas' => $entregas, 'card' => $card] = $this->pedidoConDescuentos();
        $token = $this->token();

        $this->facturar($token, [$entregas[0]->id], 'entrega')->assertStatus(201);
        $this->facturar($token, [$entregas[1]->id], 'entrega')->assertStatus(201);
        $this->facturar($token, [$root->id], 'completo')->assertStatus(201);

        $this->assertCount(3, $this->activas($root));
        $this->assertSame(round((float) $card->amount, 2), $this->sumaActivas($root));
        // Ya no queda nada por facturar en la 3ª entrega.
        $this->facturar($token, [$entregas[2]->id], 'entrega')->assertStatus(422);
    }

    // ---------------------------------------------------------------------------------------------
    // Contrato de lectura: listado, summary, preview, detail
    // ---------------------------------------------------------------------------------------------

    public function test_929_listado_una_fila_por_entrega_facturable_con_estado_y_acciones(): void
    {
        // Familia de 3: 1ª ya facturada (parcial), 2ª pendiente, 3ª todavía en Packing.
        ['root' => $root, 'entregas' => $entregas, 'card' => $card] = $this->makePedido(
            [['desc' => 'Panel', 'qty' => 9, 'price' => 100.00]],
            [[3], [3], [3]],
            stages: [2 => Order::STAGE_PACKING],
        );
        $token = $this->token();
        $this->facturar($token, [$entregas[0]->id], 'entrega')->assertStatus(201);

        $rows = collect($this->api($token, 'GET', '/api/admin-contab/invoices?view=plana')->json('rows'))->where('pedido_id', $root->id)->values();

        $this->assertCount(2, $rows, 'Una fila por entrega facturable; la 3ª (Packing) todavía no aparece.');
        $facturada = $rows->firstWhere('order_id', $entregas[0]->id);
        $pendiente = $rows->firstWhere('order_id', $entregas[1]->id);

        $this->assertSame('facturada', $facturada['estado']);
        $this->assertSame('parcial', $facturada['tipo_factura']);
        $this->assertNotNull($facturada['numero_factura']);
        $this->assertFalse($facturada['puede_facturar_entrega']);

        $this->assertSame('pendiente-facturar', $pendiente['estado']);
        $this->assertNull($pendiente['tipo_factura']);
        $this->assertTrue($pendiente['puede_facturar_entrega']);
        $this->assertEqualsWithDelta(round(300.00 + round(300.00 * $this->pct() / 100, 2), 2), (float) $pendiente['monto'], 0.001);

        // Nivel pedido, repetido en cada fila.
        foreach ($rows as $fila) {
            $this->assertTrue($fila['facturacion_por_entrega']);
            $this->assertFalse($fila['es_entrega_unica']);
            $this->assertEqualsWithDelta((float) $card->amount, (float) $fila['monto_total_pedido'], 0.001);
            $this->assertEqualsWithDelta((float) $card->amount - (float) $facturada['monto'], (float) $fila['restante'], 0.001);
            $this->assertCount(3, $fila['entregas_del_pedido']);
        }
        // La raíz ya tiene su parcial, pero el completo por el resto sigue disponible.
        $this->assertTrue($pendiente['puede_facturar_completo']);
    }

    public function test_929_listado_tras_un_completo_muestra_una_sola_fila_anclada_a_la_raiz(): void
    {
        ['root' => $root, 'entregas' => $entregas] = $this->pedidoConDescuentos();
        $token = $this->token();
        $this->facturar($token, [$root->id], 'completo')->assertStatus(201);

        $rows = collect($this->api($token, 'GET', '/api/admin-contab/invoices?view=plana')->json('rows'))->where('pedido_id', $root->id)->values();

        $this->assertCount(1, $rows, 'Las entregas cubiertas por el completo no repiten la factura.');
        $this->assertSame($root->id, $rows[0]['order_id']);
        $this->assertSame('completo', $rows[0]['tipo_factura']);
        $this->assertFalse($rows[0]['puede_facturar_completo']);
        $this->assertFalse($rows[0]['puede_facturar_entrega']);
        $this->assertEquals(0.0, $rows[0]['restante']);
        $this->assertCount(3, $rows[0]['entregas_del_pedido']);
        $this->assertTrue($rows[0]['entregas_del_pedido'][1]['cubierta_por_completo']);
    }

    public function test_929_el_export_distingue_cada_entrega_del_pedido_en_su_etiqueta(): void
    {
        ['root' => $root] = $this->makePedido([['desc' => 'Panel', 'qty' => 6, 'price' => 100.00]], [[3], [3]]);
        $token = $this->token();

        $response = $this->withHeaders($this->authHeaders($token))->get('/api/admin-contab/invoices/export?format=excel&estado=pendiente-facturar');
        $response->assertStatus(200);
        $this->reTenant();

        $tmp = tempnam(sys_get_temp_dir(), 'inv_export_');
        file_put_contents((string) $tmp, $response->streamedContent());
        $sheet = \PhpOffice\PhpSpreadsheet\IOFactory::load((string) $tmp)->getActiveSheet();
        @unlink((string) $tmp);

        $etiquetas = [$sheet->getCell('A2')->getValue(), $sheet->getCell('A3')->getValue()];
        $this->assertContains("{$root->order_number} (1/2)", $etiquetas);
        $this->assertContains("{$root->order_number} (2/2)", $etiquetas);
    }

    public function test_929_summary_cuenta_pendientes_por_entrega_facturable(): void
    {
        $this->makePedido([['desc' => 'Panel', 'qty' => 9, 'price' => 100.00]], [[3], [3], [3]], stages: [2 => Order::STAGE_PACKING]);
        $token = $this->token();

        $summary = $this->api($token, 'GET', '/api/admin-contab/invoices/summary')->json();

        $this->assertSame(2, $summary['pendientes_facturar'], 'Las 2 entregas facturables; la de Packing no cuenta.');
    }

    public function test_929_preview_por_entrega_y_por_pedido_completo_devuelven_el_desglose_de_lo_que_se_facturaria(): void
    {
        ['root' => $root, 'entregas' => $entregas, 'card' => $card] = $this->pedidoConDescuentos();
        $token = $this->token();

        $porEntrega = $this->api($token, 'POST', '/api/admin-contab/invoices/preview', ['order_ids' => [$entregas[1]->id], 'modo' => 'entrega'])
            ->assertStatus(200)->json('results.0');
        $this->assertTrue($porEntrega['ok']);
        $this->assertSame('entrega', $porEntrega['modo']);
        $this->assertSame('parcial', $porEntrega['tipo']);
        $this->assertSame($entregas[1]->id, $porEntrega['order_id']);
        $this->assertFalse($porEntrega['cierra_pedido']);
        $this->assertLessThan((float) $card->amount, (float) $porEntrega['monto']);
        $this->assertEqualsWithDelta((float) $porEntrega['monto'], (float) $porEntrega['total'], 0.001);
        $this->assertEqualsWithDelta((float) $porEntrega['total'], $porEntrega['subtotal'] - $porEntrega['descuentos'] + $porEntrega['itbms'], 0.011);
        $this->assertNotEmpty($porEntrega['items']);

        $completo = $this->api($token, 'POST', '/api/admin-contab/invoices/preview', ['order_ids' => [$entregas[1]->id], 'modo' => 'completo'])
            ->assertStatus(200)->json('results.0');
        $this->assertTrue($completo['ok']);
        $this->assertSame($root->id, $completo['order_id'], 'Completo se normaliza a la raíz.');
        $this->assertTrue($completo['cierra_pedido']);
        $this->assertEqualsWithDelta((float) $card->amount, (float) $completo['monto'], 0.001);
        $this->assertEqualsWithDelta((float) $card->amount, $completo['subtotal'] - $completo['descuentos'] + $completo['itbms'], 0.011);
        // El preview no persiste nada.
        $this->assertSame([], $this->activas($root));
    }

    public function test_929_preview_de_un_pedido_ya_facturado_lo_reporta_como_no_ok(): void
    {
        ['root' => $root] = $this->pedidoConDescuentos();
        $token = $this->token();
        $this->facturar($token, [$root->id], 'completo')->assertStatus(201);

        $r = $this->api($token, 'POST', '/api/admin-contab/invoices/preview', ['order_ids' => [$root->id], 'modo' => 'completo'])->assertStatus(200);

        $this->assertFalse($r->json('results.0.ok'));
        $this->assertContains('Este pedido ya tiene una factura generada.', $r->json('results.0.missing'));
    }

    public function test_929_detail_de_una_entrega_devuelve_su_factura_parcial_y_el_pdf_se_arma_desde_las_lineas(): void
    {
        ['root' => $root, 'entregas' => $entregas] = $this->pedidoConDescuentos();
        $token = $this->token();
        $this->facturar($token, [$entregas[1]->id], 'entrega')->assertStatus(201);
        $factura = $this->activas($root)[0];

        $detail = $this->api($token, 'GET', "/api/admin-contab/invoices/{$entregas[1]->id}/detail")->assertStatus(200)->json();

        $this->assertSame($factura->numero, $detail['numero_factura']);
        $this->assertSame('parcial', $detail['tipo_factura']);
        $this->assertSame($entregas[1]->id, $detail['order_id']);
        $this->assertSame('Entrega 2 de 3', $detail['concepto']);
        $this->assertEqualsWithDelta($factura->monto, (float) $detail['monto'], 0.001, 'El monto del detalle es el de ESA factura, no el total del pedido.');
        $this->assertEqualsWithDelta($factura->monto, (float) $detail['total_a_pagar'], 0.001);
        $this->assertEqualsWithDelta($factura->monto, (float) $detail['total'], 0.001, 'El desglose sale de las líneas facturadas.');
        $this->assertCount(3, $detail['items']);
        $this->assertLessThan(10.0, (float) $detail['items'][0]['cantidad']);

        // Una entrega hermana sin factura propia figura como pendiente.
        $otra = $this->api($token, 'GET', "/api/admin-contab/invoices/{$entregas[2]->id}/detail")->assertStatus(200)->json();
        $this->assertSame('pendiente-facturar', $otra['estado']);
        $this->assertNull($otra['numero_factura']);

        $this->api($token, 'GET', "/api/admin-contab/invoices/{$entregas[1]->id}/pdf")->assertStatus(200)->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_929_el_pdf_de_una_factura_con_lineas_se_renderiza_desde_lo_facturado(): void
    {
        ['root' => $root, 'entregas' => $entregas] = $this->makePedido(
            [['desc' => 'Panel LED 60x60', 'qty' => 10, 'price' => 100.00], ['desc' => 'Driver 40W', 'qty' => 4, 'price' => 25.00]],
            [[4, 0], [6, 4]],
        );
        $token = $this->token();
        $this->facturar($token, [$entregas[0]->id], 'entrega')->assertStatus(201);
        $factura = $this->activas($root)[0];

        $detail = app(InvoiceService::class)->detail($entregas[0]->id);
        $html   = view('admin-cont.invoice-pdf', app(\App\Modules\AdminCont\Services\InvoicePdfService::class)->viewData($detail))->render();

        $this->assertStringContainsString($factura->numero, $html);
        $this->assertStringContainsString('Entrega 1 de 2', $html);
        $this->assertStringContainsString('Panel LED 60x60', $html);
        $this->assertStringNotContainsString('Driver 40W', $html, 'Solo lo facturado en ESTA factura, no toda la cotización.');
        $this->assertStringContainsString(number_format($factura->monto, 2), $html);
    }

    public function test_929_detail_de_cualquier_entrega_cubierta_por_un_completo_muestra_esa_factura(): void
    {
        ['root' => $root, 'entregas' => $entregas] = $this->pedidoConDescuentos();
        $token = $this->token();
        $this->facturar($token, [$root->id], 'completo')->assertStatus(201);
        $factura = $this->activas($root)[0];

        $detail = $this->api($token, 'GET', "/api/admin-contab/invoices/{$entregas[2]->id}/detail")->assertStatus(200)->json();

        $this->assertSame($factura->numero, $detail['numero_factura']);
        $this->assertSame('completo', $detail['tipo_factura']);
        $this->assertSame('Pedido completo', $detail['concepto']);
    }

    // ---------------------------------------------------------------------------------------------
    // Compatibilidad con facturas legadas (sin líneas)
    // ---------------------------------------------------------------------------------------------

    public function test_929_una_factura_legada_sin_lineas_sigue_rindiendo_el_pdf_desde_la_cotizacion(): void
    {
        AdminContFiscalSettings::current()->update(['nombre_comercial' => 'ATLANTIC USA CORP', 'ruc' => '1838853-1-711641', 'dv' => '81']);
        ['root' => $root, 'card' => $card, 'quote' => $quote] = $this->pedidoConDescuentos();
        // Factura como las de antes de SCRUM-929: sin líneas, tipo completo por default.
        $legada = AdminContInvoice::create([
            'numero' => 'F-LEGADA-'.self::uniqueTestNumber(), 'order_id' => $root->id, 'quote_id' => $quote->id,
            'cliente' => 'Cliente legado', 'monto' => $card->amount, 'saldo_aplicado' => 0,
        ]);
        $this->assertSame(0, $legada->lines()->count());
        $token = $this->token();

        $detail = $this->api($token, 'GET', "/api/admin-contab/invoices/{$root->id}/detail")->assertStatus(200)->json();

        $esperado = InvoiceService::fiscalBreakdown($quote);
        $this->assertNull($detail['concepto'], 'Sin líneas no hay concepto: se renderiza exactamente como hoy.');
        $this->assertEqualsWithDelta($esperado['total'], (float) $detail['total'], 0.001);
        $this->assertEqualsWithDelta($esperado['descuentos'], (float) $detail['descuentos'], 0.001);
        $this->assertCount(count($esperado['items']), $detail['items']);

        $this->api($token, 'GET', "/api/admin-contab/invoices/{$root->id}/pdf")->assertStatus(200)->assertHeader('Content-Type', 'application/pdf');

        // El HTML de una factura legada lista TODA la cotización y no lleva ningún "concepto" nuevo.
        $html = view('admin-cont.invoice-pdf', app(\App\Modules\AdminCont\Services\InvoicePdfService::class)->viewData($detail))->render();
        $this->assertStringContainsString('Lámpara A', $html);
        $this->assertStringContainsString('Lámpara C', $html);
        $this->assertStringNotContainsString('Pedido completo', $html);
        $this->assertStringNotContainsString('Entrega 1 de', $html);
    }

    public function test_929_pedido_legado_facturado_no_ofrece_ninguna_opcion_de_facturacion(): void
    {
        ['root' => $root, 'card' => $card, 'quote' => $quote] = $this->pedidoConDescuentos();
        AdminContInvoice::create([
            'numero' => 'F-LEGADA-'.self::uniqueTestNumber(), 'order_id' => $root->id, 'quote_id' => $quote->id,
            'cliente' => 'Cliente legado', 'monto' => $card->amount, 'saldo_aplicado' => 0,
        ]);
        $token = $this->token();

        $fila = collect($this->api($token, 'GET', '/api/admin-contab/invoices?view=plana')->json('rows'))->firstWhere('pedido_id', $root->id);

        $this->assertFalse($fila['puede_facturar_completo']);
        $this->assertFalse($fila['puede_facturar_entrega']);
        $this->assertSame('facturada', $fila['estado']);
        $this->assertSame('completo', $fila['tipo_factura']);
    }

    // ---------------------------------------------------------------------------------------------
    // F1 — la cotización cambió de versión / se borró un ítem DESPUÉS de facturar parcialmente
    // ---------------------------------------------------------------------------------------------

    public function test_929_pedido_completo_tras_parcial_con_cotizacion_reversionada_se_rechaza_con_mensaje_claro(): void
    {
        ['root' => $root, 'entregas' => $entregas, 'quote' => $quote] = $this->pedidoConDescuentos();
        $token = $this->token();
        $this->facturar($token, [$entregas[1]->id], 'entrega')->assertStatus(201);

        $this->nuevaVersionDeLaCotizacion($quote);
        $this->reTenant();

        $this->facturar($token, [$root->id], 'completo')->assertStatus(422)
            ->assertJsonPath('errors.0.missing.0', InvoiceBillingPlanner::MSG_COTIZACION_CAMBIO);
        // Ni líneas negativas ni facturas de más: sigue habiendo solo la parcial.
        $this->assertCount(1, $this->activas($root));
        $this->assertSame(0, AdminContInvoiceLine::where('total', '<', 0)->count());

        // El listado no ofrece lo que el API rechazaría.
        $fila = collect($this->api($token, 'GET', '/api/admin-contab/invoices?view=plana')->json('rows'))->firstWhere('pedido_id', $root->id);
        $this->assertFalse($fila['puede_facturar_completo']);
        $this->assertSame(InvoiceBillingPlanner::MSG_COTIZACION_CAMBIO, $fila['motivo_completo_no_disponible']);
    }

    public function test_929_pedido_completo_tras_parcial_con_un_quote_item_borrado_se_rechaza_con_mensaje_claro(): void
    {
        ['root' => $root, 'entregas' => $entregas, 'items' => $items] = $this->pedidoConDescuentos();
        $token = $this->token();
        $this->facturar($token, [$entregas[1]->id], 'entrega')->assertStatus(201);

        $items[0]->delete();
        $this->reTenant();

        $this->facturar($token, [$root->id], 'completo')->assertStatus(422)
            ->assertJsonPath('errors.0.missing.0', InvoiceBillingPlanner::MSG_COTIZACION_CAMBIO);
        $this->assertCount(1, $this->activas($root));
    }

    public function test_929_un_pedido_con_cotizacion_reversionada_pero_sin_facturas_previas_se_factura_normal(): void
    {
        ['root' => $root, 'quote' => $quote, 'card' => $card] = $this->pedidoConDescuentos();
        $this->nuevaVersionDeLaCotizacion($quote);
        $token = $this->token();

        $this->facturar($token, [$root->id], 'completo')->assertStatus(201);

        $facturas = $this->activas($root);
        $this->assertCount(1, $facturas);
        $this->assertSame(round((float) $card->amount, 2), $facturas[0]->monto);
        $this->assertSame(round((float) $facturas[0]->lines()->sum('total'), 2), $facturas[0]->monto);
    }

    // ---------------------------------------------------------------------------------------------
    // F2 — lo ya emitido se lista SIEMPRE (una fila por factura), aunque el pedido deje de ser "linkable"
    // ---------------------------------------------------------------------------------------------

    public function test_929_las_parciales_emitidas_siguen_en_el_listado_y_el_export_aunque_el_pedido_deje_de_ser_facturable_por_entrega(): void
    {
        ['root' => $root, 'entregas' => $entregas, 'quote' => $quote] = $this->makePedido(
            [['desc' => 'Panel', 'qty' => 9, 'price' => 100.00]],
            [[3], [3], [3]],
        );
        $token = $this->token();
        $this->facturar($token, [$entregas[1]->id], 'entrega')->assertStatus(201);
        $this->facturar($token, [$entregas[2]->id], 'entrega')->assertStatus(201);
        $facturas = collect($this->activas($root))->keyBy('order_id');

        // Otra cotización con folio en el mismo proyecto: `linkedQuote()` cambia y el pedido deja de ser "linkable".
        $this->nuevaVersionDeLaCotizacion($quote);
        $this->reTenant();

        $rows = collect($this->api($token, 'GET', '/api/admin-contab/invoices?view=plana')->json('rows'))->where('pedido_id', $root->id)->values();

        $this->assertFalse($rows[0]['facturacion_por_entrega'], 'linkable solo decide lo que se PUEDE emitir…');
        $emitidas = $rows->whereNotNull('invoice_id')->values();
        $this->assertCount(2, $emitidas, '…nunca oculta lo ya emitido.');
        foreach ([$entregas[1], $entregas[2]] as $entrega) {
            $fila    = $emitidas->firstWhere('order_id', $entrega->id);
            $factura = $facturas[$entrega->id];
            $this->assertSame($factura->id, $fila['invoice_id']);
            $this->assertSame('parcial', $fila['tipo_factura']);
            $this->assertSame('facturada', $fila['estado']);
            $this->assertEqualsWithDelta($factura->monto, (float) $fila['monto'], 0.001, 'Cada fila lleva SU monto, no el total del pedido.');
            $this->assertFalse($fila['puede_facturar_entrega']);
        }

        // El export sigue mostrando ambas parciales con su monto.
        $filas = $this->filasDelExport($token);
        $porFactura = collect($filas)->keyBy(1);
        foreach ($facturas as $factura) {
            $this->assertTrue($porFactura->has($factura->numero), "El export debe listar {$factura->numero}");
            $this->assertEqualsWithDelta($factura->monto, (float) $porFactura[$factura->numero][3], 0.001, 'Monto propio de cada factura en el export.');
        }
    }

    // ---------------------------------------------------------------------------------------------
    // F3 — etiqueta del export: "(n/N)" solo si la fila es una ENTREGA
    // ---------------------------------------------------------------------------------------------

    public function test_929_el_export_de_una_familia_legada_con_completo_no_lleva_secuencia(): void
    {
        ['root' => $root] = $this->makePedido([['desc' => 'Panel', 'qty' => 6, 'price' => 100.00]], [[3], [3], [0]], vincular: false);
        $token = $this->token();
        $this->facturar($token, [$root->id])->assertStatus(201);

        $filas = $this->filasDelExport($token);

        $this->assertCount(1, $filas);
        $this->assertSame($root->order_number, $filas[0][0], 'El completo representa el pedido entero: sin "(n/N)".');
    }

    public function test_929_el_export_de_la_raiz_con_parcial_y_completo_da_dos_filas_con_etiquetas_distintas(): void
    {
        ['root' => $root, 'entregas' => $entregas] = $this->pedidoConDescuentos();
        $token = $this->token();
        $this->facturar($token, [$entregas[0]->id], 'entrega')->assertStatus(201);
        $this->facturar($token, [$root->id], 'completo')->assertStatus(201);

        $etiquetas = collect($this->filasDelExport($token))->pluck(0)->all();

        $this->assertEqualsCanonicalizing(["{$root->order_number} (1/3)", $root->order_number], $etiquetas);
    }

    // ---------------------------------------------------------------------------------------------
    // F6 — el residual anómalo del cierre ya no es silencioso: `advertencias`
    // ---------------------------------------------------------------------------------------------

    public function test_929_sin_descuadre_las_advertencias_van_vacias_en_el_preview_y_en_la_respuesta_de_confirmar(): void
    {
        ['root' => $root] = $this->pedidoConDescuentos();
        $token = $this->token();

        $preview = $this->api($token, 'POST', '/api/admin-contab/invoices/preview', ['order_ids' => [$root->id], 'modo' => 'completo'])->json('results.0');
        $this->assertSame([], $preview['advertencias']);

        $r = $this->facturar($token, [$root->id], 'completo')->assertStatus(201);
        $this->assertSame([], $r->json('invoices.0.advertencias'));
    }

    public function test_929_si_la_tasa_de_itbms_cambio_despues_de_aprobar_el_cierre_factura_el_total_de_la_tarjeta_pero_advierte(): void
    {
        ['root' => $root, 'card' => $card] = $this->makePedido([['desc' => 'Panel', 'qty' => 10, 'price' => 100.00]], [[10]]);
        $this->assertEqualsWithDelta(1070.0, (float) $card->amount, 0.001, 'Fixture: 1000 + 7 %.');
        $this->cambiarTasaDeItbms(10.0); // la cotización recalculada hoy daría 1100
        $token = $this->token();

        $preview = $this->api($token, 'POST', '/api/admin-contab/invoices/preview', ['order_ids' => [$root->id], 'modo' => 'completo'])->json('results.0');
        $this->assertNotEmpty($preview['advertencias']);
        $this->assertStringContainsString('no coincide con la cotización recalculada', $preview['advertencias'][0]);
        $this->assertEqualsWithDelta(1070.0, (float) $preview['monto'], 0.001);

        $r = $this->facturar($token, [$root->id], 'completo')->assertStatus(201);
        $this->assertNotEmpty($r->json('invoices.0.advertencias'));
        $factura = $this->activas($root)[0];
        $this->assertSame(1070.0, $factura->monto, 'El dinero cuadra con la tarjeta.');
        $this->assertSame(1070.0, round((float) $factura->lines()->sum('total'), 2), 'Σ líneas = monto.');
    }

    // ---------------------------------------------------------------------------------------------
    // F7 — el desglose base/ITBMS/total de lo que cierra el pedido == el de la cotización, al centavo
    // ---------------------------------------------------------------------------------------------

    public function test_929_entrega_unica_3_x_4_50_conserva_el_desglose_exacto_de_la_cotizacion(): void
    {
        $this->assertEquals(7.0, $this->pct(), 'Fixture: el ejemplo del Senior Review asume ITBMS 7 %.');
        ['root' => $root, 'quote' => $quote, 'card' => $card] = $this->makePedido(
            [['desc' => 'A', 'qty' => 1, 'price' => 4.50], ['desc' => 'B', 'qty' => 1, 'price' => 4.50], ['desc' => 'C', 'qty' => 1, 'price' => 4.50]],
            [[1, 1, 1]],
        );
        $esperado = InvoiceService::fiscalBreakdown($quote);
        $this->assertSame([13.50, 0.95, 14.45], [$esperado['subtotal'], $esperado['itbms'], $esperado['total']], 'La cotización dice 13.50 / 0.95 / 14.45.');
        $token = $this->token();

        $this->facturar($token, [$root->id])->assertStatus(201);

        $factura = $this->activas($root)[0];
        $this->assertSame(14.45, $factura->monto);
        $this->assertSame(round((float) $card->amount, 2), $factura->monto);
        $this->assertSame(13.50, round((float) $factura->lines()->sum('subtotal'), 2), 'Σ base = base de la cotización.');
        $this->assertSame(0.95, round((float) $factura->lines()->sum('itbms'), 2), 'Σ ITBMS = ITBMS de la cotización.');

        $detail = $this->api($token, 'GET', "/api/admin-contab/invoices/{$root->id}/detail")->assertStatus(200)->json();
        foreach (['subtotal', 'descuentos', 'itbms', 'total'] as $campo) {
            $this->assertEqualsWithDelta($esperado[$campo], (float) $detail[$campo], 0.0001, "PDF/detalle: {$campo}");
        }
        $this->assertCount(count($esperado['items']), $detail['items']);

        // El PDF muestra los mismos números que la cotización.
        $html = view('admin-cont.invoice-pdf', app(\App\Modules\AdminCont\Services\InvoicePdfService::class)->viewData(app(InvoiceService::class)->detail($root->id)))->render();
        $this->assertStringContainsString(number_format($esperado['subtotal'], 2), $html);
        $this->assertStringContainsString(number_format($esperado['itbms'], 2), $html);
        $this->assertStringContainsString(number_format($esperado['total'], 2), $html);
    }

    public function test_929_pedido_completo_sin_previas_con_descuentos_conserva_el_desglose_exacto_de_la_cotizacion(): void
    {
        ['root' => $root, 'quote' => $quote] = $this->pedidoConDescuentos();
        $esperado = InvoiceService::fiscalBreakdown($quote);
        $token = $this->token();
        $this->facturar($token, [$root->id], 'completo')->assertStatus(201);

        $detail = $this->api($token, 'GET', "/api/admin-contab/invoices/{$root->id}/detail")->assertStatus(200)->json();

        foreach (['subtotal', 'descuentos', 'itbms', 'total'] as $campo) {
            $this->assertEqualsWithDelta($esperado[$campo], (float) $detail[$campo], 0.0001, $campo);
        }
        foreach ($esperado['items'] as $i => $item) {
            $this->assertEqualsWithDelta($item['subtotal'], (float) $detail['items'][$i]['subtotal'], 0.0001);
            $this->assertEqualsWithDelta($item['precio_unitario'], (float) $detail['items'][$i]['precio_unitario'], 0.0001);
        }
    }

    public function test_929_tres_entregas_suman_base_e_itbms_de_la_cotizacion_al_centavo_por_separado(): void
    {
        ['root' => $root, 'entregas' => $entregas, 'quote' => $quote, 'card' => $card] = $this->pedidoConDescuentos();
        $token = $this->token();
        foreach ($entregas as $entrega) {
            $r = $this->facturar($token, [$entrega->id], 'entrega')->assertStatus(201);
        }
        $this->assertSame([], $r->json('invoices.0.advertencias'), 'El redondeo normal no genera advertencias.');

        $ids = collect($this->activas($root))->pluck('id');
        $lineas = AdminContInvoiceLine::whereIn('invoice_id', $ids);
        $this->assertSame(round($quote->netTotal(), 2), round((float) (clone $lineas)->sum('subtotal'), 2), 'Σ base = base de la cotización.');
        $this->assertSame(round($quote->itbms(), 2), round((float) (clone $lineas)->sum('itbms'), 2), 'Σ ITBMS = ITBMS de la cotización.');
        $this->assertSame(round((float) $card->amount, 2), $this->sumaActivas($root));
    }

    // ---------------------------------------------------------------------------------------------
    // F8 — `?invoice_id=`: validación, IDOR en /pdf y default determinista
    // ---------------------------------------------------------------------------------------------

    public function test_929_invoice_id_invalido_se_rechaza_con_422_en_detail_y_pdf(): void
    {
        ['root' => $root] = $this->pedidoConDescuentos();
        $token = $this->token();
        $this->facturar($token, [$root->id], 'completo')->assertStatus(201);

        foreach (['abc', '0', '-3'] as $malo) {
            $this->api($token, 'GET', "/api/admin-contab/invoices/{$root->id}/detail?invoice_id={$malo}")->assertStatus(422);
            $this->api($token, 'GET', "/api/admin-contab/invoices/{$root->id}/pdf?invoice_id={$malo}")->assertStatus(422);
        }
    }

    public function test_929_el_pdf_con_un_invoice_id_de_otro_pedido_devuelve_404(): void
    {
        ['root' => $root] = $this->pedidoConDescuentos();
        ['root' => $otra] = $this->makePedido([['desc' => 'Panel', 'qty' => 2, 'price' => 10.00]], [[2]]);
        $token = $this->token();
        $this->facturar($token, [$otra->id])->assertStatus(201);
        $this->facturar($token, [$root->id], 'completo')->assertStatus(201);
        $ajena = $this->activas($otra)[0];

        $this->api($token, 'GET', "/api/admin-contab/invoices/{$root->id}/pdf?invoice_id={$ajena->id}")->assertStatus(404);
    }

    public function test_929_detail_sin_invoice_id_prefiere_la_factura_vigente_sobre_una_parcial_anulada(): void
    {
        ['root' => $root, 'entregas' => $entregas] = $this->pedidoConDescuentos();
        $token = $this->token();
        $this->facturar($token, [$entregas[0]->id], 'entrega')->assertStatus(201);   // parcial de la raíz
        $this->facturar($token, [$root->id], 'completo')->assertStatus(201);         // completo (misma tarjeta)
        $parcial  = collect($this->activas($root))->firstWhere('tipo', AdminContInvoice::TIPO_PARCIAL);
        $completo = collect($this->activas($root))->firstWhere('tipo', AdminContInvoice::TIPO_COMPLETO);
        AdminContInvoice::whereKey($parcial->id)->update(['anulada_at' => now()]);

        $detail = $this->api($token, 'GET', "/api/admin-contab/invoices/{$root->id}/detail")->assertStatus(200)->json();

        $this->assertSame($completo->numero, $detail['numero_factura'], 'Con la parcial anulada, el default es la factura vigente.');
        $this->assertSame('facturada', $detail['estado']);
        // Con `invoice_id` explícito sí se puede pedir la anulada.
        $this->api($token, 'GET', "/api/admin-contab/invoices/{$root->id}/detail?invoice_id={$parcial->id}")->assertStatus(200)->assertJsonPath('estado', 'anulada');
    }

    // ---------------------------------------------------------------------------------------------
    // Pendientes menores de B2 (re-revisiones): N-1, N-2, N2, N4, N5, N6, N7
    // ---------------------------------------------------------------------------------------------

    public function test_929_el_ajuste_de_itbms_del_cierre_no_deja_ninguna_linea_con_importes_negativos(): void
    {
        $this->assertEquals(7.0, $this->pct(), 'Fixture: el escenario del revisor asume ITBMS 7 %.');
        $items = [];
        for ($i = 1; $i <= 10; $i++) {
            $items[] = ['desc' => "Ítem {$i}", 'qty' => 1, 'price' => 1.50];
        }
        $items[] = ['desc' => 'Ítem barato', 'qty' => 1, 'price' => 0.50]; // la ÚLTIMA línea es la barata
        ['root' => $root, 'quote' => $quote, 'card' => $card] = $this->makePedido($items, [array_fill(0, 11, 1)]);
        $token = $this->token();

        $r = $this->facturar($token, [$root->id], 'completo')->assertStatus(201);

        $factura = $this->activas($root)[0];
        foreach ($factura->lines as $linea) {
            $this->assertGreaterThanOrEqual(0.0, $linea->itbms, "ITBMS de «{$linea->descripcion}» no puede ser negativo.");
            $this->assertGreaterThanOrEqual(0.0, $linea->subtotal);
            $this->assertGreaterThanOrEqual(0.0, $linea->total);
        }
        $this->assertSame(round($quote->itbms(), 2), round((float) $factura->lines()->sum('itbms'), 2), 'Σ ITBMS = el de la cotización.');
        $this->assertSame(15.50, round((float) $factura->lines()->sum('subtotal'), 2));
        $this->assertSame(round((float) $card->amount, 2), $factura->monto);
        $this->assertSame([], $r->json('invoices.0.advertencias'), 'Es redondeo normal: sin advertencias.');
    }

    public function test_929_el_reparto_de_un_ajuste_negativo_mayor_que_una_linea_se_prorratea_sin_negativos(): void
    {
        $lineas = [
            ['subtotal' => 1.00, 'itbms' => 0.07, 'total' => 1.07],
            ['subtotal' => 1.00, 'itbms' => 0.03, 'total' => 1.03],
            ['subtotal' => 1.00, 'itbms' => 0.01, 'total' => 1.01],
        ];

        [$ajustadas, $sobrante] = \App\Modules\AdminCont\Services\Billing\InvoiceLineMath::repartir($lineas, 'itbms', -0.09);

        $this->assertSame(0.0, $sobrante);
        $this->assertSame([0.0, 0.01, 0.01], array_column($ajustadas, 'itbms'), 'Se resta de la mayor hasta 0 y sigue con la siguiente.');
        $this->assertSame([1.0, 1.01, 1.01], array_column($ajustadas, 'total'));

        // Si no alcanza ni sumando todas, lo que sobra se informa (y solo entonces queda una línea negativa).
        [, $sobrante] = \App\Modules\AdminCont\Services\Billing\InvoiceLineMath::repartir($lineas, 'itbms', -0.50);
        $this->assertSame(0.39, $sobrante);
    }

    public function test_929_el_log_de_un_cierre_con_advertencias_se_escribe_al_emitir_no_al_leer(): void
    {
        ['root' => $root, 'entregas' => $entregas] = $this->makePedido([['desc' => 'Panel', 'qty' => 6, 'price' => 100.00]], [[3], [3]]);
        $token = $this->token();
        $this->facturar($token, [$entregas[1]->id], 'entrega')->assertStatus(201);
        $this->cambiarTasaDeItbms(30.0); // el cierre queda con descuadre → advertencias
        $proyecto = $root->pipelineCard->sales_project_id;

        \Illuminate\Support\Facades\Log::spy();
        // Lecturas: listado (x2 con countInTab), resumen, wizard y vista previa.
        $this->api($token, 'GET', '/api/admin-contab/invoices?view=plana')->assertStatus(200);
        $this->api($token, 'GET', '/api/admin-contab/invoices/summary')->assertStatus(200);
        $this->api($token, 'GET', "/api/admin-contab/invoices/pending?sales_project_id={$proyecto}")->assertStatus(200);
        $preview = $this->api($token, 'POST', '/api/admin-contab/invoices/preview', ['order_ids' => [$root->id], 'modo' => 'completo'])->json('results.0');
        $this->assertNotEmpty($preview['advertencias'], 'El descuadre sí se ve en el preview (campo), aunque no se loguee.');
        \Illuminate\Support\Facades\Log::shouldNotHaveReceived('warning', ['admin_cont.invoice.cierre_con_advertencias', \Mockery::any()]);

        // Escritura: al emitir sí queda el log, una vez.
        $this->facturar($token, [$root->id], 'completo')->assertStatus(201);
        \Illuminate\Support\Facades\Log::shouldHaveReceived('warning')->with('admin_cont.invoice.cierre_con_advertencias', \Mockery::type('array'))->once();
    }

    public function test_929_advertencia_del_cierre_cuando_hay_facturas_previas_sin_detalle_de_lineas(): void
    {
        ['root' => $root, 'entregas' => $entregas, 'card' => $card] = $this->makePedido([['desc' => 'Panel', 'qty' => 6, 'price' => 100.00]], [[3], [3]]);
        // Factura activa previa hecha a mano, SIN líneas (no se sabe cuánta base/ITBMS cubre).
        AdminContInvoice::create([
            'numero' => 'F-SINL-'.self::uniqueTestNumber(), 'order_id' => $entregas[1]->id, 'tipo' => AdminContInvoice::TIPO_PARCIAL,
            'cliente' => 'X', 'monto' => 100, 'saldo_aplicado' => 0,
        ]);
        $token = $this->token();

        $preview = $this->api($token, 'POST', '/api/admin-contab/invoices/preview', ['order_ids' => [$root->id], 'modo' => 'completo'])->json('results.0');

        $this->assertTrue($preview['ok']);
        $this->assertStringContainsString('sin detalle de líneas', implode(' ', $preview['advertencias']));
        $this->assertEqualsWithDelta((float) $card->amount - 100.0, (float) $preview['monto'], 0.001, 'El total sigue siendo card->amount − Σ previas.');
    }

    public function test_929_advertencia_del_cierre_cuando_base_o_itbms_difieren_mas_que_el_redondeo(): void
    {
        ['root' => $root, 'entregas' => $entregas] = $this->makePedido([['desc' => 'Panel', 'qty' => 6, 'price' => 100.00]], [[3], [3]]);
        $token = $this->token();
        $this->facturar($token, [$entregas[1]->id], 'entrega')->assertStatus(201); // parcial a 7 %
        $this->cambiarTasaDeItbms(30.0);

        $preview = $this->api($token, 'POST', '/api/admin-contab/invoices/preview', ['order_ids' => [$root->id], 'modo' => 'completo'])->json('results.0');

        $this->assertStringContainsString('difieren del cálculo de la cotización', implode(' ', $preview['advertencias']));
    }

    public function test_929_el_contador_de_pendientes_coincide_con_las_filas_pendientes_cuando_la_cotizacion_se_reversiono_tras_facturar(): void
    {
        ['root' => $root, 'entregas' => $entregas, 'quote' => $quote] = $this->makePedido([['desc' => 'Panel', 'qty' => 6, 'price' => 100.00]], [[3], [3]]);
        $token = $this->token();
        $this->facturar($token, [$entregas[1]->id], 'entrega')->assertStatus(201);
        $this->nuevaVersionDeLaCotizacion($quote);
        $this->reTenant();

        $rows      = collect($this->api($token, 'GET', '/api/admin-contab/invoices?view=plana')->json('rows'))->where('pedido_id', $root->id);
        $pendientes = $rows->where('estado', 'pendiente-facturar')->count();
        $summary   = $this->api($token, 'GET', '/api/admin-contab/invoices/summary')->json();

        $this->assertSame(0, $pendientes, 'El listado no genera fila pendiente (solo la de la parcial emitida).');
        $this->assertSame($pendientes, $summary['pendientes_facturar'], 'El contador no anuncia un pendiente que nadie puede accionar.');
        $this->assertFalse($rows->first()['puede_facturar_completo']);
    }

    public function test_929_detail_y_resumen_prefieren_el_completo_vigente_sobre_la_parcial_anulada_de_la_entrega(): void
    {
        ['root' => $root, 'entregas' => $entregas] = $this->pedidoConDescuentos();
        $token = $this->token();
        $this->facturar($token, [$entregas[1]->id], 'entrega')->assertStatus(201);
        $parcial = $this->activas($root)[0];
        AdminContInvoice::whereKey($parcial->id)->update(['anulada_at' => now()]);
        $this->facturar($token, [$root->id], 'completo')->assertStatus(201);
        $completo = collect($this->activas($root))->firstWhere('tipo', AdminContInvoice::TIPO_COMPLETO);

        $detail = $this->api($token, 'GET', "/api/admin-contab/invoices/{$entregas[1]->id}/detail")->assertStatus(200)->json();

        $this->assertSame($completo->numero, $detail['numero_factura'], 'El completo vigente que la cubre va antes que su parcial anulada.');
        $this->assertSame('facturada', $detail['estado']);
        $resumen = collect($detail['entregas_del_pedido'])->firstWhere('order_id', $entregas[1]->id);
        $this->assertSame($completo->numero, $resumen['factura_numero']);
        $this->assertFalse($resumen['factura_anulada']);
        $this->assertTrue($resumen['cubierta_por_completo']);
    }

    public function test_929_un_invoice_id_vacio_se_trata_como_ausente(): void
    {
        ['root' => $root] = $this->pedidoConDescuentos();
        $token = $this->token();
        $this->facturar($token, [$root->id], 'completo')->assertStatus(201);

        $this->api($token, 'GET', "/api/admin-contab/invoices/{$root->id}/detail?invoice_id=")->assertStatus(200);
        $this->api($token, 'GET', "/api/admin-contab/invoices/{$root->id}/pdf?invoice_id=")->assertStatus(200);
    }

    public function test_929_el_mensaje_de_cotizacion_cambiada_es_neutro_sin_voseo(): void
    {
        $this->assertStringEndsWith('Contacte a Administración.', InvoiceBillingPlanner::MSG_COTIZACION_CAMBIO);
        $this->assertStringNotContainsString('Contactá', InvoiceBillingPlanner::MSG_COTIZACION_CAMBIO);
    }
}
