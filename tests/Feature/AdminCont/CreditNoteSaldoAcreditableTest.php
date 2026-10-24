<?php

declare(strict_types=1);

namespace Tests\Feature\AdminCont;

use App\Models\User;
use App\Modules\AdminCont\Models\AdminContCreditNote;
use App\Modules\AdminCont\Models\AdminContInvoice;
use App\Modules\AdminCont\Models\AdminContItbmsRate;
use App\Modules\AdminCont\Services\CreditNoteService;
use App\Modules\Bodega\Models\Order;
use App\Modules\VentasDiseno\Models\MasterClient;
use App\Modules\VentasDiseno\Models\SalesProject;
use App\Modules\VentasDiseno\Models\SubClient;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-1116 (P2 + P3) — la Anulación completa y todo el registro de notas consideran lo que AÚN se puede acreditar
 * de la factura (valor acreditable restante, VAR = total − notas previas aplicadas o pendientes), no el total original.
 *
 * Casos del ticket con factura de 1.000: sin notas → 1.000; con NC 300 → 700; con NC 300 + devolución 200 → 500; con
 * VAR 0 no se permite otra anulación; el acumulado nunca supera la factura (en NINGÚN tipo de nota). Más el caso REAL de
 * producción (137,77 con devolución 128,76 → 9,01) y los de cobros, que siguen devolviendo lo cobrado (REQ-484).
 */
