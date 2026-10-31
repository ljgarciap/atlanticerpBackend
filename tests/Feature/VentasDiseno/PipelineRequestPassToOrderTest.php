<?php

declare(strict_types=1);

namespace Tests\Feature\VentasDiseno;

use App\Modules\Bodega\Models\Order;
use App\Modules\Notifications\Models\NotificationSend;
use App\Modules\VentasDiseno\Models\MasterClient;
use App\Modules\VentasDiseno\Models\PipelineCard;
use App\Modules\VentasDiseno\Models\SalesProject;
use App\Modules\VentasDiseno\Models\SubClient;
use App\Shared\Multitenancy\Tenant;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\FeatureTestCase;

/** Fase 16 (5.3) — el vendedor pide que Gerencia pase a Pedido un proyecto aprobado. */
class PipelineRequestPassToOrderTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant();
    }

    private function tenant(): void
    {
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    private function userIdByEmail(string $email): int
    {
        return (int) DB::table('atlantic_auth.users')->where('email', $email)->value('id');
    }

    private function makeCard(string $stage = 'approved'): PipelineCard
    {
        $master = MasterClient::create(['name' => 'Cliente Test']);
        $sub = SubClient::create(['master_client_id' => $master->id, 'business_name' => 'Subcliente Test', 'tax_id' => '155-0000-1-2026']);
        $project = SalesProject::create(['sub_client_id' => $sub->id, 'name' => 'Proyecto Test']);

        return PipelineCard::create([
            'sales_project_id' => $project->id, 'stage' => $stage, 'master_client_id' => $master->id,
            'sub_client_id' => $sub->id, 'owner_id' => $this->userIdByEmail('vendedor@atlantic.test'), 'stage_changed_at' => Carbon::now(),
        ]);
    }

    /** @return array<string, string> */
    private function headersFor(string $email, string $password, string $role): array
    {
        $headers = $this->authHeaders($this->loginAs($email, $password, $role));
        $this->tenant();

        return $headers;
    }

    public function test_el_vendedor_dueno_solicita_el_pase_y_gerencia_lo_ve_y_es_avisada(): void
    {
        $card = $this->makeCard();
        $vendedor = $this->headersFor('vendedor@atlantic.test', 'Password123!', 'vendedor');

        $this->withHeaders($vendedor)->getJson("/api/ventas-diseno/pipeline/{$card->id}")
            ->assertOk()->assertJsonPath('can_request_pass_to_order', true)->assertJsonPath('can_pass_to_order', false);

        $this->withHeaders($vendedor)->postJson("/api/ventas-diseno/pipeline/{$card->id}/request-pass-to-order", ['note' => 'El cliente ya firmó'])
            ->assertOk()
            ->assertJsonPath('order_status', 'pending')
            ->assertJsonPath('can_request_pass_to_order', false)
            ->assertJsonPath('order_request.note', 'El cliente ya firmó')
            ->assertJsonPath('order_request.requested_by.id', $this->userIdByEmail('vendedor@atlantic.test'));

        $this->tenant();
        $this->assertSame(0, Order::where('pipeline_card_id', $card->id)->count(), 'solicitar no crea el pedido');
        $aviso = NotificationSend::where('source', NotificationSend::SOURCE_PIPELINE_ORDER_REQUEST)->where('source_ref', "pipeline_card:{$card->id}")->first();
        $this->assertNotNull($aviso);
        $this->assertContains($this->userIdByEmail('management@atlantic.test'), $aviso->audience);

        $gerencia = $this->headersFor('management@atlantic.test', 'Password123!', 'management');
        $this->withHeaders($gerencia)->getJson("/api/ventas-diseno/pipeline/{$card->id}")
            ->assertOk()->assertJsonPath('order_request.note', 'El cliente ya firmó')->assertJsonPath('can_pass_to_order', true);

        // Al pasar a pedido la solicitud deja de mostrarse.
        $this->withHeaders($gerencia)->postJson("/api/ventas-diseno/pipeline/{$card->id}/pass-to-order")->assertOk()->assertJsonPath('order_request', null);
    }

    public function test_una_segunda_solicitud_sobre_la_misma_tarjeta_se_rechaza(): void
    {
        $card = $this->makeCard();
        $vendedor = $this->headersFor('vendedor@atlantic.test', 'Password123!', 'vendedor');

        $this->withHeaders($vendedor)->postJson("/api/ventas-diseno/pipeline/{$card->id}/request-pass-to-order")->assertOk();
        $this->withHeaders($vendedor)->postJson("/api/ventas-diseno/pipeline/{$card->id}/request-pass-to-order")->assertStatus(422);
    }

    public function test_solo_se_solicita_sobre_un_proyecto_aprobado_y_sin_pedido(): void
    {
        $vendedor = $this->headersFor('vendedor@atlantic.test', 'Password123!', 'vendedor');
        $this->tenant();
        $enPropuesta = $this->makeCard('proposal');
        $this->withHeaders($vendedor)->postJson("/api/ventas-diseno/pipeline/{$enPropuesta->id}/request-pass-to-order")->assertStatus(422);

        $gerencia = $this->headersFor('management@atlantic.test', 'Password123!', 'management');
        $this->tenant();
        $conPedido = $this->makeCard();
        $this->withHeaders($gerencia)->postJson("/api/ventas-diseno/pipeline/{$conPedido->id}/pass-to-order")->assertOk();
        $this->withHeaders($vendedor)->postJson("/api/ventas-diseno/pipeline/{$conPedido->id}/request-pass-to-order")->assertStatus(409);
    }

    public function test_quien_ya_puede_pasar_a_pedido_no_necesita_solicitarlo(): void
    {
        $card = $this->makeCard();
        $gerencia = $this->headersFor('management@atlantic.test', 'Password123!', 'management');

        $this->withHeaders($gerencia)->getJson("/api/ventas-diseno/pipeline/{$card->id}")->assertJsonPath('can_request_pass_to_order', false);
        $this->withHeaders($gerencia)->postJson("/api/ventas-diseno/pipeline/{$card->id}/request-pass-to-order")->assertStatus(422);
    }

    public function test_tarjeta_inexistente_da_404(): void
    {
        $vendedor = $this->headersFor('vendedor@atlantic.test', 'Password123!', 'vendedor');
        $this->withHeaders($vendedor)->postJson('/api/ventas-diseno/pipeline/999999/request-pass-to-order')->assertStatus(404);
    }
}
