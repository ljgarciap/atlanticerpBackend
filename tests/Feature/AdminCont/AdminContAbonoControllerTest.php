<?php

declare(strict_types=1);

namespace Tests\Feature\AdminCont;

use App\Modules\AdminCont\Models\AdminContAbono;
use App\Modules\AdminCont\Models\AdminContBankAccount;
use App\Modules\AdminCont\Models\AdminContInvoice;
use App\Modules\VentasDiseno\Models\MasterClient;
use App\Modules\VentasDiseno\Models\SalesProject;
use App\Modules\VentasDiseno\Models\SubClient;
use App\Shared\Multitenancy\Tenant;
use Tests\Feature\FeatureTestCase;

/** SCRUM-902 — Registrar Abono (4O). */
class AdminContAbonoControllerTest extends FeatureTestCase
{
    private const CONTABILIDAD_EMAIL = 'contabilidad@test.com';

    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    private function loginAsContabilidad(): string
    {
        return $this->loginAs(self::CONTABILIDAD_EMAIL, self::CONTABILIDAD_EMAIL, 'lider_admin_contab');
    }

    private function makeBankAccount(): AdminContBankAccount
    {
        return AdminContBankAccount::create([
            'banco' => 'Banco Demo A', 'tipo_cuenta' => AdminContBankAccount::TIPO_CORRIENTE, 'ultimos_4_digitos' => (string) random_int(1000, 9999),
        ]);
    }

    private function basePayload(int $masterClientId, int $cuentaBancariaId): array
    {
        return [
            'master_client_id'   => $masterClientId,
            'monto_original'     => 400,
            'metodo_pago'        => 'transferencia',
            'cuenta_bancaria_id' => $cuentaBancariaId,
            'referencia'         => 'TRF-'.uniqid(),
        ];
    }

    // ── Escenario 1 — registro básico solo Cliente master ──────────────────────────────────────

    public function test_registro_basico_solo_cliente_master_queda_nivel_cliente_por_confirmar(): void
    {
        $master  = MasterClient::create(['name' => 'Cliente Abono '.uniqid()]);
        $account = $this->makeBankAccount();
        $token   = $this->loginAsContabilidad();

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/admin-contab/abonos', $this->basePayload($master->id, $account->id))
            ->assertStatus(201);

        $response->assertJson([
            'nivel_asociacion' => 'cliente',
            'estado'           => 'por_confirmar',
            'monto_disponible' => 400.0,
        ]);
    }

    // ── Escenario 2 — sub-cliente sin proyecto bloquea el registro ─────────────────────────────

    public function test_sub_cliente_sin_proyecto_bloquea_el_registro(): void
    {
        $master    = MasterClient::create(['name' => 'Cliente Abono '.uniqid()]);
        $subClient = SubClient::create(['master_client_id' => $master->id, 'business_name' => 'Sub '.uniqid(), 'tax_id' => 'TAX-'.uniqid()]);
        $account   = $this->makeBankAccount();
        $token     = $this->loginAsContabilidad();

        $payload = [...$this->basePayload($master->id, $account->id), 'sub_client_id' => $subClient->id];

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/admin-contab/abonos', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['sales_project_id']);
    }

    // ── Escenario 3 — nivel de asociación con Factura ──────────────────────────────────────────

    public function test_completar_hasta_factura_queda_nivel_factura(): void
    {
        $master  = MasterClient::create(['name' => 'Cliente Abono '.uniqid()]);
        $invoice = AdminContInvoice::create([
            'numero' => 'F-'.static::uniqueTestNumber(), 'order_id' => static::uniqueTestNumber(),
            'master_client_id' => $master->id, 'cliente' => $master->name, 'monto' => 1200, 'saldo_aplicado' => 0,
        ]);
        $account = $this->makeBankAccount();
        $token   = $this->loginAsContabilidad();

        $payload = [...$this->basePayload($master->id, $account->id), 'invoice_id' => $invoice->id];

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/admin-contab/abonos', $payload)
            ->assertStatus(201);

        $response->assertJson(['nivel_asociacion' => 'factura', 'factura' => $invoice->numero]);
    }

    // ── Escenario 4 — edición bloqueada tras confirmar ─────────────────────────────────────────

