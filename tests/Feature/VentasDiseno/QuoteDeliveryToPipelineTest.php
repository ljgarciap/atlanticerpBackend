<?php

declare(strict_types=1);

namespace Tests\Feature\VentasDiseno;

use App\Modules\VentasDiseno\Models\MasterClient;
use App\Modules\VentasDiseno\Models\PipelineCard;
use App\Modules\VentasDiseno\Models\PipelineCardDeliveryDate;
use App\Modules\VentasDiseno\Models\PipelineCardProductDelivery;
use App\Modules\VentasDiseno\Models\Quote;
use App\Modules\VentasDiseno\Models\SalesProject;
use App\Modules\VentasDiseno\Models\SubClient;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-1122 — la Fecha estimada de entrega de la cotización que se confirma viaja a la tarjeta de Pipeline:
 * Única → esa fecha; Parcial → todas, en orden; «Por definir» → la tarjeta no se toca. La cotización manda sobre lo que la
 * tarjeta ya tuviera (también en una versión nueva) y las fechas que se conservan mantienen sus asignaciones de producto.
 */
class QuoteDeliveryToPipelineTest extends FeatureTestCase
{
    private const DESIGNER = 'vendedor@atlantic.test';

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant();
    }

    private function tenant(): void
    {
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    private function token(): string
    {
        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');
        DB::table("{$slug}_auth.users")->where('email', self::DESIGNER)
            ->update(['extra_permissions' => json_encode(['ventas_diseno.read', 'ventas_diseno.write', 'ventas_diseno.edit'])]);

        return $this->loginAs(self::DESIGNER, 'Password123!', 'designer');
    }

    /** @return array<string, string> */
    private function h(string $token): array
    {
        return [...$this->authHeaders($token), 'Accept' => 'application/json'];
    }

    private function ownerId(): int
    {
        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');

        return (int) DB::table("{$slug}_auth.users")->where('email', self::DESIGNER)->value('id');
    }

    /** @return array{project: SalesProject, sub: SubClient} */
    private function project(): array
    {
        $this->tenant();
        $master = MasterClient::create(['name' => 'Cliente Entrega']);
        $sub    = SubClient::create(['master_client_id' => $master->id, 'business_name' => 'Cliente Entrega', 'tax_id' => '1-2-3']);

        return ['project' => SalesProject::create(['sub_client_id' => $sub->id, 'name' => 'Proyecto Entrega']), 'sub' => $sub];
    }

    /**
     * Cotización lista para generar sobre el proyecto dado.
     *
     * @param list<string> $dates
     */
    private function quote(SalesProject $project, SubClient $sub, ?string $type, array $dates, float $price = 100.0): Quote
    {
        $this->tenant();
        $quote = Quote::create([
            'owner_id' => $this->ownerId(), 'master_client_id' => $sub->master_client_id, 'sub_client_id' => $sub->id,
            'sales_project_id' => $project->id, 'ruc' => '1-2-3', 'description' => 'COT entrega', 'delivery_type' => $type,
        ]);
        foreach ($dates as $date) {
            $quote->deliveryDates()->create(['date' => $date]);
        }
        $quote->contacts()->attach($sub->contacts()->create(['name' => 'C', 'role' => 'client', 'phone' => '1'])->id);
        $quote->parts()->create(['name' => 'P'])->items()->create(['reference' => 'R', 'description' => 'Item', 'quantity' => 2, 'unit_price' => $price]);

        return $quote;
    }

    private function confirm(string $token, Quote $quote): void
    {
        $this->withHeaders($this->h($token))->postJson("/api/ventas-diseno/quotes/{$quote->id}/generate")->assertStatus(200);
        $this->withHeaders($this->h($token))->postJson("/api/ventas-diseno/quotes/{$quote->id}/confirm")->assertStatus(200);
        $this->tenant();
    }

    private function card(SalesProject $project): PipelineCard
    {
        $this->tenant();

        return PipelineCard::where('sales_project_id', $project->id)->firstOrFail();
    }

    /** @return list<string> */
    private function cardDates(PipelineCard $card): array
    {
        return PipelineCardDeliveryDate::where('pipeline_card_id', $card->id)->orderBy('id')->get()
            ->map(fn ($d): string => $d->date->toDateString())->all();
    }

    // ── A1 / A6: entrega única, y la confirmación que CREA la tarjeta ───────────────────────────────────────────

    public function test_a1_a6_entrega_unica_viaja_a_la_tarjeta_que_se_crea_al_confirmar(): void
    {
        $token = $this->token();
        ['project' => $project, 'sub' => $sub] = $this->project();

        $this->confirm($token, $this->quote($project, $sub, 'single', ['2026-11-15']));

        $card = $this->card($project);
        $this->assertSame('single', $card->delivery_type);
        $this->assertSame(['2026-11-15'], $this->cardDates($card));
        $this->assertEquals(214.0, (float) $card->amount); // el monto sigue trasladándose como siempre
        $this->assertSame('quote', $card->stage);
    }

    // ── A2: entrega parcial, todas las fechas y en el mismo orden ───────────────────────────────────────────────

    public function test_a2_entrega_parcial_traslada_todas_las_fechas_en_orden(): void
    {
        $token = $this->token();
        ['project' => $project, 'sub' => $sub] = $this->project();

        $this->confirm($token, $this->quote($project, $sub, 'partial', ['2026-11-15', '2026-11-30', '2026-12-15']));

        $card = $this->card($project);
        $this->assertSame('partial', $card->delivery_type);
        $this->assertSame(['2026-11-15', '2026-11-30', '2026-12-15'], $this->cardDates($card));
    }

    // ── A3 / A7: «Por definir» no traslada ni borra ─────────────────────────────────────────────────────────────

    public function test_a3_por_definir_no_traslada_ninguna_fecha(): void
    {
        $token = $this->token();
        ['project' => $project, 'sub' => $sub] = $this->project();

        $this->confirm($token, $this->quote($project, $sub, 'tbd', []));

        $card = $this->card($project);
        $this->assertNull($card->delivery_type);
        $this->assertSame([], $this->cardDates($card));
    }

    public function test_a3_por_definir_no_borra_las_fechas_que_la_tarjeta_ya_tenia(): void
    {
        $token = $this->token();
        ['project' => $project, 'sub' => $sub] = $this->project();
        $this->confirm($token, $this->quote($project, $sub, 'single', ['2026-11-15']));
        $card = $this->card($project);

        // versión nueva en «Por definir»
        $v1 = Quote::where('sales_project_id', $project->id)->firstOrFail();
        $v2Id = (int) $this->withHeaders($this->h($token))->postJson("/api/ventas-diseno/quotes/{$v1->id}/duplicate")->assertStatus(201)->json('id');
        $this->tenant();
        $v2 = Quote::findOrFail($v2Id);
        $v2->update(['delivery_type' => 'tbd']);
        $v2->deliveryDates()->delete();
        $this->confirm($token, $v2);

        $card->refresh();
        $this->assertSame('single', $card->delivery_type);
        $this->assertSame(['2026-11-15'], $this->cardDates($card));
    }

    // ── A4: la cotización manda, y las fechas que se conservan mantienen sus asignaciones de producto ──────────

    public function test_a4_la_cotizacion_reemplaza_las_fechas_de_la_tarjeta_y_conserva_las_coincidentes(): void
    {
        $token = $this->token();
        ['project' => $project, 'sub' => $sub] = $this->project();

        // tarjeta sin cotización confirmada todavía, con fechas tecleadas a mano y una asignación de producto
        $card = PipelineCard::create([
            'sales_project_id' => $project->id, 'stage' => 'lead', 'master_client_id' => $sub->master_client_id,
            'sub_client_id' => $sub->id, 'owner_id' => $this->ownerId(), 'created_by' => $this->ownerId(), 'delivery_type' => 'partial',
        ]);
        $keep = $card->deliveryDates()->create(['date' => '2026-11-15']);
        $card->deliveryDates()->create(['date' => '2026-10-01']);

        $quote = $this->quote($project, $sub, 'partial', ['2026-11-15', '2026-12-20']);
        $item  = $quote->parts()->first()->items()->first();
        PipelineCardProductDelivery::create(['pipeline_card_id' => $card->id, 'quote_item_id' => $item->id, 'pipeline_card_delivery_date_id' => $keep->id]);

        $this->confirm($token, $quote);

        $card->refresh();
        $this->assertSame(['2026-11-15', '2026-12-20'], collect($this->cardDates($card))->sort()->values()->all());
        $this->assertSame($keep->id, PipelineCardDeliveryDate::where('pipeline_card_id', $card->id)->where('date', '2026-11-15')->value('id'));
        $this->assertSame(1, PipelineCardProductDelivery::where('pipeline_card_delivery_date_id', $keep->id)->count()); // asignación intacta
    }

    // ── A5: una versión nueva con fechas distintas actualiza la tarjeta ─────────────────────────────────────────

    public function test_a5_version_nueva_con_otras_fechas_actualiza_la_tarjeta(): void
    {
        $token = $this->token();
        ['project' => $project, 'sub' => $sub] = $this->project();
        $this->confirm($token, $this->quote($project, $sub, 'single', ['2026-11-15']));

        $v1 = Quote::where('sales_project_id', $project->id)->firstOrFail();
        $v2Id = (int) $this->withHeaders($this->h($token))->postJson("/api/ventas-diseno/quotes/{$v1->id}/duplicate")->assertStatus(201)->json('id');
        $this->tenant();
        $v2 = Quote::findOrFail($v2Id);
        $v2->update(['delivery_type' => 'partial']);
        $v2->deliveryDates()->delete();
        foreach (['2026-12-01', '2026-12-20'] as $d) {
            $v2->deliveryDates()->create(['date' => $d]);
        }
        $this->confirm($token, $v2);

        $card = $this->card($project);
        $this->assertSame('partial', $card->delivery_type);
        $this->assertSame(['2026-12-01', '2026-12-20'], $this->cardDates($card));
    }

    // ── Senior Review: duplicadas, asignaciones que desaparecen y evento ────────────────────────────────────────

    public function test_fechas_repetidas_en_la_cotizacion_se_trasladan_una_sola_vez(): void
    {
        $token = $this->token();
        ['project' => $project, 'sub' => $sub] = $this->project();

        $this->confirm($token, $this->quote($project, $sub, 'partial', ['2026-11-15', '2026-11-15', '2026-12-01']));

        $this->assertSame(['2026-11-15', '2026-12-01'], $this->cardDates($this->card($project)));
    }

    public function test_una_fecha_que_la_cotizacion_ya_no_trae_se_va_con_sus_asignaciones_y_la_que_queda_las_conserva(): void
    {
        $token = $this->token();
        ['project' => $project, 'sub' => $sub] = $this->project();
        $card = PipelineCard::create([
            'sales_project_id' => $project->id, 'stage' => 'design', 'master_client_id' => $sub->master_client_id,
            'sub_client_id' => $sub->id, 'owner_id' => $this->ownerId(), 'created_by' => $this->ownerId(), 'delivery_type' => 'partial',
        ]);
        $stays = $card->deliveryDates()->create(['date' => '2026-11-15']);
        $goes  = $card->deliveryDates()->create(['date' => '2026-11-30']);

        $quote = $this->quote($project, $sub, 'single', ['2026-11-15']);
        $part  = $quote->parts()->first();
        $item  = $part->items()->first();
        $item2 = $part->items()->create(['reference' => 'R2', 'description' => 'Item 2', 'quantity' => 1, 'unit_price' => 50]);
        // una asignación por producto y tarjeta: el primer producto va a la fecha que se queda, el segundo a la que desaparece
        PipelineCardProductDelivery::create(['pipeline_card_id' => $card->id, 'quote_item_id' => $item->id, 'pipeline_card_delivery_date_id' => $stays->id]);
        PipelineCardProductDelivery::create(['pipeline_card_id' => $card->id, 'quote_item_id' => $item2->id, 'pipeline_card_delivery_date_id' => $goes->id]);

        $this->confirm($token, $quote);

        // comportamiento aceptado (D1): la cotización manda; la fecha que desaparece arrastra sus asignaciones (FK en cascada)
        $this->assertSame(['2026-11-15'], $this->cardDates($card->refresh()));
        $this->assertSame('single', $card->delivery_type);
        $this->assertSame(1, PipelineCardProductDelivery::where('pipeline_card_id', $card->id)->count());
        $this->assertSame(1, PipelineCardProductDelivery::where('pipeline_card_delivery_date_id', $stays->id)->count());
    }

    public function test_el_traslado_no_cambia_el_evento_ni_la_etapa_de_la_confirmacion(): void
    {
        $token = $this->token();
        ['project' => $project, 'sub' => $sub] = $this->project();
        \Illuminate\Support\Facades\Event::fake([\App\Modules\VentasDiseno\Events\QuoteConfirmed::class]);

        $this->confirm($token, $this->quote($project, $sub, 'partial', ['2026-11-15', '2026-11-30']));

        \Illuminate\Support\Facades\Event::assertDispatched(
            \App\Modules\VentasDiseno\Events\QuoteConfirmed::class,
            fn ($e): bool => $e->salesProjectId === $project->id && ! $e->isNewVersion,
        );
        $this->assertSame('quote', $this->card($project)->stage);
    }

    // ── A8: el resto del flujo de confirmación sigue igual ──────────────────────────────────────────────────────

    public function test_a8_no_hay_fechas_inventadas_y_el_resto_del_flujo_sigue_igual(): void
    {
        $token = $this->token();
        ['project' => $project, 'sub' => $sub] = $this->project();

        // entrega única SIN fecha (el estado «sin definir» de una cotización antigua): la tarjeta no recibe ninguna
        $quote = $this->quote($project, $sub, 'single', ['2026-11-15']);
        $quote->deliveryDates()->delete();
        $quote->update(['delivery_type' => 'tbd']);
        $this->confirm($token, $quote);

        $card = $this->card($project);
        $this->assertSame([], $this->cardDates($card));
        $this->assertSame('quote', $card->stage);
        $this->assertEquals(214.0, (float) $card->amount);
    }
}
