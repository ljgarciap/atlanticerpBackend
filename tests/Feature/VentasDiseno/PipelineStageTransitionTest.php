<?php

declare(strict_types=1);

namespace Tests\Feature\VentasDiseno;

use App\Modules\VentasDiseno\Models\MasterClient;
use App\Modules\VentasDiseno\Models\PipelineCard;
use App\Modules\VentasDiseno\Models\SalesProject;
use App\Modules\VentasDiseno\Models\SubClient;
use App\Shared\Multitenancy\Tenant;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\FeatureTestCase;

class PipelineStageTransitionTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    /** @param list<string> $extra */
    private function grantVentasDisenoEdit(string $email, array $extra = []): void
    {
        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');
        $permissions = array_merge(['ventas_diseno.read', 'ventas_diseno.write', 'ventas_diseno.edit'], $extra);
        DB::table("{$slug}_auth.users")
            ->where('email', $email)
            ->update(['extra_permissions' => json_encode($permissions)]);
    }

    private function userIdByEmail(string $email): int
    {
        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');
        return (int) DB::table("{$slug}_auth.users")->where('email', $email)->value('id');
    }

    private function makeCard(int $ownerId, string $stage = 'lead'): PipelineCard
    {
        $master = MasterClient::create(['name' => 'Cliente Test']);
        $sub    = SubClient::create(['master_client_id' => $master->id, 'business_name' => 'Subcliente Test', 'tax_id' => '155-0000-1-2026']);
        $project = SalesProject::create(['sub_client_id' => $sub->id, 'name' => 'Proyecto Test']);

        return PipelineCard::create([
            'sales_project_id' => $project->id, 'stage' => $stage, 'master_client_id' => $master->id,
            'sub_client_id' => $sub->id, 'owner_id' => $ownerId, 'stage_changed_at' => Carbon::now(),
        ]);
    }

    // ── REQ-011: Lead → otra etapa requiere contacto + Cliente Master ──────────

    public function test_mover_desde_lead_sin_contacto_retorna_422(): void
    {
        $this->grantVentasDisenoEdit('vendedor@atlantic.test');
        $designerId = $this->userIdByEmail('vendedor@atlantic.test');
        $card = $this->makeCard($designerId, 'lead');

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/ventas-diseno/pipeline/{$card->id}/stage", ['stage' => 'design'])
            ->assertStatus(422);
    }

    public function test_mover_desde_lead_a_perdido_no_requiere_contacto(): void
    {
        $this->grantVentasDisenoEdit('vendedor@atlantic.test');
        $designerId = $this->userIdByEmail('vendedor@atlantic.test');
        $card = $this->makeCard($designerId, 'lead');

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/ventas-diseno/pipeline/{$card->id}/stage", ['stage' => 'lost'])
            ->assertStatus(200)
            ->assertJsonPath('stage', 'lost');
    }

    public function test_mover_desde_lead_con_contacto_y_cliente_reinicia_dias_en_etapa(): void
    {
        $this->grantVentasDisenoEdit('vendedor@atlantic.test');
        $designerId = $this->userIdByEmail('vendedor@atlantic.test');
        $card = $this->makeCard($designerId, 'lead');
        $card->contacts()->create(['name' => 'Cliente', 'role' => 'client', 'phone' => '6000-0000']);

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/ventas-diseno/pipeline/{$card->id}/stage", ['stage' => 'design'])
            ->assertStatus(200);

        $response->assertJsonPath('stage', 'design');
        $response->assertJsonPath('days_in_stage', 0);
    }

    public function test_mover_desde_lead_sin_subcliente_retorna_422(): void
    {
        $this->grantVentasDisenoEdit('vendedor@atlantic.test');
        $designerId = $this->userIdByEmail('vendedor@atlantic.test');
        $card = $this->makeCard($designerId, 'lead');
        $card->contacts()->create(['name' => 'Cliente', 'role' => 'client', 'phone' => '6000-0000']);
        // Master vinculado, pero sin Subcliente — REQ-011 exige ambos.
        $card->update(['sub_client_id' => null]);

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/ventas-diseno/pipeline/{$card->id}/stage", ['stage' => 'design'])
            ->assertStatus(422);
    }

    public function test_no_se_puede_saltear_etapas(): void
    {
        $this->grantVentasDisenoEdit('vendedor@atlantic.test');
        $designerId = $this->userIdByEmail('vendedor@atlantic.test');
        $card = $this->makeCard($designerId, 'lead');
        $card->contacts()->create(['name' => 'Cliente', 'role' => 'client', 'phone' => '6000-0000']);

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        // Lead tiene contacto + Master + Subcliente (todo lo que pide su propio gate),
        // pero saltar directo a Aprobado se saltea los gates de Cotización/Propuesta.
        $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/ventas-diseno/pipeline/{$card->id}/stage", ['stage' => 'approved'])
            ->assertStatus(422);
    }

    // ── REQ-014: Diseño → Cotización requiere superficie + archivo de diseño ───

    public function test_mover_de_diseno_a_cotizacion_sin_superficie_retorna_422(): void
    {
        $this->grantVentasDisenoEdit('vendedor@atlantic.test');
        $designerId = $this->userIdByEmail('vendedor@atlantic.test');
        $card = $this->makeCard($designerId, 'design');

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/ventas-diseno/pipeline/{$card->id}/stage", ['stage' => 'quote'])
            ->assertStatus(422);
    }

    public function test_mover_de_diseno_a_cotizacion_sin_archivo_de_diseno_retorna_422(): void
    {
        $this->grantVentasDisenoEdit('vendedor@atlantic.test');
        $designerId = $this->userIdByEmail('vendedor@atlantic.test');
        $card = $this->makeCard($designerId, 'design');
        $card->update(['worked_area_m2' => 45.5]);

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/ventas-diseno/pipeline/{$card->id}/stage", ['stage' => 'quote'])
            ->assertStatus(422);
    }

    // RN5 (SCRUM-677/680) — el endpoint genérico de cambio de etapa nunca puede mover Diseño a
    // Cotización, ni con superficie y archivo de diseño ya cargados. La única vía es generar una
    // cotización real ("Crear cotización" → QuoteService::confirm() → advanceCardToQuoteStage()),
    // que a propósito no pasa por este gate (ver docblock de confirm()).
    public function test_mover_de_diseno_a_cotizacion_via_endpoint_generico_siempre_retorna_422(): void
    {
        $this->grantVentasDisenoEdit('vendedor@atlantic.test');
        $designerId = $this->userIdByEmail('vendedor@atlantic.test');
        $card = $this->makeCard($designerId, 'design');
        $card->update(['worked_area_m2' => 45.5]);
        $card->files()->create([
            'type' => 'design', 'file_name' => 'plano.pdf', 'storage_key' => 'x/y.pdf',
            'size_bytes' => 100, 'mime_type' => 'application/pdf', 'uploaded_by' => $designerId,
        ]);

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/ventas-diseno/pipeline/{$card->id}/stage", ['stage' => 'quote'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'La transición a Cotización solo ocurre generando una cotización real desde "Crear cotización".');
    }

    // ── REQ-016: Cotización → Propuesta requiere superficie + cotización firmada ─

    // SCRUM-849.3 — el check de cotización firmada se movió de esta transición a
    // Propuesta→Aprobado (ver abajo): ya no bloquea acá, solo exige la superficie trabajada.
    public function test_mover_de_cotizacion_a_propuesta_sin_cotizacion_firmada_ya_no_bloquea(): void
    {
        $this->grantVentasDisenoEdit('vendedor@atlantic.test');
        $designerId = $this->userIdByEmail('vendedor@atlantic.test');
        $card = $this->makeCard($designerId, 'quote');
        $card->update(['worked_area_m2' => 30]);

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/ventas-diseno/pipeline/{$card->id}/stage", ['stage' => 'proposal'])
            ->assertStatus(200)
            ->assertJsonPath('stage', 'proposal');
    }

    // ── REQ-017 / SCRUM-849.3: Propuesta → Aprobado requiere fecha de entrega +
    // cotización firmada + comprobante de aprobación ────────────────────────────

    public function test_mover_de_propuesta_a_aprobado_sin_fecha_de_entrega_retorna_422(): void
    {
        $this->grantVentasDisenoEdit('vendedor@atlantic.test');
        $designerId = $this->userIdByEmail('vendedor@atlantic.test');
        $card = $this->makeCard($designerId, 'proposal');
        $card->files()->create([
            'type' => 'approval_proof', 'file_name' => 'aprobacion.pdf', 'storage_key' => 'x/y.pdf',
            'size_bytes' => 100, 'mime_type' => 'application/pdf', 'uploaded_by' => $designerId,
        ]);

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/ventas-diseno/pipeline/{$card->id}/stage", ['stage' => 'approved'])
            ->assertStatus(422);
    }

    public function test_mover_de_propuesta_a_aprobado_sin_cotizacion_firmada_retorna_422(): void
    {
        $this->grantVentasDisenoEdit('vendedor@atlantic.test');
        $designerId = $this->userIdByEmail('vendedor@atlantic.test');
        $card = $this->makeCard($designerId, 'proposal');
        $card->deliveryDates()->create(['date' => '2026-12-01']);
        $card->files()->create([
            'type' => 'approval_proof', 'file_name' => 'aprobacion.pdf', 'storage_key' => 'x/y.pdf',
            'size_bytes' => 100, 'mime_type' => 'application/pdf', 'uploaded_by' => $designerId,
        ]);

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/ventas-diseno/pipeline/{$card->id}/stage", ['stage' => 'approved'])
            ->assertStatus(422);
    }

    public function test_mover_de_propuesta_a_aprobado_con_fecha_firma_y_comprobante_ok(): void
    {
        $this->grantVentasDisenoEdit('vendedor@atlantic.test');
        $designerId = $this->userIdByEmail('vendedor@atlantic.test');
        $card = $this->makeCard($designerId, 'proposal');
        $card->deliveryDates()->create(['date' => '2026-12-01']);
        $card->files()->create([
            'type' => 'signed_quote', 'file_name' => 'cotizacion.pdf', 'storage_key' => 'x/y.pdf',
            'size_bytes' => 100, 'mime_type' => 'application/pdf', 'uploaded_by' => $designerId,
        ]);
        $card->files()->create([
            'type' => 'approval_proof', 'file_name' => 'aprobacion.pdf', 'storage_key' => 'x/y.pdf',
            'size_bytes' => 100, 'mime_type' => 'application/pdf', 'uploaded_by' => $designerId,
        ]);

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/ventas-diseno/pipeline/{$card->id}/stage", ['stage' => 'approved'])
            ->assertStatus(200)
            ->assertJsonPath('stage', 'approved');
    }

    // ── REQ-018: reactivación de Perdido, único movimiento permitido ───────────

    public function test_reactivar_tarjeta_perdida_a_lead(): void
    {
        $this->grantVentasDisenoEdit('vendedor@atlantic.test');
        $designerId = $this->userIdByEmail('vendedor@atlantic.test');
        $card = $this->makeCard($designerId, 'lost');

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/ventas-diseno/pipeline/{$card->id}/stage", ['stage' => 'lead'])
            ->assertStatus(200)
            ->assertJsonPath('stage', 'lead');
    }

    public function test_tarjeta_perdida_no_puede_moverse_a_otra_etapa_que_no_sea_lead(): void
    {
        $this->grantVentasDisenoEdit('vendedor@atlantic.test');
        $designerId = $this->userIdByEmail('vendedor@atlantic.test');
        $card = $this->makeCard($designerId, 'lost');

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/ventas-diseno/pipeline/{$card->id}/stage", ['stage' => 'design'])
            ->assertStatus(422);
    }

    public function test_cualquier_etapa_activa_puede_marcarse_perdida_sin_gate(): void
    {
        $this->grantVentasDisenoEdit('vendedor@atlantic.test');
        $designerId = $this->userIdByEmail('vendedor@atlantic.test');
        $card = $this->makeCard($designerId, 'proposal');

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/ventas-diseno/pipeline/{$card->id}/stage", ['stage' => 'lost'])
            ->assertStatus(200);
    }

    // ── REQ-603 RN2 / REQ-597 RN3 (Epic CRM Batch B): Aprobado es terminal ──────
    // Hallazgo real: un usuario con RESTRICTED_EDIT_PERMISSION (ventas_diseno.edit.approved,
    // ej. Gerencia en producción) podía mover una tarjeta Aprobada a Perdido — isEditableBy() la
    // trata como editable para ese permiso, y el gate no excluía 'approved' como origen.

    public function test_tarjeta_aprobada_no_puede_marcarse_perdida_retorna_422(): void
    {
        $this->grantVentasDisenoEdit('vendedor@atlantic.test', ['ventas_diseno.edit.approved']);
        $designerId = $this->userIdByEmail('vendedor@atlantic.test');
        $card = $this->makeCard($designerId, 'approved');

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/ventas-diseno/pipeline/{$card->id}/stage", ['stage' => 'lost'])
            ->assertStatus(422)
            ->assertJson(['message' => 'Una tarjeta en Aprobado no puede marcarse como Perdido — es una etapa final.']);
    }
}