    public function test_editar_abono_por_confirmar_funciona_pero_confirmado_ya_no(): void
    {
        $master  = MasterClient::create(['name' => 'Cliente Abono '.uniqid()]);
        $account = $this->makeBankAccount();
        $token   = $this->loginAsContabilidad();

        $created = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/admin-contab/abonos', $this->basePayload($master->id, $account->id))
            ->assertStatus(201);
        $abonoId = $created->json('id');

        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/admin-contab/abonos/{$abonoId}", [...$this->basePayload($master->id, $account->id), 'monto_original' => 999])
            ->assertStatus(200)
            ->assertJson(['monto_original' => 999.0]);

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/admin-contab/abonos/{$abonoId}/confirm")
            ->assertStatus(200)
            ->assertJson(['estado' => 'confirmado']);

        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/admin-contab/abonos/{$abonoId}", $this->basePayload($master->id, $account->id))
            ->assertStatus(422);

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/admin-contab/abonos/{$abonoId}/confirm")
            ->assertStatus(422);
    }

    // ── §4.5 — excedente de un abono de Factura se convierte en saldo de Cliente master ────────

    public function test_excedente_de_abono_de_factura_al_cobrar_genera_abono_nuevo_a_nivel_cliente(): void
    {
        $master  = MasterClient::create(['name' => 'Cliente Abono '.uniqid()]);
        $invoice = AdminContInvoice::create([
            'numero' => 'F-'.static::uniqueTestNumber(), 'order_id' => static::uniqueTestNumber(),
            'master_client_id' => $master->id, 'cliente' => $master->name, 'monto' => 300, 'saldo_aplicado' => 0,
        ]);
        $account = $this->makeBankAccount();
        $token   = $this->loginAsContabilidad();

        // Abono de $500 (nivel Factura) sobre una factura de solo $300.
        $created = $this->withHeaders($this->authHeaders($token))->postJson('/api/admin-contab/abonos', [
            'master_client_id' => $master->id, 'invoice_id' => $invoice->id, 'monto_original' => 500,
            'metodo_pago' => 'transferencia', 'cuenta_bancaria_id' => $account->id, 'referencia' => 'TRF-EXC',
        ])->assertStatus(201);
        $abonoId = $created->json('id');
        $this->withHeaders($this->authHeaders($token))->postJson("/api/admin-contab/abonos/{$abonoId}/confirm")->assertStatus(200);

        // Cobro (efectivo confirma de inmediato) — el abono obligatorio cubre $300 (todo lo que la factura necesita).
        $this->withHeaders($this->authHeaders($token))->postJson('/api/admin-contab/payments', [
            'master_client_id' => $master->id, 'invoice_ids' => [$invoice->id],
            'metodo_pago' => 'efectivo', 'monto_recibido_estimado' => 0,
        ])->assertStatus(201);

        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $original = AdminContAbono::findOrFail($abonoId);
        $this->assertEquals(0.0, $original->montoDisponible(), 'El abono original queda agotado (monto_usado = monto_original).');

        $excedente = AdminContAbono::where('abono_origen_id', $abonoId)->first();
        $this->assertNotNull($excedente, 'Debe existir un abono nuevo trazable al original.');
        $this->assertSame('cliente', $excedente->nivelAsociacion());
        $this->assertEquals(200.0, $excedente->montoDisponible());
        $this->assertSame('confirmado', $excedente->estado);
    }

    // ── §4.4 Escenario 9 — aplicación parcial del saldo de cliente ─────────────────────────────

    public function test_aplicacion_parcial_del_saldo_de_cliente_reduce_solo_lo_usado(): void
    {
        $master  = MasterClient::create(['name' => 'Cliente Abono '.uniqid()]);
        $invoice = AdminContInvoice::create([
            'numero' => 'F-'.static::uniqueTestNumber(), 'order_id' => static::uniqueTestNumber(),
            'master_client_id' => $master->id, 'cliente' => $master->name, 'monto' => 1000, 'saldo_aplicado' => 0,
        ]);
        $account = $this->makeBankAccount();
        $token   = $this->loginAsContabilidad();

        $created = $this->withHeaders($this->authHeaders($token))->postJson('/api/admin-contab/abonos', [
            'master_client_id' => $master->id, 'monto_original' => 400,
            'metodo_pago' => 'transferencia', 'cuenta_bancaria_id' => $account->id, 'referencia' => 'TRF-PARCIAL',
        ])->assertStatus(201);
        $abonoId = $created->json('id');
        $this->withHeaders($this->authHeaders($token))->postJson("/api/admin-contab/abonos/{$abonoId}/confirm")->assertStatus(200);

        $this->withHeaders($this->authHeaders($token))->postJson('/api/admin-contab/payments', [
            'master_client_id' => $master->id, 'invoice_ids' => [$invoice->id], 'bank_account_id' => $account->id,
            'monto_saldo_cliente_aplicado' => 150, 'metodo_pago' => 'efectivo', 'monto_recibido_estimado' => 850,
        ])->assertStatus(201);

        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $abono = AdminContAbono::findOrFail($abonoId);
        $this->assertEquals(150.0, $abono->monto_usado);
        $this->assertEquals(250.0, $abono->montoDisponible());
        $this->assertSame('usado_parcialmente', $abono->estadoSaldo());
    }

