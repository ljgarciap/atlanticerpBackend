<?php

declare(strict_types=1);

namespace Tests\Feature\VentasDiseno;

use App\Modules\VentasDiseno\Models\Architect;
use App\Modules\VentasDiseno\Models\MasterClient;
use App\Modules\VentasDiseno\Models\PipelineCard;
use App\Modules\VentasDiseno\Models\Quote;
use App\Modules\VentasDiseno\Models\SalesProject;
use App\Modules\VentasDiseno\Models\SubClient;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-1121 (P2) — los asociados se guardan A NIVEL DE PROYECTO: lo que se agrega desde una cotización aparece en la tarjeta de Pipeline y
 * viceversa (una sola fuente de verdad); una cotización sin proyecto guarda los suyos aparte y los traslada al proyecto cuando se le asigna
 * uno. `quotes.architect_id` (el que origina la comisión) no cambia de significado.
 */
class ProjectAssociatesTest extends FeatureTestCase
{
    private const DESIGNER = 'vendedor@atlantic.test';
    private const OTHER    = 'nivel5@atlantic.test';

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant();
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

    private function ownerId(): int
    {
        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');

        return (int) DB::table("{$slug}_auth.users")->where('email', self::DESIGNER)->value('id');
    }

    private function token(): string
    {
        $this->grant(self::DESIGNER, ['ventas_diseno.read', 'ventas_diseno.write', 'ventas_diseno.edit']);
        $token = $this->loginAs(self::DESIGNER, 'Password123!', 'designer');
        $this->tenant();

        return $token;
    }

    /** @return array<string, string> */
    private function h(string $token): array
    {
        return [...$this->authHeaders($token), 'Accept' => 'application/json'];
    }

    private function associate(string $type = 'designer', string $name = 'Diseñadora Asociada'): Architect
    {
        $this->tenant();

        return Architect::create(['type' => $type, 'name' => $name, 'phone' => '6'.random_int(1000000, 9999999)]);
    }

    /** @return array{project: SalesProject, card: PipelineCard, quote: Quote} */
    private function projectWithCardAndQuote(): array
    {
        $this->tenant();
        $master  = MasterClient::create(['name' => 'Cliente Asociados']);
        $sub     = SubClient::create(['master_client_id' => $master->id, 'business_name' => 'Cliente Asociados', 'tax_id' => '1-2-3']);
        $project = SalesProject::create(['sub_client_id' => $sub->id, 'name' => 'Proyecto Asociados']);
        $card    = PipelineCard::create([
            'sales_project_id' => $project->id, 'stage' => 'lead', 'master_client_id' => $master->id, 'sub_client_id' => $sub->id,
            'owner_id' => $this->ownerId(), 'created_by' => $this->ownerId(),
        ]);
        $quote = Quote::create([
            'owner_id' => $this->ownerId(), 'master_client_id' => $master->id, 'sub_client_id' => $sub->id,
            'sales_project_id' => $project->id, 'description' => 'COT asociados',
        ]);

        return ['project' => $project, 'card' => $card, 'quote' => $quote];
    }

    /** @return list<int> */
    private function ids(array $associates): array
    {
        return array_map(fn (array $a): int => (int) $a['id'], $associates);
    }

    // ── A5: cotización ↔ proyecto, una sola fuente de verdad ───────────────────────────────────────────────

    public function test_a5_un_asociado_agregado_desde_la_cotizacion_aparece_en_la_tarjeta(): void
    {
        $token = $this->token();
        ['card' => $card, 'quote' => $quote] = $this->projectWithCardAndQuote();
        $designer = $this->associate('designer');

        $this->withHeaders($this->h($token))->postJson("/api/ventas-diseno/quotes/{$quote->id}/associates", ['architect_id' => $designer->id])
            ->assertStatus(201);

        $this->assertSame([$designer->id], $this->ids($this->withHeaders($this->h($token))->getJson("/api/ventas-diseno/pipeline/{$card->id}")->assertStatus(200)->json('associates')));
        $this->assertSame([$designer->id], $this->ids($this->withHeaders($this->h($token))->getJson("/api/ventas-diseno/quotes/{$quote->id}")->assertStatus(200)->json('associates')));
    }

