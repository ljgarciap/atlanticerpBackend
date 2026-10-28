<?php

declare(strict_types=1);

namespace Tests\Feature\AdminCont;

use App\Models\User;
use App\Modules\AdminCont\Models\AdminContBankMovement;
use App\Modules\AdminCont\Models\AdminContCreditNote;
use App\Modules\AdminCont\Models\AdminContFiscalSettings;
use App\Modules\AdminCont\Models\AdminContInvoice;
use App\Modules\AdminCont\Services\CommissionOrderService;
use App\Modules\Bodega\Models\Order;
use App\Modules\VentasDiseno\Models\MasterClient;
use App\Modules\VentasDiseno\Models\PipelineCard;
use App\Modules\VentasDiseno\Models\SalesProject;
use App\Modules\VentasDiseno\Models\SubClient;
use App\Shared\Multitenancy\Tenant;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Tests\Feature\FeatureTestCase;

/**
 * Batch 14 de Admin&Cont (SCRUM-575→579, REQ-498→502) — Comisiones Internas. Ver
 * docs/adr/ADR-SCRUM575-579-batch14-comisiones-internas.md.
 */
class AdminContInternalCommissionControllerTest extends FeatureTestCase
{
    private const APPROVER_EMAIL = 'gerencia3@test.com';

    private const CONTABILIDAD_EMAIL = 'contabilidad@test.com';

    private const ASISTENTE_EMAIL = 'asistenteadministrativa@test.com';

    private const DESIGNER_EMAIL = 'vendedor@atlantic.test';

    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    private function loginAsMark(): string
    {
        return $this->loginAs(self::APPROVER_EMAIL, self::APPROVER_EMAIL, 'management');
    }

    private function loginAsFelix(): string
    {
        return $this->loginAs(self::CONTABILIDAD_EMAIL, self::CONTABILIDAD_EMAIL, 'lider_admin_contab');
    }

    private function loginAsYaneth(): string
    {
        return $this->loginAs(self::ASISTENTE_EMAIL, self::ASISTENTE_EMAIL, 'asistente_administrativa');
    }

    /**
     * `vendedor@atlantic.test` es la cuenta demo (AuthUserSeeder, solo local/dev/testing) —
     * los 10 vendedores REALES reciben `admin_contab.commissions_internal.view` vía
     * `SpecialPermissionSeeder` (que no corre acá, ver docblock: "son personas y permisos reales,
     * no datos demo"), así que el fixture de test lo otorga directo, mismo patrón que el resto de
     * tests de permisos angostos en `tests/Feature/Compras/*.php`.
     */
    private function loginAsDesigner(): string
    {
        User::where('email', self::DESIGNER_EMAIL)
            ->update(['extra_permissions' => json_encode(['admin_contab.commissions_internal.view'])]);

        return $this->loginAs(self::DESIGNER_EMAIL, 'Password123!', 'designer');
    }

    /** Mismo patrón que AdminContAccountStatementControllerTest::userIdByEmailHelper(). */
    private function userIdByEmail(string $email): int
    {
        return (int) User::where('email', $email)->value('id');
    }

    /**
     * Pedido aprobado, con Order + AdminContInvoice, cobro configurable. `mesCierre` por defecto
     * el mes en curso; `compartidoCon` (ids de responsables adicionales, REQ-506) por defecto
     * ninguno. Devuelve la factura creada para poder colgarle notas de crédito (REQ-503).
     */
    private function makeCommissionableOrder(
        int $ownerId,
        float $amount,
        float $totalCobrado,
        ?Carbon $mesCierre = null,
        ?array $compartidoCon = null,
    ): AdminContInvoice {
        $master = MasterClient::create(['name' => 'Cliente Comisión '.uniqid()]);
        $sub = SubClient::create(['master_client_id' => $master->id, 'business_name' => 'Subcliente Comisión '.uniqid(), 'tax_id' => '155-0000-1-'.random_int(1000, 9999)]);
        $project = SalesProject::create(['sub_client_id' => $sub->id, 'name' => 'Proyecto Comisión '.uniqid()]);
        $card = PipelineCard::create([
            'sales_project_id' => $project->id, 'stage' => 'approved', 'master_client_id' => $master->id,
            'sub_client_id' => $sub->id, 'owner_id' => $ownerId, 'amount' => $amount,
            'stage_changed_at' => $mesCierre ?? Carbon::now(), 'compartido_con' => $compartidoCon,
        ]);
        $order = Order::create([
            'order_number' => 'PED-COM-'.random_int(1000, 999999), 'pipeline_card_id' => $card->id,
            'order_type' => Order::ORDER_TYPE_PEDIDO, 'stage' => Order::STAGE_POR_DESPACHAR,
            'customer_name' => $sub->business_name,
        ]);

        return AdminContInvoice::create([
            'numero' => 'F-COM-'.random_int(1000, 999999), 'order_id' => $order->id, 'cliente' => $sub->business_name,
            'master_client_id' => $master->id, 'monto' => $amount, 'saldo_aplicado' => $totalCobrado,
        ]);
    }

    /** REQ-503 — nota de crédito real, `estado` configurable (solo 'aplicada' resta la base). */
    private function makeCreditNote(AdminContInvoice $factura, float $monto, string $estado = AdminContCreditNote::ESTADO_APLICADA): AdminContCreditNote
    {
        return AdminContCreditNote::create([
            'numero' => 'NC-'.random_int(1000, 999999), 'master_client_id' => $factura->master_client_id, 'cliente' => $factura->cliente,
            'tipo' => AdminContCreditNote::TIPO_DESCUENTO_COMERCIAL, 'factura_origen_id' => $factura->id,
            'monto' => $monto, 'subtotal' => $monto, 'itbms' => 0, 'motivo' => 'Ajuste de prueba',
            'estado' => $estado, 'registrado_por' => $this->userIdByEmail(self::CONTABILIDAD_EMAIL), 'fecha' => Carbon::now(),
        ]);
    }

