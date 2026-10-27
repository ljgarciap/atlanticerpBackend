<?php

declare(strict_types=1);

namespace Tests\Feature\VentasDiseno;

use App\Modules\VentasDiseno\Events\QuoteConfirmed;
use App\Modules\VentasDiseno\Models\Architect;
use App\Modules\VentasDiseno\Models\MasterClient;
use App\Modules\VentasDiseno\Models\PipelineCard;
use App\Modules\VentasDiseno\Models\Quote;
use App\Modules\VentasDiseno\Models\SalesProject;
use App\Modules\VentasDiseno\Models\SubClient;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-1115 — un proyecto restaurado desde Perdido (Lead/Diseño) con cotizaciones confirmadas debe poder partir de
 * ellas ("Usar como base para nueva versión"), no solo de una cotización en blanco.
 *
 * Reproduce el paso a paso del ticket: cotización confirmada (la tarjeta pasa a Cotización) → Perdido → Lead → Diseño. Antes del
 * arreglo `duplicate` respondía 422 "solo mientras el proyecto está en etapa Cotización" y a Cotización solo se llegaba con una
 * cotización nueva en blanco (RN5 de SCRUM-677/680, que se mantiene: el PATCH genérico a `quote` sigue bloqueado).
 */
class QuoteNewVersionFromDesignTest extends FeatureTestCase
{
    private const DESIGNER = 'designer@atlantic.test';

    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    private function tenant(): void
    {
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    private function grant(string $email, array $permissions): void
    {
        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');
        DB::table("{$slug}_auth.users")->where('email', $email)->update(['extra_permissions' => json_encode($permissions)]);
    }

    private function token(): string
    {
        $this->grant(self::DESIGNER, ['ventas_diseno.read', 'ventas_diseno.write', 'ventas_diseno.edit']);

        return $this->loginAs(self::DESIGNER, 'Password123!', 'designer');
    }

    /** @return array<string, string> */
    private function h(string $token): array
    {
        return [...$this->authHeaders($token), 'Accept' => 'application/json'];
    }

    /** Proyecto con una cotización confirmada de 2 × 100 (la tarjeta nace en `quote`). @return array{quote: Quote, project: SalesProject, card: PipelineCard} */
    private function projectWithConfirmedQuote(string $token): array
    {
        $this->tenant();
        $slug    = (string) Tenant::where('slug', 'atlantic')->value('slug');
        $ownerId = (int) DB::table("{$slug}_auth.users")->where('email', self::DESIGNER)->value('id');

        $master  = MasterClient::create(['name' => 'Patricia Bellos']);
        $sub     = SubClient::create(['master_client_id' => $master->id, 'business_name' => 'Patricia Bellos', 'tax_id' => '155-0000-1-2026']);
        $project = SalesProject::create(['sub_client_id' => $sub->id, 'name' => 'Proyecto Marina']);
        $arch    = Architect::create(['name' => 'Arq. Test', 'phone' => '6000-0000']);
        $contact = $sub->contacts()->create(['name' => 'Contacto', 'role' => 'client', 'phone' => '6111-1111']);

        $quote = Quote::create([
            'owner_id' => $ownerId, 'master_client_id' => $master->id, 'sub_client_id' => $sub->id, 'sales_project_id' => $project->id,
            'ruc' => '155-0000-1-2026', 'description' => 'COT original', 'architect_id' => $arch->id,
        ]);
        $quote->deliveryDates()->create(['date' => '2026-09-01']);
        $quote->contacts()->attach($contact->id);
        $part = $quote->parts()->create(['name' => 'Partida 1']);
        $part->items()->create(['reference' => 'REF-1', 'description' => 'Producto', 'quantity' => 2, 'unit_price' => 100, 'discount_percent' => 5]);

        $this->withHeaders($this->h($token))->postJson("/api/ventas-diseno/quotes/{$quote->id}/generate")->assertStatus(200);
        $this->withHeaders($this->h($token))->postJson("/api/ventas-diseno/quotes/{$quote->id}/confirm")->assertStatus(200);

        $this->tenant();
        $card = PipelineCard::where('sales_project_id', $project->id)->firstOrFail();
        $card->contacts()->create(['name' => 'Contacto', 'role' => 'client', 'phone' => '6111-1111']);

        return ['quote' => $quote->fresh() ?? $quote, 'project' => $project, 'card' => $card];
    }

    private function moveTo(string $token, PipelineCard $card, string ...$stages): void
    {
        foreach ($stages as $stage) {
            $this->withHeaders($this->h($token))->patchJson("/api/ventas-diseno/pipeline/{$card->id}/stage", ['stage' => $stage])->assertStatus(200);
        }
    }

    private function stageOf(PipelineCard $card): string
    {
        $this->tenant();

        return (string) PipelineCard::findOrFail($card->id)->stage;
    }

    // ── A1 / A3: el paso a paso del ticket ─────────────────────────────────────────────────────────────────────

    public function test_a1_restaurado_a_diseno_se_puede_crear_version_nueva_con_todos_los_datos_de_la_original(): void
    {
        $token = $this->token();
        ['quote' => $quote, 'card' => $card] = $this->projectWithConfirmedQuote($token);
        $this->moveTo($token, $card, 'lost', 'lead', 'design');

        $r = $this->withHeaders($this->h($token))->postJson("/api/ventas-diseno/quotes/{$quote->id}/duplicate")->assertStatus(201);

        $this->assertNotSame($quote->id, $r->json('id'));
        $this->assertNull($r->json('folio'));
        $this->assertSame('draft', $r->json('document_status'));
        $this->assertSame($quote->sales_project_id, $r->json('sales_project.id'));
        $this->assertCount(1, $r->json('parts'));
        $this->assertEquals(2.0, $r->json('parts.0.items.0.quantity'));
        $this->assertEquals(100.0, $r->json('parts.0.items.0.unit_price'));
        $this->assertEquals(5.0, $r->json('parts.0.items.0.discount_percent'));
        $this->assertSame('design', $this->stageOf($card), 'duplicar no mueve la tarjeta; solo confirmar lo hace');
    }

    public function test_a3_restaurado_a_lead_tambien_se_puede_crear_version_nueva(): void
    {
        $token = $this->token();
        ['quote' => $quote, 'card' => $card] = $this->projectWithConfirmedQuote($token);
        $this->moveTo($token, $card, 'lost', 'lead');

        $this->withHeaders($this->h($token))->postJson("/api/ventas-diseno/quotes/{$quote->id}/duplicate")->assertStatus(201);
    }

    // ── A2: confirmar la versión nueva devuelve la tarjeta a Cotización ───────────────────────────────────────

    public function test_a2_confirmar_la_version_nueva_mueve_la_tarjeta_a_cotizacion_y_queda_como_version_2(): void
    {
        $token = $this->token();
        ['quote' => $quote, 'card' => $card, 'project' => $project] = $this->projectWithConfirmedQuote($token);
        $this->moveTo($token, $card, 'lost', 'lead', 'design');

        $newId = (int) $this->withHeaders($this->h($token))->postJson("/api/ventas-diseno/quotes/{$quote->id}/duplicate")->assertStatus(201)->json('id');
        $this->withHeaders($this->h($token))->postJson("/api/ventas-diseno/quotes/{$newId}/generate")->assertStatus(200);
        $this->withHeaders($this->h($token))->postJson("/api/ventas-diseno/quotes/{$newId}/confirm")->assertStatus(200);

        $this->assertSame('quote', $this->stageOf($card));
        $versiones = $this->withHeaders($this->h($token))->getJson("/api/ventas-diseno/quotes/{$newId}/versions")->assertStatus(200);
        $this->assertCount(2, $versiones->json('versions'));
        $this->assertSame('quote', $versiones->json('card_stage'));
        $this->tenant();
        $this->assertSame(2, Quote::where('sales_project_id', $project->id)->whereNotNull('confirmed_at')->count());
    }

    public function test_a3_confirmar_la_version_nueva_desde_lead_mueve_la_tarjeta_a_cotizacion(): void
    {
        $token = $this->token();
        ['quote' => $quote, 'card' => $card] = $this->projectWithConfirmedQuote($token);
        $this->moveTo($token, $card, 'lost', 'lead');

        $newId = (int) $this->withHeaders($this->h($token))->postJson("/api/ventas-diseno/quotes/{$quote->id}/duplicate")->assertStatus(201)->json('id');
        $this->withHeaders($this->h($token))->postJson("/api/ventas-diseno/quotes/{$newId}/generate")->assertStatus(200);
        $this->withHeaders($this->h($token))->postJson("/api/ventas-diseno/quotes/{$newId}/confirm")->assertStatus(200);

        $this->assertSame('quote', $this->stageOf($card));
    }

    public function test_a2_confirmar_la_version_nueva_dispara_quote_confirmed_como_nueva_version(): void
    {
        $token = $this->token();
        ['quote' => $quote, 'card' => $card] = $this->projectWithConfirmedQuote($token);
        $this->moveTo($token, $card, 'lost', 'lead', 'design');
        $newId = (int) $this->withHeaders($this->h($token))->postJson("/api/ventas-diseno/quotes/{$quote->id}/duplicate")->assertStatus(201)->json('id');
        $this->withHeaders($this->h($token))->postJson("/api/ventas-diseno/quotes/{$newId}/generate")->assertStatus(200);

        Event::fake([QuoteConfirmed::class]);
        $this->withHeaders($this->h($token))->postJson("/api/ventas-diseno/quotes/{$newId}/confirm")->assertStatus(200);

        Event::assertDispatched(QuoteConfirmed::class, fn (QuoteConfirmed $e) => $e->quoteId === $newId && $e->isNewVersion === true);
    }

    public function test_sin_tarjeta_en_el_pipeline_no_se_puede_crear_version_y_dice_por_que(): void
    {
        $token = $this->token();
        ['quote' => $quote, 'card' => $card] = $this->projectWithConfirmedQuote($token);
        $this->tenant();
        PipelineCard::whereKey($card->id)->delete();

        $mensaje = (string) $this->withHeaders($this->h($token))->postJson("/api/ventas-diseno/quotes/{$quote->id}/duplicate")->assertStatus(422)->json('message');

        $this->assertStringContainsString('proyecto en el Pipeline', $mensaje);
    }

    // ── A4 / A5: lo que sigue bloqueado y su mensaje ──────────────────────────────────────────────────────────

    public function test_a4_a5_en_perdido_sigue_bloqueado_y_pide_reactivar(): void
    {
        $token = $this->token();
        ['quote' => $quote, 'card' => $card] = $this->projectWithConfirmedQuote($token);
        $this->moveTo($token, $card, 'lost');

        $mensaje = (string) $this->withHeaders($this->h($token))->postJson("/api/ventas-diseno/quotes/{$quote->id}/duplicate")->assertStatus(422)->json('message');

        $this->assertStringContainsString('Perdido', $mensaje);
        $this->assertStringContainsString('reactívelo a Lead', $mensaje);
    }

    public function test_a4_a5_en_propuesta_y_aprobado_sigue_bloqueado_y_dice_que_esta_congelada(): void
    {
        $token = $this->token();
        ['quote' => $quote, 'card' => $card] = $this->projectWithConfirmedQuote($token);

        foreach (['proposal', 'approved'] as $stage) {
            $this->tenant();
            PipelineCard::whereKey($card->id)->update(['stage' => $stage]);

            $mensaje = (string) $this->withHeaders($this->h($token))->postJson("/api/ventas-diseno/quotes/{$quote->id}/duplicate")->assertStatus(422)->json('message');
            $this->assertStringContainsString('congelada', $mensaje, "etapa {$stage}");
        }
    }

    public function test_a4_una_cotizacion_no_confirmada_no_sirve_de_base_en_ninguna_etapa(): void
    {
        $token = $this->token();
        ['quote' => $quote, 'card' => $card] = $this->projectWithConfirmedQuote($token);
        $this->moveTo($token, $card, 'lost', 'lead', 'design');
        $this->tenant();
        Quote::whereKey($quote->id)->update(['confirmed_at' => null]);

        $this->withHeaders($this->h($token))->postJson("/api/ventas-diseno/quotes/{$quote->id}/duplicate")->assertStatus(422);
    }

    // ── A8: la regla RN5 sigue intacta ─────────────────────────────────────────────────────────────────────────

    public function test_a8_el_atajo_directo_de_diseno_a_cotizacion_sigue_bloqueado(): void
    {
        $token = $this->token();
        ['card' => $card] = $this->projectWithConfirmedQuote($token);
        $this->moveTo($token, $card, 'lost', 'lead', 'design');

        $r = $this->withHeaders($this->h($token))->patchJson("/api/ventas-diseno/pipeline/{$card->id}/stage", ['stage' => 'quote'])->assertStatus(422);

        $this->assertStringContainsString('solo ocurre generando una cotización real', (string) $r->json('message'));
        $this->assertSame('design', $this->stageOf($card));
    }

    // ── A10: permisos ─────────────────────────────────────────────────────────────────────────────────────────

    public function test_a10_sin_permiso_de_edicion_no_se_puede_duplicar(): void
    {
        $token = $this->token();
        ['quote' => $quote, 'card' => $card] = $this->projectWithConfirmedQuote($token);
        $this->moveTo($token, $card, 'lost', 'lead', 'design');
        // El rol del diseñador ya trae edición; el supervisor solo lectura (mismo patrón que QuoteControllerTest).
        $this->grant('supervisor@atlantic.test', ['ventas_diseno.read']);
        $soloLectura = $this->loginAs('supervisor@atlantic.test', 'Password123!');

        $this->withHeaders($this->h($soloLectura))->postJson("/api/ventas-diseno/quotes/{$quote->id}/duplicate")->assertStatus(403);
    }

    public function test_a10_un_vendedor_que_no_es_dueno_de_la_cotizacion_ni_ve_el_equipo_recibe_403(): void
    {
        $token = $this->token();
        ['quote' => $quote, 'card' => $card] = $this->projectWithConfirmedQuote($token);
        $this->moveTo($token, $card, 'lost', 'lead', 'design');
        // Con permiso de edición pero sin ser el dueño ni tener vista de equipo: el 403 viene de canAccess(), no del middleware.
        $this->grant('supervisor@atlantic.test', ['ventas_diseno.read', 'ventas_diseno.write', 'ventas_diseno.edit']);
        $otro = $this->loginAs('supervisor@atlantic.test', 'Password123!');

        $this->withHeaders($this->h($otro))->postJson("/api/ventas-diseno/quotes/{$quote->id}/duplicate")->assertStatus(403);
    }

    // ── P2: el detalle de la tarjeta expone el folio de la última cotización confirmada ───────────────────────

    public function test_p2_el_detalle_de_la_tarjeta_expone_id_y_folio_de_la_ultima_confirmada(): void
    {
        $token = $this->token();
        ['quote' => $quote, 'card' => $card] = $this->projectWithConfirmedQuote($token);
        $this->moveTo($token, $card, 'lost', 'lead', 'design');
        $this->tenant();
        $folio = (string) Quote::findOrFail($quote->id)->folio;

        $r = $this->withHeaders($this->h($token))->getJson("/api/ventas-diseno/pipeline/{$card->id}")->assertStatus(200);

        $this->assertSame($quote->id, $r->json('latest_quote_id'));
        $this->assertSame($folio, $r->json('latest_quote_folio'));
        $this->assertNotSame('', $folio);
    }

    public function test_p2_sin_cotizacion_confirmada_ambos_campos_son_nulos(): void
    {
        $token = $this->token();
        $this->tenant();
        $master  = MasterClient::create(['name' => 'Cliente sin cotización']);
        $sub     = SubClient::create(['master_client_id' => $master->id, 'business_name' => 'Sub', 'tax_id' => '155-0000-1-2027']);
        $project = SalesProject::create(['sub_client_id' => $sub->id, 'name' => 'Proyecto sin cotización']);
        $slug    = (string) Tenant::where('slug', 'atlantic')->value('slug');
        $ownerId = (int) DB::table("{$slug}_auth.users")->where('email', self::DESIGNER)->value('id');
        $card    = PipelineCard::create([
            'sales_project_id' => $project->id, 'stage' => 'design', 'master_client_id' => $master->id, 'sub_client_id' => $sub->id,
            'owner_id' => $ownerId, 'created_by' => $ownerId, 'stage_changed_at' => now(),
        ]);

        $r = $this->withHeaders($this->h($token))->getJson("/api/ventas-diseno/pipeline/{$card->id}")->assertStatus(200);

        $this->assertNull($r->json('latest_quote_id'));
        $this->assertNull($r->json('latest_quote_folio'));
    }
}