class CreditNoteSaldoAcreditableTest extends FeatureTestCase
{
    private const FELIX_EMAIL = 'contabilidad@test.com';

    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        Storage::fake('s3-private');
    }

    private function tenant(): void
    {
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    private function felixId(): int
    {
        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');

        return (int) DB::table("{$slug}_auth.users")->where('email', self::FELIX_EMAIL)->value('id');
    }

    private function token(): string
    {
        return $this->loginAs(self::FELIX_EMAIL, self::FELIX_EMAIL, 'lider_admin_contab');
    }

    private function factura(float $monto, float $saldoAplicado = 0.0): AdminContInvoice
    {
        $master = MasterClient::create(['name' => 'Cliente VAR ' . uniqid()]);
        $sub    = SubClient::create(['master_client_id' => $master->id, 'business_name' => 'Sub ' . uniqid(), 'tax_id' => '155-0000-1-' . random_int(1000, 9999)]);
        SalesProject::create(['sub_client_id' => $sub->id, 'name' => 'Proyecto ' . uniqid()]);
        $order = Order::create([
            'order_number' => 'PED-2026-' . random_int(1000, 999999), 'order_type' => Order::ORDER_TYPE_PEDIDO,
            'stage' => Order::STAGE_POR_DESPACHAR, 'customer_name' => $sub->business_name,
        ]);
        $rate = AdminContItbmsRate::where('nombre', '7% — General')->firstOrFail();

        return AdminContInvoice::create([
            'numero' => 'F-' . static::uniqueTestNumber(), 'order_id' => $order->id, 'itbms_rate_id' => $rate->id,
            'master_client_id' => $master->id, 'cliente' => $sub->business_name, 'monto' => $monto, 'saldo_aplicado' => $saldoAplicado,
        ]);
    }

    /** @param array<string, mixed> $extra */
    private function registrar(string $token, AdminContInvoice $f, string $tipo, ?float $monto = null, array $extra = []): \Illuminate\Testing\TestResponse
    {
        $payload = [
            'master_client_id'  => $f->master_client_id,
            'cliente'           => $f->cliente,
            'tipo'              => $tipo,
            'factura_origen_id' => $f->id,
            'motivo'            => 'Prueba SCRUM-1116',
            'comprobante'       => UploadedFile::fake()->create('soporte.pdf', 100, 'application/pdf'),
            ...($tipo === AdminContCreditNote::TIPO_ANULACION_COMPLETA ? ['subtipo_anulacion' => AdminContCreditNote::SUBTIPO_CANCELADO] : []),
            ...($monto !== null ? ['monto' => $monto] : []),
            ...$extra,
        ];

        // `Accept: application/json` para que un error de validación llegue como 422 y no como redirección.
        return $this->withHeaders([...$this->authHeaders($token), 'Accept' => 'application/json'])->post('/api/admin-contab/notas-credito', $payload);
    }

    private function descuento(string $token, AdminContInvoice $f, float $monto): \Illuminate\Testing\TestResponse
    {
        return $this->registrar($token, $f, AdminContCreditNote::TIPO_DESCUENTO_COMERCIAL, $monto);
    }

    private function devolucion(string $token, AdminContInvoice $f, float $monto): \Illuminate\Testing\TestResponse
    {
        return $this->registrar($token, $f, AdminContCreditNote::TIPO_DEVOLUCION_MERCANCIA, $monto);
    }

    private function anular(string $token, AdminContInvoice $f, array $extra = []): \Illuminate\Testing\TestResponse
    {
        return $this->registrar($token, $f, AdminContCreditNote::TIPO_ANULACION_COMPLETA, null, $extra);
    }

    private function notasDe(AdminContInvoice $f): int
    {
        $this->tenant();

        return AdminContCreditNote::query()->where('factura_origen_id', $f->id)->count();
    }

    // ── Los casos del ticket ───────────────────────────────────────────────────────────────────────────────────

    public function test_t1_sin_notas_previas_la_anulacion_es_por_el_total_de_la_factura(): void
    {
        $f = $this->factura(1000);

        $this->anular($this->token(), $f)
            ->assertStatus(201)
            ->assertJson(['monto' => 1000, 'resultado' => AdminContCreditNote::RESULTADO_APLICADO_SALDO, 'estado' => AdminContCreditNote::ESTADO_PENDIENTE_APROBACION]);
    }

    public function test_t2_con_una_nota_previa_de_300_la_anulacion_es_por_700_sin_pedir_devuelto_ni_saldo_a_favor(): void
    {
        $f     = $this->factura(1000);
        $token = $this->token();
        $this->descuento($token, $f, 300)->assertStatus(201);

        // Sin `resultado`: ya no hay excedente. Antes salía por 1.000 y exigía elegir devuelto / saldo a favor.
        $r = $this->anular($token, $f, ['monto' => 1]) // un monto mandado se ignora, como siempre
            ->assertStatus(201)
            ->assertJson(['monto' => 700, 'resultado' => AdminContCreditNote::RESULTADO_APLICADO_SALDO]);

        // El desglose subtotal / ITBMS sale del monto REAL de la nota (7 %), no del total de la factura.
        $this->assertEquals(round(700 / 1.07, 2), (float) $r->json('subtotal'));
        $this->assertEquals(round(700 - round(700 / 1.07, 2), 2), (float) $r->json('itbms'));
    }

    public function test_t4_con_nota_de_300_mas_devolucion_de_200_la_anulacion_es_por_500_considerando_todas_las_notas(): void
    {
        $f     = $this->factura(1000);
        $token = $this->token();
        $this->descuento($token, $f, 300)->assertStatus(201);
        $this->devolucion($token, $f, 200)->assertStatus(201);

        $this->anular($token, $f)->assertStatus(201)->assertJson(['monto' => 500]);
    }

    public function test_t7_con_el_valor_acreditable_en_cero_no_se_permite_otra_anulacion(): void
    {
        $f     = $this->factura(1000);
        $token = $this->token();
        $this->anular($token, $f)->assertStatus(201); // queda pendiente de aprobación y RESERVA los 1.000

        $this->anular($token, $f)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['factura_origen_id'])
            ->assertJsonPath('errors.factura_origen_id.0', "La factura {$f->numero} ya está completamente acreditada por notas previas: no queda valor que anular.");

        $this->assertSame(1, $this->notasDe($f), 'la segunda anulación no debe crear ninguna nota');
    }

    public function test_a6_caso_real_de_produccion_factura_de_13777_con_devolucion_de_12876_se_anula_por_901(): void
    {
        $f     = $this->factura(137.77);
        $token = $this->token();
        $this->devolucion($token, $f, 128.76)->assertStatus(201);

        $r = $this->anular($token, $f)->assertStatus(201);

        $this->assertEquals(9.01, (float) $r->json('monto'));
        $this->assertSame('aplicado_saldo', $r->json('resultado'));
    }

    // ── El acumulado nunca supera la factura, en NINGÚN tipo (D2) ──────────────────────────────────────────────

    public function test_una_nota_mayor_que_el_valor_de_la_factura_se_rechaza(): void
    {
        $f = $this->factura(1000);

        $this->descuento($this->token(), $f, 1500)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['monto']);

        $this->assertSame(0, $this->notasDe($f));
    }

    public function test_el_acumulado_de_descuentos_y_devoluciones_no_puede_pasar_de_la_factura(): void
    {
        $f     = $this->factura(1000);
        $token = $this->token();
        $this->descuento($token, $f, 300)->assertStatus(201);

        $this->devolucion($token, $f, 701)->assertStatus(422)->assertJsonValidationErrors(['monto']); // 300 + 701 > 1.000
        $this->devolucion($token, $f, 700)->assertStatus(201);                                         // justo el valor restante
        $this->descuento($token, $f, 0.01)->assertStatus(422);                                         // ya no queda nada
    }

    public function test_el_mensaje_de_exceso_dice_cuanto_queda_y_cuanto_ya_tiene_la_factura(): void
    {
        $f     = $this->factura(1000);
        $token = $this->token();
        $this->descuento($token, $f, 300)->assertStatus(201);

        $mensaje = (string) $this->descuento($token, $f, 800)->assertStatus(422)->json('errors.monto.0');

        $this->assertStringContainsString('USD 800.00', $mensaje);   // lo pedido
        $this->assertStringContainsString('USD 700.00', $mensaje);   // lo que aún se puede acreditar
        $this->assertStringContainsString('USD 1,000.00', $mensaje); // el valor de la factura
        $this->assertStringContainsString('USD 300.00', $mensaje);   // lo ya acreditado
    }

    // ── Pendientes y rechazadas (D1) ───────────────────────────────────────────────────────────────────────────

    public function test_una_anulacion_pendiente_de_aprobacion_reserva_el_valor_y_bloquea_otras_notas(): void
    {
        $f     = $this->factura(1000);
        $token = $this->token();
        $this->anular($token, $f)->assertStatus(201); // pendiente de aprobación

        $this->descuento($token, $f, 1)->assertStatus(422)->assertJsonValidationErrors(['monto']);
    }

    public function test_al_rechazarse_una_nota_pendiente_su_valor_vuelve_a_estar_disponible(): void
    {
        $f     = $this->factura(1000);
        $token = $this->token();
        $id    = (int) $this->anular($token, $f)->assertStatus(201)->json('id');

        $this->tenant();
        AdminContCreditNote::query()->whereKey($id)->update(['estado' => AdminContCreditNote::ESTADO_RECHAZADA]);

        $this->anular($token, $f)->assertStatus(201)->assertJson(['monto' => 1000]);
    }

    // ── Cobros: siguen devolviendo lo cobrado (REQ-484), la regla no los toca ──────────────────────────────────

    public function test_a1_factura_cobrada_a_medias_se_anula_por_el_total_y_el_excedente_es_lo_cobrado(): void
    {
        $f     = $this->factura(1000, saldoAplicado: 500); // 500 cobrados
        $token = $this->token();

        // Hay excedente real (los 500 cobrados): sigue exigiendo elegir devuelto / saldo a favor.
        $this->anular($token, $f)->assertStatus(422)->assertJsonValidationErrors(['resultado']);

        $r = $this->anular($token, $f, ['resultado' => AdminContCreditNote::RESULTADO_SALDO_FAVOR])->assertStatus(201);
        $this->assertEquals(1000.0, (float) $r->json('monto'));
        $this->assertSame('saldo_favor', $r->json('resultado'));
    }

    public function test_nota_previa_mas_cobro_la_anulacion_es_por_el_valor_restante_y_el_excedente_es_el_cobro(): void
    {
        $f     = $this->factura(1000, saldoAplicado: 500); // 500 cobrados
        $token = $this->token();
        $this->descuento($token, $f, 300)->assertStatus(201); // se aplica de inmediato: saldo_aplicado 500 + 300 = 800

        $r = $this->anular($token, $f, ['resultado' => AdminContCreditNote::RESULTADO_SALDO_FAVOR])->assertStatus(201);

        // Monto = total 1.000 − nota previa 300. Cobros (500) + nota aplicada (300) = saldo_aplicado 800, saldo por cobrar 200:
        // al aprobar Mark (P4), el excedente que se devuelve / queda a favor es 700 − 200 = 500 = lo cobrado.
        $this->assertEquals(700.0, (float) $r->json('monto'));
        $this->assertSame('saldo_favor', $r->json('resultado'));
        $this->assertSame(AdminContCreditNote::ESTADO_PENDIENTE_APROBACION, $r->json('estado'));
        $this->tenant();
        $this->assertEquals(800.0, (float) AdminContInvoice::findOrFail($f->id)->saldo_aplicado);
    }

    // ── El servicio repite la validación bajo el bloqueo de la factura (no depende del Form Request) ───────────

    public function test_el_servicio_rechaza_un_exceso_aunque_se_salte_el_form_request(): void
    {
        $f     = $this->factura(1000);
        $actor = User::findOrFail($this->felixId());

        try {
            app(CreditNoteService::class)->register([
                'master_client_id' => $f->master_client_id, 'cliente' => $f->cliente,
                'tipo' => AdminContCreditNote::TIPO_DESCUENTO_COMERCIAL, 'subtipo_anulacion' => null,
                'factura_origen_id' => $f->id, 'monto' => 1500.0, 'motivo' => 'Prueba', 'resultado' => 'saldo_favor',
                'cuenta_bancaria_salida_id' => null, 'mercancia_regresa_bodega' => null, 'devolucion_bodega_id' => null,
            ], null, $actor);
            $this->fail('debía lanzar ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('monto', $e->errors());
        }

        $this->assertSame(0, $this->notasDe($f));
    }

    public function test_el_servicio_pide_el_resultado_si_aparece_un_excedente_que_el_form_request_no_vio(): void
    {
        $f     = $this->factura(1000, saldoAplicado: 500);
        $actor = User::findOrFail($this->felixId());

        try {
            app(CreditNoteService::class)->register([
                'master_client_id' => $f->master_client_id, 'cliente' => $f->cliente,
                'tipo' => AdminContCreditNote::TIPO_ANULACION_COMPLETA, 'subtipo_anulacion' => AdminContCreditNote::SUBTIPO_CANCELADO,
                'factura_origen_id' => $f->id, 'monto' => null, 'motivo' => 'Prueba', 'resultado' => null,
                'cuenta_bancaria_salida_id' => null, 'mercancia_regresa_bodega' => null, 'devolucion_bodega_id' => null,
            ], null, $actor);
            $this->fail('debía lanzar ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('resultado', $e->errors());
        }

        $this->assertSame(0, $this->notasDe($f));
    }

    public function test_el_servicio_rechaza_la_anulacion_de_una_factura_sin_nada_por_acreditar(): void
    {
        $f     = $this->factura(1000);
        $actor = User::findOrFail($this->felixId());
        $datos = [
            'master_client_id' => $f->master_client_id, 'cliente' => $f->cliente,
            'tipo' => AdminContCreditNote::TIPO_ANULACION_COMPLETA, 'subtipo_anulacion' => AdminContCreditNote::SUBTIPO_CANCELADO,
            'factura_origen_id' => $f->id, 'monto' => null, 'motivo' => 'Prueba', 'resultado' => null,
            'cuenta_bancaria_salida_id' => null, 'mercancia_regresa_bodega' => null, 'devolucion_bodega_id' => null,
        ];
        app(CreditNoteService::class)->register($datos, null, $actor); // la primera reserva los 1.000

        $this->expectException(ValidationException::class);
        app(CreditNoteService::class)->register($datos, null, $actor);
    }

    // ── P4: la aprobación revalida (caso NC-0027) ──────────────────────────────────────────────────────────────

    private function markToken(): string
    {
        return $this->loginAs('gerencia3@test.com', 'gerencia3@test.com', 'management');
    }

    private function decidir(string $token, int $notaId, bool $aprobar): \Illuminate\Testing\TestResponse
    {
        return $this->withHeaders($this->authHeaders($token))->putJson("/api/admin-contab/notas-credito/{$notaId}/decision", $aprobar ? ['approve' => true] : ['approve' => false, 'motivo_rechazo' => 'Excede el valor de la factura']);
    }

    /** Crea a mano una nota pendiente que ya excede la factura (lo que existía antes del fix, ej. NC-0027). */
    private function notaPendienteForzada(AdminContInvoice $f, float $monto, string $tipo): AdminContCreditNote
    {
        $this->tenant();

        return AdminContCreditNote::create([
            'numero' => 'NC-' . static::uniqueTestNumber(), 'master_client_id' => $f->master_client_id, 'cliente' => $f->cliente,
            'tipo' => $tipo, 'factura_origen_id' => $f->id, 'monto' => $monto, 'subtotal' => $monto, 'itbms' => 0,
            'motivo' => 'Heredada', 'estado' => AdminContCreditNote::ESTADO_PENDIENTE_APROBACION, 'registrado_por' => $this->felixId(), 'fecha' => now(),
            'resultado' => AdminContCreditNote::RESULTADO_APLICADO_SALDO,
            'comprobante_url' => 'tests/soporte.pdf',
        ]);
    }

    public function test_p4_mark_no_puede_aprobar_una_nota_que_haria_pasar_el_acumulado_de_la_factura(): void
    {
        $f = $this->factura(137.77);
        $this->tenant();
        AdminContCreditNote::create([
            'numero' => 'NC-' . static::uniqueTestNumber(), 'master_client_id' => $f->master_client_id, 'cliente' => $f->cliente,
            'tipo' => AdminContCreditNote::TIPO_DEVOLUCION_MERCANCIA, 'factura_origen_id' => $f->id, 'monto' => 128.76, 'subtotal' => 128.76, 'itbms' => 0,
            'motivo' => 'Devolución', 'estado' => AdminContCreditNote::ESTADO_APLICADA, 'registrado_por' => $this->felixId(), 'fecha' => now(),
        ]);
        $anulacion = $this->notaPendienteForzada($f, 137.77, AdminContCreditNote::TIPO_ANULACION_COMPLETA);

        $mensaje = (string) $this->decidir($this->markToken(), $anulacion->id, true)->assertStatus(422)->json('message');

        $this->assertStringContainsString('no se puede aprobar', $mensaje);
        $this->assertStringContainsString('USD 9.01', $mensaje);
        $this->tenant();
        $this->assertSame(AdminContCreditNote::ESTADO_PENDIENTE_APROBACION, AdminContCreditNote::findOrFail($anulacion->id)->estado);
        $this->assertEquals(0.0, (float) AdminContInvoice::findOrFail($f->id)->saldo_aplicado, 'no debe mover saldo');
    }

    public function test_p4_mark_si_puede_rechazar_la_nota_excedida(): void
    {
        $f = $this->factura(100);
        $this->tenant();
        AdminContCreditNote::create([
            'numero' => 'NC-' . static::uniqueTestNumber(), 'master_client_id' => $f->master_client_id, 'cliente' => $f->cliente,
            'tipo' => AdminContCreditNote::TIPO_DESCUENTO_COMERCIAL, 'factura_origen_id' => $f->id, 'monto' => 60, 'subtotal' => 60, 'itbms' => 0,
            'motivo' => 'Previa', 'estado' => AdminContCreditNote::ESTADO_APLICADA, 'registrado_por' => $this->felixId(), 'fecha' => now(),
        ]);
        $nota = $this->notaPendienteForzada($f, 100, AdminContCreditNote::TIPO_ANULACION_COMPLETA);

        $this->decidir($this->markToken(), $nota->id, false)->assertStatus(200)->assertJson(['estado' => AdminContCreditNote::ESTADO_RECHAZADA]);
    }

    public function test_p4_la_aprobacion_normal_sigue_funcionando_y_la_nota_no_cuenta_contra_si_misma(): void
    {
        $f     = $this->factura(1000);
        $token = $this->token();
        $this->descuento($token, $f, 300)->assertStatus(201);
        $id = (int) $this->anular($token, $f)->assertStatus(201)->assertJson(['monto' => 700])->json('id');

        $this->decidir($this->markToken(), $id, true)->assertStatus(200)->assertJson(['estado' => AdminContCreditNote::ESTADO_APLICADA]);
    }

    // ── P6: Corrección de datos con notas previas ──────────────────────────────────────────────────────────────

    public function test_p6_corregir_una_factura_con_notas_previas_esta_bloqueado(): void
    {
        $f     = $this->factura(1000);
        $token = $this->token();
        $this->descuento($token, $f, 300)->assertStatus(201);
        $this->tenant();
        $rate = AdminContItbmsRate::where('nombre', '7% — General')->firstOrFail();

        $this->withHeaders([...$this->authHeaders($token), 'Accept' => 'application/json'])
            ->postJson('/api/admin-contab/notas-credito/correccion/preview', [
                'master_client_id' => $f->master_client_id, 'factura_origen_id' => $f->id,
                'motivo_correccion' => AdminContCreditNote::MOTIVO_CORRECCION_ITBMS,
                'nuevo_tratamiento_itbms_rate_id' => $rate->id, 'motivo' => 'Prueba',
            ])->assertStatus(422);
    }

    // ── P5: el listado de facturas expone el valor acreditable ─────────────────────────────────────────────────

    public function test_p5_el_listado_de_facturas_del_cliente_expone_monto_acreditable_y_notas_previas(): void
    {
        $f     = $this->factura(1000, saldoAplicado: 500);
        $token = $this->token();
        $this->descuento($token, $f, 300)->assertStatus(201);
        $this->devolucion($token, $f, 200)->assertStatus(201);

        $fila = collect($this->withHeaders($this->authHeaders($token))
            ->getJson("/api/admin-contab/notas-credito/clientes/{$f->master_client_id}/facturas")
            ->assertStatus(200)->json('data'))
            ->firstWhere('id', $f->id);

        $this->assertNotNull($fila);
        $this->assertEquals(500.0, $fila['monto_acreditable']);
        $this->assertEquals(500.0, $fila['notas_previas_total']);
    }

    public function test_a3_una_anulacion_desactualizada_no_se_aprueba_aunque_no_exceda_la_factura(): void
    {
        // Factura 1.000 + descuento de 300 PENDIENTE: la anulación sale por 700. Mark rechaza el descuento → el valor restante
        // pasa a 1.000 y la anulación (700) ya no es "completa": aprobarla dejaría 300 sin anular.
        $f     = $this->factura(1000);
        $token = $this->token();
        // Los descuentos pequeños se aplican solos: se fuerza uno pendiente (como los de monto alto o heredados).
        $descuentoId  = $this->notaPendienteForzada($f, 300, AdminContCreditNote::TIPO_DESCUENTO_COMERCIAL)->id;
        $anulacionId  = (int) $this->anular($token, $f)->assertStatus(201)->assertJson(['monto' => 700])->json('id');

        $mark = $this->markToken();
        $this->decidir($mark, $descuentoId, false)->assertStatus(200);

        $mensaje = (string) $this->decidir($mark, $anulacionId, true)->assertStatus(422)->json('message');
        $this->assertStringContainsString('ya no coincide', $mensaje);
        $this->assertStringContainsString('USD 1,000.00', $mensaje);
        $this->tenant();
        $this->assertSame(AdminContCreditNote::ESTADO_PENDIENTE_APROBACION, AdminContCreditNote::findOrFail($anulacionId)->estado);
        $this->assertEquals(0.0, (float) AdminContInvoice::findOrFail($f->id)->saldo_aplicado);
    }

    public function test_m1_dos_notas_pendientes_sobre_la_misma_factura_aprobar_la_primera_bloquea_la_segunda_si_juntas_exceden(): void
    {
        $f = $this->factura(1000);
        $a = $this->notaPendienteForzada($f, 600, AdminContCreditNote::TIPO_DESCUENTO_COMERCIAL)->id;
        // Nota heredada (pre-fix) que junto con la primera excede la factura: 600 + 600 > 1.000.
        $b = $this->notaPendienteForzada($f, 600, AdminContCreditNote::TIPO_DEVOLUCION_MERCANCIA)->id;

        $mark = $this->markToken();
        $this->decidir($mark, $a, true)->assertStatus(422); // con la otra pendiente de 600 el acumulado sería 1.200
        $this->decidir($mark, $b, false)->assertStatus(200); // rechazar una libera el valor...
        $this->decidir($mark, $a, true)->assertStatus(200)->assertJson(['estado' => AdminContCreditNote::ESTADO_APLICADA]); // ...y la otra se aprueba
    }

    public function test_b1_corregir_una_factura_con_otra_nota_pendiente_esta_bloqueado_al_registrar(): void
    {
        $f     = $this->factura(1000);
        $token = $this->token();
        $this->descuento($token, $f, 300)->assertStatus(201); // pendiente (> umbral) o aplicada: en ambos casos bloquea
        $this->tenant();
        AdminContCreditNote::query()->where('factura_origen_id', $f->id)->update(['estado' => AdminContCreditNote::ESTADO_PENDIENTE_APROBACION]);
        $rate = AdminContItbmsRate::where('nombre', '7% — General')->firstOrFail();

        $this->withHeaders([...$this->authHeaders($token), 'Accept' => 'application/json'])
            ->postJson('/api/admin-contab/notas-credito/correccion/preview', [
                'master_client_id' => $f->master_client_id, 'factura_origen_id' => $f->id,
                'motivo_correccion' => AdminContCreditNote::MOTIVO_CORRECCION_ITBMS,
                'nuevo_tratamiento_itbms_rate_id' => $rate->id, 'motivo' => 'Prueba',
            ])->assertStatus(422);
    }

    // ── Pre-QA obs. 1 y 2 ──────────────────────────────────────────────────────────────────────────────────────

    public function test_un_monto_que_supera_lo_acreditable_no_pide_ademas_el_resultado(): void
    {
        // Factura de 1.000 ya cobrada por completo (saldo por cobrar 0) y sin valor acreditable: un descuento de 200 supera lo
        // acreditable Y el saldo por cobrar. Solo debe fallar `monto`; antes pedía también "resultado" por un excedente que
        // nunca se iba a registrar, y ese mensaje salía primero.
        $f = $this->factura(1000, saldoAplicado: 1000);
        $this->notaPendienteForzada($f, 1000, AdminContCreditNote::TIPO_ANULACION_COMPLETA);

        $r = $this->descuento($this->token(), $f, 200)->assertStatus(422)->assertJsonValidationErrors(['monto']);

        $this->assertArrayNotHasKey('resultado', $r->json('errors'));
        $this->assertStringContainsString('supera el valor que aún se puede acreditar', (string) $r->json('message'));
    }

    public function test_un_excedente_legitimo_dentro_de_lo_acreditable_sigue_pidiendo_el_resultado(): void
    {
        $f = $this->factura(1000, saldoAplicado: 1000); // ya cobrada: todo el descuento es excedente real

        $this->descuento($this->token(), $f, 200)->assertStatus(422)->assertJsonValidationErrors(['resultado']);
    }

    public function test_corregir_con_un_descuento_pendiente_no_dice_que_hay_una_correccion_pendiente(): void
    {
        $f = $this->factura(1000);
        $nota = $this->notaPendienteForzada($f, 300, AdminContCreditNote::TIPO_DESCUENTO_COMERCIAL);
        $this->tenant();
        $rate = AdminContItbmsRate::where('nombre', '7% — General')->firstOrFail();

        $mensaje = (string) $this->withHeaders([...$this->authHeaders($this->token()), 'Accept' => 'application/json'])
            ->postJson('/api/admin-contab/notas-credito/correccion/preview', [
                'master_client_id' => $f->master_client_id, 'factura_origen_id' => $f->id,
                'motivo_correccion' => AdminContCreditNote::MOTIVO_CORRECCION_ITBMS,
                'nuevo_tratamiento_itbms_rate_id' => $rate->id, 'motivo' => 'Prueba',
            ])->assertStatus(422)->json('message');

        $this->assertStringContainsString($nota->numero, $mensaje);
        $this->assertStringContainsString('notas de crédito pendientes', $mensaje);
        $this->assertStringNotContainsString('corrección pendiente', $mensaje);
    }
}