    // ---- CommissionOrderService, sin HTTP ----

    public function test_percent_for_usa_los_6_tramos_seedeados(): void
    {
        $service = app(CommissionOrderService::class);

        $this->assertSame(1.5, $service->percentFor(15000));
        $this->assertSame(2.0, $service->percentFor(20001));
        $this->assertSame(3.0, $service->percentFor(60000));
        $this->assertSame(4.0, $service->percentFor(81000));
        $this->assertSame(4.5, $service->percentFor(120000));
        $this->assertSame(5.0, $service->percentFor(500000));
    }

    public function test_estado_for_abono_parcial_permanece_pendiente_de_cobro(): void
    {
        $service = app(CommissionOrderService::class);
        $mes = CarbonImmutable::now()->startOfMonth();

        // RN2 REQ-501: $3,000 de $6,600 — abono parcial, nunca dispara comisión.
        $this->assertSame(
            CommissionOrderService::ESTADO_PENDIENTE_COBRO,
            $service->estadoFor($mes, 6600.0, 3000.0, 6600.0),
        );
    }

    public function test_estado_for_cobro_completo_en_mes_en_curso_es_por_pagar(): void
    {
        $service = app(CommissionOrderService::class);
        $mes = CarbonImmutable::now()->startOfMonth();

        // RN3 REQ-501: 100% cobrado, mes de cierre = mes en curso → ganada pero no liquidada.
        $this->assertSame(
            CommissionOrderService::ESTADO_POR_PAGAR,
            $service->estadoFor($mes, 6600.0, 6600.0, 6600.0),
        );
    }

    public function test_estado_for_mes_cerrado_ya_cobrado_es_pagado(): void
    {
        $service = app(CommissionOrderService::class);
        $mesPasado = CarbonImmutable::now()->subMonth()->startOfMonth();

        // RN4/RN5 REQ-501: mes de cierre anterior al mes en curso + 100% cobrado → liquidada.
        $this->assertSame(
            CommissionOrderService::ESTADO_PAGADO,
            $service->estadoFor($mesPasado, 6600.0, 6600.0, 6600.0),
        );
    }

    public function test_estado_for_sin_factura_es_pendiente_de_cobro(): void
    {
        $service = app(CommissionOrderService::class);
        $this->assertSame(
            CommissionOrderService::ESTADO_PENDIENTE_COBRO,
            $service->estadoFor(null, null, 0.0, 0.0),
        );
    }

    /**
     * SCRUM-929 (B3b, decisión de Luis 2026-09-19) — mismo bug que en Externas: con la regla vieja
     * (solo cobrado vs facturado, sin comparar contra el pedido completo), tras cobrar la 1ª parcial
     * el pedido pasaba a `por_pagar`/`pagado` con base parcial y, al emitirse la 2ª factura, volvía
     * a `pendiente_cobro` — una comisión ya "pagada" retrocedía. Ahora exige que lo facturado
     * alcance el pedido completo antes de dejar `pendiente_cobro`.
     */
    public function test_estado_for_una_parcial_cobrada_del_pedido_permanece_pendiente_de_cobro(): void
    {
        $service = app(CommissionOrderService::class);
        $mes = CarbonImmutable::now()->startOfMonth();

        $this->assertSame(
            CommissionOrderService::ESTADO_PENDIENTE_COBRO,
            $service->estadoFor($mes, 321.0, 321.0, 963.0),
        );
    }

    // ---- Endpoint /commissions/internal ----

    public function test_summary_agrega_tarjetas_del_mes_para_vista_de_equipo(): void
    {
        $vendorId = $this->userIdByEmail(self::DESIGNER_EMAIL);

        // 100% cobrado este mes → por_pagar.
        $this->makeCommissionableOrder($vendorId, 20000.0, 20000.0);
        // Abono parcial → pendiente_cobro (estimado sobre total_facturado).
        $this->makeCommissionableOrder($vendorId, 5000.0, 2000.0);

        $token = $this->loginAsFelix();

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/commissions/internal')
            ->assertStatus(200);

        $response->assertJsonPath('puede_ver_equipo', true);
        $response->assertJsonPath('puede_exportar', true);
        // 25000 (int, no 25000.0) — json_encode de PHP serializa un float sin parte decimal sin
        // ".0" (a menos que se use JSON_PRESERVE_ZERO_FRACTION, que este proyecto no setea en
        // ningún endpoint), así que json_decode lo trae de vuelta como int. El dato interno sigue
        // siendo float — es solo la forma serializada de un entero exacto.
        $response->assertJsonPath('total_pedidos_mes', 25000);
        $response->assertJsonPath('vendedores_con_pedidos_mes', 1);
        // 25000 total → tramo [20001,35000] = 2% (RN1 REQ-502, "provisional" mientras el mes no
        // cierre). OJO: 20000.0 exacto caería en el tramo anterior (1.5%, monto_maximo=20000
        // inclusive) — se usa 25000 a propósito para no quedar en el límite ambiguo.
        $vendedores = $response->json('vendedores');
        $row = collect($vendedores)->firstWhere('vendedor_id', $vendorId);
        $this->assertNotNull($row);
        $this->assertSame(2, $row['porcentaje']); // mismo motivo que total_pedidos_mes arriba
        $this->assertFalse($row['porcentaje_fijo']);

        // groups[] envuelve los pedidos del mes filtrado (forma CommissionVendorMonthGroup del
        // frontend) — 2 pedidos: uno por_pagar (100% cobrado) y uno pendiente_cobro (abono parcial).
        $this->assertCount(1, $row['groups']);
        $this->assertSame('por_pagar', collect($row['groups'][0]['pedidos'])->firstWhere('total_pedido', 20000.0)['estado']);
        $abono = collect($row['groups'][0]['pedidos'])->firstWhere('total_pedido', 5000.0);
        $this->assertSame('pendiente_cobro', $abono['estado']);
        $this->assertTrue($abono['es_abono_parcial']);
    }