    // ── Cascadas del formulario ─────────────────────────────────────────────────────────────

    public function test_cascadas_del_formulario_devuelven_solo_lo_del_padre_elegido(): void
    {
        $master     = MasterClient::create(['name' => 'Cliente Cascada '.uniqid()]);
        $subClient  = SubClient::create(['master_client_id' => $master->id, 'business_name' => 'Sub Cascada', 'tax_id' => 'TAX-'.uniqid()]);
        $otroSub    = SubClient::create(['master_client_id' => $master->id, 'business_name' => 'Otro Sub', 'tax_id' => 'TAX-'.uniqid()]);
        $project    = SalesProject::create(['sub_client_id' => $subClient->id, 'name' => 'Proyecto Cascada']);
        SalesProject::create(['sub_client_id' => $otroSub->id, 'name' => 'Proyecto de Otro Sub']);
        $token = $this->loginAsContabilidad();

        $subs = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/admin-contab/abonos/cascada/sub-clientes?master_client_id={$master->id}")
            ->assertStatus(200)->json('data');
        $this->assertCount(2, $subs);

        $projects = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/admin-contab/abonos/cascada/proyectos?sub_client_id={$subClient->id}")
            ->assertStatus(200)->json('data');
        $this->assertCount(1, $projects);
        $this->assertSame($project->id, $projects[0]['id']);
    }

    // ── SCRUM-947 — paginación estándar ─────────────────────────────────────────────────────

    public function test_index_pagina_20_por_defecto_y_expone_meta(): void
    {
        $master  = MasterClient::create(['name' => 'Cliente Paginacion '.uniqid()]);
        $account = $this->makeBankAccount();
        $token   = $this->loginAsContabilidad();

        for ($i = 0; $i < 25; $i++) {
            $this->withHeaders($this->authHeaders($token))
                ->postJson('/api/admin-contab/abonos', $this->basePayload($master->id, $account->id))
                ->assertStatus(201);
        }

        $page1 = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/abonos?master_client_id='.$master->id)
            ->assertStatus(200)->json();

        $this->assertCount(20, $page1['data']);
        $this->assertSame(['total' => 25, 'per_page' => 20, 'current_page' => 1, 'last_page' => 2], $page1['meta']);

        $page2 = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/abonos?master_client_id='.$master->id.'&page=2')
            ->assertStatus(200)->json();

        $this->assertCount(5, $page2['data']);
        $this->assertSame(2, $page2['meta']['current_page']);
        // Sin solapamiento entre páginas.
        $idsPagina1 = array_column($page1['data'], 'id');
        $idsPagina2 = array_column($page2['data'], 'id');
        $this->assertEmpty(array_intersect($idsPagina1, $idsPagina2));
    }

    public function test_index_respeta_per_page(): void
    {
        $master  = MasterClient::create(['name' => 'Cliente PerPage '.uniqid()]);
        $account = $this->makeBankAccount();
        $token   = $this->loginAsContabilidad();

        for ($i = 0; $i < 3; $i++) {
            $this->withHeaders($this->authHeaders($token))
                ->postJson('/api/admin-contab/abonos', $this->basePayload($master->id, $account->id))
                ->assertStatus(201);
        }

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/abonos?master_client_id='.$master->id.'&per_page=10')
            ->assertStatus(200)->json();

        $this->assertCount(3, $response['data']);
        $this->assertSame(10, $response['meta']['per_page']);
    }

    // ── SCRUM-948 — mismos criterios de búsqueda que Historial de cobros ───────────────────────