    public function test_a5_un_asociado_agregado_desde_el_proyecto_aparece_en_sus_cotizaciones(): void
    {
        $token = $this->token();
        ['card' => $card, 'quote' => $quote] = $this->projectWithCardAndQuote();
        $contractor = $this->associate('contractor', 'Contratista Asociado');

        $this->withHeaders($this->h($token))->postJson("/api/ventas-diseno/pipeline/{$card->id}/associates", ['architect_id' => $contractor->id])
            ->assertStatus(201)->assertJsonPath('data.0.type', 'contractor');

        $this->assertSame([$contractor->id], $this->ids($this->withHeaders($this->h($token))->getJson("/api/ventas-diseno/quotes/{$quote->id}")->json('associates')));
    }

    public function test_agregar_dos_veces_no_duplica_y_quitar_desde_cualquier_lado_lo_quita_de_ambos(): void
    {
        $token = $this->token();
        ['card' => $card, 'quote' => $quote] = $this->projectWithCardAndQuote();
        $eng = $this->associate('engineer', 'Ingeniero Asociado');

        $this->withHeaders($this->h($token))->postJson("/api/ventas-diseno/quotes/{$quote->id}/associates", ['architect_id' => $eng->id])->assertStatus(201);
        $this->withHeaders($this->h($token))->postJson("/api/ventas-diseno/pipeline/{$card->id}/associates", ['architect_id' => $eng->id])->assertStatus(201);
        $this->assertSame([$eng->id], $this->ids($this->withHeaders($this->h($token))->getJson("/api/ventas-diseno/pipeline/{$card->id}")->json('associates')));

        $this->withHeaders($this->h($token))->deleteJson("/api/ventas-diseno/quotes/{$quote->id}/associates/{$eng->id}")->assertStatus(200);
        $this->assertSame([], $this->withHeaders($this->h($token))->getJson("/api/ventas-diseno/pipeline/{$card->id}")->json('associates'));
    }

    // ── A6: cotización sin proyecto ─────────────────────────────────────────────────────────────────────────

    public function test_a6_los_asociados_de_un_borrador_sin_proyecto_pasan_al_proyecto_cuando_se_le_asigna_uno(): void
    {
        $token = $this->token();
        ['project' => $project, 'card' => $card] = $this->projectWithCardAndQuote();
        $draft = Quote::create(['owner_id' => $this->ownerId(), 'description' => 'borrador sin proyecto']);
        $consultant = $this->associate('consultant', 'Consultora Borrador');

        $this->withHeaders($this->h($token))->postJson("/api/ventas-diseno/quotes/{$draft->id}/associates", ['architect_id' => $consultant->id])->assertStatus(201);
        $this->assertSame([$consultant->id], $this->ids($this->withHeaders($this->h($token))->getJson("/api/ventas-diseno/quotes/{$draft->id}")->json('associates')));
        $this->assertSame([], $this->withHeaders($this->h($token))->getJson("/api/ventas-diseno/pipeline/{$card->id}")->json('associates'));

        $this->tenant();
        $sub = SubClient::findOrFail($project->sub_client_id);
        $this->withHeaders($this->h($token))->putJson("/api/ventas-diseno/quotes/{$draft->id}", [
            'master_client_id' => $sub->master_client_id, 'sub_client_id' => $sub->id, 'sales_project_id' => $project->id,
        ])->assertStatus(200);

        $this->assertSame([$consultant->id], $this->ids($this->withHeaders($this->h($token))->getJson("/api/ventas-diseno/pipeline/{$card->id}")->json('associates')));
        $this->tenant();
        $this->assertSame(0, $draft->fresh()->draftAssociates()->count()); // el pivote de borrador queda vacío
    }

    // ── el «Arquitecto» de la cotización (comisión) no cambia de significado ────────────────────────────────

    public function test_elegir_el_arquitecto_de_la_cotizacion_lo_agrega_al_proyecto(): void
    {
        $token = $this->token();
        ['card' => $card, 'quote' => $quote] = $this->projectWithCardAndQuote();
        $arch = $this->associate('architect', 'Arquitecto Comisión');

        $this->withHeaders($this->h($token))->putJson("/api/ventas-diseno/quotes/{$quote->id}", ['architect_id' => $arch->id])->assertStatus(200);

        $this->assertSame([$arch->id], $this->ids($this->withHeaders($this->h($token))->getJson("/api/ventas-diseno/pipeline/{$card->id}")->json('associates')));
        $this->tenant();
        $this->assertSame($arch->id, $quote->fresh()->architect_id);
    }

