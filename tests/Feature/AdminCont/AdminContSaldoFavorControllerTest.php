<?php

declare(strict_types=1);

namespace Tests\Feature\AdminCont;

use App\Modules\AdminCont\Models\AdminContAbono;
use App\Modules\AdminCont\Models\AdminContBankAccount;
use App\Modules\AdminCont\Models\AdminContCreditNote;
use App\Modules\AdminCont\Models\AdminContInvoice;
use App\Modules\VentasDiseno\Models\MasterClient;
use App\Shared\Multitenancy\Tenant;
use Tests\Feature\FeatureTestCase;

/** SCRUM-903 — Saldo a Favor de Clientes (4N), pantalla de solo consulta. */
class AdminContSaldoFavorControllerTest extends FeatureTestCase
{
    private const CONTABILIDAD_EMAIL = 'contabilidad@test.com';

    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    private function loginAsFelix(): string
    {
        return $this->loginAs(self::CONTABILIDAD_EMAIL, self::CONTABILIDAD_EMAIL, 'lider_admin_contab');
    }

    private function makeBankAccount(): AdminContBankAccount
    {
        return AdminContBankAccount::create([
            'banco' => 'Banco General', 'tipo_cuenta' => AdminContBankAccount::TIPO_CORRIENTE, 'ultimos_4_digitos' => (string) random_int(1000, 9999),
        ]);
    }

    private function makeAbono(MasterClient $master, string $estado, float $monto = 300, float $usado = 0): AdminContAbono
    {
        return AdminContAbono::create([
            'master_client_id'   => $master->id,
            'monto_original'     => $monto,
            'monto_usado'        => $usado,
            'metodo_pago'        => 'transferencia',
            'cuenta_bancaria_id' => $this->makeBankAccount()->id,
            'referencia'         => 'TRF-'.uniqid(),
            'estado'             => $estado,
            'registrado_por'     => \App\Models\User::where('email', self::CONTABILIDAD_EMAIL)->value('id'),
            'fecha_registro'     => now(),
            'fecha_confirmacion' => $estado === AdminContAbono::ESTADO_CONFIRMADO ? now() : null,
        ]);
    }

    // ── Escenario 1/2 — un abono "Por confirmar" no aparece, y sí al confirmarse ───────────────

    public function test_abono_por_confirmar_no_aparece_pero_confirmado_si(): void
    {
        $master = MasterClient::create(['name' => 'Cliente Saldo '.uniqid()]);
        $pendiente = $this->makeAbono($master, AdminContAbono::ESTADO_POR_CONFIRMAR);
        $confirmado = $this->makeAbono($master, AdminContAbono::ESTADO_CONFIRMADO);

        $response = $this->withHeaders($this->authHeaders($this->loginAsFelix()))
            ->getJson('/api/admin-contab/saldo-favor')
            ->assertStatus(200);

        $referencias = array_column($response->json('rows'), 'referencia');
        $this->assertNotContains($pendiente->referencia, $referencias);
        $this->assertContains($confirmado->referencia, $referencias);
    }

    // ── Escenario 3 — Nota de Crédito siempre entra a nivel Cliente master ─────────────────────

