<?php

declare(strict_types=1);

namespace Tests\Feature\VentasDiseno;

use App\Modules\VentasDiseno\Models\CotizaYaSettings;
use App\Modules\VentasDiseno\Models\MasterClient;
use App\Modules\VentasDiseno\Models\PipelineCard;
use App\Modules\VentasDiseno\Models\Quote;
use App\Modules\VentasDiseno\Models\SalesProject;
use App\Modules\VentasDiseno\Models\SubClient;
use App\Modules\VentasDiseno\Services\CotizaYaClientGuard;
use App\Modules\VentasDiseno\Services\CotizaYaService;
use App\Modules\VentasDiseno\Support\CotizaYaSchema;
use App\Modules\VentasDiseno\Services\QuotePdfService;
use App\Modules\VentasDiseno\Support\CotizaYaContext;
use App\Shared\Multitenancy\Tenant;
use Database\Seeders\CotizaYaClientSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-1120 — CotizaYA: cotizaciones de venta chica, independientes del flujo comercial.
 *
 * Cubre: ciclo de vida (borrador → partidas → Generar), folio de serie propia `COTY-AAAA-NNNN`, que NADA de CotizaYA
 * se cuele en Cotizaciones/Pipeline/proyectos, que las rutas de CotizaYA no sirvan de puerta lateral a una cotización
 * comercial, el alcance Mías/Equipo y el bloqueo del cliente «CotizaYA» en el flujo normal.
 */
class CotizaYaTest extends FeatureTestCase
{
    private const DESIGNER = 'designer@atlantic.test';
    private const MANAGER  = 'management@atlantic.test';
    private const OTHER    = 'supervisor@atlantic.test';

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant();
        CotizaYaClientSeeder::ensureClient();
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

    private function userId(string $email): int
    {
        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');

        return (int) DB::table("{$slug}_auth.users")->where('email', $email)->value('id');
    }

    private function designerToken(): string
    {
        $this->grant(self::DESIGNER, ['ventas_diseno.read', 'ventas_diseno.write', 'ventas_diseno.edit']);

        $token = $this->loginAs(self::DESIGNER, 'Password123!', 'designer');
        $this->tenant(); // loginAs olvida el tenant actual

        return $token;
    }

    private function managerToken(): string
    {
        $token = $this->loginAs(self::MANAGER, 'Password123!', 'management');
        $this->tenant();

        return $token;
    }

    /** @return array<string, string> */
    private function h(string $token): array
    {
        return [...$this->authHeaders($token), 'Accept' => 'application/json'];
    }

    /** Crea el borrador por la API y le agrega una partida con un ítem. @return array{id: int} */
    private function draftWithItem(string $token, array $fields = [], float $price = 100.0, float $qty = 2.0): array
    {
        $id = (int) $this->withHeaders($this->h($token))->postJson('/api/ventas-diseno/cotizaya', $fields)
            ->assertStatus(201)->json('id');

        $partId = (int) $this->withHeaders($this->h($token))->postJson("/api/ventas-diseno/cotizaya/{$id}/parts", ['name' => 'Partida 1'])
            ->assertStatus(201)->json('id');

        $this->withHeaders($this->h($token))->postJson("/api/ventas-diseno/cotizaya/{$id}/parts/{$partId}/items", [
            'is_custom' => true, 'reference' => 'REF-1', 'description' => 'Luminaria', 'quantity' => $qty, 'unit_price' => $price,
        ])->assertStatus(201);

        return ['id' => $id];
    }

    private function generate(string $token, int $id): \Illuminate\Testing\TestResponse
    {
        return $this->withHeaders($this->h($token))->postJson("/api/ventas-diseno/cotizaya/{$id}/generate");
    }

    // ── ciclo de vida ──────────────────────────────────────────────────────────────────────────────────────────

    public function test_contexto_devuelve_el_cliente_fijo_y_sus_contactos(): void
    {
        $settings = CotizaYaSettings::current();
        SubClient::findOrFail($settings->sub_client_id)->contacts()->create(['name' => 'Contacto CY', 'role' => 'client', 'phone' => '6000-0000']);

        $token = $this->designerToken();
        $json  = $this->withHeaders($this->h($token))->getJson('/api/ventas-diseno/cotizaya/context')->assertStatus(200)->json();

        $this->assertTrue($json['configured']);
        $this->assertSame('Cotización', $json['master_client']['name']);
        $this->assertSame('Contacto CY', $json['contacts'][0]['name']);
    }

