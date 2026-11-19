<?php

declare(strict_types=1);

namespace Tests\Feature\Bodega;

use App\Modules\Bodega\Models\Order;
use App\Shared\Catalogs\CatalogEntry;
use App\Shared\Catalogs\CatalogService;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Bodega\Concerns\BodegaOrderTestHelpers;
use Tests\Feature\FeatureTestCase;

/**
 * Fase 34a (docs/specs/fase34-vocabularios-por-empresa.md) — los motivos de faltante al entregar son un catálogo por empresa
 * (`shortage_reason`): se siembran los de siempre, `sin_stock` y `otra` son de sistema, y una entrada propia vale al registrar la entrega.
 */
final class ShortageReasonCatalogTest extends FeatureTestCase
{
    use BodegaOrderTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeCurrentTenant();
        Storage::fake('s3-private');
    }

    public function test_se_siembran_los_motivos_de_siempre_y_solo_dos_son_de_sistema(): void
    {
        $this->assertSame(
            ['sin_stock', 'no_encontrado', 'danado', 'reservado_otro_pedido', 'referencia_equivocada', 'otra'],
            CatalogService::keys('shortage_reason'),
        );
        $sistema = CatalogEntry::query()->where('domain', 'shortage_reason')->where('is_system', true)->orderBy('sort_order')->pluck('key')->all();
        $this->assertSame(['sin_stock', 'otra'], $sistema);
    }

    public function test_una_entrada_propia_se_acepta_al_entregar_y_una_inexistente_o_inactiva_se_rechaza(): void
    {
        $this->grantBodegaPermissions('liderbodega@test.com');
        $token = $this->loginAs('liderbodega@test.com', 'liderbodega@test.com', 'lider_bodega');
        $h = $this->authHeaders($token);
        $super = $this->authHeaders($this->loginAs('superadmin@atlantic.test', 'Password123!'));
        $this->withHeaders($super)->postJson('/api/admin/catalogs/shortage_reason', ['key' => 'retraso_proveedor', 'label' => 'Retraso del proveedor'])->assertCreated();

        $this->makeCurrentTenant();
        $order = $this->makeOrder(['stage' => Order::STAGE_PACKING], ['qty_requested' => 5, 'qty_picked' => 5]);
        $item = $order->items->first();
        $payload = fn (string $motivo) => ['items' => [['order_item_id' => $item->id, 'qty_delivered' => 3, 'motivo' => $motivo]]];

        $this->withHeaders($h)->postJson("/api/bodega/orders/{$order->id}/register-delivery", $payload('inexistente'))
            ->assertStatus(422)->assertJsonValidationErrors(['items.0.motivo']);

        $this->withHeaders($h)->postJson("/api/bodega/orders/{$order->id}/register-delivery", $payload('retraso_proveedor'))->assertOk();
        $this->makeCurrentTenant();
        $this->assertSame('retraso_proveedor', $item->fresh()->shortage_reason);
    }

    public function test_las_entradas_de_sistema_no_se_borran_y_una_propia_en_uso_tampoco(): void
    {
        $super = $this->authHeaders($this->loginAs('superadmin@atlantic.test', 'Password123!'));
        $this->makeCurrentTenant();
        $id = (int) CatalogEntry::query()->where('domain', 'shortage_reason')->where('key', 'sin_stock')->value('id');
        $this->withHeaders($super)->deleteJson("/api/admin/catalogs/shortage_reason/{$id}")->assertStatus(422);
    }
}