    /**
     * REQ-505 Escenario 2 (Pre-QA/Visual Review Batch 15, 2026-08-25): un `vendedor`
     * sin NINGÚN pedido en toda su historia (no solo en el mes filtrado) es seleccionable en el
     * filtro (`vendorOptions()` lista el rol completo), pero antes de este fix `vendorIds` en
     * `buildSummary()` salía únicamente de `ordersForAllVendors()->pluck('vendedor_id')` — un
     * vendedor sin ningún pedido nunca aparecía ahí, así que filtrar a ese vendedor devolvía
     * `vendedores: []` (el estado "Sin vendedores para este filtro." genérico de la tabla) en vez
     * de una fila con `groups: []` que el frontend puede expandir para mostrar "Sin pedidos
     * registrados todavía." — reproducido contra dev.atlanticerp.ai con el usuario real "Vendedor Demo".
     */
    public function test_summary_vendedor_sin_ningun_pedido_en_su_historia_aparece_como_fila_vacia(): void
    {
        $vendorId = $this->userIdByEmail(self::DESIGNER_EMAIL);
        // sin ningún makeCommissionableOrder() para este vendedor — cero pedidos en TODA su historia.

        $token = $this->loginAsFelix();

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/admin-contab/commissions/internal?vendedor_id={$vendorId}")
            ->assertStatus(200);

        $vendedores = $response->json('vendedores');
        $this->assertCount(1, $vendedores, 'el vendedor sin pedidos debe tener su propia fila, no una lista vacía');
        $row = $vendedores[0];
        $this->assertSame($vendorId, $row['vendedor_id']);
        // 0 (int), no 0.0 — mismo gotcha de serialización ya documentado arriba en este archivo.
        $this->assertSame(0, $row['total_pedidos_mes']);
        $this->assertSame([], $row['groups']);
    }

    /**
     * Mismo escenario pero sin filtrar a un vendedor puntual — el roster completo del rol
     * `vendedor` debe aparecer siempre (con o sin pedidos), no solo quienes ya tienen
     * algún pedido en el histórico.
     */
    public function test_summary_sin_filtro_incluye_vendedores_sin_ningun_pedido(): void
    {
        $vendorId = $this->userIdByEmail(self::DESIGNER_EMAIL);
        $token = $this->loginAsFelix();

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/commissions/internal')
            ->assertStatus(200);

        $vendedores = collect($response->json('vendedores'));
        $row = $vendedores->firstWhere('vendedor_id', $vendorId);
        $this->assertNotNull($row, 'el roster completo de vendedor debe listarse aunque no tenga pedidos');
        $this->assertSame([], $row['groups']);
    }

    /**
     * Regresión de Pre-QA/Visual Review (2026-08-25): el frontend gateaba el CRUD de tramos
     * (botones editar/eliminar/agregar del modal "Tabla de comisión escalonada") con
     * `admin_contab.edit` — Contabilidad también tiene ese flag sin ser Gerencia, así que veía los
     * controles y se encontraba con un 403 real al intentar guardar (`primary_approver_only` gatea
     * POST/PUT/DELETE .../tiers). `puede_editar_tramos` es el cómputo server-side dependiente
     * del actor (`current_user_id === primary_approver_user_id`), mismo criterio ya establecido en
     * `puede_decidir_incobrable` (AdminContInvoiceController::detail()).
     */
    public function test_summary_puede_editar_tramos_solo_para_mark(): void
    {
        $contabilidadToken = $this->loginAsFelix();
        $this->withHeaders($this->authHeaders($contabilidadToken))
            ->getJson('/api/admin-contab/commissions/internal')
            ->assertStatus(200)
            ->assertJsonPath('puede_editar_tramos', false);

        $markToken = $this->loginAsMark();
        $this->withHeaders($this->authHeaders($markToken))
            ->getJson('/api/admin-contab/commissions/internal')
            ->assertStatus(200)
            ->assertJsonPath('puede_editar_tramos', true);
    }

    public function test_summary_autofiltra_a_un_vendedor_sin_view_team_y_le_oculta_exportar(): void
    {
        $vendorId = $this->userIdByEmail(self::DESIGNER_EMAIL);
        $this->makeCommissionableOrder($vendorId, 10000.0, 10000.0);

        // Otro vendedor — no debería aparecer en la vista del designer.
        $contabilidadId = $this->userIdByEmail(self::CONTABILIDAD_EMAIL);
        $this->makeCommissionableOrder($contabilidadId, 30000.0, 30000.0);

        $token = $this->loginAsDesigner();

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/commissions/internal')
            ->assertStatus(200);

        $response->assertJsonPath('puede_ver_equipo', false);
        $response->assertJsonPath('puede_exportar', false);
        $vendedores = $response->json('vendedores');
        $this->assertCount(1, $vendedores);
        $this->assertSame($vendorId, $vendedores[0]['vendedor_id']);
    }

