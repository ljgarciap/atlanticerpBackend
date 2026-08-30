<?php

declare(strict_types=1);

namespace Tests\Feature\Bodega;

use App\Modules\Bodega\Models\AdjustmentRequest;
use App\Modules\Bodega\Models\AdjustmentRequestLine;
use App\Modules\Bodega\Models\InventoryMovement;
use App\Modules\Bodega\Models\Order;
use App\Modules\Bodega\Models\OrderDocument;
use App\Modules\Bodega\Models\OrderStageHistory;
use App\Modules\Bodega\Models\Warehouse;
use App\Modules\Bodega\Services\BodegaSettingsService;
use App\Modules\Compras\Models\Provider;
use App\Modules\Compras\Models\PurchaseOrder;
use App\Modules\Compras\Models\PurchaseOrderLine;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Bodega\Concerns\BodegaOrderTestHelpers;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-329 Oleada A / Batch B1 (REQ-294→304, SCRUM-363→374) — "Home de Bodega".
 */
class BodegaHomeControllerTest extends FeatureTestCase
{
    use BodegaOrderTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeCurrentTenant();
        $this->fakeOutlookCalendar();
    }

    /**
     * `Http::fake()` resuelve contra el PRIMER stub registrado que matchea la URL (confirmado
     * empíricamente) — el fake genérico de `setUp()` (eventos vacíos) siempre gana sobre uno
     * registrado después dentro de un test individual, a menos que se resetee la factory antes de
     * volver a fakear. Solo lo necesita el test que verifica un conteo de eventos real distinto de 0.
     *
     * @param list<array<string, mixed>> $events
     */
    private function fakeOutlookCalendarWith(array $events): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory());
        $this->fakeOutlookCalendar($events);
    }

    private function authHeadersFor(string $email, string $role): array
    {
        $this->grantBodegaPermissions($email, ['bodega.read']);
        $token = $this->loginAs($email, $email, $role);

        return $this->authHeaders($token);
    }

    private function stageHistory(Order $order, ?string $fromStage, string $toStage, Carbon $createdAt): OrderStageHistory
    {
        return OrderStageHistory::create([
            'order_id'   => $order->id,
            'from_stage' => $fromStage,
            'to_stage'   => $toStage,
            'changed_by' => null,
            'created_at' => $createdAt,
        ]);
    }

    // ── SCRUM-365/366 (REQ-295/296) — equipo y alcance ──────────────────────

    public function test_team_retorna_lista_vacia_para_picker_sin_alcance_de_equipo(): void
    {
        $response = $this->withHeaders($this->authHeadersFor('apolonio.gonzalez@illuminations.com.pa', 'ayudante_general_bodega'))
            ->getJson('/api/bodega/home/team')
            ->assertStatus(200);

        $this->assertSame([], $response->json('data'));
    }

    /**
     * SCRUM-366 — fix QA 2026-07-24 (marly.rangel): el selector de personas solo debe listar
     * Asistentes de Bodega (`BodegaRoles::TEAM_SELECTABLE`), nunca al Jefe (Esteban), al Ayudante
     * General/Picker (Apolonio) ni a Transporte (Gary) — pese a que los 3 son miembros reales del
     * equipo de Bodega para otros propósitos (`bodegaRoleIds()`, guard de `owner_id`, calendario).
     */
    public function test_team_retorna_solo_los_asistentes_de_bodega_para_el_jefe(): void
    {
        $response = $this->withHeaders($this->authHeadersFor('almacen@illuminations.com.pa', 'lider_bodega'))
            ->getJson('/api/bodega/home/team')
            ->assertStatus(200);

        $names = collect($response->json('data'))->pluck('name');
        $this->assertCount(2, $names);
        $this->assertTrue($names->contains('Osvaldo Santos'));
        $this->assertTrue($names->contains('Mariano Sandoval'));
        $this->assertFalse($names->contains('Esteban Cardenas'));
        $this->assertFalse($names->contains('Apolonio Gonzalez'));
        $this->assertFalse($names->contains('Gary Arrocha'));
    }

    // ── Fix de regresión cruzada (2026-07-28) — GET /bodega/team-members ────

    /**
     * Regresión encontrada por Frontend Dev: `/bodega/home/team` (restringido a solo Asistentes
     * tras SCRUM-366) era el único endpoint que "Asignar Repartidor"/"Quién entregó" (SCRUM-396/
     * 399) y "Asignar Picker" (SCRUM-383) tenían para listar personas — rompiéndolos
     * silenciosamente. Este endpoint genérico los repara.
     */
    public function test_team_members_role_courier_retorna_solo_transporte(): void
    {
        $response = $this->withHeaders($this->authHeadersFor('almacen@illuminations.com.pa', 'lider_bodega'))
            ->getJson('/api/bodega/team-members?role=courier')
            ->assertStatus(200);

        $names = collect($response->json('data'))->pluck('name');
        $this->assertTrue($names->contains('Gary Arrocha'));
        $this->assertFalse($names->contains('Osvaldo Santos'));
        $this->assertFalse($names->contains('Apolonio Gonzalez'));
    }

    public function test_team_members_role_picker_retorna_solo_ayudante_general(): void
    {
        $response = $this->withHeaders($this->authHeadersFor('almacen@illuminations.com.pa', 'lider_bodega'))
            ->getJson('/api/bodega/team-members?role=picker')
            ->assertStatus(200);

        $names = collect($response->json('data'))->pluck('name');
        $this->assertTrue($names->contains('Apolonio Gonzalez'));
        $this->assertFalse($names->contains('Gary Arrocha'));
        $this->assertFalse($names->contains('Osvaldo Santos'));
    }

    public function test_team_members_role_asistente_bodega_mismo_resultado_que_home_team(): void
    {
        $response = $this->withHeaders($this->authHeadersFor('almacen@illuminations.com.pa', 'lider_bodega'))
            ->getJson('/api/bodega/team-members?role=asistente_bodega')
            ->assertStatus(200);

        $names = collect($response->json('data'))->pluck('name');
        $this->assertCount(2, $names);
        $this->assertTrue($names->contains('Osvaldo Santos'));
        $this->assertTrue($names->contains('Mariano Sandoval'));
    }

    public function test_team_members_role_invalido_retorna_422(): void
    {
        $this->withHeaders($this->authHeadersFor('almacen@illuminations.com.pa', 'lider_bodega'))
            ->getJson('/api/bodega/team-members?role=jefe_de_bodega')
            ->assertStatus(422);
    }

    public function test_summary_owner_id_fuera_del_equipo_cae_al_alcance_propio(): void
    {
        // Hallazgo CRÍTICO de Pre-QA (2026-07-21, mismo guard en OutlookCalendarController):
        // Gerencia (o cualquier rol con view_team) no puede pasar el owner_id de alguien fuera
        // del equipo de Bodega para ver su alcance "Mío".
        $jefeId = $this->userIdByEmail('almacen@illuminations.com.pa');
        $outsiderId = $this->userIdByEmail('management@illuminations.test');

        $order = $this->makeOrder(['assistant_id' => $jefeId, 'stage' => Order::STAGE_ASIGNADO]);
        $this->stageHistory($order, null, Order::STAGE_ASIGNADO, Carbon::now());

        $response = $this->withHeaders($this->authHeadersFor('almacen@illuminations.com.pa', 'lider_bodega'))
            ->getJson("/api/bodega/home/summary?scope=team&owner_id={$outsiderId}")
            ->assertStatus(200);

        // Cae al alcance propio del actor (Jefe) — su propio pedido asignado hoy sí cuenta.
        $this->assertSame(1, $response->json('mi_dia.asignados_hoy'));
    }

    public function test_summary_scope_team_es_ignorado_para_quien_no_puede_ver_equipo(): void
    {
        $pickerId = $this->userIdByEmail('apolonio.gonzalez@illuminations.com.pa');
        $order = $this->makeOrder(['picker_id' => $pickerId, 'stage' => Order::STAGE_ASIGNADO]);
        $this->stageHistory($order, null, Order::STAGE_ASIGNADO, Carbon::now());

        // Un pedido de otra persona no debería contar para el picker en "team" (que ni debería
        // aplicarse, ya que ayudante_general_bodega no tiene view_team).
        $otherId = $this->userIdByEmail('almacen2@illuminations.com.pa');
        $this->makeOrder(['assistant_id' => $otherId, 'stage' => Order::STAGE_ASIGNADO]);

        $response = $this->withHeaders($this->authHeadersFor('apolonio.gonzalez@illuminations.com.pa', 'ayudante_general_bodega'))
            ->getJson('/api/bodega/home/summary?scope=team')
            ->assertStatus(200);

        $this->assertSame(1, $response->json('mi_dia.asignados_hoy'));
    }

    public function test_summary_scope_team_sin_owner_id_agrega_todo_el_equipo(): void
    {
        $osvaldoId = $this->userIdByEmail('logistica@illuminations.com.pa');
        $marianoId = $this->userIdByEmail('almacen2@illuminations.com.pa');

        $o1 = $this->makeOrder(['assistant_id' => $osvaldoId, 'stage' => Order::STAGE_ASIGNADO]);
        $this->stageHistory($o1, null, Order::STAGE_ASIGNADO, Carbon::now());
        $o2 = $this->makeOrder(['assistant_id' => $marianoId, 'stage' => Order::STAGE_ASIGNADO]);
        $this->stageHistory($o2, null, Order::STAGE_ASIGNADO, Carbon::now());

        $response = $this->withHeaders($this->authHeadersFor('almacen@illuminations.com.pa', 'lider_bodega'))
            ->getJson('/api/bodega/home/summary?scope=team')
            ->assertStatus(200);

        $this->assertSame(2, $response->json('mi_dia.asignados_hoy'));
        // "Equipo completo" (sin owner_id) SÍ es alcance de equipo real — saludo en plural.
        $this->assertStringContainsString('El equipo tiene', $response->json('saludo.resumen'));
    }

    /**
     * SCRUM-366 RN2 (fix QA 2026-07-24, BUG 2) — filtrar por una persona específica dentro de
     * "Equipo" debe hablar exactamente como si esa persona estuviera en "Inicio" (singular,
     * "Tienes..."), no en plural de equipo, aunque `scope=team` siga presente en el request.
     */
    public function test_summary_scope_team_con_owner_id_puntual_saluda_en_singular(): void
    {
        $osvaldoId = $this->userIdByEmail('logistica@illuminations.com.pa');
        $marianoId = $this->userIdByEmail('almacen2@illuminations.com.pa');

        $o1 = $this->makeOrder(['assistant_id' => $osvaldoId, 'stage' => Order::STAGE_ASIGNADO]);
        $this->stageHistory($o1, null, Order::STAGE_ASIGNADO, Carbon::now());
        $o2 = $this->makeOrder(['assistant_id' => $marianoId, 'stage' => Order::STAGE_ASIGNADO]);
        $this->stageHistory($o2, null, Order::STAGE_ASIGNADO, Carbon::now());

        $response = $this->withHeaders($this->authHeadersFor('almacen@illuminations.com.pa', 'lider_bodega'))
            ->getJson("/api/bodega/home/summary?scope=team&owner_id={$osvaldoId}")
            ->assertStatus(200);

        // El filtro por persona sigue funcionando (RN2, solo el pedido de Osvaldo cuenta).
        $this->assertSame(1, $response->json('mi_dia.asignados_hoy'));
        $this->assertStringContainsString('Tienes', $response->json('saludo.resumen'));
        $this->assertStringNotContainsString('El equipo tiene', $response->json('saludo.resumen'));
    }

    // ── SCRUM-367 (REQ-297) — Mi día / Día del equipo ───────────────────────

    /**
     * Fix de regresión (QA marly.rangel 2026-07-26, comentario SCRUM-367 — "Mismo tema que
     * SCRUM-372") — `asignados_hoy` dependía de `order_stage_history.to_stage` ("entró a Asignado
     * HOY"), que se queda en 0 si el pedido nunca pasó por un servicio de transición de etapa
     * real (exactamente el caso de `makeOrder()`, que crea el `Order` directo, igual que el
     * seeder de QA SCRUM-501). Corregido: cuenta el backlog ACTUAL (cualquier etapa salvo
     * Entregado), sin depender de que exista una fila de historial.
     */
    public function test_mi_dia_asignados_hoy_cuenta_pedidos_sin_historial_de_etapa(): void
    {
        $userId = $this->userIdByEmail('logistica@illuminations.com.pa');
        // Sin llamar a stageHistory() a propósito — reproduce el gap real del seeder SCRUM-501.
        $this->makeOrder(['assistant_id' => $userId, 'stage' => Order::STAGE_ASIGNADO]);

        $response = $this->withHeaders($this->authHeadersFor('logistica@illuminations.com.pa', 'asistente_bodega'))
            ->getJson('/api/bodega/home/summary')
            ->assertStatus(200);

        $this->assertSame(1, $response->json('mi_dia.asignados_hoy'));
    }

    /**
     * Un pedido asignado hace varios días y que sigue en el pipeline (no Entregado todavía)
     * sigue contando como parte del backlog actual — `asignados_hoy` ya no es un conteo de
     * eventos "hoy", es un snapshot del trabajo pendiente, igual que `estadoPedidos()`.
     */
    public function test_mi_dia_asignados_hoy_incluye_pedidos_asignados_en_dias_anteriores(): void
    {
        $userId = $this->userIdByEmail('logistica@illuminations.com.pa');
        $order = $this->makeOrder(['assistant_id' => $userId, 'stage' => Order::STAGE_EN_PICKING]);
        $this->stageHistory($order, Order::STAGE_ASIGNADO, Order::STAGE_EN_PICKING, Carbon::now()->subDays(3));

        $response = $this->withHeaders($this->authHeadersFor('logistica@illuminations.com.pa', 'asistente_bodega'))
            ->getJson('/api/bodega/home/summary')
            ->assertStatus(200);

        $this->assertSame(1, $response->json('mi_dia.asignados_hoy'));
    }

    /** Un pedido ya Entregado está cerrado — no debe seguir contando como backlog del día. */
    public function test_mi_dia_asignados_hoy_excluye_pedidos_entregados(): void
    {
        $userId = $this->userIdByEmail('logistica@illuminations.com.pa');
        $this->makeOrder(['assistant_id' => $userId, 'stage' => Order::STAGE_ENTREGADO]);

        $response = $this->withHeaders($this->authHeadersFor('logistica@illuminations.com.pa', 'asistente_bodega'))
            ->getJson('/api/bodega/home/summary')
            ->assertStatus(200);

        $this->assertSame(0, $response->json('mi_dia.asignados_hoy'));
    }

    // ── SCRUM-369 (REQ-299) — Pendientes ─────────────────────────────────────

    public function test_pendientes_marca_picking_no_iniciado_pasado_el_umbral_configurado(): void
    {
        $userId = $this->userIdByEmail('logistica@illuminations.com.pa');
        $order = $this->makeOrder(['assistant_id' => $userId, 'stage' => Order::STAGE_ASIGNADO]);
        // Default 3 horas (BodegaSettingsService) — 5 horas atrás está vencido.
        $this->stageHistory($order, null, Order::STAGE_ASIGNADO, Carbon::now()->subHours(5));

        $response = $this->withHeaders($this->authHeadersFor('logistica@illuminations.com.pa', 'asistente_bodega'))
            ->getJson('/api/bodega/home/summary')
            ->assertStatus(200);

        $items = collect($response->json('pendientes.items'));
        $found = $items->first(fn (array $i) => $i['type'] === 'picking_no_iniciado' && $i['order_id'] === $order->id);
        $this->assertNotNull($found);
        $this->assertSame(1, $response->json('pendientes.count'));
    }

    public function test_pendientes_no_marca_picking_dentro_del_umbral(): void
    {
        $userId = $this->userIdByEmail('logistica@illuminations.com.pa');
        $order = $this->makeOrder(['assistant_id' => $userId, 'stage' => Order::STAGE_ASIGNADO]);
        $this->stageHistory($order, null, Order::STAGE_ASIGNADO, Carbon::now()->subHour());

        $response = $this->withHeaders($this->authHeadersFor('logistica@illuminations.com.pa', 'asistente_bodega'))
            ->getJson('/api/bodega/home/summary')
            ->assertStatus(200);

        $items = collect($response->json('pendientes.items'));
        $this->assertNull($items->first(fn (array $i) => $i['order_id'] === $order->id));
    }

    public function test_pendientes_umbral_de_picking_es_configurable_via_bodega_settings(): void
    {
        // Regla del proyecto: nunca hardcodear el umbral — se lee de bodega_settings.
        app(BodegaSettingsService::class)->pendientesPickingHoras(); // fuerza el default-seed (3h)
        \App\Modules\Bodega\Models\BodegaSetting::where('key', 'home_pendientes_picking_horas')->update(['value' => '1']);

        $userId = $this->userIdByEmail('logistica@illuminations.com.pa');
        $order = $this->makeOrder(['assistant_id' => $userId, 'stage' => Order::STAGE_ASIGNADO]);
        // 2 horas atrás — no superaría el default de 3h, pero SÍ el umbral configurado a 1h.
        $this->stageHistory($order, null, Order::STAGE_ASIGNADO, Carbon::now()->subHours(2));

        $response = $this->withHeaders($this->authHeadersFor('logistica@illuminations.com.pa', 'asistente_bodega'))
            ->getJson('/api/bodega/home/summary')
            ->assertStatus(200);

        $items = collect($response->json('pendientes.items'));
        $this->assertNotNull($items->first(fn (array $i) => $i['order_id'] === $order->id));
    }

    public function test_pendientes_marca_guia_de_entrega_sin_subir_pasado_el_umbral(): void
    {
        $userId = $this->userIdByEmail('logistica@illuminations.com.pa');
        $order = $this->makeOrder(['assistant_id' => $userId, 'stage' => Order::STAGE_DESPACHADO]);
        // Default 2 días — 3 días atrás está vencido.
        $this->stageHistory($order, Order::STAGE_POR_DESPACHAR, Order::STAGE_DESPACHADO, Carbon::now()->subDays(3));

        $response = $this->withHeaders($this->authHeadersFor('logistica@illuminations.com.pa', 'asistente_bodega'))
            ->getJson('/api/bodega/home/summary')
            ->assertStatus(200);

        $items = collect($response->json('pendientes.items'));
        $found = $items->first(fn (array $i) => $i['type'] === 'guia_sin_subir' && $i['order_id'] === $order->id);
        $this->assertNotNull($found);
    }

    public function test_pendientes_no_marca_guia_si_ya_fue_subida(): void
    {
        $userId = $this->userIdByEmail('logistica@illuminations.com.pa');
        $order = $this->makeOrder(['assistant_id' => $userId, 'stage' => Order::STAGE_DESPACHADO]);
        $this->stageHistory($order, Order::STAGE_POR_DESPACHAR, Order::STAGE_DESPACHADO, Carbon::now()->subDays(5));
        OrderDocument::create([
            'order_id' => $order->id, 'tipo' => OrderDocument::TIPO_GUIA_ENTREGA,
            'storage_key' => 'guides/test.pdf', 'received_by_name' => 'Juan Pérez', 'created_at' => Carbon::now(),
        ]);

        $response = $this->withHeaders($this->authHeadersFor('logistica@illuminations.com.pa', 'asistente_bodega'))
            ->getJson('/api/bodega/home/summary')
            ->assertStatus(200);

        $items = collect($response->json('pendientes.items'));
        $this->assertNull($items->first(fn (array $i) => $i['order_id'] === $order->id));
    }

    /**
     * REQ-299, condición 3 — interpretación del batch: `AdjustmentRequestLine` pendiente cuenta
     * como "Pendientes" (ver docblock de `BodegaHomeService::pendientesAjustesInventario()`).
     */
    public function test_pendientes_incluye_solicitud_de_ajuste_pendiente_del_propio_solicitante(): void
    {
        $userId = $this->userIdByEmail('logistica@illuminations.com.pa');
        $otherId = $this->userIdByEmail('almacen2@illuminations.com.pa');
        $product = $this->makeCatalogProduct();
        $warehouse = Warehouse::where('name', 'Bodega Central')->firstOrFail();

        $ownRequest = AdjustmentRequest::create(['catalog_product_id' => $product->id, 'solicitado_por' => $userId]);
        AdjustmentRequestLine::create([
            'adjustment_request_id' => $ownRequest->id, 'warehouse_id' => $warehouse->id,
            'tipo' => AdjustmentRequestLine::TIPO_SUMAR, 'cantidad' => 3, 'motivo' => 'Conteo físico',
            'estado' => AdjustmentRequestLine::ESTADO_PENDIENTE,
        ]);

        $otherRequest = AdjustmentRequest::create(['catalog_product_id' => $product->id, 'solicitado_por' => $otherId]);
        AdjustmentRequestLine::create([
            'adjustment_request_id' => $otherRequest->id, 'warehouse_id' => $warehouse->id,
            'tipo' => AdjustmentRequestLine::TIPO_RESTAR, 'cantidad' => 1, 'motivo' => 'Merma',
            'estado' => AdjustmentRequestLine::ESTADO_PENDIENTE,
        ]);

        $response = $this->withHeaders($this->authHeadersFor('logistica@illuminations.com.pa', 'asistente_bodega'))
            ->getJson('/api/bodega/home/summary')
            ->assertStatus(200);

        $items = collect($response->json('pendientes.items'));
        $this->assertNotNull($items->first(fn (array $i) => $i['type'] === 'ajuste_inventario_pendiente' && str_contains((string) $i['solicitante'], 'Osvaldo')));
        $this->assertNull($items->first(fn (array $i) => $i['type'] === 'ajuste_inventario_pendiente' && str_contains((string) $i['solicitante'], 'Mariano')));
    }

    public function test_pendientes_de_ajuste_ve_todas_las_solicitudes_en_equipo_completo(): void
    {
        $osvaldoId = $this->userIdByEmail('logistica@illuminations.com.pa');
        $product = $this->makeCatalogProduct();
        $warehouse = Warehouse::where('name', 'Bodega Central')->firstOrFail();

        $request = AdjustmentRequest::create(['catalog_product_id' => $product->id, 'solicitado_por' => $osvaldoId]);
        AdjustmentRequestLine::create([
            'adjustment_request_id' => $request->id, 'warehouse_id' => $warehouse->id,
            'tipo' => AdjustmentRequestLine::TIPO_SUMAR, 'cantidad' => 2, 'motivo' => 'Conteo físico',
            'estado' => AdjustmentRequestLine::ESTADO_PENDIENTE,
        ]);

        $response = $this->withHeaders($this->authHeadersFor('almacen@illuminations.com.pa', 'lider_bodega'))
            ->getJson('/api/bodega/home/summary?scope=team')
            ->assertStatus(200);

        $items = collect($response->json('pendientes.items'));
        $this->assertNotNull($items->first(fn (array $i) => $i['type'] === 'ajuste_inventario_pendiente'));
    }

    // ── SCRUM-370 (REQ-300) — Despachos urgentes ────────────────────────────

    public function test_despachos_urgentes_filtra_por_ventana_configurada(): void
    {
        $userId = $this->userIdByEmail('logistica@illuminations.com.pa');
        $urgent = $this->makeOrder(['assistant_id' => $userId, 'stage' => Order::STAGE_POR_DESPACHAR, 'committed_delivery_date' => Carbon::tomorrow()]);
        $this->makeOrder(['assistant_id' => $userId, 'stage' => Order::STAGE_POR_DESPACHAR, 'committed_delivery_date' => Carbon::today()->addDays(20)]);

        $response = $this->withHeaders($this->authHeadersFor('logistica@illuminations.com.pa', 'asistente_bodega'))
            ->getJson('/api/bodega/home/summary')
            ->assertStatus(200);

        $ids = collect($response->json('despachos_urgentes'))->pluck('order_id')->all();
        $this->assertSame([$urgent->id], $ids);
    }

    public function test_despachos_urgentes_excluye_entregados(): void
    {
        $userId = $this->userIdByEmail('logistica@illuminations.com.pa');
        $this->makeOrder(['assistant_id' => $userId, 'stage' => Order::STAGE_ENTREGADO, 'committed_delivery_date' => Carbon::today()]);

        $response = $this->withHeaders($this->authHeadersFor('logistica@illuminations.com.pa', 'asistente_bodega'))
            ->getJson('/api/bodega/home/summary')
            ->assertStatus(200);

        $this->assertSame([], $response->json('despachos_urgentes'));
    }

    // ── SCRUM-372 (REQ-302) — Estado de pedidos ─────────────────────────────

    /**
     * Fix de regresión (QA marly.rangel 2026-07-26, comentario SCRUM-372) — la implementación
     * original solo contaba pedidos que ENTRARON hoy a la etapa, dejando el panel en 0 mientras el
     * Kanban mostraba backlog real. Corregido: las 6 primeras etapas cuentan el backlog ACTUAL
     * sentado en la columna (mismo criterio que `OrderBoardService::board()`), no solo lo de hoy —
     * un pedido asignado hace 2 días sigue contando mientras siga en esa etapa.
     */
    public function test_estado_de_pedidos_cuenta_el_backlog_actual_no_solo_lo_que_entro_hoy(): void
    {
        $userId = $this->userIdByEmail('logistica@illuminations.com.pa');
        $today = $this->makeOrder(['assistant_id' => $userId, 'stage' => Order::STAGE_ASIGNADO]);
        $this->stageHistory($today, null, Order::STAGE_ASIGNADO, Carbon::now());

        $old = $this->makeOrder(['assistant_id' => $userId, 'stage' => Order::STAGE_ASIGNADO]);
        $this->stageHistory($old, null, Order::STAGE_ASIGNADO, Carbon::now()->subDays(2));

        $response = $this->withHeaders($this->authHeadersFor('logistica@illuminations.com.pa', 'asistente_bodega'))
            ->getJson('/api/bodega/home/summary')
            ->assertStatus(200);

        $stages = collect($response->json('estado_pedidos.stages'))->keyBy('stage');
        $this->assertSame(2, $stages[Order::STAGE_ASIGNADO]['count']);
    }

    public function test_estado_de_pedidos_entregado_usa_ventana_de_una_semana(): void
    {
        $userId = $this->userIdByEmail('logistica@illuminations.com.pa');
        $recent = $this->makeOrder(['assistant_id' => $userId, 'stage' => Order::STAGE_ENTREGADO]);
        $this->stageHistory($recent, Order::STAGE_DESPACHADO, Order::STAGE_ENTREGADO, Carbon::now()->subDays(3));

        $old = $this->makeOrder(['assistant_id' => $userId, 'stage' => Order::STAGE_ENTREGADO]);
        $this->stageHistory($old, Order::STAGE_DESPACHADO, Order::STAGE_ENTREGADO, Carbon::now()->subDays(10));

        $response = $this->withHeaders($this->authHeadersFor('logistica@illuminations.com.pa', 'asistente_bodega'))
            ->getJson('/api/bodega/home/summary')
            ->assertStatus(200);

        $stages = collect($response->json('estado_pedidos.stages'))->keyBy('stage');
        $this->assertSame(1, $stages[Order::STAGE_ENTREGADO]['count']);
    }

    public function test_estado_de_pedidos_urgentes_count_usa_el_mismo_criterio_que_el_chip(): void
    {
        $userId = $this->userIdByEmail('logistica@illuminations.com.pa');
        $this->makeOrder(['assistant_id' => $userId, 'stage' => Order::STAGE_POR_DESPACHAR, 'committed_delivery_date' => Carbon::tomorrow()]);
        $this->makeOrder(['assistant_id' => $userId, 'stage' => Order::STAGE_POR_DESPACHAR, 'committed_delivery_date' => Carbon::today()->addDays(20)]);

        $response = $this->withHeaders($this->authHeadersFor('logistica@illuminations.com.pa', 'asistente_bodega'))
            ->getJson('/api/bodega/home/summary')
            ->assertStatus(200);

        $this->assertSame(1, $response->json('estado_pedidos.urgentes_count'));
    }

    // ── SCRUM-371 (REQ-301) — Por recibir (no cambia con el scope) ──────────

    public function test_por_recibir_expone_ordenes_en_transito_con_distintivo_zona_libre(): void
    {
        $product = CatalogProduct::create([
            'reference' => 'REF-POR-RECIBIR', 'factory_reference' => 'FAB-777', 'name' => 'Lámpara colgante', 'description' => 'Lámpara colgante',
            'price_full' => 200, 'cost' => 120, 'stock_quantity' => 0, 'is_active' => true,
        ]);
        $provider = Provider::create(['name' => 'ZonaLibreCorp', 'currency' => 'USD']);
        $purchaseOrder = PurchaseOrder::create([
            'provider_id' => $provider->id, 'currency' => 'USD',
            'status' => PurchaseOrder::STATUS_EN_TRANSITO, 'status_changed_at' => now(),
            'modality' => PurchaseOrder::MODALITY_ZONA_LIBRE,
            'estimated_arrival_date' => '2026-09-01', 'total_amount' => 500,
        ]);
        PurchaseOrderLine::create([
            'purchase_order_id' => $purchaseOrder->id, 'catalog_product_id' => $product->id,
            'quantity' => 8, 'unit_cost' => 25, 'subtotal' => 200,
        ]);

        $response = $this->withHeaders($this->authHeadersFor('logistica@illuminations.com.pa', 'asistente_bodega'))
            ->getJson('/api/bodega/home/summary')
            ->assertStatus(200);

        $rows = collect($response->json('por_recibir'));
        $row = $rows->firstWhere('purchase_order_id', $purchaseOrder->id);
        $this->assertNotNull($row);
        $this->assertSame('FAB-777', $row['factory_reference']);
        $this->assertSame(8, $row['quantity']);
        $this->assertSame('ZonaLibreCorp', $row['provider_name']);
        $this->assertTrue($row['is_zona_libre']);
    }

    public function test_por_recibir_excluye_ordenes_recibidas(): void
    {
        $product = CatalogProduct::create([
            'reference' => 'REF-YA-RECIBIDO', 'name' => 'Producto recibido', 'description' => 'Producto recibido',
            'price_full' => 100, 'cost' => 60, 'stock_quantity' => 10, 'is_active' => true,
        ]);
        $provider = Provider::create(['name' => 'ProveedorRecibido', 'currency' => 'USD']);
        $purchaseOrder = PurchaseOrder::create([
            'provider_id' => $provider->id, 'currency' => 'USD',
            'status' => PurchaseOrder::STATUS_RECIBIDO, 'status_changed_at' => now(),
            'estimated_arrival_date' => '2026-06-01', 'total_amount' => 100,
        ]);
        PurchaseOrderLine::create([
            'purchase_order_id' => $purchaseOrder->id, 'catalog_product_id' => $product->id,
            'quantity' => 3, 'unit_cost' => 20, 'subtotal' => 60,
        ]);

        $response = $this->withHeaders($this->authHeadersFor('logistica@illuminations.com.pa', 'asistente_bodega'))
            ->getJson('/api/bodega/home/summary')
            ->assertStatus(200);

        $rows = collect($response->json('por_recibir'));
        $this->assertNull($rows->firstWhere('purchase_order_id', $purchaseOrder->id));
    }

    // ── SCRUM-373 (REQ-303) — Mayor rotación (no cambia con el scope) ───────

    public function test_mayor_rotacion_cuenta_salidas_de_los_ultimos_30_dias_y_excluye_lo_anterior(): void
    {
        $inWindow = $this->makeCatalogProduct(['reference' => 'REF-ROTA-1']);
        $outOfWindow = $this->makeCatalogProduct(['reference' => 'REF-ROTA-2']);
        $warehouse = Warehouse::where('name', 'Bodega Central')->firstOrFail();

        InventoryMovement::create([
            'catalog_product_id' => $inWindow->id, 'warehouse_id' => $warehouse->id,
            'tipo' => InventoryMovement::TIPO_SALIDA, 'cantidad' => -15, 'referencia_texto' => 'test',
            'saldo_inicial' => 15, 'saldo_resultante' => 0, 'created_at' => Carbon::now()->subDays(5),
        ]);
        InventoryMovement::create([
            'catalog_product_id' => $outOfWindow->id, 'warehouse_id' => $warehouse->id,
            'tipo' => InventoryMovement::TIPO_SALIDA, 'cantidad' => -50, 'referencia_texto' => 'test',
            'saldo_inicial' => 50, 'saldo_resultante' => 0, 'created_at' => Carbon::now()->subDays(45),
        ]);

        $response = $this->withHeaders($this->authHeadersFor('logistica@illuminations.com.pa', 'asistente_bodega'))
            ->getJson('/api/bodega/home/summary')
            ->assertStatus(200);

        $rows = collect($response->json('mayor_rotacion'));
        $this->assertNotNull($rows->firstWhere('catalog_product_id', $inWindow->id));
        $this->assertNull($rows->firstWhere('catalog_product_id', $outOfWindow->id));
        $this->assertSame(15, $rows->firstWhere('catalog_product_id', $inWindow->id)['total_salidas']);
    }

    // ── SCRUM-374 (REQ-304) — Artículos críticos (no cambia con el scope) ───

    public function test_articulos_criticos_incluye_producto_bajo_el_punto_de_reorden(): void
    {
        $critical = CatalogProduct::create([
            'reference' => 'REF-CRIT', 'name' => 'Producto crítico', 'description' => 'Producto crítico',
            'price_full' => 100, 'cost' => 60, 'stock_quantity' => 2, 'reorder_point' => 5, 'is_active' => true,
        ]);
        $healthy = CatalogProduct::create([
            'reference' => 'REF-OK', 'name' => 'Producto saludable', 'description' => 'Producto saludable',
            'price_full' => 100, 'cost' => 60, 'stock_quantity' => 100, 'reorder_point' => 5, 'is_active' => true,
        ]);

        $response = $this->withHeaders($this->authHeadersFor('logistica@illuminations.com.pa', 'asistente_bodega'))
            ->getJson('/api/bodega/home/summary')
            ->assertStatus(200);

        $rows = collect($response->json('articulos_criticos'));
        $this->assertNotNull($rows->firstWhere('catalog_product_id', $critical->id));
        $this->assertNull($rows->firstWhere('catalog_product_id', $healthy->id));
    }

    // ── SCRUM-368 (REQ-298) — Mi calendario ──────────────────────────────────

    public function test_calendario_de_bodega_lee_eventos_reales_del_usuario(): void
    {
        $this->fakeOutlookCalendarWith([
            ['id' => 'evt-1', 'subject' => 'Reunión de bodega', 'start' => ['dateTime' => now()->toIso8601String()], 'end' => ['dateTime' => now()->addHour()->toIso8601String()]],
        ]);

        $response = $this->withHeaders($this->authHeadersFor('logistica@illuminations.com.pa', 'asistente_bodega'))
            ->getJson('/api/bodega/home/summary')
            ->assertStatus(200);

        $this->assertSame(1, $response->json('mi_calendario.events_today_count'));
    }
}