    public function test_index_filtra_por_metodo_pago_exacto(): void
    {
        $master  = MasterClient::create(['name' => 'Cliente Metodo '.uniqid()]);
        $account = $this->makeBankAccount();
        $token   = $this->loginAsContabilidad();

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/admin-contab/abonos', $this->basePayload($master->id, $account->id))
            ->assertStatus(201);
        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/admin-contab/abonos', [...$this->basePayload($master->id, $account->id), 'metodo_pago' => 'efectivo'])
            ->assertStatus(201);

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/abonos?master_client_id='.$master->id.'&metodo_pago=efectivo')
            ->assertStatus(200)->json();

        $this->assertCount(1, $response['data']);
        $this->assertSame('efectivo', $response['data'][0]['metodo_pago']);
    }

    public function test_index_filtra_por_rango_de_fecha_desde_hasta_inclusive(): void
    {
        $master  = MasterClient::create(['name' => 'Cliente Fecha '.uniqid()]);
        $account = $this->makeBankAccount();
        $token   = $this->loginAsContabilidad();

        $dentro = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/admin-contab/abonos', $this->basePayload($master->id, $account->id))
            ->assertStatus(201)->json('id');
        $fuera = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/admin-contab/abonos', $this->basePayload($master->id, $account->id))
            ->assertStatus(201)->json('id');

        // El límite del rango debe ser inclusive: "dentro" queda exactamente en desde/hasta.
        // postJson() resetea el search_path del tenant al terminar el request — reaplicar antes
        // de un Eloquent directo (mismo patrón que el resto de este archivo, ver línea ~165).
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        AdminContAbono::whereKey($dentro)->update(['fecha_registro' => '2026-06-15 10:00:00']);
        AdminContAbono::whereKey($fuera)->update(['fecha_registro' => '2026-07-01 10:00:00']);

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/abonos?master_client_id='.$master->id.'&desde=2026-06-15&hasta=2026-06-15')
            ->assertStatus(200)->json();

        $this->assertCount(1, $response['data']);
        $this->assertSame($dentro, $response['data'][0]['id']);
    }

    // ── SCRUM-955 punto 2 — folio propio ABN-XXX ────────────────────────────────────────────────

    public function test_registrar_abono_asigna_folio_abn_secuencial_y_unico(): void
    {
        $master  = MasterClient::create(['name' => 'Cliente Abono '.uniqid()]);
        $account = $this->makeBankAccount();
        $token   = $this->loginAsContabilidad();

        $r1 = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/admin-contab/abonos', $this->basePayload($master->id, $account->id))
            ->assertStatus(201);
        $r2 = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/admin-contab/abonos', $this->basePayload($master->id, $account->id))
            ->assertStatus(201);

        $this->assertMatchesRegularExpression('/^ABN-\d{3}$/', (string) $r1->json('numero_recibo'));
        $this->assertNotSame($r1->json('numero_recibo'), $r2->json('numero_recibo'));
    }

    // ── SCRUM-955 punto 3 — "Otros" (retención de impuestos / ajuste de cuenta) ─────────────────

    public function test_registrar_abono_con_retencion_de_impuestos_no_exige_cuenta_bancaria(): void
    {
        $master = MasterClient::create(['name' => 'Cliente Abono '.uniqid()]);
        $token  = $this->loginAsContabilidad();

        $payload = [
            'master_client_id' => $master->id,
            'monto_original'   => 150,
            'metodo_pago'      => 'retencion_impuestos',
            'referencia'       => 'RET-'.uniqid(),
        ];

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/admin-contab/abonos', $payload)
            ->assertStatus(201);

        $this->assertNull($response->json('cuenta_bancaria_id'));
    }

    public function test_registrar_abono_con_retencion_de_impuestos_ignora_cuenta_bancaria_si_llega(): void
    {
        // RN4 — defensa en profundidad: aunque el frontend mande una cuenta bancaria real para un
        // método que no la usa, el backend nunca la persiste (mismo criterio que PaymentService).
        $master  = MasterClient::create(['name' => 'Cliente Abono '.uniqid()]);
        $account = $this->makeBankAccount();
        $token   = $this->loginAsContabilidad();

        $payload = [
            'master_client_id'   => $master->id,
            'monto_original'     => 150,
            'metodo_pago'        => 'ajuste_cuenta',
            'cuenta_bancaria_id' => $account->id,
            'referencia'         => 'ADJ-'.uniqid(),
        ];

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/admin-contab/abonos', $payload)
            ->assertStatus(201);

        $this->assertNull($response->json('cuenta_bancaria_id'));
    }