    public function test_crear_borrador_usa_el_cliente_fijo_y_no_toca_pipeline_ni_proyectos(): void
    {
        $token = $this->designerToken();
        $cards = PipelineCard::count();
        $projects = SalesProject::count();

        $json = $this->withHeaders($this->h($token))->postJson('/api/ventas-diseno/cotizaya', [
            'cotizaya_customer_name' => 'María Pérez', 'cotizaya_customer_phone' => '6677-8899',
        ])->assertStatus(201)->json();

        $this->tenant(); // cada request HTTP olvida el tenant actual
        $this->assertSame('cotizaya', $json['origin']);
        $this->assertSame('María Pérez', $json['cotizaya']['customer_name']);
        $this->assertSame('6677-8899', $json['cotizaya']['customer_phone']);
        $this->assertSame(CotizaYaSettings::current()->master_client_id, $json['master_client']['id']);
        $this->assertNull($json['sales_project']);
        $this->assertNull($json['pipeline_card_id']);
        $this->assertSame($cards, PipelineCard::count());
        $this->assertSame($projects, SalesProject::count());
    }

    public function test_el_cliente_no_se_puede_cambiar_por_la_api(): void
    {
        $token = $this->designerToken();
        $otro  = MasterClient::create(['name' => 'Otro Cliente']);

        $json = $this->withHeaders($this->h($token))->postJson('/api/ventas-diseno/cotizaya', [
            'master_client_id' => $otro->id, 'sub_client_id' => 999999,
        ])->assertStatus(201)->json();

        $this->tenant();
        $this->assertSame(CotizaYaSettings::current()->master_client_id, $json['master_client']['id']);
        $this->assertNotSame($otro->id, $json['master_client']['id']);

        $this->withHeaders($this->h($token))->putJson("/api/ventas-diseno/cotizaya/{$json['id']}", ['master_client_id' => $otro->id])
            ->assertStatus(200);
        $this->tenant();
        $this->assertSame(CotizaYaSettings::current()->master_client_id, Quote::query()->withoutGlobalScopes()->findOrFail($json['id'])->master_client_id);
    }

    public function test_entorno_sin_cliente_configurado_responde_422_claro(): void
    {
        CotizaYaSettings::query()->delete();
        $token = $this->designerToken();

        $this->withHeaders($this->h($token))->postJson('/api/ventas-diseno/cotizaya', [])
            ->assertStatus(422)
            ->assertJsonPath('message', 'CotizaYA no está configurado en este entorno: falta el cliente CotizaYA.');
    }

    public function test_partidas_reutilizan_la_logica_y_los_totales_se_calculan(): void
    {
        $token = $this->designerToken();
        $draft = $this->draftWithItem($token, [], 100.0, 2.0);

        $json = $this->withHeaders($this->h($token))->getJson("/api/ventas-diseno/cotizaya/{$draft['id']}")->assertStatus(200)->json();

        $this->assertEquals(200.0, $json['subtotal']);
        $this->assertEquals(14.0, $json['itbms']);          // tasa vigente (7%)
        $this->assertEquals(214.0, $json['grand_total']);
        $this->assertCount(1, $json['parts']);
    }

    public function test_generar_asigna_folio_propio_y_deja_la_cotizacion_fija(): void
    {
        $token = $this->designerToken();
        $draft = $this->draftWithItem($token);

        $json = $this->generate($token, $draft['id'])->assertStatus(200)->json();

        $this->assertMatchesRegularExpression('/^COTY-\d{4}-0001$/', $json['folio']);
        $this->assertNotNull($json['generated_at']);
        $this->assertNotNull($json['confirmed_at']);
        $this->assertFalse($json['can_edit']);

        // fija: ni los campos propios ni las partidas se pueden tocar
        $this->withHeaders($this->h($token))->putJson("/api/ventas-diseno/cotizaya/{$draft['id']}", ['description' => 'cambio'])->assertStatus(422);
        $this->withHeaders($this->h($token))->postJson("/api/ventas-diseno/cotizaya/{$draft['id']}/parts", ['name' => 'Otra'])->assertStatus(422);

        // el mensaje no ofrece «Usar como base para nueva versión», que CotizaYA no tiene
        $msg = (string) $this->withHeaders($this->h($token))->postJson("/api/ventas-diseno/cotizaya/{$draft['id']}/parts", ['name' => 'Otra'])->json('message');
        $this->assertStringContainsString('CotizaYA', $msg);
        $this->assertStringNotContainsString('nueva versión', $msg);
    }

