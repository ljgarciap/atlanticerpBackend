<?php

declare(strict_types=1);

namespace Tests\Feature\Bodega;

use App\Modules\Bodega\Models\Order;
use App\Modules\Bodega\Models\OrderDocument;
use App\Modules\VentasDiseno\Models\Quote;
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
        $this->grantBodegaPermissions('almacen@illuminations.com.pa', ['bodega.read']);
        $token = $this->loginAs('almacen@illuminations.com.pa', 'almacen@illuminations.com.pa', 'lider_bodega');

        return $this->authHeaders($token);
    }

    /** @param list<string> $extra */
    private function grantVentasDisenoRead(string $email, array $extra = []): void
    {
        $slug = (string) \App\Shared\Multitenancy\Tenant::where('slug', 'illuminations')->value('slug');
        $permissions = array_merge(['ventas_diseno.read'], $extra);
        DB::table("{$slug}_auth.users")->where('email', $email)->update(['extra_permissions' => json_encode($permissions)]);
    }

    /** REQ-338 — un pedido dividido en varias tarjetas (familia) aparece como UNA sola fila. */
    public function test_lista_consolida_familia_en_una_sola_fila(): void
    {
        $sellerId = $this->userIdByEmail('designer@illuminations.test');
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
        $sellerId = $this->userIdByEmail('designer@illuminations.test');
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
        $sellerId = $this->userIdByEmail('designer@illuminations.test');
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

    /** REQ-340 — pendiente consolidado a través de la familia (una tarjeta entregada, otra pendiente). */
    public function test_detalle_consolida_pendiente_por_producto_a_traves_de_la_familia(): void
    {
        $product = $this->makeCatalogProduct(['reference' => 'REF-CONSOL']);
        $sellerId = $this->userIdByEmail('designer@illuminations.test');

        $root = $this->makeOrderWithPipelineCard(
            $sellerId,
            ['order_number' => 'PED-2026-SPLIT', 'stage' => Order::STAGE_POR_DESPACHAR],
            ['catalog_product_id' => $product->id, 'qty_requested' => 6, 'qty_delivered' => 6],
        );
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
        $this->assertSame(10, $line['solicitada'], 'Solicitada = 6 (raíz) + 4 (hija).');
        $this->assertSame(6, $line['entregado'], 'Entregado = 6 (raíz) + 0 (hija).');
        $this->assertSame(4, $line['pendiente'], 'Pendiente = max(0, 10 - 6) = 4.');

        // También accesible abriendo por el id de la tarjeta HIJA — misma consolidación de familia.
        $responseFromChild = $this->withHeaders($this->authHeadersForBodegaReader())
            ->getJson("/api/bodega/orders/status/{$child->id}")
            ->assertStatus(200);
        $this->assertSame($root->id, $responseFromChild->json('order_id'));
    }

    /** REQ-340 — "si el pedido completo ya se entregó, pendiente = 0 en todas las líneas". */
    public function test_detalle_pendiente_es_cero_cuando_familia_completa_esta_entregada(): void
    {
        $product = $this->makeCatalogProduct(['reference' => 'REF-DONE']);
        $sellerId = $this->userIdByEmail('designer@illuminations.test');

        $root = $this->makeOrderWithPipelineCard(
            $sellerId,
            ['order_number' => 'PED-2026-DONE', 'stage' => Order::STAGE_ENTREGADO],
            ['catalog_product_id' => $product->id, 'qty_requested' => 5, 'qty_delivered' => 5],
        );

        $response = $this->withHeaders($this->authHeadersForBodegaReader())
            ->getJson("/api/bodega/orders/status/{$root->id}")
            ->assertStatus(200);

        $line = collect($response->json('items'))->firstWhere('catalog_product_id', $product->id);
        $this->assertSame(0, $line['pendiente']);
    }

    /** REQ-339 — datos del modal: proyecto, diseñador (=owner_id), cliente, contacto, teléfono. */
    public function test_detalle_expone_datos_generales_del_modal(): void
    {
        $sellerId = $this->userIdByEmail('designer@illuminations.test');
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
        $sellerA = $this->userIdByEmail('designer@illuminations.test');
        $sellerB = $this->userIdByEmail('management@illuminations.test');
        $this->makeOrderWithPipelineCard($sellerA, ['order_number' => 'PED-2026-SELLERA']);
        $this->makeOrderWithPipelineCard($sellerB, ['order_number' => 'PED-2026-SELLERB']);

        $response = $this->withHeaders($this->authHeadersForBodegaReader())
            ->getJson('/api/bodega/orders/status')
            ->assertStatus(200);

        $orderNumbers = collect($response->json('data'))->pluck('order_number');
        $this->assertContains('PED-2026-SELLERA', $orderNumbers);
        $this->assertContains('PED-2026-SELLERB', $orderNumbers);
    }

    /** REQ-342 — Ventas & Diseño ve SOLO los pedidos propios (owner_id), nunca los de otro vendedor. */
    public function test_vendedor_ve_solo_sus_propios_pedidos(): void
    {
        $this->grantVentasDisenoRead('designer@illuminations.test');
        $sellerA = $this->userIdByEmail('designer@illuminations.test');
        $sellerB = $this->userIdByEmail('management@illuminations.test');

        $ownOrder   = $this->makeOrderWithPipelineCard($sellerA, ['order_number' => 'PED-2026-MINE']);
        $otherOrder = $this->makeOrderWithPipelineCard($sellerB, ['order_number' => 'PED-2026-OTHERS']);

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $headers = $this->authHeaders($token);

        $response = $this->withHeaders($headers)
            ->getJson('/api/bodega/orders/status')
            ->assertStatus(200);

        $orderNumbers = collect($response->json('data'))->pluck('order_number');
        $this->assertContains('PED-2026-MINE', $orderNumbers);
        $this->assertNotContains('PED-2026-OTHERS', $orderNumbers, 'Un vendedor no debe ver pedidos de otro vendedor.');

        // Tampoco puede abrir el detalle de un pedido ajeno directamente por id.
        $this->withHeaders($headers)
            ->getJson("/api/bodega/orders/status/{$otherOrder->id}")
            ->assertStatus(403);

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
        $this->grantVentasDisenoRead('designer@illuminations.test');
        $sellerA = $this->userIdByEmail('designer@illuminations.test');
        $this->makeOrderWithPipelineCard($sellerA, ['order_number' => 'PED-2026-UATHIDDEN']);

        $superToken = $this->loginAs('superadmin@illuminations.test', 'Password123!');
        $this->withHeaders($this->authHeaders($superToken))
            ->postJson('/api/admin/module-visibility/bulk', ['modules' => ['bodega'], 'menuItems' => [], 'action' => 'hide'])
            ->assertStatus(200);

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/bodega/orders/status')
            ->assertStatus(403);

        // Restaurar deja pasar de nuevo — la máscara es reversible, no un cambio de permisos real.
        $this->withHeaders($this->authHeaders($superToken))
            ->postJson('/api/admin/module-visibility/bulk', ['modules' => ['bodega'], 'menuItems' => [], 'action' => 'restore'])
            ->assertStatus(200);
        $restoredToken = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($restoredToken))
            ->getJson('/api/bodega/orders/status')
            ->assertStatus(200);
    }

    /**
     * SCRUM-741, revisado tras hallazgo real de Pre-QA (2026-08-09, con las cuentas reales del
     * ticket): David y Mark (Gerencia) tienen bodega.read REAL vía su rol `management` — no solo
     * el fallback restringido de ventas_diseno.read. Luis decidió que, mientras Bodega esté oculto
     * por UAT, NADIE no-superadmin accede de verdad, ni siquiera con bodega.read real.
     */
    public function test_bodega_read_pierde_acceso_cuando_bodega_esta_oculto_por_uat(): void
    {
        $sellerA = $this->userIdByEmail('designer@illuminations.test');
        $this->makeOrderWithPipelineCard($sellerA, ['order_number' => 'PED-2026-BODEGAHIDDEN']);

        $superToken = $this->loginAs('superadmin@illuminations.test', 'Password123!');
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

        $superToken = $this->loginAs('superadmin@illuminations.test', 'Password123!');
        $this->withHeaders($this->authHeaders($superToken))
            ->postJson('/api/admin/module-visibility/bulk', ['modules' => ['bodega'], 'menuItems' => [], 'action' => 'hide'])
            ->assertStatus(200);

        $freshSuperToken = $this->loginAs('superadmin@illuminations.test', 'Password123!');
        $response = $this->withHeaders($this->authHeaders($freshSuperToken))
            ->getJson('/api/bodega/orders/status')
            ->assertStatus(200);
        $orderNumbers = collect($response->json('data'))->pluck('order_number');
        $this->assertContains('PED-2026-SUPERADMIN', $orderNumbers);
    }

    /**
     * Un usuario sin ningún permiso de Bodega ni de Ventas & Diseño no tiene acceso.
     * `supervisor@illuminations.test` (level 5, `role_key` null — sin `RoleModuleVisibility` ni
     * `superadmin.all`, a diferencia de `management@illuminations.test`/`gerencia@...` que en
     * este fixture están en level 10 = Super Admin y por lo tanto SIEMPRE traen `superadmin.all`
     * vía `plainEscapeHatchPermissions()`, sin importar el rol elegido al loguear).
     */
    public function test_usuario_sin_permiso_relevante_recibe_403(): void
    {
        $order = $this->makeOrder(['order_number' => 'PED-2026-NOPERM']);

        $token = $this->loginAs('supervisor@illuminations.test', 'Password123!', 'supervisor');
        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/bodega/orders/status')
            ->assertStatus(403);

        $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/bodega/orders/status/{$order->id}")
            ->assertStatus(403);
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
        $sellerId = $this->userIdByEmail('designer@illuminations.test');
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
     * Senior Review 2026-07-23 (patrón `bulkSupplierEtaByProduct()`, ver
     * `OrderBoardControllerTest::test_eta_proveedor_no_escala_en_queries_con_mas_pedidos_sin_stock()`)
     * — el N° de cotización se batchea en 1 sola query para TODO el listado
     * (`bulkQuoteFolioBySalesProject()`), sin importar cuántos pedidos/proyectos distintos haya.
     */
    public function test_listado_no_escala_en_queries_de_cotizacion_con_mas_pedidos(): void
    {
        $sellerId = $this->userIdByEmail('designer@illuminations.test');

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
