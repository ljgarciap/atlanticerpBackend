<?php

declare(strict_types=1);

namespace Tests\Feature\Bodega;

use App\Modules\Bodega\Models\Order;
use App\Modules\Bodega\Models\OrderDocument;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Bodega\Concerns\BodegaOrderTestHelpers;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-791 (Epic SCRUM-788 — Logs y Telemetría). OrderDeliveryController::downloadDocument no
 * tenía ningún test HTTP existente que lo ejercitara — el resto de las acciones nuevas de este
 * batch (picking-sheet/items export) ya estaban cubiertas por OrderExportControllerTest.
 */
class AuditActionsScrum791Test extends FeatureTestCase
{
    use BodegaOrderTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeCurrentTenant();
        Storage::fake('s3-private');
    }

    public function test_descargar_documento_de_entrega_logea_accion(): void
    {
        $order = $this->makeOrder(['stage' => Order::STAGE_PACKING], ['qty_requested' => 5, 'qty_picked' => 5]);
        $item = $order->items->first();
        $this->grantBodegaPermissions('liderbodega@test.com');
        $token = $this->loginAs('liderbodega@test.com', 'liderbodega@test.com', 'lider_bodega');

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/bodega/orders/{$order->id}/register-delivery", [
                'items' => [['order_item_id' => $item->id, 'qty_delivered' => 5]],
            ])
            ->assertStatus(200);

        $this->makeCurrentTenant();
        $document = OrderDocument::where('order_id', $order->id)
            ->where('tipo', OrderDocument::TIPO_GUIA_ENTREGA)
            ->firstOrFail();

        $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/bodega/orders/{$order->id}/documents/{$document->id}/download")
            ->assertStatus(200)
            ->assertJsonStructure(['url']);

        $log = DB::table('atlantic_auth.audit_logs')
            ->where('action', 'bodega.order_document.downloaded')
            ->where('entity_id', (string) $document->id)
            ->exists();

        $this->assertTrue($log, 'Se esperaba bodega.order_document.downloaded en audit_logs.');
    }
}