    public function test_nota_credito_saldo_favor_siempre_aparece_a_nivel_cliente_master(): void
    {
        $master  = MasterClient::create(['name' => 'Cliente Saldo '.uniqid()]);
        $invoice = AdminContInvoice::create([
            'numero' => 'F-'.static::uniqueTestNumber(), 'order_id' => static::uniqueTestNumber(),
            'master_client_id' => $master->id, 'cliente' => $master->name, 'monto' => 300, 'saldo_aplicado' => 300,
        ]);
        AdminContCreditNote::create([
            'numero' => 'NC-'.static::uniqueTestNumber(), 'master_client_id' => $master->id, 'cliente' => $master->name,
            'tipo' => AdminContCreditNote::TIPO_DESCUENTO_COMERCIAL, 'factura_origen_id' => $invoice->id,
            'monto' => 400, 'subtotal' => 400, 'itbms' => 0, 'monto_aplicado_saldo' => 0, 'monto_usado' => 0,
            'motivo' => 'Excedente de prueba', 'resultado' => AdminContCreditNote::RESULTADO_SALDO_FAVOR,
            'estado' => AdminContCreditNote::ESTADO_APLICADA, 'registrado_por' => \App\Models\User::where('email', self::CONTABILIDAD_EMAIL)->value('id'),
            'fecha' => now(),
        ]);

        $response = $this->withHeaders($this->authHeaders($this->loginAsFelix()))
            ->getJson('/api/admin-contab/saldo-favor')
            ->assertStatus(200);

        $row = collect($response->json('rows'))->firstWhere('origen', 'Descuento comercial');
        $this->assertNotNull($row);
        $this->assertSame('Cliente master', $row['asociado_a']);
        $this->assertEquals(400.0, $row['monto_disponible']);
    }

    // ── Escenario 4 — Nota de Crédito rechazada nunca genera saldo ─────────────────────────────

    public function test_nota_credito_rechazada_nunca_genera_saldo(): void
    {
        $master  = MasterClient::create(['name' => 'Cliente Saldo '.uniqid()]);
        $invoice = AdminContInvoice::create([
            'numero' => 'F-'.static::uniqueTestNumber(), 'order_id' => static::uniqueTestNumber(),
            'master_client_id' => $master->id, 'cliente' => $master->name, 'monto' => 300, 'saldo_aplicado' => 300,
        ]);
        AdminContCreditNote::create([
            'numero' => 'NC-'.static::uniqueTestNumber(), 'master_client_id' => $master->id, 'cliente' => $master->name,
            'tipo' => AdminContCreditNote::TIPO_DESCUENTO_COMERCIAL, 'factura_origen_id' => $invoice->id,
            'monto' => 400, 'subtotal' => 400, 'itbms' => 0, 'monto_usado' => 0,
            'motivo' => 'Rechazada de prueba', 'resultado' => AdminContCreditNote::RESULTADO_SALDO_FAVOR,
            'estado' => AdminContCreditNote::ESTADO_RECHAZADA, 'registrado_por' => \App\Models\User::where('email', self::CONTABILIDAD_EMAIL)->value('id'),
            'fecha' => now(), 'motivo_rechazo' => 'No corresponde',
        ]);

        $response = $this->withHeaders($this->authHeaders($this->loginAsFelix()))
            ->getJson('/api/admin-contab/saldo-favor')
            ->assertStatus(200);

        $row = collect($response->json('rows'))->firstWhere('origen', 'Descuento comercial');
        $this->assertNull($row);
    }

    // ── Escenario 6 — aplicación parcial se refleja correctamente ──────────────────────────────

    public function test_aplicacion_parcial_de_abono_se_refleja_en_estado_y_montos(): void
    {
        $master = MasterClient::create(['name' => 'Cliente Saldo '.uniqid()]);
        $this->makeAbono($master, AdminContAbono::ESTADO_CONFIRMADO, monto: 400, usado: 150);

        $response = $this->withHeaders($this->authHeaders($this->loginAsFelix()))
            ->getJson('/api/admin-contab/saldo-favor')
            ->assertStatus(200);

        $row = collect($response->json('rows'))->firstWhere('origen', 'Abono de más');
        $this->assertNotNull($row);
        $this->assertEquals(150.0, $row['monto_usado']);
        $this->assertEquals(250.0, $row['monto_disponible']);
        $this->assertSame('usado_parcialmente', $row['estado']);
    }

    // ── Escenario 8 — filtros funcionan de forma independiente y combinada ────────────────────