    public function test_generar_dos_veces_no_cambia_el_folio_y_la_serie_es_consecutiva(): void
    {
        $token = $this->designerToken();
        $a = $this->draftWithItem($token);
        $b = $this->draftWithItem($token);

        $folioA = $this->generate($token, $a['id'])->assertStatus(200)->json('folio');
        $this->assertSame($folioA, $this->generate($token, $a['id'])->assertStatus(200)->json('folio'));

        $folioB = $this->generate($token, $b['id'])->assertStatus(200)->json('folio');
        $this->assertMatchesRegularExpression('/-0002$/', $folioB);
    }

    public function test_generar_sin_items_responde_422(): void
    {
        $token = $this->designerToken();
        $id = (int) $this->withHeaders($this->h($token))->postJson('/api/ventas-diseno/cotizaya', [])->json('id');

        $this->generate($token, $id)->assertStatus(422)->assertJsonPath('missing.0', 'Ítems');
    }

    public function test_solo_el_diseniador_es_obligatorio_descripcion_contacto_y_cliente_son_opcionales(): void
    {
        $token = $this->designerToken();
        $draft = $this->draftWithItem($token, []);   // sin nombre, teléfono, descripción, observaciones ni contactos

        $this->generate($token, $draft['id'])->assertStatus(200);
    }

    // ── aislamiento del flujo comercial ────────────────────────────────────────────────────────────────────────

    public function test_la_cotizaya_generada_no_aparece_en_cotizaciones_ni_en_el_modelo(): void
    {
        $token = $this->designerToken();
        $draft = $this->draftWithItem($token);
        $folio = $this->generate($token, $draft['id'])->assertStatus(200)->json('folio');

        // fuera del módulo CotizaYA ninguna consulta de Eloquent la ve
        $this->tenant();
        $this->assertNull(Quote::find($draft['id']));
        $this->assertSame(0, Quote::count());

        // Cotizaciones (listado, detalle, PDF) tampoco
        $list = $this->withHeaders($this->h($token))->getJson('/api/ventas-diseno/quotes')->assertStatus(200)->json();
        $this->assertStringNotContainsString($folio, json_encode($list));
        $this->withHeaders($this->h($token))->getJson("/api/ventas-diseno/quotes/{$draft['id']}")->assertStatus(404);
        $this->withHeaders($this->h($token))->getJson("/api/ventas-diseno/quotes/{$draft['id']}/pdf")->assertStatus(404);
        $this->withHeaders($this->h($token))->postJson("/api/ventas-diseno/quotes/{$draft['id']}/parts", ['name' => 'x'])->assertStatus(404);
    }

    public function test_generar_no_crea_lead_tarjeta_ni_proyecto(): void
    {
        $token = $this->designerToken();
        $cards = PipelineCard::count();
        $projects = SalesProject::count();

        $draft = $this->draftWithItem($token);
        $this->generate($token, $draft['id'])->assertStatus(200);

        $this->tenant();
        $this->assertSame($cards, PipelineCard::count());
        $this->assertSame($projects, SalesProject::count());
        $this->assertNull(CotizaYaContext::run(fn () => Quote::findOrFail($draft['id']))->sales_project_id);
    }

    public function test_el_contexto_se_apaga_al_terminar_la_request(): void
    {
        $token = $this->designerToken();
        $this->draftWithItem($token);

        $this->assertFalse(CotizaYaContext::isActive());
    }

    public function test_las_rutas_de_cotizaya_no_sirven_para_una_cotizacion_comercial(): void
    {
        $token = $this->designerToken();
        $master = MasterClient::create(['name' => 'Cliente Comercial']);
        $quote  = Quote::create(['owner_id' => $this->userId(self::DESIGNER), 'master_client_id' => $master->id]);
        $part   = $quote->parts()->create(['name' => 'P']);

        $this->withHeaders($this->h($token))->getJson("/api/ventas-diseno/cotizaya/{$quote->id}")->assertStatus(404);
        $this->withHeaders($this->h($token))->putJson("/api/ventas-diseno/cotizaya/{$quote->id}", ['description' => 'x'])->assertStatus(404);
        $this->withHeaders($this->h($token))->postJson("/api/ventas-diseno/cotizaya/{$quote->id}/generate")->assertStatus(404);
        $this->withHeaders($this->h($token))->getJson("/api/ventas-diseno/cotizaya/{$quote->id}/pdf")->assertStatus(404);
        $this->withHeaders($this->h($token))->postJson("/api/ventas-diseno/cotizaya/{$quote->id}/parts", ['name' => 'x'])->assertStatus(404);
        $this->withHeaders($this->h($token))->deleteJson("/api/ventas-diseno/cotizaya/{$quote->id}/parts/{$part->id}")->assertStatus(404);
    }

