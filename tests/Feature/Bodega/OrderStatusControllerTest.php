<?php

declare(strict_types=1);

namespace Tests\Feature\Bodega;

use App\Modules\Bodega\Models\Order;
use App\Modules\Bodega\Models\OrderDocument;
use App\Modules\Bodega\Services\OrderStatusService;
use App\Modules\VentasDiseno\Models\Quote;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Bodega\Concerns\BodegaOrderTestHelpers;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-329 Oleada A / Batch A4 (REQ-337 a 343) — pantalla "Status de Pedidos": listado
 * consolidado por familia (REQ-338), modal de detalle con productos consolidados (REQ-339/340),
 * documento "Estatus de Pedido" (REQ-341), permisos Bodega/Ventas & Diseño (REQ-342) y lectura en
 * vivo (REQ-343, satisfecho por diseño: todo se lee directo de `Order`/`OrderItem`/`PipelineCard`
 * en cada request, sin caché intermedia — no requiere un test dedicado más allá del resto).
 */
class OrderStatusControllerTest extends FeatureTestCase
{
    use BodegaOrderTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeCurrentTenant();
        Storage::fake('s3-private');
    }

    private function authHeadersForBodegaReader(): array
    {
        $this->grantBodegaPermissions('liderbodega@test.com', ['bodega.read']);
        $token = $this->loginAs('liderbodega@test.com', 'liderbodega@test.com', 'lider_bodega');

        return $this->authHeaders($token);
    }

    /** @param list<string> $extra */
    private function grantVentasDisenoRead(string $email, array $extra = []): void
    {
        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');
        $permissions = array_merge(['ventas_diseno.read'], $extra);
        DB::table("{$slug}_auth.users")->where('email', $email)->update(['extra_permissions' => json_encode($permissions)]);
    }

    /** REQ-338 — un pedido dividido en varias tarjetas (familia) aparece como UNA sola fila. */
    public function test_lista_consolida_familia_en_una_sola_fila(): void
    {
        $sellerId = $this->userIdByEmail('designer@atlantic.test');
        $root = $this->makeOrderWithPipelineCard($sellerId, ['order_number' => 'PED-2026-FAM01']);
        $this->makeOrder(['order_number' => 'PED-2026-FAM01', 'family_id' => $root->id, 'pipeline_card_id' => $root->pipeline_card_id]);

        $response = $this->withHeaders($this->authHeadersForBodegaReader())
            ->getJson('/api/bodega/orders/status')
            ->assertStatus(200);

        $rows = collect($response->json('data'))->where('order_number', 'PED-2026-FAM01');
        $this->assertCount(1, $rows, 'Un pedido dividido en varias tarjetas debe aparecer como una sola fila.');
        $this->assertSame($root->id, $rows->first()['order_id']);
    }

    /** REQ-338 — columnas exactas: N° pedido, N° cotización, Subcliente, Fecha de pedido, Vendedor. */
    public function test_lista_expone_las_columnas_requeridas(): void
    {
        $sellerId = $this->userIdByEmail('designer@atlantic.test');
        $order = $this->makeOrderWithPipelineCard($sellerId, ['order_number' => 'PED-2026-COLS']);

        Quote::create([
            'sales_project_id' => $order->pipelineCard->sales_project_id,
            'owner_id' => $sellerId, 'status' => 'approved',
            'folio' => 'COT-2026-9001', 'generated_at' => now(),
        ]);

        $response = $this->withHeaders($this->authHeadersForBodegaReader())
            ->getJson('/api/bodega/orders/status?search=COLS')
            ->assertStatus(200);

        $row = collect($response->json('data'))->firstWhere('order_number', 'PED-2026-COLS');
        $this->assertNotNull($row);
        $this->assertSame($order->id, $row['order_id']);
        $this->assertSame('COT-2026-9001', $row['quote_number']);
        $this->assertSame($order->customer_name, $row['subcliente']);
        $this->assertSame($order->created_at->toDateString(), $row['fecha_pedido']);
        $this->assertNotEmpty($row['vendedor']);
    }

    public function test_buscador_encuentra_por_numero_de_cotizacion(): void
    {
        $sellerId = $this->userIdByEmail('designer@atlantic.test');
        $order = $this->makeOrderWithPipelineCard($sellerId, ['order_number' => 'PED-2026-QSEARCH']);
        Quote::create([
            'sales_project_id' => $order->pipelineCard->sales_project_id,
            'owner_id' => $sellerId, 'status' => 'approved',
            'folio' => 'COT-2026-UNIQUE', 'generated_at' => now(),
        ]);
        $this->makeOrder(['order_number' => 'PED-2026-OTHER']);

        $response = $this->withHeaders($this->authHeadersForBodegaReader())
            ->getJson('/api/bodega/orders/status?search=COT-2026-UNIQUE')
            ->assertStatus(200);

        $rows = collect($response->json('data'));
        $this->assertCount(1, $rows);
        $this->assertSame($order->id, $rows->first()['order_id']);
    }

    /**
     * REQ-340, corregido SCRUM-895 (2 vueltas) — "Solicitada" es el monto FIJO original (el de la
     * tarjeta RAÍZ), nunca la suma across la familia: cuando Picking/Packing generan una tarjeta
     * hija por faltante (`PickingService::createInventoryReviewCard()`/
     * `DeliveryService::createFaltanteCard()`), el ítem de esa hija nace con `qty_requested` =
     * REMANENTE todavía pendiente (un fragmento del mismo pedido, no una solicitud adicional) —
     * mientras la tarjeta raíz conserva su `qty_requested` original sin tocar. "Entregado" solo
     * suma `qty_delivered` de las tarjetas que YA llegaron a `Order::STAGE_ENTREGADO` (2da vuelta,
     * ver docblock de `OrderStatusService::consolidatedItems()`). Escenario: "entregas parciales,
     * con algunas tarjetas ya completadas (Entregado) y otras todavía pendientes (etapa anterior)"
     * (uno de los 4 escenarios pedidos en el ticket).
     */
    public function test_detalle_consolida_pendiente_por_producto_a_traves_de_la_familia(): void
    {
        $product = $this->makeCatalogProduct(['reference' => 'REF-CONSOL']);
        $sellerId = $this->userIdByEmail('designer@atlantic.test');

        // Raíz: 10 solicitados originalmente (FIJO), de los cuales ya se entregaron 6 — el
        // qty_requested de la raíz NUNCA se reduce a 6, sigue reflejando el pedido completo tal
        // como se aprobó (mismo patrón real de DeliveryService::registerDelivery()). Ya llegó a
        // Entregado: su porción SÍ cuenta.
        $root = $this->makeOrderWithPipelineCard(
            $sellerId,
            ['order_number' => 'PED-2026-SPLIT', 'stage' => Order::STAGE_ENTREGADO],
            ['catalog_product_id' => $product->id, 'qty_requested' => 10, 'qty_delivered' => 6],
        );
        // Hija (Faltante): nace con el REMANENTE (10 - 6 = 4) como su propio qty_requested — no es
        // un pedido adicional, es la porción todavía sin entregar del mismo pedido original.
        // Todavía en Asignado (antes de Entregado): su porción NO cuenta como entregada todavía.
        $child = $this->makeOrder([
            'order_number' => 'PED-2026-SPLIT', 'family_id' => $root->id,
            'pipeline_card_id' => $root->pipeline_card_id, 'stage' => Order::STAGE_ASIGNADO,
        ], [
            'catalog_product_id' => $product->id, 'qty_requested' => 4, 'qty_delivered' => 0,
        ]);

        $response = $this->withHeaders($this->authHeadersForBodegaReader())
            ->getJson("/api/bodega/orders/status/{$root->id}")
            ->assertStatus(200);

        $items = collect($response->json('items'));
        $line = $items->firstWhere('catalog_product_id', $product->id);
        $this->assertNotNull($line);
        $this->assertSame(10, $line['solicitada'], 'Solicitada = 10, fija — SOLO la raíz, nunca sumada con el remanente de la hija.');
        $this->assertSame(6, $line['entregado'], 'Entregado = 6 de la raíz (ya en Entregado) — la hija (Asignado) todavía no aporta nada.');
        $this->assertSame(4, $line['pendiente'], 'Pendiente = max(0, 10 - 6) = 4.');
        $this->assertSame($line['solicitada'], $line['entregado'] + $line['pendiente'], 'Invariante: Entregado + Pendiente = Solicitada.');

        // También accesible abriendo por el id de la tarjeta HIJA — misma consolidación de familia.
        $responseFromChild = $this->withHeaders($this->authHeadersForBodegaReader())
            ->getJson("/api/bodega/orders/status/{$child->id}")
            ->assertStatus(200);
        $this->assertSame($root->id, $responseFromChild->json('order_id'));
    }

    /**
     * SCRUM-895 (2da vuelta) — regresión del rebote real de Gerencia (2026-09-11): `qty_delivered`
     * se setea en `DeliveryService::registerDelivery()` en la transición Packing → Por despachar
     * (declarado al despachar, no confirmado en destino) — una tarjeta que sigue en Por despachar
     * o Despachado (todavía no llegó a Entregado) NO debe contar como "entregado" aunque su
     * `qty_delivered` ya tenga un valor.
     */
    public function test_detalle_no_cuenta_como_entregado_una_tarjeta_en_por_despachar_o_despachado(): void
    {
        $product = $this->makeCatalogProduct(['reference' => 'REF-EN-TRANSITO']);
        $sellerId = $this->userIdByEmail('designer@atlantic.test');

        $root = $this->makeOrderWithPipelineCard(
            $sellerId,
            ['order_number' => 'PED-2026-TRANSITO', 'stage' => Order::STAGE_DESPACHADO],
            ['catalog_product_id' => $product->id, 'qty_requested' => 20, 'qty_delivered' => 20],
        );

        $response = $this->withHeaders($this->authHeadersForBodegaReader())
            ->getJson("/api/bodega/orders/status/{$root->id}")
            ->assertStatus(200);

        $line = collect($response->json('items'))->firstWhere('catalog_product_id', $product->id);
        $this->assertSame(20, $line['solicitada']);
        $this->assertSame(0, $line['entregado'], 'Despachado != Entregado — todavía no llegó al cliente, no debe contar como entregado.');
        $this->assertSame(20, $line['pendiente'], 'Pendiente = el total, hasta que la tarjeta llegue realmente a Entregado.');

        // Pre-QA (2026-09-11) — el documento "Estatus de Pedido" (PDF) usa exactamente el mismo
        // payload que este JSON (`OrderDocumentService::generateEstatusPedido()` recibe
        // `detailPayload()` sin recalcular nada, ver su docblock) — confirmado acá renderizando la
        // vista real con el payload de ESTE escenario (tarjeta en tránsito), no solo leyendo el
        // código: si algún día el blade calculara los números por su cuenta en vez de imprimir el
        // array recibido, este assert lo detectaría.
        $this->makeCurrentTenant();
        $payload = app(OrderStatusService::class)->detailPayload($root->fresh());
        $html = view('bodega.estatus-pedido-pdf', ['status' => $payload])->render();
        $rowPos = strpos($html, 'REF-EN-TRANSITO');
        $this->assertNotFalse($rowPos, 'La fila del producto en tránsito debe aparecer en el PDF.');
        $rowHtml = substr($html, $rowPos, 400);
        $this->assertMatchesRegularExpression('/<td>20<\/td>\s*<td>0<\/td>\s*<td>20<\/td>/', $rowHtml, 'El PDF debe mostrar Solicitada=20/Entregado=0/Pendiente=20, igual que el modal JSON.');
    }

    /**
     * Pre-QA (2026-09-11) — mismo escenario que el test anterior pero con MÁS de 2 tarjetas
     * miembro, para confirmar que el filtro por `stage === Order::STAGE_ENTREGADO` no depende de
     * que la familia tenga exactamente raíz+1 hija: una familia de 3 tarjetas en 3 stages
     * distintos (Asignado, Despachado, Entregado) debe sumar `entregado` de la ÚNICA tarjeta que
     * ya llegó a Entregado, sin importar cuántas tarjetas intermedias existan entre medio.
     */
    public function test_detalle_entregado_solo_suma_la_tarjeta_en_entregado_con_mas_de_dos_tarjetas_en_la_familia(): void
    {
        $product = $this->makeCatalogProduct(['reference' => 'REF-3CARDS']);
        $sellerId = $this->userIdByEmail('designer@atlantic.test');

        // Raíz: 15 solicitados originalmente (FIJO) — todavía en Asignado, su propia porción (4)
        // no se ha movido ni entregado.
        $root = $this->makeOrderWithPipelineCard(
            $sellerId,
            ['order_number' => 'PED-2026-3CARDS', 'stage' => Order::STAGE_ASIGNADO],
            ['catalog_product_id' => $product->id, 'qty_requested' => 15, 'qty_delivered' => 0],
        );
        // Hija A: ya despachada (en tránsito) — declaró 5 a entregar, pero todavía NO llegó al
        // cliente. No debe contar como entregado.
        $childDespachado = $this->makeOrder([
            'order_number' => 'PED-2026-3CARDS', 'family_id' => $root->id,
            'pipeline_card_id' => $root->pipeline_card_id, 'stage' => Order::STAGE_DESPACHADO,
        ], [
            'catalog_product_id' => $product->id, 'qty_requested' => 5, 'qty_delivered' => 5,
        ]);
        // Hija B: ya llegó a Entregado — su porción (6) SÍ cuenta.
        $childEntregado = $this->makeOrder([
            'order_number' => 'PED-2026-3CARDS', 'family_id' => $root->id,
            'pipeline_card_id' => $root->pipeline_card_id, 'stage' => Order::STAGE_ENTREGADO,
        ], [
            'catalog_product_id' => $product->id, 'qty_requested' => 6, 'qty_delivered' => 6,
        ]);

        $response = $this->withHeaders($this->authHeadersForBodegaReader())
            ->getJson("/api/bodega/orders/status/{$root->id}")
            ->assertStatus(200);

        $line = collect($response->json('items'))->firstWhere('catalog_product_id', $product->id);
        $this->assertNotNull($line);
        $this->assertSame(15, $line['solicitada'], 'Solicitada = 15, fija — SOLO la raíz.');
        $this->assertSame(6, $line['entregado'], 'Entregado = 6 (solo la tarjeta en stage Entregado) — Despachado (5) y Asignado (0) no cuentan.');
        $this->assertSame(9, $line['pendiente'], 'Pendiente = max(0, 15 - 6) = 9.');
        $this->assertSame($line['solicitada'], $line['entregado'] + $line['pendiente'], 'Invariante: Entregado + Pendiente = Solicitada.');

        // Accesible también desde cualquiera de las 2 tarjetas hijas — misma consolidación.
        foreach ([$childDespachado, $childEntregado] as $child) {
            $this->withHeaders($this->authHeadersForBodegaReader())
                ->getJson("/api/bodega/orders/status/{$child->id}")
                ->assertStatus(200)
                ->assertJsonPath('order_id', $root->id);
        }
    }

    /**
     * SCRUM-895 — regresión del bug real reportado por QA (pedido #PED-2026-0065, "Facturación 7",
     * producto GE-SOCKET1): entregas parciales completadas EN SU TOTALIDAD (raíz + hija, ambas ya
     * entregadas) deben dar Pendiente = 0, no un remanente fantasma por haber sumado el
     * `qty_requested` de la hija sobre el de la raíz.
     */
    public function test_detalle_pendiente_es_cero_cuando_entregas_parciales_completan_el_total(): void
    {
        $product = $this->makeCatalogProduct(['reference' => 'GE-SOCKET1']);
        $sellerId = $this->userIdByEmail('designer@atlantic.test');

        $root = $this->makeOrderWithPipelineCard(
            $sellerId,
            ['order_number' => 'PED-2026-0065', 'stage' => Order::STAGE_ENTREGADO],
            ['catalog_product_id' => $product->id, 'qty_requested' => 10, 'qty_delivered' => 6],
        );
        $child = $this->makeOrder([
            'order_number' => 'PED-2026-0065', 'family_id' => $root->id,
            'pipeline_card_id' => $root->pipeline_card_id, 'stage' => Order::STAGE_ENTREGADO,
        ], [
            'catalog_product_id' => $product->id, 'qty_requested' => 4, 'qty_delivered' => 4,
        ]);

        $response = $this->withHeaders($this->authHeadersForBodegaReader())
            ->getJson("/api/bodega/orders/status/{$root->id}")
            ->assertStatus(200);

        $line = collect($response->json('items'))->firstWhere('catalog_product_id', $product->id);
        $this->assertSame(10, $line['solicitada']);
        $this->assertSame(10, $line['entregado'], 'Entregado = 6 (raíz) + 4 (hija) = 10, el total.');
        $this->assertSame(0, $line['pendiente'], 'Pendiente debe dar 0, no 10 — bug real reportado por QA.');

        // El documento PDF ("Estatus de Pedido") usa exactamente el mismo payload — sin discrepancia.
        $document = $this->withHeaders($this->authHeadersForBodegaReader())
            ->getJson("/api/bodega/orders/status/{$child->id}/document")
            ->assertStatus(200);
        $this->assertSame($root->id, $document->json('order_id'));
    }

    /** REQ-340 — "si el pedido completo ya se entregó, pendiente = 0 en todas las líneas" (tarjeta única, sin split). */
    public function test_detalle_pendiente_es_cero_cuando_familia_completa_esta_entregada(): void
    {
        $product = $this->makeCatalogProduct(['reference' => 'REF-DONE']);
        $sellerId = $this->userIdByEmail('designer@atlantic.test');

        $root = $this->makeOrderWithPipelineCard(
            $sellerId,
            ['order_number' => 'PED-2026-DONE', 'stage' => Order::STAGE_ENTREGADO],
            ['catalog_product_id' => $product->id, 'qty_requested' => 5, 'qty_delivered' => 5],
        );

        $response = $this->withHeaders($this->authHeadersForBodegaReader())
            ->getJson("/api/bodega/orders/status/{$root->id}")
            ->assertStatus(200);

        $line = collect($response->json('items'))->firstWhere('catalog_product_id', $product->id);
        $this->assertSame(5, $line['solicitada']);
        $this->assertSame(5, $line['entregado']);
        $this->assertSame(0, $line['pendiente']);
    }

    /** Escenario del ticket: tarjeta única, entrega todavía NO realizada — pendiente = solicitada completo. */
    public function test_detalle_pendiente_es_el_total_cuando_entrega_unica_no_se_realizo(): void
    {
        $product = $this->makeCatalogProduct(['reference' => 'REF-NODONE']);
        $sellerId = $this->userIdByEmail('designer@atlantic.test');

        $root = $this->makeOrderWithPipelineCard(
            $sellerId,
            ['order_number' => 'PED-2026-NODONE', 'stage' => Order::STAGE_ASIGNADO],
            ['catalog_product_id' => $product->id, 'qty_requested' => 8, 'qty_delivered' => 0],
        );

        $response = $this->withHeaders($this->authHeadersForBodegaReader())
            ->getJson("/api/bodega/orders/status/{$root->id}")
            ->assertStatus(200);

        $line = collect($response->json('items'))->firstWhere('catalog_product_id', $product->id);
        $this->assertSame(8, $line['solicitada']);
        $this->assertSame(0, $line['entregado']);
        $this->assertSame(8, $line['pendiente']);
    }

    /** REQ-339 — datos del modal: proyecto, diseñador (=owner_id), cliente, contacto, teléfono. */
    public function test_detalle_expone_datos_generales_del_modal(): void
    {
        $sellerId = $this->userIdByEmail('designer@atlantic.test');
        $order = $this->makeOrderWithPipelineCard($sellerId, ['order_number' => 'PED-2026-DETAIL']);

        $response = $this->withHeaders($this->authHeadersForBodegaReader())
            ->getJson("/api/bodega/orders/status/{$order->id}")
            ->assertStatus(200);

        $this->assertNotNull($response->json('proyecto'));
        $this->assertNotEmpty($response->json('disenador'));
        $this->assertSame($order->customer_name, $response->json('cliente'));
        $this->assertSame('Contacto Status Test', $response->json('contacto'));
        $this->assertSame('6000-9999', $response->json('telefono'));
    }

    /** REQ-342 — Bodega ve TODOS los pedidos, sin filtro de dueño. */
    public function test_bodega_ve_todos_los_pedidos(): void
    {
        $sellerA = $this->userIdByEmail('designer@atlantic.test');
        $sellerB = $this->userIdByEmail('management@atlantic.test');
        $this->makeOrderWithPipelineCard($sellerA, ['order_number' => 'PED-2026-SELLERA']);
        $this->makeOrderWithPipelineCard($sellerB, ['order_number' => 'PED-2026-SELLERB']);

        $response = $this->withHeaders($this->authHeadersForBodegaReader())
            ->getJson('/api/bodega/orders/status')
            ->assertStatus(200);

        $orderNumbers = collect($response->json('data'))->pluck('order_number');
        $this->assertContains('PED-2026-SELLERA', $orderNumbers);
        $this->assertContains('PED-2026-SELLERB', $orderNumbers);
    }

    /**
     * SCRUM-897 — Administración & Contabilidad replica "Status de Pedidos" en Facturación (mismo
     * endpoint, frontend en paralelo). Contabilidad (lider_admin_contab), Asistente Administrativa
     * (asistente_administrativa) y Gerencia (management) — mismos roles que ya gatean
     * Facturación/Cobros (`routes/admin-contab.php`) — deben poder consultar el listado completo
     * y el detalle, sin `bodega.read` ni `ventas_diseno.read`, solo por su rol de Admin&Cont.
     */
    public function test_administracion_y_contabilidad_accede_al_listado_y_detalle_de_status_de_pedidos(): void
    {
        $sellerA = $this->userIdByEmail('designer@atlantic.test');
        $order = $this->makeOrderWithPipelineCard($sellerA, ['order_number' => 'PED-2026-ADMINCONT']);

        foreach ([
            ['contabilidad@test.com', 'lider_admin_contab'],
            ['asistenteadministrativa@test.com', 'asistente_administrativa'],
            ['lideroperaciones@test.com', 'lider_operaciones'], // SCRUM-1139: espeja a la asistente
            ['gerencia3@test.com', 'management'],
        ] as [$email, $role]) {
            $token = $this->loginAs($email, $email, $role);
            $headers = $this->authHeaders($token);

            $response = $this->withHeaders($headers)
                ->getJson('/api/bodega/orders/status')
                ->assertStatus(200);
            $orderNumbers = collect($response->json('data'))->pluck('order_number');
            $this->assertContains(
                'PED-2026-ADMINCONT', $orderNumbers,
                "{$role} debe ver TODOS los pedidos en el listado, igual que Bodega.",
            );

            $this->withHeaders($headers)
                ->getJson("/api/bodega/orders/status/{$order->id}")
                ->assertStatus(200);
        }
    }

    /** REQ-342 — Ventas & Diseño ve SOLO los pedidos propios (owner_id), nunca los de otro vendedor. */
    public function test_vendedor_ve_solo_sus_propios_pedidos(): void
    {
        $this->grantVentasDisenoRead('designer@atlantic.test');
        $sellerA = $this->userIdByEmail('designer@atlantic.test');
        $sellerB = $this->userIdByEmail('management@atlantic.test');

        $ownOrder = $this->makeOrderWithPipelineCard($sellerA, ['order_number' => 'PED-2026-MINE']);
        $otherOrder = $this->makeOrderWithPipelineCard($sellerB, ['order_number' => 'PED-2026-OTHERS']);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $headers = $this->authHeaders($token);

        $response = $this->withHeaders($headers)
            ->getJson('/api/bodega/orders/status')
            ->assertStatus(200);

        $orderNumbers = collect($response->json('data'))->pluck('order_number');
        $this->assertContains('PED-2026-MINE', $orderNumbers);
        $this->assertNotContains('PED-2026-OTHERS', $orderNumbers, 'Un vendedor no debe ver pedidos de otro vendedor.');

        // Tampoco puede abrir el detalle de un pedido ajeno directamente por id. SCRUM-1171: responde 404, igual que un id inexistente
        // (con 403 vs 404 podía enumerar qué ids existen).
        $this->withHeaders($headers)
            ->getJson("/api/bodega/orders/status/{$otherOrder->id}")
            ->assertStatus(404);

        // Pero sí el propio.
        $this->withHeaders($headers)
            ->getJson("/api/bodega/orders/status/{$ownOrder->id}")
            ->assertStatus(200);
    }

    /**
     * SCRUM-741 — mismo hallazgo que InventoryControllerTest, mismo mecanismo
     * (UatVisibilityService::isModuleHidden()): la rama restringida a "lo propio" es el único
     * camino que un usuario sin bodega.read tiene hacia estos datos, así que debe cortarse cuando
     * Bodega está oculto por la etapa de UAT vigente, a diferencia del resto de la app donde esa
     * máscara es solo cosmética.
     */
    public function test_vendedor_pierde_acceso_cuando_bodega_esta_oculto_por_uat(): void
    {
        $this->grantVentasDisenoRead('designer@atlantic.test');
        $sellerA = $this->userIdByEmail('designer@atlantic.test');
        $this->makeOrderWithPipelineCard($sellerA, ['order_number' => 'PED-2026-UATHIDDEN']);

        $superToken = $this->loginAs('superadmin@atlantic.test', 'Password123!');
        $this->withHeaders($this->authHeaders($superToken))
            ->postJson('/api/admin/module-visibility/bulk', ['modules' => ['bodega'], 'menuItems' => [], 'action' => 'hide'])
            ->assertStatus(200);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/bodega/orders/status')
            ->assertStatus(403);

        // Restaurar deja pasar de nuevo — la máscara es reversible, no un cambio de permisos real.
        $this->withHeaders($this->authHeaders($superToken))
            ->postJson('/api/admin/module-visibility/bulk', ['modules' => ['bodega'], 'menuItems' => [], 'action' => 'restore'])
            ->assertStatus(200);
        $restoredToken = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($restoredToken))
            ->getJson('/api/bodega/orders/status')
            ->assertStatus(200);
    }

    /**
     * SCRUM-741, revisado tras hallazgo real de Pre-QA (2026-08-09, con las cuentas reales del
     * ticket): Gerencia y Gerencia (Gerencia) tienen bodega.read REAL vía su rol `management` — no solo
     * el fallback restringido de ventas_diseno.read. Luis decidió que, mientras Bodega esté oculto
     * por UAT, NADIE no-superadmin accede de verdad, ni siquiera con bodega.read real.
     */
    public function test_bodega_read_pierde_acceso_cuando_bodega_esta_oculto_por_uat(): void
    {
        $sellerA = $this->userIdByEmail('designer@atlantic.test');
        $this->makeOrderWithPipelineCard($sellerA, ['order_number' => 'PED-2026-BODEGAHIDDEN']);

        $superToken = $this->loginAs('superadmin@atlantic.test', 'Password123!');
        $this->withHeaders($this->authHeaders($superToken))
            ->postJson('/api/admin/module-visibility/bulk', ['modules' => ['bodega'], 'menuItems' => [], 'action' => 'hide'])
            ->assertStatus(200);

        $this->withHeaders($this->authHeadersForBodegaReader())
            ->getJson('/api/bodega/orders/status')
            ->assertStatus(403);

        // Restaurar deja pasar de nuevo — la máscara es reversible, no un cambio de permisos real.
        $this->withHeaders($this->authHeaders($superToken))
            ->postJson('/api/admin/module-visibility/bulk', ['modules' => ['bodega'], 'menuItems' => [], 'action' => 'restore'])
            ->assertStatus(200);
        $response = $this->withHeaders($this->authHeadersForBodegaReader())
            ->getJson('/api/bodega/orders/status')
            ->assertStatus(200);
        $orderNumbers = collect($response->json('data'))->pluck('order_number');
        $this->assertContains('PED-2026-BODEGAHIDDEN', $orderNumbers);
    }

    /** SCRUM-741 — superadmin.all nunca se ve afectado por la máscara UAT, en ningún escenario. */
    public function test_superadmin_conserva_acceso_aunque_bodega_este_oculto_por_uat(): void
    {
        $this->makeOrder(['order_number' => 'PED-2026-SUPERADMIN']);

        $superToken = $this->loginAs('superadmin@atlantic.test', 'Password123!');
        $this->withHeaders($this->authHeaders($superToken))
            ->postJson('/api/admin/module-visibility/bulk', ['modules' => ['bodega'], 'menuItems' => [], 'action' => 'hide'])
            ->assertStatus(200);

        $freshSuperToken = $this->loginAs('superadmin@atlantic.test', 'Password123!');
        $response = $this->withHeaders($this->authHeaders($freshSuperToken))
            ->getJson('/api/bodega/orders/status')
            ->assertStatus(200);
        $orderNumbers = collect($response->json('data'))->pluck('order_number');
        $this->assertContains('PED-2026-SUPERADMIN', $orderNumbers);
    }

    /**
     * Un usuario sin ningún permiso de Bodega ni de Ventas & Diseño no tiene acceso.
     * `supervisor@atlantic.test` (level 5, `role_key` null — sin `RoleModuleVisibility` ni
     * `superadmin.all`, a diferencia de `management@atlantic.test`/`gerencia@...` que en
     * este fixture están en level 10 = Super Admin y por lo tanto SIEMPRE traen `superadmin.all`
     * vía `plainEscapeHatchPermissions()`, sin importar el rol elegido al loguear).
     */
    public function test_usuario_sin_permiso_relevante_recibe_403(): void
    {
        $order = $this->makeOrder(['order_number' => 'PED-2026-NOPERM']);

        $token = $this->loginAs('supervisor@atlantic.test', 'Password123!', 'supervisor');
        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/bodega/orders/status')
            ->assertStatus(403);

        $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/bodega/orders/status/{$order->id}")
            ->assertStatus(403);
    }

    // SCRUM-1171 — un pedido ajeno y uno inexistente son INDISTINGUIBLES para el vendedor (mismo código y mismo cuerpo): no se pueden enumerar ids.
    public function test_pedido_ajeno_e_inexistente_responden_igual_al_vendedor_en_detalle_y_documento(): void
    {
        $this->grantVentasDisenoRead('designer@atlantic.test');
        $otherOrder = $this->makeOrderWithPipelineCard($this->userIdByEmail('management@atlantic.test'), ['order_number' => 'PED-2026-AJENO']);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $headers = $this->authHeaders($token);

        foreach (['', '/document'] as $suffix) {
            $ajeno = $this->withHeaders($headers)->getJson("/api/bodega/orders/status/{$otherOrder->id}{$suffix}");
            $inexistente = $this->withHeaders($headers)->getJson("/api/bodega/orders/status/999999{$suffix}");

            $ajeno->assertStatus(404);
            $inexistente->assertStatus(404);
            $this->assertSame($inexistente->json(), $ajeno->json(), "El cuerpo debe ser idéntico para ajeno e inexistente ({$suffix}).");
        }
    }

    // SCRUM-1171 — un id no numérico o mayor que int64 daba 500 (TypeError) con la ruta del archivo del servidor en el cuerpo; ahora 404 sin ninguna traza.
    public function test_id_no_numerico_retorna_404_sin_exponer_trazas(): void
    {
        foreach (['/api/bodega/orders/status/abc', '/api/bodega/orders/status/abc/document', '/api/bodega/orders/status/1.5', '/api/bodega/orders/status/1e3', '/api/bodega/orders/status/99999999999999999999', '/api/bodega/orders/status/99999999999999999999/document', '/api/bodega/orders/status/9223372036854775808'] as $url) {
            $response = $this->withHeaders($this->authHeadersForBodegaReader())->getJson($url);

            $response->assertStatus(404);
            $body = $response->getContent();
            $this->assertStringNotContainsString('TypeError', (string) $body, $url);
            $this->assertStringNotContainsString('.php', (string) $body, $url);
            $this->assertStringNotContainsString('/var/www', (string) $body, $url);
        }
    }

    // SCRUM-1171 — `%` y `_` del buscador son literales: `search=%` ya no lista todo ni `search=_` coincide con cualquier carácter.
    public function test_buscador_trata_porcentaje_y_guion_bajo_como_texto_literal(): void
    {
        $sellerId = $this->userIdByEmail('designer@atlantic.test');
        $this->makeOrderWithPipelineCard($sellerId, ['order_number' => 'PED-2026-LIT-A']);
        $this->makeOrderWithPipelineCard($sellerId, ['order_number' => 'PED-2026-LIT_B']);

        $headers = $this->authHeadersForBodegaReader();
        $numeros = fn (string $q) => collect($this->withHeaders($headers)->getJson('/api/bodega/orders/status?search='.rawurlencode($q))->assertStatus(200)->json('data'))->pluck('order_number')->all();

        $this->assertSame([], $numeros('%'), '`%` literal no coincide con ningún pedido (antes listaba todos).');
        $this->assertSame(['PED-2026-LIT_B'], $numeros('LIT_B'), '`_` literal: solo el pedido que realmente lo contiene.');
        $this->assertSame(['PED-2026-LIT_B'], $numeros('LIT_'), '`LIT_` literal coincide solo con el pedido que contiene ese texto, no con `LIT-A` (el guion bajo no es comodín de un carácter).');
        $this->assertContains('PED-2026-LIT-A', $numeros('LIT-A'), 'La búsqueda normal sigue funcionando.');
    }

    public function test_detalle_de_pedido_inexistente_retorna_404(): void
    {
        $this->withHeaders($this->authHeadersForBodegaReader())
            ->getJson('/api/bodega/orders/status/999999')
            ->assertStatus(404);
    }

    /**
     * REQ-341 — genera el documento "Estatus de Pedido" con el tipo reservado, adjunto a la
     * tarjeta RAÍZ de la familia (aunque se pida por el id de una tarjeta hija), y devuelve una
     * URL de descarga en la misma respuesta.
     */
    public function test_genera_documento_estatus_pedido_con_tipo_correcto(): void
    {
        $sellerId = $this->userIdByEmail('designer@atlantic.test');
        $root = $this->makeOrderWithPipelineCard($sellerId, ['order_number' => 'PED-2026-DOC']);
        $child = $this->makeOrder(['order_number' => 'PED-2026-DOC', 'family_id' => $root->id, 'pipeline_card_id' => $root->pipeline_card_id]);

        $response = $this->withHeaders($this->authHeadersForBodegaReader())
            ->getJson("/api/bodega/orders/status/{$child->id}/document")
            ->assertStatus(200);

        $this->assertSame($root->id, $response->json('order_id'));
        $this->assertNotEmpty($response->json('url'));

        $this->makeCurrentTenant();
        $document = OrderDocument::find($response->json('document_id'));
        $this->assertNotNull($document);
        $this->assertSame(OrderDocument::TIPO_ESTATUS_PEDIDO, $document->tipo);
        $this->assertSame($root->id, $document->order_id);
    }

    /**
     * SCRUM-1171 — «Ver/Descargar» ya no crea un documento nuevo por clic: con el mismo contenido se reutiliza el existente (misma fila y mismo
     * objeto en S3); si el estatus CAMBIA, se genera uno nuevo (nunca se sirve un PDF con cantidades viejas).
     */
    public function test_documento_de_estatus_se_reutiliza_si_nada_cambio_y_se_regenera_si_cambia(): void
    {
        $sellerId = $this->userIdByEmail('designer@atlantic.test');
        $root = $this->makeOrderWithPipelineCard($sellerId, ['order_number' => 'PED-2026-REUSE']);
        $headers = $this->authHeadersForBodegaReader();

        $first = $this->withHeaders($headers)->getJson("/api/bodega/orders/status/{$root->id}/document")->assertStatus(200);
        $second = $this->withHeaders($headers)->getJson("/api/bodega/orders/status/{$root->id}/document")->assertStatus(200);

        $this->assertSame($first->json('document_id'), $second->json('document_id'), 'Sin cambios debe reutilizar el mismo documento.');
        $this->makeCurrentTenant();
        $this->assertSame(1, OrderDocument::where('order_id', $root->id)->where('tipo', OrderDocument::TIPO_ESTATUS_PEDIDO)->count());

        // Cambia lo que muestra el documento: el payload solo cuenta como entregada la tarjeta en etapa «Entregado», así que se entrega parte del ítem →
        // el pendiente baja → huella distinta → documento nuevo.
        $root->update(['stage' => Order::STAGE_ENTREGADO]);
        $root->items()->first()->update(['qty_delivered' => 3]);

        $third = $this->withHeaders($headers)->getJson("/api/bodega/orders/status/{$root->id}/document")->assertStatus(200);
        $this->assertNotSame($first->json('document_id'), $third->json('document_id'), 'Con el estatus cambiado debe generar un documento nuevo.');
        $this->makeCurrentTenant();
        $this->assertSame(2, OrderDocument::where('order_id', $root->id)->where('tipo', OrderDocument::TIPO_ESTATUS_PEDIDO)->count());

        // Y ese nuevo también se reutiliza si no vuelve a cambiar nada.
        $fourth = $this->withHeaders($headers)->getJson("/api/bodega/orders/status/{$root->id}/document")->assertStatus(200);
        $this->assertSame($third->json('document_id'), $fourth->json('document_id'));
    }

    /**
     * Senior Review 2026-07-23 (patrón `bulkSupplierEtaByProduct()`, ver
     * `OrderBoardControllerTest::test_eta_proveedor_no_escala_en_queries_con_mas_pedidos_sin_stock()`)
     * — el N° de cotización se batchea en 1 sola query para TODO el listado
     * (`bulkQuoteFolioBySalesProject()`), sin importar cuántos pedidos/proyectos distintos haya.
     */
    public function test_listado_no_escala_en_queries_de_cotizacion_con_mas_pedidos(): void
    {
        $sellerId = $this->userIdByEmail('designer@atlantic.test');

        for ($i = 1; $i <= 5; $i++) {
            $order = $this->makeOrderWithPipelineCard($sellerId, ['order_number' => "PED-2026-SCALE-{$i}"]);
            Quote::create([
                'sales_project_id' => $order->pipelineCard->sales_project_id,
                'owner_id' => $sellerId, 'status' => 'approved',
                'folio' => "COT-2026-SCALE-{$i}", 'generated_at' => now(),
            ]);
        }

        DB::enableQueryLog();
        $this->withHeaders($this->authHeadersForBodegaReader())
            ->getJson('/api/bodega/orders/status')
            ->assertStatus(200);
        $quoteQueries = collect(DB::getQueryLog())
            ->filter(fn (array $q) => str_contains($q['query'], '"quotes"') || str_contains($q['query'], '.quotes'))
            ->count();
        DB::flushQueryLog();
        DB::disableQueryLog();

        $this->assertSame(
            1,
            $quoteQueries,
            "El N° de cotización debe resolverse en 1 sola query batcheada, no una por proyecto ({$quoteQueries} encontradas).",
        );
    }
}
