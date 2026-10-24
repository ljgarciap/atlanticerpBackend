<?php

declare(strict_types=1);

namespace Tests\Feature\VentasDiseno;

use App\Modules\Bodega\Models\Order;
use App\Modules\VentasDiseno\Models\MasterClient;
use App\Modules\VentasDiseno\Models\PipelineCard;
use App\Modules\VentasDiseno\Models\SalesProject;
use App\Modules\VentasDiseno\Models\SubClient;
use App\Shared\Multitenancy\Tenant;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-1128 — aprobación explícita "Pasar a Pedido": el pedido en Bodega ya NO nace al aprobar la
 * cotización en Pipeline, solo cuando Gerencia o Contabilidad confirman. Cubre el camino feliz, los
 * negativos de permiso (Vendedor/Diseñador por ruta directa), la idempotencia, la trazabilidad
 * (quién/cuándo), el filtro `orders` y el acceso de SOLO LECTURA de Contabilidad.
 */
class PipelinePassToOrderTest extends FeatureTestCase
{
    private const FELIX = 'contabilidad@test.com';

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
        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');

        return (int) DB::table("{$slug}_auth.users")->where('email', $email)->value('id');
    }

    private function makeCard(int $ownerId, string $stage = 'approved'): PipelineCard
    {
        $master  = MasterClient::create(['name' => 'Cliente Test']);
        $sub     = SubClient::create(['master_client_id' => $master->id, 'business_name' => 'Subcliente Test', 'tax_id' => '155-0000-1-2026']);
        $project = SalesProject::create(['sub_client_id' => $sub->id, 'name' => 'Proyecto Test']);

        return PipelineCard::create([
            'sales_project_id' => $project->id, 'stage' => $stage, 'master_client_id' => $master->id,
            'sub_client_id' => $sub->id, 'owner_id' => $ownerId, 'stage_changed_at' => Carbon::now(),
        ]);
    }

    /** @return array<string, string> */
    private function headersFor(string $email, string $password, string $role): array
    {
        $headers = $this->authHeaders($this->loginAs($email, $password, $role));
        $this->tenant();

        return $headers;
    }

    private function sellerId(): int
    {
        return $this->userIdByEmail('designer@atlantic.test');
    }

    public function test_gerencia_pasa_a_pedido_genera_el_pedido_y_registra_quien_y_cuando(): void
    {
        $card    = $this->makeCard($this->sellerId());
        $headers = $this->headersFor('management@atlantic.test', 'Password123!', 'management');

        $this->withHeaders($headers)->getJson("/api/ventas-diseno/pipeline/{$card->id}")
            ->assertOk()
            ->assertJsonPath('order_status', 'pending')
            ->assertJsonPath('can_pass_to_order', true)
            ->assertJsonPath('order_approved_by', null);

        $this->withHeaders($headers)->postJson("/api/ventas-diseno/pipeline/{$card->id}/pass-to-order")
            ->assertOk()
            ->assertJsonPath('order_status', 'approved')
            ->assertJsonPath('can_pass_to_order', false)
            ->assertJsonPath('order_approved_by.id', $this->userIdByEmail('management@atlantic.test'))
            ->assertJsonStructure(['order_approved_at']);

        $this->tenant();
        $order = Order::where('pipeline_card_id', $card->id)->first();
        $this->assertNotNull($order);
        $this->assertSame(Order::STAGE_ASIGNADO, $order->stage);

        // Persiste al volver a consultar.
        $this->withHeaders($headers)->getJson("/api/ventas-diseno/pipeline/{$card->id}")
            ->assertOk()
            ->assertJsonPath('order_status', 'approved')
            ->assertJsonPath('order_approved_by.id', $this->userIdByEmail('management@atlantic.test'));
    }

    public function test_contabilidad_puede_pasar_a_pedido_un_proyecto_ajeno(): void
    {
        $card    = $this->makeCard($this->sellerId());
        $headers = $this->headersFor(self::FELIX, self::FELIX, 'lider_admin_contab');

        $this->withHeaders($headers)->postJson("/api/ventas-diseno/pipeline/{$card->id}/pass-to-order")
            ->assertOk()
            ->assertJsonPath('order_approved_by.id', $this->userIdByEmail(self::FELIX));

        $this->tenant();
        $this->assertSame(1, Order::where('pipeline_card_id', $card->id)->count());
    }

    public function test_vendedor_disenador_dueno_del_proyecto_recibe_403_por_ruta_directa(): void
    {
        $card    = $this->makeCard($this->sellerId());
        $headers = $this->headersFor('designer@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($headers)->postJson("/api/ventas-diseno/pipeline/{$card->id}/pass-to-order")
            ->assertStatus(403);

        $this->tenant();
        $this->assertSame(0, Order::where('pipeline_card_id', $card->id)->count());
        $this->assertNull(PipelineCard::find($card->id)->order_approved_at);
    }

    public function test_aun_con_permiso_de_editar_aprobado_un_vendedor_no_puede_pasar_a_pedido(): void
    {
        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');
        DB::table("{$slug}_auth.users")->where('email', 'designer@atlantic.test')->update(['extra_permissions' => json_encode([
            'ventas_diseno.read', 'ventas_diseno.write', 'ventas_diseno.edit', 'ventas_diseno.edit.approved',
        ])]);
        $card    = $this->makeCard($this->sellerId());
        $headers = $this->headersFor('designer@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($headers)->postJson("/api/ventas-diseno/pipeline/{$card->id}/pass-to-order")
            ->assertStatus(403);
        $this->withHeaders($headers)->getJson("/api/ventas-diseno/pipeline/{$card->id}")
            ->assertOk()
            ->assertJsonPath('can_pass_to_order', false);
    }

    public function test_solo_un_proyecto_aprobado_puede_pasar_a_pedido(): void
    {
        $headers = $this->headersFor('management@atlantic.test', 'Password123!', 'management');

        foreach (['lead', 'design', 'quote', 'proposal', 'lost'] as $stage) {
            $card = $this->makeCard($this->sellerId(), $stage);
            $this->withHeaders($headers)->postJson("/api/ventas-diseno/pipeline/{$card->id}/pass-to-order")
                ->assertStatus(422);
            $this->tenant();
            $this->assertSame(0, Order::where('pipeline_card_id', $card->id)->count(), $stage);
        }
    }

    public function test_tarjeta_inexistente_da_404(): void
    {
        $headers = $this->headersFor('management@atlantic.test', 'Password123!', 'management');

        $this->withHeaders($headers)->postJson('/api/ventas-diseno/pipeline/99999999/pass-to-order')->assertStatus(404);
    }

    public function test_segundo_intento_responde_409_sin_duplicar_el_pedido_ni_pisar_la_aprobacion(): void
    {
        $card    = $this->makeCard($this->sellerId());
        $headers = $this->headersFor('management@atlantic.test', 'Password123!', 'management');

        $this->withHeaders($headers)->postJson("/api/ventas-diseno/pipeline/{$card->id}/pass-to-order")->assertOk();
        $this->tenant();
        $firstAt = PipelineCard::find($card->id)->order_approved_at;

        // Otra persona (Contabilidad) repite la acción: no debe pisar quién aprobó ni crear otro pedido.
        $felixHeaders = $this->headersFor(self::FELIX, self::FELIX, 'lider_admin_contab');
        $this->withHeaders($felixHeaders)->postJson("/api/ventas-diseno/pipeline/{$card->id}/pass-to-order")
            ->assertStatus(409)
            ->assertJsonPath('data.order_approved_by.id', $this->userIdByEmail('management@atlantic.test'));

        $this->tenant();
        $this->assertSame(1, Order::where('pipeline_card_id', $card->id)->count());
        $this->assertEquals($firstAt, PipelineCard::find($card->id)->order_approved_at);
    }

    public function test_si_bodega_falla_responde_500_claro_hace_rollback_y_la_tarjeta_se_puede_reintentar(): void
    {
        $card    = $this->makeCard($this->sellerId());
        $headers = $this->headersFor('management@atlantic.test', 'Password123!', 'management');

        // OrderService es final (no mockeable): se fuerza el fallo real de Bodega al insertar el Order.
        Order::creating(static function (): void {
            throw new \RuntimeException('detalle interno secreto');
        });

        $this->withHeaders($headers)->postJson("/api/ventas-diseno/pipeline/{$card->id}/pass-to-order")
            ->assertStatus(500)
            ->assertJsonMissing(['message' => 'detalle interno secreto'])
            ->assertJsonPath('message', 'No se pudo generar el pedido en Bodega. La aprobación no se registró; intenta de nuevo.');

        // Rollback: ni aprobación registrada ni pedido; sigue "por aprobar".
        $this->tenant();
        $fresh = PipelineCard::find($card->id);
        $this->assertNull($fresh->order_approved_at);
        $this->assertNull($fresh->order_approved_by);
        $this->assertSame(0, Order::where('pipeline_card_id', $card->id)->count());

        // Reintento con Bodega sana: funciona.
        Order::flushEventListeners();
        Order::clearBootedModels(); // re-registra los listeners reales del modelo (audit trail, etc.)
        $this->withHeaders($headers)->postJson("/api/ventas-diseno/pipeline/{$card->id}/pass-to-order")
            ->assertOk()
            ->assertJsonPath('order_status', 'approved');

        $this->tenant();
        $this->assertSame(1, Order::where('pipeline_card_id', $card->id)->count());
    }

    public function test_cambiar_a_aprobado_en_pipeline_no_genera_pedido_y_queda_por_aprobar(): void
    {
        $card = $this->makeCard($this->sellerId(), 'proposal');
        $card->contacts()->create(['name' => 'C', 'role' => 'client', 'phone' => '6000-1234']);
        $card->deliveryDates()->create(['date' => '2026-12-01']);
        foreach (['signed_quote', 'approval_proof'] as $type) {
            $card->files()->create([
                'type' => $type, 'file_name' => 'x.pdf', 'storage_key' => 'x/y.pdf',
                'size_bytes' => 100, 'mime_type' => 'application/pdf', 'uploaded_by' => $this->sellerId(),
            ]);
        }
        $headers = $this->headersFor('management@atlantic.test', 'Password123!', 'management');

        $this->withHeaders($headers)->patchJson("/api/ventas-diseno/pipeline/{$card->id}/stage", ['stage' => 'approved'])
            ->assertOk()
            ->assertJsonPath('order_status', 'pending');

        $this->tenant();
        $this->assertSame(0, Order::where('pipeline_card_id', $card->id)->count());

        $ids = collect($this->withHeaders($headers)->getJson('/api/ventas-diseno/pipeline?scope=team&orders=pending')->assertOk()->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($card->id));
    }

    public function test_filtro_orders_separa_aprobados_y_por_aprobar_segun_el_pedido_real(): void
    {
        $pending  = $this->makeCard($this->sellerId());
        $done     = $this->makeCard($this->sellerId());
        $inLead   = $this->makeCard($this->sellerId(), 'lead');
        $headers  = $this->headersFor('management@atlantic.test', 'Password123!', 'management');

        $this->withHeaders($headers)->postJson("/api/ventas-diseno/pipeline/{$done->id}/pass-to-order")->assertOk();

        $ids = fn (string $filter): array => collect(
            $this->withHeaders($headers)->getJson("/api/ventas-diseno/pipeline?scope=team&orders={$filter}")->assertOk()->json('data')
        )->pluck('id')->all();

        $this->assertSame([$pending->id], $ids('pending'));
        $this->assertSame([$done->id], $ids('approved'));

        // Sin filtro vuelve todo, y un valor inválido se ignora (no rompe el listado).
        $all = collect($this->withHeaders($headers)->getJson('/api/ventas-diseno/pipeline?scope=team&orders=cualquiera')->assertOk()->json('data'))->pluck('id');
        $this->assertTrue($all->contains($inLead->id));
    }

    public function test_filtro_orders_se_combina_con_los_otros_filtros(): void
    {
        $a = $this->makeCard($this->sellerId());
        $b = $this->makeCard($this->sellerId());
        $a->salesProject->update(['name' => 'Torre Alfa']);
        $b->salesProject->update(['name' => 'Edificio Beta']);
        $headers = $this->headersFor('management@atlantic.test', 'Password123!', 'management');

        $ids = collect($this->withHeaders($headers)->getJson('/api/ventas-diseno/pipeline?scope=team&orders=pending&search=Alfa')->assertOk()->json('data'))->pluck('id')->all();

        $this->assertSame([$a->id], $ids);
    }

    public function test_contabilidad_ve_el_pipeline_de_todo_el_equipo_y_el_detalle_en_solo_lectura(): void
    {
        $this->felixComoEnScrum1139();
        // SCRUM-1139 — Contabilidad hoy SÍ edita el Pipeline (`pipeline.edit`); este test protege la lectura SOLA (`pipeline.read_all`),
        // así que se le quita el permiso de escritura para reproducir ese perfil.
        $contabilidad = \App\Models\User::where('email', self::FELIX)->firstOrFail();
        $contabilidad->update(['extra_permissions' => array_values(array_diff($contabilidad->extra_permissions ?? [], ['ventas_diseno.pipeline.edit']))]);

        $card    = $this->makeCard($this->sellerId());
        $headers = $this->headersFor(self::FELIX, self::FELIX, 'lider_admin_contab');

        $ids = collect($this->withHeaders($headers)->getJson('/api/ventas-diseno/pipeline?scope=team')->assertOk()->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($card->id));

        $this->withHeaders($headers)->getJson("/api/ventas-diseno/pipeline/{$card->id}")
            ->assertOk()
            ->assertJsonPath('can_edit', false)
            ->assertJsonPath('can_pass_to_order', true);
    }

    public function test_contabilidad_no_puede_escribir_nada_en_el_pipeline(): void
    {
        $this->felixComoEnScrum1139();
        // SCRUM-1139 — Contabilidad hoy SÍ edita el Pipeline (`pipeline.edit`); este test protege la lectura SOLA (`pipeline.read_all`),
        // así que se le quita el permiso de escritura para reproducir ese perfil.
        $contabilidad = \App\Models\User::where('email', self::FELIX)->firstOrFail();
        $contabilidad->update(['extra_permissions' => array_values(array_diff($contabilidad->extra_permissions ?? [], ['ventas_diseno.pipeline.edit']))]);

        $card    = $this->makeCard($this->sellerId(), 'proposal');
        $headers = $this->headersFor(self::FELIX, self::FELIX, 'lider_admin_contab');

        $this->withHeaders($headers)->patchJson("/api/ventas-diseno/pipeline/{$card->id}/stage", ['stage' => 'lost'])->assertStatus(403);
        $this->withHeaders($headers)->putJson("/api/ventas-diseno/pipeline/{$card->id}", ['name' => 'Hackeado'])->assertStatus(403);
        $this->withHeaders($headers)->postJson('/api/ventas-diseno/pipeline', ['type' => 'lead', 'name' => 'Nuevo'])->assertStatus(403);
        $this->withHeaders($headers)->deleteJson("/api/ventas-diseno/pipeline/{$card->id}/files/1")->assertStatus(403);

        $this->tenant();
        $this->assertSame('proposal', PipelineCard::find($card->id)->stage);
    }

    public function test_el_permiso_de_lectura_total_de_pipeline_no_abre_el_resto_de_ventas_y_diseno(): void
    {
        $this->felixComoEnScrum1139();
        $headers = $this->headersFor(self::FELIX, self::FELIX, 'lider_admin_contab');

        $this->withHeaders($headers)->getJson('/api/ventas-diseno/projects')->assertStatus(403);
    }

    public function test_vendedor_sigue_viendo_solo_lo_propio_en_el_pipeline(): void
    {
        $other = $this->makeCard($this->userIdByEmail('management@atlantic.test'));
        $own   = $this->makeCard($this->sellerId());
        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');
        DB::table("{$slug}_auth.users")->where('email', 'designer@atlantic.test')->update(['extra_permissions' => json_encode(['ventas_diseno.read'])]);
        $headers = $this->headersFor('designer@atlantic.test', 'Password123!', 'designer');

        $ids = collect($this->withHeaders($headers)->getJson('/api/ventas-diseno/pipeline?scope=team')->assertOk()->json('data'))->pluck('id');

        $this->assertTrue($ids->contains($own->id));
        $this->assertFalse($ids->contains($other->id));
        $this->withHeaders($headers)->getJson("/api/ventas-diseno/pipeline/{$other->id}")->assertStatus(403);
    }
}