    public function test_la_serie_comercial_cot_no_se_altera(): void
    {
        $token = $this->designerToken();
        $a = $this->draftWithItem($token);
        $this->generate($token, $a['id'])->assertStatus(200);

        $this->tenant();
        $master = MasterClient::create(['name' => 'Cliente Comercial']);
        $sub    = SubClient::create(['master_client_id' => $master->id, 'business_name' => 'CC', 'tax_id' => '1-1-1']);
        $quote  = Quote::create([
            'owner_id' => $this->userId(self::DESIGNER), 'master_client_id' => $master->id, 'sub_client_id' => $sub->id,
            'ruc' => '1-1-1', 'description' => 'comercial', 'delivery_type' => 'tbd',
        ]);
        $quote->contacts()->attach($sub->contacts()->create(['name' => 'C', 'role' => 'client', 'phone' => '1'])->id);
        $project = SalesProject::create(['sub_client_id' => $sub->id, 'name' => 'Proy']);
        $quote->update(['sales_project_id' => $project->id]);
        $quote->parts()->create(['name' => 'P'])->items()->create(['reference' => 'R', 'description' => 'D', 'quantity' => 1, 'unit_price' => 10]);

        $folio = $this->withHeaders($this->h($token))->postJson("/api/ventas-diseno/quotes/{$quote->id}/generate")->assertStatus(200)->json('folio');
        $this->assertMatchesRegularExpression('/^COT-\d{4}-0001$/', $folio);
    }

    // ── listado y alcance ──────────────────────────────────────────────────────────────────────────────────────

    public function test_listado_muestra_solo_las_generadas_con_folio_nombre_y_total(): void
    {
        $token = $this->designerToken();
        $gen   = $this->draftWithItem($token, ['cotizaya_customer_name' => 'Cliente Uno', 'cotizaya_customer_phone' => '111']);
        $folio = $this->generate($token, $gen['id'])->json('folio');
        $this->draftWithItem($token);   // borrador sin generar: no se lista

        $json = $this->withHeaders($this->h($token))->getJson('/api/ventas-diseno/cotizaya')->assertStatus(200)->json();

        $this->assertCount(1, $json['data']);
        $this->assertSame($folio, $json['data'][0]['folio']);
        $this->assertSame('Cliente Uno', $json['data'][0]['customer_name']);
        $this->assertSame('111', $json['data'][0]['customer_phone']);
        $this->assertEquals(214.0, $json['data'][0]['grand_total']);
        $this->assertSame(1, $json['meta']['total']);
    }

    public function test_busqueda_por_nombre_del_cliente_y_folio(): void
    {
        $token = $this->designerToken();
        $a = $this->draftWithItem($token, ['cotizaya_customer_name' => 'Zulema Quintero']);
        $b = $this->draftWithItem($token, ['cotizaya_customer_name' => 'Otro Comprador']);
        $folioA = $this->generate($token, $a['id'])->json('folio');
        $this->generate($token, $b['id']);

        $byName = $this->withHeaders($this->h($token))->getJson('/api/ventas-diseno/cotizaya?search=zulema')->json('data');
        $this->assertCount(1, $byName);
        $this->assertSame($folioA, $byName[0]['folio']);

        $byFolio = $this->withHeaders($this->h($token))->getJson('/api/ventas-diseno/cotizaya?search=' . $folioA)->json('data');
        $this->assertCount(1, $byFolio);
    }