    public function test_filtros_combinados_de_cliente_y_estado(): void
    {
        $masterA = MasterClient::create(['name' => 'Cliente Filtro A '.uniqid()]);
        $masterB = MasterClient::create(['name' => 'Cliente Filtro B '.uniqid()]);
        $this->makeAbono($masterA, AdminContAbono::ESTADO_CONFIRMADO, monto: 300, usado: 0);       // disponible
        $this->makeAbono($masterA, AdminContAbono::ESTADO_CONFIRMADO, monto: 300, usado: 300);     // agotado
        $this->makeAbono($masterB, AdminContAbono::ESTADO_CONFIRMADO, monto: 300, usado: 0);       // disponible, otro cliente

        $response = $this->withHeaders($this->authHeaders($this->loginAsFelix()))
            ->getJson('/api/admin-contab/saldo-favor?master_client_id='.$masterA->id.'&estado=disponible')
            ->assertStatus(200);

        $this->assertCount(1, $response->json('rows'));
        $this->assertSame($masterA->name, $response->json('rows.0.cliente'));
    }

    // ── Indicadores — siempre sobre el total real, sin importar el filtro aplicado ─────────────

    public function test_indicadores_no_se_afectan_por_el_filtro_de_la_tabla(): void
    {
        $masterA = MasterClient::create(['name' => 'Cliente Ind A '.uniqid()]);
        $masterB = MasterClient::create(['name' => 'Cliente Ind B '.uniqid()]);
        $this->makeAbono($masterA, AdminContAbono::ESTADO_CONFIRMADO, monto: 300, usado: 0);
        $this->makeAbono($masterB, AdminContAbono::ESTADO_CONFIRMADO, monto: 200, usado: 0);

        $response = $this->withHeaders($this->authHeaders($this->loginAsFelix()))
            ->getJson('/api/admin-contab/saldo-favor?master_client_id='.$masterA->id)
            ->assertStatus(200);

        $this->assertCount(1, $response->json('rows'));
        $this->assertEquals(500.0, $response->json('indicadores.saldo_disponible_total'));
        $this->assertEquals(2, $response->json('indicadores.clientes_con_saldo'));
    }

    // SCRUM-947 — antes este filtro era 100% client-side (el frontend nunca lo mandaba al
    // backend); con paginación real deja de tener sentido, se movió acá como "contiene" texto.
    public function test_filtro_de_texto_por_cliente(): void
    {
        $masterA = MasterClient::create(['name' => 'Restaurante Sensei '.uniqid()]);
        $masterB = MasterClient::create(['name' => 'Torres Pacífico '.uniqid()]);
        $this->makeAbono($masterA, AdminContAbono::ESTADO_CONFIRMADO, monto: 100, usado: 0);
        $this->makeAbono($masterB, AdminContAbono::ESTADO_CONFIRMADO, monto: 100, usado: 0);

        $response = $this->withHeaders($this->authHeaders($this->loginAsFelix()))
            ->getJson('/api/admin-contab/saldo-favor?cliente=Sensei')
            ->assertStatus(200);

        $this->assertCount(1, $response->json('rows'));
        $this->assertStringContainsString('Sensei', $response->json('rows.0.cliente'));
    }

    // ── SCRUM-947 — paginación estándar ─────────────────────────────────────────────────────

    public function test_index_pagina_20_por_defecto_sin_afectar_indicadores(): void
    {
        $master = MasterClient::create(['name' => 'Cliente Paginacion '.uniqid()]);
        for ($i = 0; $i < 25; $i++) {
            $this->makeAbono($master, AdminContAbono::ESTADO_CONFIRMADO, monto: 10, usado: 0);
        }

        $response = $this->withHeaders($this->authHeaders($this->loginAsFelix()))
            ->getJson('/api/admin-contab/saldo-favor')
            ->assertStatus(200);

        $this->assertCount(20, $response->json('rows'));
        $this->assertSame(['total' => 25, 'per_page' => 20, 'current_page' => 1, 'last_page' => 2], $response->json('meta'));
        // Indicadores siguen sobre el total real (25 × $10 = $250), no solo la página mostrada.
        $this->assertEquals(250.0, $response->json('indicadores.saldo_disponible_total'));
    }
}