    /**
     * SCRUM-881 (corrección de Gerencia, 2026-09-09) — regresión del bug real: Asistente
     * Administrativa (Asistente Administrativa) quedaba auto-filtrada a su propia fila, como si fuera vendedora
     * (`can_view_team` en `false` para su rol en `admin_contab`, ver
     * `AdminContRoleVisibilityBackfillSeeder`). Debe ver la tabla COMPLETA de vendedores (11
     * reales + el fixture de este test), igual que Contabilidad, con `puede_ver_equipo`/`puede_exportar`
     * en `true` y los indicadores agregados sobre TODOS los vendedores, no solo ella.
     */
    public function test_asistente_ve_la_tabla_completa_de_vendedores_no_solo_su_propia_fila(): void
    {
        $designerId = $this->userIdByEmail(self::DESIGNER_EMAIL);
        $this->makeCommissionableOrder($designerId, 20000.0, 20000.0);
        $contabilidadId = $this->userIdByEmail(self::CONTABILIDAD_EMAIL);
        $this->makeCommissionableOrder($contabilidadId, 30000.0, 30000.0);

        $token = $this->loginAsYaneth();

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/commissions/internal')
            ->assertStatus(200);

        $response->assertJsonPath('puede_ver_equipo', true);
        $response->assertJsonPath('puede_exportar', true);
        // total_pedidos_mes agregado sobre TODOS los vendedores (50000 = 20000 + 30000), no solo
        // el de Asistente Administrativa (que no tiene ningún pedido propio acá — confirma que no se auto-filtró).
        $response->assertJsonPath('total_pedidos_mes', 50000);

        $vendedores = collect($response->json('vendedores'));
        $this->assertGreaterThan(1, $vendedores->count(), 'Asistente Administrativa debe ver la tabla completa, no una sola fila.');
        $this->assertNotNull($vendedores->firstWhere('vendedor_id', $designerId), 'Debe incluir vendedores que no son ella misma.');
        $this->assertNotNull($vendedores->firstWhere('vendedor_id', $contabilidadId), 'Debe incluir vendedores que no son ella misma.');
    }

    public function test_export_prohibido_para_un_vendedor(): void
    {
        $token = $this->loginAsDesigner();

        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/commissions/internal/export')
            ->assertStatus(403);
    }

    /**
     * Camino feliz de exportar — encontrado en Senior Review sin cobertura propia (solo el 403
     * de arriba): `CommissionExportService`/la vista PDF seguían leyendo los nombres de campo
     * viejos de `summaryForVendor()` (`vendedor`/`total_pedidos`/`fijo`/`pendiente`) tras el
     * reshape del contrato — habría tirado 500 en el primer export real. `format` (no `formato`)
     * es a propósito, mismo nombre que manda el frontend.
     */
    public function test_export_pdf_exitoso_para_contabilidad(): void
    {
        $vendorId = $this->userIdByEmail(self::DESIGNER_EMAIL);
        $this->makeCommissionableOrder($vendorId, 20000.0, 20000.0);

        $token = $this->loginAsFelix();

        $response = $this->withHeaders($this->authHeaders($token))
            ->get('/api/admin-contab/commissions/internal/export?format=pdf')
            ->assertStatus(200);

        $this->assertStringContainsString('application/pdf', (string) $response->headers->get('Content-Type'));
    }

    public function test_export_excel_exitoso_para_contabilidad(): void
    {
        $vendorId = $this->userIdByEmail(self::DESIGNER_EMAIL);
        $this->makeCommissionableOrder($vendorId, 20000.0, 20000.0);

        $token = $this->loginAsFelix();

        $response = $this->withHeaders($this->authHeaders($token))
            ->get('/api/admin-contab/commissions/internal/export?format=excel')
            ->assertStatus(200);

        $this->assertStringContainsString('spreadsheetml', (string) $response->headers->get('Content-Type'));
    }

    public function test_vendor_options_lista_vendedor_disenador_para_contabilidad(): void
    {
        $token = $this->loginAsFelix();

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/commissions/internal/vendors')
            ->assertStatus(200);

        $nombres = collect($response->json())->pluck('nombre');
        $this->assertTrue($nombres->contains('Vendedor Demo'));
    }

    public function test_vendor_options_prohibido_para_un_vendedor(): void
    {
        $token = $this->loginAsDesigner();

        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/commissions/internal/vendors')
            ->assertStatus(403);
    }

    public function test_tiers_index_visible_para_un_vendedor(): void
    {
        $token = $this->loginAsDesigner();

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/commissions/internal/tiers')
            ->assertStatus(200);

        $response->assertJsonCount(6);
    }

    public function test_tier_crud_exclusivo_de_mark(): void
    {
        $contabilidadToken = $this->loginAsFelix();

        $this->withHeaders($this->authHeaders($contabilidadToken))
            ->postJson('/api/admin-contab/commissions/internal/tiers', [
                'monto_minimo' => 0, 'monto_maximo' => 1000, 'porcentaje' => 1, 'orden' => 99,
            ])
            ->assertStatus(403);
    }