    public function test_el_vendedor_ve_solo_las_suyas_y_gerencia_ve_todas(): void
    {
        $designer = $this->designerToken();
        $mine = $this->draftWithItem($designer);
        $this->generate($designer, $mine['id']);

        $manager = $this->managerToken();
        $theirs  = $this->draftWithItem($manager);
        $this->generate($manager, $theirs['id']);

        $this->assertCount(1, $this->withHeaders($this->h($designer))->getJson('/api/ventas-diseno/cotizaya')->json('data'));
        $this->assertCount(2, $this->withHeaders($this->h($manager))->getJson('/api/ventas-diseno/cotizaya')->json('data'));

        // y no puede abrir ni generar la ajena
        $this->withHeaders($this->h($designer))->getJson("/api/ventas-diseno/cotizaya/{$theirs['id']}")->assertStatus(403);
    }

    public function test_sin_permisos_de_ventas_diseno_no_accede(): void
    {
        $this->grant(self::OTHER, []);
        $token = $this->loginAs(self::OTHER, 'Password123!', 'supervisor');

        $this->withHeaders($this->h($token))->getJson('/api/ventas-diseno/cotizaya')->assertStatus(403);
        $this->withHeaders($this->h($token))->postJson('/api/ventas-diseno/cotizaya', [])->assertStatus(403);
    }

    public function test_sin_token_responde_401(): void
    {
        $this->getJson('/api/ventas-diseno/cotizaya')->assertStatus(401);
    }

    // ── PDF ────────────────────────────────────────────────────────────────────────────────────────────────────

    public function test_pdf_se_descarga_y_muestra_el_nombre_del_cliente_libre_sin_subcliente_ni_proyecto(): void
    {
        $token = $this->designerToken();
        $draft = $this->draftWithItem($token, ['cotizaya_customer_name' => 'Zulema Quintero', 'cotizaya_customer_phone' => '6000-1234']);
        $this->generate($token, $draft['id'])->assertStatus(200);

        $this->withHeaders($this->h($token))->get("/api/ventas-diseno/cotizaya/{$draft['id']}/pdf")
            ->assertStatus(200)->assertHeader('content-type', 'application/pdf');

        $detail = $this->withHeaders($this->h($token))->getJson("/api/ventas-diseno/cotizaya/{$draft['id']}")->json();
        $html   = view('ventas-diseno.quote-pdf', app(QuotePdfService::class)->viewData($detail, false))->render();

        $this->assertStringContainsString('Zulema Quintero', $html);
        $this->assertStringContainsString('6000-1234', $html);
        $this->assertStringContainsString($detail['folio'], $html);
        $this->assertStringNotContainsString('CotizaYA', $html);
        $this->assertStringNotContainsString('Subcliente:', $html);
        $this->assertStringNotContainsString('Proyecto:', $html);
    }

    public function test_pdf_sin_nombre_libre_no_muestra_cotizaya(): void
    {
        $token = $this->designerToken();
        $draft = $this->draftWithItem($token);
        $this->generate($token, $draft['id'])->assertStatus(200);

        $detail = $this->withHeaders($this->h($token))->getJson("/api/ventas-diseno/cotizaya/{$draft['id']}")->json();
        $html   = view('ventas-diseno.quote-pdf', app(QuotePdfService::class)->viewData($detail, false))->render();

        $this->assertStringNotContainsString('CotizaYA', $html);
    }

    // ── bloqueo del cliente CotizaYA en el flujo normal (A12) ──────────────────────────────────────────────────

    public function test_una_cotizacion_comercial_no_puede_usar_el_cliente_cotizaya(): void
    {
        $settings = CotizaYaSettings::current();
        $token = $this->designerToken();

        $this->withHeaders($this->h($token))->postJson('/api/ventas-diseno/quotes', [
            'master_client_id' => $settings->master_client_id, 'sub_client_id' => $settings->sub_client_id, 'description' => 'x',
        ])->assertStatus(422)->assertJsonValidationErrors(['master_client_id']);

        // y tampoco cambiando el cliente de una comercial existente
        $this->tenant();
        $otro  = MasterClient::create(['name' => 'Otro']);
        $quote = Quote::create(['owner_id' => $this->userId(self::DESIGNER), 'master_client_id' => $otro->id]);
        $this->withHeaders($this->h($token))->putJson("/api/ventas-diseno/quotes/{$quote->id}", ['master_client_id' => $settings->master_client_id])
            ->assertStatus(422);
        $this->tenant();
        $this->assertSame($otro->id, $quote->fresh()->master_client_id);
    }