    public function test_agregar_un_arquitecto_solo_al_proyecto_no_toca_la_comision_de_la_cotizacion(): void
    {
        $token = $this->token();
        ['card' => $card, 'quote' => $quote] = $this->projectWithCardAndQuote();
        $arch = $this->associate('architect', 'Arquitecto Solo Proyecto');

        $this->withHeaders($this->h($token))->postJson("/api/ventas-diseno/pipeline/{$card->id}/associates", ['architect_id' => $arch->id])->assertStatus(201);

        $this->tenant();
        $this->assertNull($quote->fresh()->architect_id);
    }

    // ── permisos y bordes ───────────────────────────────────────────────────────────────────────────────────

    public function test_otro_vendedor_no_puede_tocar_los_asociados_de_un_proyecto_ajeno(): void
    {
        $owner = $this->token();
        ['card' => $card, 'quote' => $quote] = $this->projectWithCardAndQuote();
        $a = $this->associate();

        $this->grant(self::OTHER, ['ventas_diseno.read', 'ventas_diseno.write', 'ventas_diseno.edit']);
        $other = $this->loginAs(self::OTHER, 'Password123!', 'supervisor');

        $this->withHeaders($this->h($other))->postJson("/api/ventas-diseno/quotes/{$quote->id}/associates", ['architect_id' => $a->id])->assertStatus(403);
        $this->withHeaders($this->h($other))->postJson("/api/ventas-diseno/pipeline/{$card->id}/associates", ['architect_id' => $a->id])->assertStatus(403);
        $this->assertNotNull($owner);
    }

    public function test_un_asociado_inexistente_da_422(): void
    {
        $token = $this->token();
        ['quote' => $quote] = $this->projectWithCardAndQuote();

        $this->withHeaders($this->h($token))->postJson("/api/ventas-diseno/quotes/{$quote->id}/associates", ['architect_id' => 99999999])->assertStatus(422);
    }

    public function test_solo_lectura_no_puede_agregar_aunque_sea_el_dueno(): void
    {
        ['card' => $card, 'quote' => $quote] = $this->projectWithCardAndQuote();
        $a = $this->associate();
        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');
        $supervisorId = (int) DB::table("{$slug}_auth.users")->where('email', self::OTHER)->value('id');
        $quote->update(['owner_id' => $supervisorId]);
        $card->update(['owner_id' => $supervisorId]);

        $this->grant(self::OTHER, ['ventas_diseno.read']);
        $readOnly = $this->loginAs(self::OTHER, 'Password123!', 'supervisor');
        $this->tenant();

        $this->withHeaders($this->h($readOnly))->postJson("/api/ventas-diseno/quotes/{$quote->id}/associates", ['architect_id' => $a->id])->assertStatus(403);
        $this->withHeaders($this->h($readOnly))->postJson("/api/ventas-diseno/pipeline/{$card->id}/associates", ['architect_id' => $a->id])->assertStatus(403);
    }

    public function test_una_tarjeta_perdida_no_admite_cambios_de_asociados(): void
    {
        $token = $this->token();
        ['card' => $card] = $this->projectWithCardAndQuote();
        $card->update(['stage' => 'lost']);
        $a = $this->associate();

        $this->withHeaders($this->h($token))->postJson("/api/ventas-diseno/pipeline/{$card->id}/associates", ['architect_id' => $a->id])->assertStatus(403);
    }

    // ── Senior Review (hallazgos 3 y 4) ─────────────────────────────────────────────────────────────────────

    public function test_un_arquitecto_quitado_de_asociados_no_resucita_en_el_siguiente_guardado(): void
    {
        $token = $this->token();
        ['card' => $card, 'quote' => $quote] = $this->projectWithCardAndQuote();
        $arch = $this->associate('architect', 'Arquitecto Quitado');

        $this->withHeaders($this->h($token))->putJson("/api/ventas-diseno/quotes/{$quote->id}", ['architect_id' => $arch->id])->assertStatus(200);
        $this->assertSame([$arch->id], $this->ids($this->withHeaders($this->h($token))->getJson("/api/ventas-diseno/pipeline/{$card->id}")->json('associates')));

        // SCRUM-1121 (decisión de Luis): mientras sea el «Arquitecto» de la cotización no se puede quitar; primero se cambia el campo
        $this->withHeaders($this->h($token))->deleteJson("/api/ventas-diseno/quotes/{$quote->id}/associates/{$arch->id}")->assertStatus(422);
        $this->withHeaders($this->h($token))->putJson("/api/ventas-diseno/quotes/{$quote->id}", ['architect_id' => null])->assertStatus(200);
        $this->withHeaders($this->h($token))->deleteJson("/api/ventas-diseno/quotes/{$quote->id}/associates/{$arch->id}")->assertStatus(200);

        // un guardado que NO toca ni el arquitecto ni el proyecto (descripción) no debe volver a agregarlo
        $this->withHeaders($this->h($token))->putJson("/api/ventas-diseno/quotes/{$quote->id}", ['description' => 'solo cambia la descripción'])->assertStatus(200);
        $this->assertSame([], $this->withHeaders($this->h($token))->getJson("/api/ventas-diseno/pipeline/{$card->id}")->json('associates'));
    }