    public function test_tier_store_por_mark(): void
    {
        $token = $this->loginAsMark();

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/admin-contab/commissions/internal/tiers', [
                'monto_minimo' => 500001, 'monto_maximo' => null, 'porcentaje' => 6, 'orden' => 7,
            ])
            ->assertStatus(201);

        $response->assertJsonPath('porcentaje', 6);
    }

    /**
     * Rebote de QA (SCRUM-575, 2026-08-26, OBS-1): "Agregar tramo" dejaba el tramo nuevo al final
     * de la lista en vez de ordenado por monto mínimo — el frontend mandaba `orden` como
     * `sorted.length + 1` (push al final) sin importar dónde caía `monto_minimo`. El backend ahora
     * recalcula `orden` de TODOS los tramos por `monto_minimo` tras cada escritura, server-side.
     */
    public function test_tier_store_reordena_automaticamente_por_monto_minimo(): void
    {
        $token = $this->loginAsMark();

        // Cae entre los tramos [81001,120000] y [120001,∞) — un `orden` a propósito "de trámite"
        // (99), simulando el push-al-final que hacía el frontend antes del fix.
        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/admin-contab/commissions/internal/tiers', [
                'monto_minimo' => 90000, 'monto_maximo' => 100000, 'porcentaje' => 4.2, 'orden' => 99,
            ])
            ->assertStatus(201);

        $tiers = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/commissions/internal/tiers')
            ->assertStatus(200)
            ->json();

        $montos = collect($tiers)->pluck('monto_minimo')->values();
        $this->assertSame($montos->sort()->values()->all(), $montos->all(), 'La lista debe quedar ordenada por monto_minimo ascendente.');

        $this->assertSame(6, collect($tiers)->firstWhere('monto_minimo', 90000)['orden']);
        $this->assertSame(7, collect($tiers)->firstWhere('monto_minimo', 120001)['orden']);
    }

    /** Mismo fix que arriba — borrar un tramo del medio no debe dejar huecos en `orden`. */
    public function test_tier_destroy_resequencia_orden_sin_huecos(): void
    {
        $token = $this->loginAsMark();
        $before = $this->withHeaders($this->authHeaders($token))->getJson('/api/admin-contab/commissions/internal/tiers')->json();
        $middleId = collect($before)->firstWhere('monto_minimo', 35001)['id'];

        $this->withHeaders($this->authHeaders($token))
            ->deleteJson("/api/admin-contab/commissions/internal/tiers/{$middleId}")
            ->assertStatus(204);

        $after = $this->withHeaders($this->authHeaders($token))->getJson('/api/admin-contab/commissions/internal/tiers')->json();
        $this->assertCount(5, $after);
        $this->assertSame([1, 2, 3, 4, 5], collect($after)->pluck('orden')->sort()->values()->all());
    }

    /**
     * Rebote de QA (SCRUM-577, 2026-08-26) — RN3: el filtro de vendedor_id angosta la tabla de
     * vendedores, pero las 4 tarjetas de resumen del mes deben seguir mostrando el total sin
     * filtrar. Antes, las tarjetas sumaban las mismas filas que la tabla, ya angostadas por el
     * filtro.
     */
    public function test_summary_tarjetas_de_resumen_ignoran_el_filtro_de_vendedor(): void
    {
        $vendorA = $this->userIdByEmail(self::DESIGNER_EMAIL);
        $vendorB = $this->userIdByEmail('vendedor2@test.com');

        $this->makeCommissionableOrder($vendorA, 10000.0, 10000.0);
        $this->makeCommissionableOrder($vendorB, 8000.0, 4000.0);

        $token = $this->loginAsFelix();

        $unfiltered = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/commissions/internal')
            ->assertStatus(200)->json();

        $filtered = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/commissions/internal?vendedor_id='.$vendorA)
            ->assertStatus(200)->json();

        // SCRUM-904 — el banner de "se pagará automáticamente" sigue la misma regla que las 4
        // tarjetas: no se angosta por el filtro manual de vendedor_id (solo por falta de team-view).
        foreach (['total_pedidos_mes', 'ya_pagada', 'por_pagar', 'pendiente_cobro', 'banner_comisiones_count', 'banner_comisiones_total'] as $key) {
            $this->assertSame($unfiltered[$key], $filtered[$key], "La tarjeta {$key} no debe cambiar con el filtro de vendedor.");
        }

        $this->assertCount(1, $filtered['vendedores']);
        $this->assertSame($vendorA, $filtered['vendedores'][0]['vendedor_id']);
    }

    /**
     * SCRUM-904 — un Vendedor/Diseñador sin team-view debe ver el banner "se pagará
     * automáticamente al cierre de mes" calculado SOLO sobre sus propias comisiones "por pagar",
     * nunca el total agregado de todos los vendedores (bug real: Vendedor Test 2 con $0.00 "por pagar"
     * veía el mismo texto "4 comisión(es) por USD 876.04" que Asistente Administrativa).
     */
    public function test_banner_auto_pago_se_angosta_al_vendedor_sin_view_team_scrum904(): void
    {
        $designerId = $this->userIdByEmail(self::DESIGNER_EMAIL);
        $contabilidadId = $this->userIdByEmail(self::CONTABILIDAD_EMAIL);

        // Designer: pedido propio, cobrado, "por pagar" — 8000 * 1.5% = 120.
        $this->makeCommissionableOrder($designerId, 8000.0, 8000.0);
        // Otro vendedor con una comisión "por pagar" grande — NO debe filtrarse al banner del designer.
        $this->makeCommissionableOrder($contabilidadId, 30000.0, 30000.0);

        $token = $this->loginAsDesigner();
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/commissions/internal')
            ->assertStatus(200);

        $response->assertJsonPath('banner_comisiones_count', 1);
        $this->assertEqualsWithDelta(120.0, $response->json('banner_comisiones_total'), 0.01);
    }

    /**
     * SCRUM-904 — caso de prueba explícito del ticket: un vendedor sin NINGUNA comisión "por
     * pagar" (todo en $0.00) no debe ver el banner reflejando comisiones/montos de otro vendedor.
     */
    public function test_banner_auto_pago_en_cero_no_muestra_comisiones_ajenas_scrum904(): void
    {
        $designerId = $this->userIdByEmail(self::DESIGNER_EMAIL);
        $contabilidadId = $this->userIdByEmail(self::CONTABILIDAD_EMAIL);

        // Designer: facturado pero todavía NO cobrado → pendiente_cobro, no cuenta para el banner.
        $this->makeCommissionableOrder($designerId, 8000.0, 0.0);
        // Contabilidad: comisión "por pagar" real de otro vendedor — no debe "prestarse" al designer.
        $this->makeCommissionableOrder($contabilidadId, 30000.0, 30000.0);

        $token = $this->loginAsDesigner();
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/commissions/internal')
            ->assertStatus(200);

        $response->assertJsonPath('banner_comisiones_count', 0);
        // json_encode(0.0) serializa como `0` (sin JSON_PRESERVE_ZERO_FRACTION) → json_decode lo
        // trae como int(0), no float(0.0); assertSame con tipos estrictos fallaría sin ser un bug
        // real. Mismo patrón de delta ya usado en el resto del archivo (ver línea 551).
        $this->assertEqualsWithDelta(0.0, $response->json('banner_comisiones_total'), 0.01);
    }

    // ==== Batch 15 (SCRUM-580→584, REQ-503→507) ====================================

    // ---- REQ-503 — notas de crédito restan la base de comisión ----

    public function test_nota_credito_aplicada_reduce_la_base_de_comision(): void
    {
        $vendorId = $this->userIdByEmail(self::DESIGNER_EMAIL);
        // 100% cobrado → base bruta $6,600; NC aplicada de $200 → base real $6,400.
        $factura = $this->makeCommissionableOrder($vendorId, 6600.0, 6600.0);
        $nc = $this->makeCreditNote($factura, 200.0);

        $token = $this->loginAsFelix();
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/commissions/internal')
            ->assertStatus(200);

        $row = collect($response->json('vendedores'))->firstWhere('vendedor_id', $vendorId);
        $pedido = collect($row['groups'][0]['pedidos'])->first();

        $this->assertSame(200, $pedido['total_nota_credito']);
        $this->assertSame($nc->numero, $pedido['nota_credito_ref']);
        // 1.5% (tramo <20000) sobre $6,400 (no $6,600) = $96.00.
        $this->assertSame(96, $pedido['monto_comision']);
        $this->assertSame(200, $row['total_nota_credito']);
    }

    public function test_nota_credito_pendiente_de_aprobacion_no_reduce_la_base(): void
    {
        $vendorId = $this->userIdByEmail(self::DESIGNER_EMAIL);
        $factura = $this->makeCommissionableOrder($vendorId, 6600.0, 6600.0);
        $this->makeCreditNote($factura, 200.0, AdminContCreditNote::ESTADO_PENDIENTE_APROBACION);

        $token = $this->loginAsFelix();
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/commissions/internal')
            ->assertStatus(200);

        $row = collect($response->json('vendedores'))->firstWhere('vendedor_id', $vendorId);
        $pedido = collect($row['groups'][0]['pedidos'])->first();

        $this->assertSame(0, $pedido['total_nota_credito']);
        // 1.5% sobre $6,600 completo = $99.00, sin descuento — la nota no está aplicada todavía.
        $this->assertSame(99, $pedido['monto_comision']);
    }

    // ---- REQ-504 — arrastre correcto (deja de aparecer una vez pagado) ----

    public function test_arrastre_pedido_sin_cobrar_de_mes_cerrado_aparece_en_el_mes_actual(): void
    {
        $vendorId = $this->userIdByEmail(self::DESIGNER_EMAIL);
        // Mes anterior, abono parcial — sigue pendiente, debe arrastrarse.
        $this->makeCommissionableOrder($vendorId, 6600.0, 3000.0, Carbon::now()->subMonth());

        $token = $this->loginAsFelix();
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/commissions/internal')
            ->assertStatus(200);

        $row = collect($response->json('vendedores'))->firstWhere('vendedor_id', $vendorId);
        $this->assertCount(1, $row['groups']);
        $this->assertTrue($row['groups'][0]['arrastrado']);
        $this->assertSame('pendiente_cobro', $row['groups'][0]['pedidos'][0]['estado']);
    }

    public function test_arrastre_pedido_ya_pagado_deja_de_aparecer(): void
    {
        $vendorId = $this->userIdByEmail(self::DESIGNER_EMAIL);
        // 2 meses atrás, 100% cobrado → mes ya cerrado y ya liquidado ("pagado"), RN2 REQ-504:
        // no debe seguir arrastrándose al ver el mes actual.
        $this->makeCommissionableOrder($vendorId, 6600.0, 6600.0, Carbon::now()->subMonths(2));

        $token = $this->loginAsFelix();
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/commissions/internal')
            ->assertStatus(200);

        $vendedores = collect($response->json('vendedores'));
        // Sin pedidos relevantes este mes → el vendedor ni siquiera aparece en `vendedores` (0
        // pedidos comisionables en el filtro actual) o, si aparece, sin groups.
        $row = $vendedores->firstWhere('vendedor_id', $vendorId);
        if ($row !== null) {
            $this->assertCount(0, $row['groups']);
            $this->assertSame(0, $row['pagada']);
        }
    }

    public function test_groups_agrupa_mes_actual_y_arrastrado_por_separado(): void
    {
        $vendorId = $this->userIdByEmail(self::DESIGNER_EMAIL);
        $this->makeCommissionableOrder($vendorId, 10000.0, 10000.0); // mes actual, por_pagar
        $this->makeCommissionableOrder($vendorId, 5000.0, 1000.0, Carbon::now()->subMonth()); // arrastrado, pendiente

        $token = $this->loginAsFelix();
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/commissions/internal')
            ->assertStatus(200);

        $row = collect($response->json('vendedores'))->firstWhere('vendedor_id', $vendorId);
        $this->assertCount(2, $row['groups']);
        $arrastrados = collect($row['groups'])->where('arrastrado', true);
        $this->assertCount(1, $arrastrados);
        $actual = collect($row['groups'])->firstWhere('arrastrado', false);
        $this->assertNotNull($actual);
    }

    // ---- REQ-506 — proyectos compartidos ----

    public function test_proyecto_compartido_divide_el_monto_en_partes_iguales_entre_2(): void
    {
        $vendorId = $this->userIdByEmail(self::DESIGNER_EMAIL);
        $contabilidadId = $this->userIdByEmail(self::CONTABILIDAD_EMAIL);

        $this->makeCommissionableOrder($vendorId, 20000.0, 20000.0, null, [$contabilidadId]);

        $token = $this->loginAsFelix();
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/commissions/internal')
            ->assertStatus(200);

        $vendedores = collect($response->json('vendedores'));
        foreach ([$vendorId, $contabilidadId] as $id) {
            $row = $vendedores->firstWhere('vendedor_id', $id);
            $this->assertNotNull($row, "vendedor {$id} debería tener una fila comisionable");
            $pedido = collect($row['groups'][0]['pedidos'])->first();
            $this->assertSame(10000, $pedido['total_pedido']);
            $this->assertSame(20000, $pedido['total_pedido_completo']);
            $this->assertCount(1, $pedido['compartido_con']);
            // SCRUM-906 — la comisión se calcula sobre la parte YA dividida (10000, 1.5%=150),
            // nunca sobre el monto completo del pedido (20000, que daría 300 a cada uno).
            $this->assertSame(150, $pedido['monto_comision']);
        }
    }

    public function test_proyecto_compartido_divide_el_monto_en_partes_iguales_entre_3(): void
    {
        $vendorId = $this->userIdByEmail(self::DESIGNER_EMAIL);
        $contabilidadId = $this->userIdByEmail(self::CONTABILIDAD_EMAIL);
        $markId = $this->userIdByEmail(self::APPROVER_EMAIL);

        $this->makeCommissionableOrder($vendorId, 30000.0, 30000.0, null, [$contabilidadId, $markId]);

        $token = $this->loginAsMark();
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/commissions/internal')
            ->assertStatus(200);

        $row = collect($response->json('vendedores'))->firstWhere('vendedor_id', $markId);
        $pedido = collect($row['groups'][0]['pedidos'])->first();
        $this->assertSame(10000, $pedido['total_pedido']); // 30000 / 3
        $this->assertCount(2, $pedido['compartido_con']);
        // SCRUM-906 — 10000 (parte dividida) * 1.5% = 150, no 30000 * 1.5% = 450 replicado a cada uno.
        $this->assertSame(150, $pedido['monto_comision']);
    }

    /**
     * SCRUM-906 (corrección) — caso real reportado: proyecto de $10,700 compartido entre 3
     * responsables (Gerencia Test 3, Vendedor Demo, Vendedor Test 5 en el ticket original), comisión
     * 1.5%. Antes del fix, cada uno recibía $160.50 (comisión sobre el monto COMPLETO,
     * replicada), sumando $481.50 — 3 veces la comisión real de ese proyecto. Con el fix, cada
     * responsable recibe ~$53.50 (10700/3 * 1.5%), sumando $160.50 en total — la misma comisión
     * que generaría el proyecto con un solo responsable.
     */
    public function test_proyecto_compartido_no_triplica_la_comision_caso_real_scrum906(): void
    {
        $designerId = $this->userIdByEmail(self::DESIGNER_EMAIL);
        $contabilidadId = $this->userIdByEmail(self::CONTABILIDAD_EMAIL);
        $markId = $this->userIdByEmail(self::APPROVER_EMAIL);

        $this->makeCommissionableOrder($designerId, 10700.0, 10700.0, null, [$contabilidadId, $markId]);

        $token = $this->loginAsMark();
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/commissions/internal')
            ->assertStatus(200);

        $vendedores = collect($response->json('vendedores'));
        $totalComision = 0.0;
        foreach ([$designerId, $contabilidadId, $markId] as $id) {
            $row = $vendedores->firstWhere('vendedor_id', $id);
            $pedido = collect($row['groups'][0]['pedidos'])->first();
            $this->assertEqualsWithDelta(53.5, $pedido['monto_comision'], 0.05, "Responsable {$id} debería recibir ~\$53.50, no \$160.50.");
            $totalComision += $pedido['monto_comision'];
        }

        $this->assertEqualsWithDelta(160.5, $totalComision, 0.05, 'La suma repartida entre los 3 debe ser igual a la comisión de UN proyecto de $10,700, no el triple.');
    }

    /** SCRUM-906 AC — la lógica de división/prorrateo se mantiene correcta también con 4+ responsables. */
    public function test_proyecto_compartido_divide_la_comision_entre_4_responsables_scrum906(): void
    {
        $designerId = $this->userIdByEmail(self::DESIGNER_EMAIL);
        $contabilidadId = $this->userIdByEmail(self::CONTABILIDAD_EMAIL);
        $markId = $this->userIdByEmail(self::APPROVER_EMAIL);
        $vendedor2Id = $this->userIdByEmail('vendedor2@test.com');

        // 40000 / 4 = 10000 c/u (tramo 1.5%) → 150 de comisión c/u, 600 en total.
        $this->makeCommissionableOrder($designerId, 40000.0, 40000.0, null, [$contabilidadId, $markId, $vendedor2Id]);

        $token = $this->loginAsMark();
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/commissions/internal')
            ->assertStatus(200);

        $vendedores = collect($response->json('vendedores'));
        $totalComision = 0.0;
        foreach ([$designerId, $contabilidadId, $markId, $vendedor2Id] as $id) {
            $row = $vendedores->firstWhere('vendedor_id', $id);
            $pedido = collect($row['groups'][0]['pedidos'])->first();
            $this->assertSame(10000, $pedido['total_pedido']);
            $this->assertSame(150, $pedido['monto_comision']);
            $totalComision += $pedido['monto_comision'];
        }

        $this->assertSame(600.0, $totalComision);
    }

    // ---- REQ-507 — estado de cuenta de comisiones ----

    public function test_estado_de_cuenta_mes_en_curso_incluye_los_3_totales(): void
    {
        $vendorId = $this->userIdByEmail(self::DESIGNER_EMAIL);
        $this->makeCommissionableOrder($vendorId, 20000.0, 20000.0); // por_pagar, mes actual

        $token = $this->loginAsFelix();
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/admin-contab/commissions/internal/{$vendorId}/account-statement")
            ->assertStatus(200);

        $response->assertJsonPath('mes_cerrado', false);
        $this->assertGreaterThan(0, $response->json('por_pagar'));
        $this->assertArrayHasKey('pendiente_cobro', $response->json());
    }

    public function test_estado_de_cuenta_mes_cerrado_solo_muestra_lo_pagado(): void
    {
        $vendorId = $this->userIdByEmail(self::DESIGNER_EMAIL);
        $mesPasado = Carbon::now()->subMonths(2);
        // Pagado del mes cerrado.
        $this->makeCommissionableOrder($vendorId, 10000.0, 10000.0, $mesPasado);
        // Otro del MISMO mes cerrado, todavía sin cobrar — NO debe aparecer en el estado de
        // cuenta de ese mes (RN1 REQ-507, vive en el estado de cuenta del mes en curso).
        $this->makeCommissionableOrder($vendorId, 5000.0, 0.0, $mesPasado);

        $token = $this->loginAsFelix();
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/admin-contab/commissions/internal/{$vendorId}/account-statement?mes=".$mesPasado->format('Y-m'))
            ->assertStatus(200);

        $response->assertJsonPath('mes_cerrado', true);
        $this->assertCount(1, $response->json('groups'));
        $this->assertCount(1, $response->json('groups.0.pedidos'));
        $this->assertSame('pagado', $response->json('groups.0.pedidos.0.estado'));
    }

    public function test_estado_de_cuenta_prohibido_para_ver_el_de_otro_vendedor(): void
    {
        $contabilidadId = $this->userIdByEmail(self::CONTABILIDAD_EMAIL);
        $token = $this->loginAsDesigner();

        $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/admin-contab/commissions/internal/{$contabilidadId}/account-statement")
            ->assertStatus(403);
    }

    public function test_estado_de_cuenta_permitido_para_el_propio_vendedor(): void
    {
        $vendorId = $this->userIdByEmail(self::DESIGNER_EMAIL);
        $token = $this->loginAsDesigner();

        $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/admin-contab/commissions/internal/{$vendorId}/account-statement")
            ->assertStatus(200);
    }

    public function test_estado_de_cuenta_export_pdf_exitoso(): void
    {
        $vendorId = $this->userIdByEmail(self::DESIGNER_EMAIL);
        $this->makeCommissionableOrder($vendorId, 10000.0, 10000.0);

        $token = $this->loginAsFelix();
        $response = $this->withHeaders($this->authHeaders($token))
            ->get("/api/admin-contab/commissions/internal/{$vendorId}/account-statement/pdf")
            ->assertStatus(200);

        $this->assertStringContainsString('application/pdf', (string) $response->headers->get('Content-Type'));
    }

    // ---- SCRUM-879 — comisiones internas como salida de caja ----

    public function test_879_job_genera_un_movimiento_de_caja_por_vendedor_y_mes_cerrado(): void
    {
        AdminContFiscalSettings::current()->update(['arqueo_tracking_start_date' => CarbonImmutable::now()->subMonths(6)->toDateString()]);
        $vendorId = $this->userIdByEmail(self::DESIGNER_EMAIL);
        $mesCierre = CarbonImmutable::now()->subMonth()->startOfMonth();

        // Pedido cobrado 100% cuyo mes de cierre ya pasó → comisión "pagada".
        $this->makeCommissionableOrder($vendorId, 20000.0, 20000.0, Carbon::instance($mesCierre));

        $created = app(CommissionOrderService::class)->settleInternalCommissionsToCashLedger();
        $this->assertSame(1, $created);

        $mov = AdminContBankMovement::where('source_type', AdminContBankMovement::SOURCE_INTERNAL_COMMISSION)->first();
        $this->assertNotNull($mov);
        $this->assertNull($mov->bank_account_id);
        $this->assertSame('comision', $mov->tipo);
        // Fecha = día 1 del mes siguiente al de cierre (decisión E-5).
        $this->assertSame($mesCierre->addMonth()->startOfMonth()->toDateString(), $mov->fecha->toDateString());
        $this->assertGreaterThan(0, (float) $mov->monto);

        // Idempotente.
        $this->assertSame(0, app(CommissionOrderService::class)->settleInternalCommissionsToCashLedger());
        $this->assertSame(1, AdminContBankMovement::where('source_type', AdminContBankMovement::SOURCE_INTERNAL_COMMISSION)->count());
    }

    public function test_879_job_no_toca_meses_anteriores_a_la_fecha_de_corte(): void
    {
        AdminContFiscalSettings::current()->update(['arqueo_tracking_start_date' => CarbonImmutable::now()->subMonth()->startOfMonth()->toDateString()]);
        $vendorId = $this->userIdByEmail(self::DESIGNER_EMAIL);
        // Mes de cierre 3 meses atrás — antes del corte.
        $this->makeCommissionableOrder($vendorId, 20000.0, 20000.0, Carbon::instance(CarbonImmutable::now()->subMonths(3)->startOfMonth()));

        $this->assertSame(0, app(CommissionOrderService::class)->settleInternalCommissionsToCashLedger());
        $this->assertSame(0, AdminContBankMovement::where('source_type', AdminContBankMovement::SOURCE_INTERNAL_COMMISSION)->count());
    }

    public function test_879_comando_settle_internal_corre_sin_error(): void
    {
        $this->artisan('commissions:settle-internal')->assertSuccessful();
    }
}