    public function test_registrar_abono_con_transferencia_sigue_exigiendo_cuenta_bancaria(): void
    {
        $master = MasterClient::create(['name' => 'Cliente Abono '.uniqid()]);
        $token  = $this->loginAsContabilidad();

        $payload = [
            'master_client_id' => $master->id,
            'monto_original'   => 150,
            'metodo_pago'      => 'transferencia',
            'referencia'       => 'TRF-'.uniqid(),
        ];

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/admin-contab/abonos', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['cuenta_bancaria_id']);
    }

    // ── SCRUM-955 punto 1 — NO implementado, ver docblock de StoreAbonoRequest: un abono de nivel
    // Factura mayor a la factura sigue siendo válido a propósito (SCRUM-902 §4.5, excedente), y
    // ese comportamiento ya tiene su propio test (test_excedente_de_abono_de_factura_al_cobrar_
    // genera_abono_nuevo_a_nivel_cliente, arriba). Sin test de bloqueo acá hasta que Luis decida
    // cómo reconciliar el punto 1 del ticket con el excedente ya aprobado.

    public function test_registrar_abono_igual_al_monto_de_la_factura_se_permite(): void
    {
        $master  = MasterClient::create(['name' => 'Cliente Abono '.uniqid()]);
        $invoice = AdminContInvoice::create([
            'numero' => 'F-'.static::uniqueTestNumber(), 'order_id' => static::uniqueTestNumber(),
            'master_client_id' => $master->id, 'cliente' => $master->name, 'monto' => 500, 'saldo_aplicado' => 0,
        ]);
        $account = $this->makeBankAccount();
        $token   = $this->loginAsContabilidad();

        $payload = [...$this->basePayload($master->id, $account->id), 'invoice_id' => $invoice->id, 'monto_original' => 500];

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/admin-contab/abonos', $payload)
            ->assertStatus(201)
            ->assertJson(['monto_original' => 500.0]);
    }

    // ── SCRUM-955 punto 1 — monto de la factura expuesto en el selector de "Registrar Abono" ────

    public function test_facturas_por_subcliente_expone_el_monto_de_cada_factura(): void
    {
        $master    = MasterClient::create(['name' => 'Cliente Abono '.uniqid()]);
        $subClient = SubClient::create(['master_client_id' => $master->id, 'business_name' => 'Sub '.uniqid(), 'tax_id' => 'TAX-'.uniqid()]);
        $project   = SalesProject::create(['sub_client_id' => $subClient->id, 'name' => 'Proyecto '.uniqid()]);
        AdminContInvoice::create([
            'numero' => 'F-'.static::uniqueTestNumber(), 'order_id' => static::uniqueTestNumber(),
            'master_client_id' => $master->id, 'cliente' => $master->name, 'monto' => 777.5, 'saldo_aplicado' => 0,
            'sales_project_id' => $project->id,
        ]);
        $token = $this->loginAsContabilidad();

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/abonos/cascada/facturas-por-subcliente?sub_client_id='.$subClient->id)
            ->assertStatus(200)->json('data');

        $this->assertSame(777.5, $response[0]['monto']);
    }

    // ── SCRUM-955 punto 2 — recibo de abono en PDF ──────────────────────────────────────────────

    public function test_pdf_del_recibo_de_abono_devuelve_un_pdf_valido(): void
    {
        $master  = MasterClient::create(['name' => 'Cliente Abono '.uniqid()]);
        $account = $this->makeBankAccount();
        $token   = $this->loginAsContabilidad();

        $created = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/admin-contab/abonos', $this->basePayload($master->id, $account->id))
            ->assertStatus(201);

        $response = $this->withHeaders($this->authHeaders($token))
            ->get('/api/admin-contab/abonos/'.$created->json('id').'/pdf')
            ->assertStatus(200);

        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
    }

    public function test_pdf_de_abono_inexistente_devuelve_404(): void
    {
        $this->withHeaders($this->authHeaders($this->loginAsContabilidad()))
            ->get('/api/admin-contab/abonos/999999/pdf')
            ->assertStatus(404);
    }
}