    public function test_desde_la_cotizacion_no_se_editan_los_asociados_de_un_proyecto_perdido_o_aprobado(): void
    {
        $token = $this->token();
        ['card' => $card, 'quote' => $quote] = $this->projectWithCardAndQuote();
        $a = $this->associate();
        $this->withHeaders($this->h($token))->postJson("/api/ventas-diseno/quotes/{$quote->id}/associates", ['architect_id' => $a->id])->assertStatus(201);

        foreach (['lost', 'approved'] as $stage) {
            $this->tenant();
            $card->update(['stage' => $stage]);
            $this->withHeaders($this->h($token))->postJson("/api/ventas-diseno/quotes/{$quote->id}/associates", ['architect_id' => $this->associate('other', 'Otro '.$stage)->id])->assertStatus(403);
            $this->withHeaders($this->h($token))->deleteJson("/api/ventas-diseno/quotes/{$quote->id}/associates/{$a->id}")->assertStatus(403);
        }

        // y los asociados no cambiaron
        $this->tenant();
        $this->assertSame([$a->id], $this->ids($this->withHeaders($this->h($token))->getJson("/api/ventas-diseno/pipeline/{$card->id}")->json('associates')));
    }

    // ── Pre-QA (MEDIO-1 y BAJO-1) ───────────────────────────────────────────────────────────────────────────

    public function test_el_formulario_real_manda_el_mismo_architect_id_sin_duplicar_ni_alterar_los_asociados(): void
    {
        $token = $this->token();
        ['card' => $card, 'quote' => $quote] = $this->projectWithCardAndQuote();
        $arch = $this->associate('architect', 'Arquitecto Formulario');
        $designer = $this->associate('designer', 'Diseñadora Formulario');

        $this->withHeaders($this->h($token))->putJson("/api/ventas-diseno/quotes/{$quote->id}", ['architect_id' => $arch->id])->assertStatus(200);
        $this->withHeaders($this->h($token))->postJson("/api/ventas-diseno/quotes/{$quote->id}/associates", ['architect_id' => $designer->id])->assertStatus(201);
        $this->withHeaders($this->h($token))->deleteJson("/api/ventas-diseno/quotes/{$quote->id}/associates/{$designer->id}")->assertStatus(200);

        // «Guardar borrador» desde la UI manda el formulario COMPLETO, con el architect_id sin cambios: no re-agrega a quien se quitó
        // (la diseñadora) ni duplica al arquitecto, que sigue siendo el de la comisión.
        $this->withHeaders($this->h($token))->putJson("/api/ventas-diseno/quotes/{$quote->id}", ['description' => 'guardado', 'architect_id' => $arch->id])->assertStatus(200);
        $this->assertSame([$arch->id], $this->ids($this->withHeaders($this->h($token))->getJson("/api/ventas-diseno/pipeline/{$card->id}")->json('associates')));

        // cambiar de verdad el arquitecto sí agrega al nuevo
        $otro = $this->associate('architect', 'Arquitecto Otro');
        $this->withHeaders($this->h($token))->putJson("/api/ventas-diseno/quotes/{$quote->id}", ['architect_id' => $otro->id])->assertStatus(200);
        $this->assertEqualsCanonicalizing([$arch->id, $otro->id], $this->ids($this->withHeaders($this->h($token))->getJson("/api/ventas-diseno/pipeline/{$card->id}")->json('associates')));
    }