    public function test_el_guard_bloquea_tarjeta_proyecto_y_subcliente_nuevo_del_flujo_normal(): void
    {
        $settings = CotizaYaSettings::current();
        $owner    = $this->userId(self::DESIGNER);

        $this->assertThrowsValidation(fn () => PipelineCard::create([
            'stage' => 'lead', 'master_client_id' => $settings->master_client_id, 'owner_id' => $owner,
        ]));
        $this->assertThrowsValidation(fn () => SalesProject::create(['sub_client_id' => $settings->sub_client_id, 'name' => 'P']));
        $this->assertThrowsValidation(fn () => SubClient::create([
            'master_client_id' => $settings->master_client_id, 'business_name' => 'Otro sub', 'tax_id' => '9-9-9',
        ]));
    }

    public function test_registros_existentes_con_el_cliente_siguen_funcionando_si_no_cambian_de_cliente(): void
    {
        $settings = CotizaYaSettings::current();
        // histórico: existía antes de que hubiera regla (se inserta saltando el observer, como en prod)
        $id = DB::table((new Quote())->getTable())->insertGetId([
            'origin' => 'comercial', 'owner_id' => $this->userId(self::DESIGNER),
            'master_client_id' => $settings->master_client_id, 'sub_client_id' => $settings->sub_client_id,
            'status' => 'draft', 'price_type' => 'public', 'discount_mode' => 'line',
            'global_discount_percent' => 0, 'discount_totals_type' => 'percent', 'discount_totals_value' => 0,
            'includes_installation' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $quote = Quote::findOrFail($id);
        $quote->update(['description' => 'sigue editable']);

        $this->assertSame('sigue editable', $quote->fresh()->description);
    }

    public function test_los_selectores_del_flujo_normal_no_ofrecen_el_cliente(): void
    {
        $settings = CotizaYaSettings::current();
        $token = $this->designerToken();

        $masters = $this->withHeaders($this->h($token))->getJson('/api/ventas-diseno/master-clients')->assertStatus(200)->json('data');
        $this->assertNotContains($settings->master_client_id, array_column($masters, 'id'));

        $subs = $this->withHeaders($this->h($token))
            ->getJson('/api/ventas-diseno/sub-clients?master_client_id=' . $settings->master_client_id)->assertStatus(200)->json('data');
        $this->assertSame([], $subs);
    }

    public function test_el_guard_no_hace_nada_si_el_entorno_no_tiene_cliente_configurado(): void
    {
        CotizaYaSettings::query()->delete();

        $this->assertNull(app(CotizaYaClientGuard::class)->masterClientId());
        $master = MasterClient::create(['name' => 'Cualquiera']);
        $quote  = Quote::create(['owner_id' => $this->userId(self::DESIGNER), 'master_client_id' => $master->id]);

        $this->assertNotNull($quote->id);
    }

    // ── hallazgos del Senior Review (I-1 a I-4 y huecos de pruebas) ─────────────────────────────────────────────

    public function test_los_contactos_de_una_cotizaya_generada_no_se_pueden_cambiar(): void
    {
        $token = $this->designerToken();
        $contact = SubClient::findOrFail(CotizaYaSettings::current()->sub_client_id)->contacts()->create(['name' => 'C1', 'role' => 'client', 'phone' => '1']);
        $draft = $this->draftWithItem($token);

        $this->withHeaders($this->h($token))->postJson("/api/ventas-diseno/cotizaya/{$draft['id']}/contacts", ['sub_client_contact_id' => $contact->id])
            ->assertSuccessful();
        $this->generate($token, $draft['id'])->assertStatus(200);

        $this->withHeaders($this->h($token))->postJson("/api/ventas-diseno/cotizaya/{$draft['id']}/contacts", ['sub_client_contact_id' => $contact->id])
            ->assertStatus(422);
        $this->withHeaders($this->h($token))->deleteJson("/api/ventas-diseno/cotizaya/{$draft['id']}/contacts/{$contact->id}")
            ->assertStatus(422);
    }

    public function test_otro_vendedor_no_puede_operar_la_cotizaya_ajena_por_ninguna_ruta(): void
    {
        $owner = $this->designerToken();
        $draft = $this->draftWithItem($owner);
        $this->generate($owner, $draft['id'])->assertStatus(200);

        $this->grant(self::OTHER, ['ventas_diseno.read', 'ventas_diseno.write', 'ventas_diseno.edit']);
        $other = $this->loginAs(self::OTHER, 'Password123!', 'supervisor');
        $id = $draft['id'];

        $this->withHeaders($this->h($other))->getJson("/api/ventas-diseno/cotizaya/{$id}")->assertStatus(403);
        $this->withHeaders($this->h($other))->putJson("/api/ventas-diseno/cotizaya/{$id}", ['description' => 'x'])->assertStatus(403);
        $this->withHeaders($this->h($other))->postJson("/api/ventas-diseno/cotizaya/{$id}/generate")->assertStatus(403);
        $this->withHeaders($this->h($other))->getJson("/api/ventas-diseno/cotizaya/{$id}/pdf")->assertStatus(403)
            ->assertJsonPath('message', trans('auth.unauthorized'));
        $this->withHeaders($this->h($other))->postJson("/api/ventas-diseno/cotizaya/{$id}/parts", ['name' => 'x'])->assertStatus(403);
        $this->withHeaders($this->h($other))->postJson("/api/ventas-diseno/cotizaya/{$id}/contacts", ['sub_client_contact_id' => 1])->assertStatus(403);
    }

    public function test_el_pdf_de_cotizaya_no_lo_baja_admin_contab(): void
    {
        $this->felixComoEnScrum1139();
        $owner = $this->designerToken();
        $draft = $this->draftWithItem($owner);
        $this->generate($owner, $draft['id'])->assertStatus(200);

        $token = $this->loginAs('contabilidad@test.com', 'contabilidad@test.com', 'lider_admin_contab');
        $this->withHeaders($this->h($token))->get("/api/ventas-diseno/cotizaya/{$draft['id']}/pdf")->assertStatus(403);
    }

    public function test_el_pdf_de_un_borrador_sin_generar_responde_422(): void
    {
        $token = $this->designerToken();
        $draft = $this->draftWithItem($token);

        $this->withHeaders($this->h($token))->get("/api/ventas-diseno/cotizaya/{$draft['id']}/pdf")->assertStatus(422);
    }

    public function test_generar_con_un_modelo_desactualizado_no_duplica_ni_salta_el_folio(): void
    {
        $token = $this->designerToken();
        $draft = $this->draftWithItem($token);
        $this->tenant();

        // dos "pestañas" que cargaron la cotización antes de que cualquiera generara
        $a = CotizaYaContext::run(fn () => Quote::findOrFail($draft['id']));
        $b = CotizaYaContext::run(fn () => Quote::findOrFail($draft['id']));

        $service = app(CotizaYaService::class);
        $folioA = CotizaYaContext::run(fn () => $service->generate($a))->folio;
        $folioB = CotizaYaContext::run(fn () => $service->generate($b))->folio;

        $this->assertSame($folioA, $folioB);
        $this->assertSame(1, (int) DB::table((new \App\Modules\VentasDiseno\Models\CotizaYaFolioCounter())->getTable())->where('year', (int) date('Y'))->value('last_number'));
    }

    public function test_el_contexto_se_apaga_aunque_el_modulo_falle(): void
    {
        try {
            CotizaYaContext::run(function (): void {
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException) {
            // esperado
        }

        $this->assertFalse(CotizaYaContext::isActive());
    }

    public function test_una_cotizaya_enorme_no_mueve_cotizaciones_reportes_ni_pedidos(): void
    {
        $manager = $this->managerToken();
        $snapshot = function () use ($manager): array {
            $this->tenant();

            return [
                'quotes'  => $this->withHeaders($this->h($manager))->getJson('/api/ventas-diseno/quotes')->assertStatus(200)->json(),
                'reports' => $this->withHeaders($this->h($manager))->getJson('/api/ventas-diseno/reports/summary')->assertStatus(200)->json(),
                'orders'  => $this->withHeaders($this->h($manager))->getJson('/api/ventas-diseno/orders')->assertStatus(200)->json(),
            ];
        };

        $before = $snapshot();

        $draft = $this->draftWithItem($manager, [], 250000.0, 4.0);
        $this->generate($manager, $draft['id'])->assertStatus(200);

        $this->assertEquals($before, $snapshot());
    }

    public function test_el_seeder_reutiliza_el_cliente_cotizaya_que_ya_existe_en_produccion(): void
    {
        // camino de prod: el cliente se creó a mano (otro uso de mayúsculas) y todavía no hay fila de ajustes
        CotizaYaSettings::query()->delete();
        $existing = MasterClient::query()->whereRaw('lower(name) = ?', ['cotización'])->firstOrFail();
        $existing->forceFill(['name' => 'COTIZAYA'])->save();
        $sub = SubClient::query()->where('master_client_id', $existing->id)->firstOrFail();
        $masters = MasterClient::count();
        $subs = SubClient::count();

        $settings = CotizaYaClientSeeder::ensureClient();

        $this->assertSame($existing->id, $settings->master_client_id);
        $this->assertSame($sub->id, $settings->sub_client_id);
        $this->assertSame($masters, MasterClient::count());
        $this->assertSame($subs, SubClient::count());
        $this->assertSame('COTIZAYA', MasterClient::findOrFail($existing->id)->name); // no se modifica
    }

    public function test_sin_esquema_migrado_el_filtro_y_el_observer_se_apagan_solos(): void
    {
        $slug = (string) Tenant::current()?->slug;
        $ready = new \ReflectionProperty(CotizaYaSchema::class, 'ready');
        $checked = new \ReflectionProperty(CotizaYaSchema::class, 'checkedAt');

        try {
            // simula la ventana de despliegue: código nuevo, migración todavía sin correr
            $ready->setValue(null, []);
            $checked->setValue(null, [$slug => time()]);

            $this->assertFalse(CotizaYaSchema::ready());
            $this->assertStringNotContainsString('origin', Quote::query()->toSql());

            $settings = CotizaYaSettings::current();
            $quote = Quote::create(['owner_id' => $this->userId(self::DESIGNER), 'master_client_id' => $settings->master_client_id]);
            $this->assertNotNull($quote->id); // el observer tampoco bloquea mientras el esquema no está listo
        } finally {
            CotizaYaSchema::forget();
        }

        $this->assertTrue(CotizaYaSchema::ready());
        $this->assertStringContainsString('origin', Quote::query()->toSql());
    }

    public function test_el_guard_tambien_bloquea_reasignar_un_subcliente_existente_al_cliente_cotizaya(): void
    {
        $otro = MasterClient::create(['name' => 'Otro Maestro']);
        $sub  = SubClient::create(['master_client_id' => $otro->id, 'business_name' => 'Sub X', 'tax_id' => '5-5-5']);

        $this->assertThrowsValidation(function () use ($sub): void {
            $sub->update(['master_client_id' => CotizaYaSettings::current()->master_client_id]);
        });
    }

    private function assertThrowsValidation(callable $callback): void
    {
        try {
            $callback();
        } catch (ValidationException $e) {
            $this->assertSame([CotizaYaClientGuard::MESSAGE], array_values($e->errors())[0]);

            return;
        }

        $this->fail('Se esperaba ValidationException por el cliente CotizaYA.');
    }

    // ── migración de nombres (folio COTY- y cliente «Cotización») ──────────────────────────────────────────────

    public function test_la_migracion_renombra_folios_y_cliente_y_es_reversible(): void
    {
        $token = $this->designerToken();
        $draft = $this->draftWithItem($token);
        $this->generate($token, $draft['id'])->assertStatus(200);

        $this->tenant(); // el request HTTP deja el tenant en blanco
        $settings = CotizaYaSettings::current();
        $quote    = CotizaYaContext::run(fn () => Quote::findOrFail($draft['id']));
        DB::table((new Quote())->getTable())->where('id', $quote->id)->update(['folio' => 'CotizaYA-2026-0007']);
        MasterClient::whereKey($settings->master_client_id)->update(['name' => 'CotizaYA']);
        SubClient::whereKey($settings->sub_client_id)->update(['business_name' => 'CotizaYA']);

        $migration = require database_path('migrations/tenant/2026_10_09_100000_rename_cotizaya_folios_prefix_to_coty.php');
        $migration->up();

        $this->assertSame('COTY-2026-0007', DB::table((new Quote())->getTable())->where('id', $quote->id)->value('folio'));
        $this->assertSame('Cotización', MasterClient::findOrFail($settings->master_client_id)->name);
        $this->assertSame('Cotización', SubClient::findOrFail($settings->sub_client_id)->business_name);

        $migration->down();

        $this->assertSame('CotizaYA-2026-0007', DB::table((new Quote())->getTable())->where('id', $quote->id)->value('folio'));
        $this->assertSame('CotizaYA', MasterClient::findOrFail($settings->master_client_id)->name);
    }
}