    public function test_si_cualquier_tarjeta_del_proyecto_no_es_editable_la_cotizacion_tampoco_edita_asociados(): void
    {
        $token = $this->token();
        ['project' => $project, 'card' => $card, 'quote' => $quote] = $this->projectWithCardAndQuote();
        $a = $this->associate();

        // dato histórico: dos tarjetas en el mismo proyecto, una Aprobada y una editable
        $this->tenant();
        $approved = PipelineCard::create([
            'sales_project_id' => $project->id, 'stage' => 'approved', 'master_client_id' => $card->master_client_id,
            'sub_client_id' => $card->sub_client_id, 'owner_id' => $this->ownerId(), 'created_by' => $this->ownerId(),
        ]);

        $this->withHeaders($this->h($token))->postJson("/api/ventas-diseno/quotes/{$quote->id}/associates", ['architect_id' => $a->id])->assertStatus(403);

        // al desaparecer la tarjeta no editable vuelve a ser posible
        $this->tenant();
        $approved->delete();
        $this->withHeaders($this->h($token))->postJson("/api/ventas-diseno/quotes/{$quote->id}/associates", ['architect_id' => $a->id])->assertStatus(201);
    }

    public function test_los_pivotes_guardan_sus_marcas_de_tiempo(): void
    {
        $token = $this->token();
        ['project' => $project, 'quote' => $quote] = $this->projectWithCardAndQuote();
        $a = $this->associate();

        $this->withHeaders($this->h($token))->postJson("/api/ventas-diseno/quotes/{$quote->id}/associates", ['architect_id' => $a->id])->assertStatus(201);

        $this->tenant();
        $this->assertNotNull(DB::table(Tenant::current()->slug.'_ventas_diseno.sales_project_associates')->where('sales_project_id', $project->id)->value('created_at'));
    }

    // ── decisión de Luis: avisar y no quitar al arquitecto que origina la comisión ──────────────────────────────

    public function test_no_se_puede_quitar_de_asociados_al_arquitecto_de_la_comision_ni_desde_la_tarjeta(): void
    {
        $token = $this->token();
        ['card' => $card, 'quote' => $quote] = $this->projectWithCardAndQuote();
        $arch = $this->associate('architect', 'Arquitecto Comisión Bloqueado');
        $this->withHeaders($this->h($token))->putJson("/api/ventas-diseno/quotes/{$quote->id}", ['architect_id' => $arch->id])->assertStatus(200);

        $msg = (string) $this->withHeaders($this->h($token))->deleteJson("/api/ventas-diseno/pipeline/{$card->id}/associates/{$arch->id}")
            ->assertStatus(422)->json('message');
        $this->assertStringContainsString('Arquitecto', $msg);

        // sigue vinculado, y otro asociado cualquiera sí se puede quitar
        $this->assertSame([$arch->id], $this->ids($this->withHeaders($this->h($token))->getJson("/api/ventas-diseno/pipeline/{$card->id}")->json('associates')));
        $designer = $this->associate('designer', 'Diseñadora Quitable');
        $this->withHeaders($this->h($token))->postJson("/api/ventas-diseno/pipeline/{$card->id}/associates", ['architect_id' => $designer->id])->assertStatus(201);
        $this->withHeaders($this->h($token))->deleteJson("/api/ventas-diseno/pipeline/{$card->id}/associates/{$designer->id}")->assertStatus(200);
    }

    public function test_el_bloqueo_aplica_a_cualquier_cotizacion_del_proyecto_y_tambien_al_borrador_sin_proyecto(): void
    {
        $token = $this->token();
        ['project' => $project, 'quote' => $quote] = $this->projectWithCardAndQuote();
        $arch = $this->associate('architect', 'Arquitecto Otra Cotización');
        // la comisión la origina OTRA cotización del mismo proyecto
        $other = Quote::create(['owner_id' => $this->ownerId(), 'sales_project_id' => $project->id, 'architect_id' => $arch->id, 'description' => 'otra']);
        $this->withHeaders($this->h($token))->postJson("/api/ventas-diseno/quotes/{$quote->id}/associates", ['architect_id' => $arch->id])->assertStatus(201);

        $this->withHeaders($this->h($token))->deleteJson("/api/ventas-diseno/quotes/{$quote->id}/associates/{$arch->id}")->assertStatus(422);
        $this->assertNotNull($other);

        // borrador sin proyecto: su propio arquitecto tampoco se quita de su lista
        $this->tenant();
        $draft = Quote::create(['owner_id' => $this->ownerId(), 'description' => 'borrador', 'architect_id' => $arch->id]);
        $this->withHeaders($this->h($token))->postJson("/api/ventas-diseno/quotes/{$draft->id}/associates", ['architect_id' => $arch->id])->assertStatus(201);
        $this->withHeaders($this->h($token))->deleteJson("/api/ventas-diseno/quotes/{$draft->id}/associates/{$arch->id}")->assertStatus(422);
    }
}
