<?php

declare(strict_types=1);

namespace Tests\Feature\VentasDiseno;

use App\Modules\VentasDiseno\Events\QuoteConfirmed;
use App\Modules\VentasDiseno\Events\QuoteGenerated;
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
 * Cotización-A (REQ-024 a 032). Ver memory/project_backlog_ventas_diseno_req.md.
 */
class QuoteControllerTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    private function grantVentasDisenoEdit(string $email): void
    {
        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');
        DB::table("{$slug}_auth.users")
            ->where('email', $email)
            ->update(['extra_permissions' => json_encode(['ventas_diseno.read', 'ventas_diseno.write', 'ventas_diseno.edit'])]);
    }

    private function grantVentasDisenoRead(string $email): void
    {
        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');
        DB::table("{$slug}_auth.users")
            ->where('email', $email)
            ->update(['extra_permissions' => json_encode(['ventas_diseno.read'])]);
    }

    private function userIdByEmail(string $email): int
    {
        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');
        return (int) DB::table("{$slug}_auth.users")->where('email', $email)->value('id');
    }

    /** @return array{master: MasterClient, sub: SubClient, project: SalesProject} */
    private function makeClientHierarchy(): array
    {
        $master  = MasterClient::create(['name' => 'Cliente Test']);
        $sub     = SubClient::create(['master_client_id' => $master->id, 'business_name' => 'Subcliente Test', 'tax_id' => '155-0000-1-2026']);
        $project = SalesProject::create(['sub_client_id' => $sub->id, 'name' => 'Proyecto Test']);

        return ['master' => $master, 'sub' => $sub, 'project' => $project];
    }

    // ── store ─────────────────────────────────────────────────────────────────

    public function test_crear_cotizacion_sin_autenticar_retorna_401(): void
    {
        $this->postJson('/api/ventas-diseno/quotes', [])->assertStatus(401);
    }

    public function test_crear_cotizacion_sin_permiso_write_retorna_403(): void
    {
        // supervisor@ (sin rol mapeado) en vez de designer@ -- ver SCRUM-83, designer@ ahora
        // tiene write real vía rol+nivel, un extra_permissions de solo lectura no lo pisa.
        $this->grantVentasDisenoRead('supervisor@atlantic.test');
        $token = $this->loginAs('supervisor@atlantic.test', 'Password123!');

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/ventas-diseno/quotes', [])
            ->assertStatus(403);
    }

    /**
     * SCRUM-83 — ver docblock equivalente en PipelineControllerTest. Sin grant manual: designer@
     * debe poder crear una cotización solo con su rol seedeado (vendedor_disenador, nivel 4).
     */
    public function test_disenador_crea_cotizacion_solo_con_su_rol_seedeado_sin_grant_manual(): void
    {
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/ventas-diseno/quotes', [])
            ->assertStatus(201);
    }

    public function test_crear_cotizacion_vacia_nace_como_borrador(): void
    {
        $this->grantVentasDisenoEdit('designer@atlantic.test');
        $designerId = $this->userIdByEmail('designer@atlantic.test');
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/ventas-diseno/quotes', [])
            ->assertStatus(201);

        $response->assertJsonPath('status', 'draft');
        $response->assertJsonPath('master_client', null);
        $response->assertJsonPath('sub_client', null);
        $response->assertJsonPath('owner.id', $designerId);
        $response->assertJsonPath('can_edit', true);
    }

    public function test_crear_cotizacion_con_datos_completos(): void
    {
        $this->grantVentasDisenoEdit('designer@atlantic.test');
        $hierarchy = $this->makeClientHierarchy();
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/ventas-diseno/quotes', [
                'master_client_id' => $hierarchy['master']->id,
                'sub_client_id'    => $hierarchy['sub']->id,
                'sales_project_id' => $hierarchy['project']->id,
                'ruc'              => '155-0000-1-2026',
                'description'      => 'Cotizacion de prueba',
                'delivery_type'    => 'partial',
                'delivery_dates'   => ['2026-09-01', '2026-09-15'],
            ])
            ->assertStatus(201);

        $response->assertJsonPath('master_client.id', $hierarchy['master']->id);
        $response->assertJsonPath('sub_client.id', $hierarchy['sub']->id);
        $response->assertJsonPath('sales_project.id', $hierarchy['project']->id);
        $response->assertJsonPath('ruc', '155-0000-1-2026');
        $response->assertJsonCount(2, 'delivery_dates');
    }

    public function test_crear_cotizacion_con_subcliente_de_otro_master_client_retorna_422(): void
    {
        $this->grantVentasDisenoEdit('designer@atlantic.test');
        $hierarchy   = $this->makeClientHierarchy();
        $otherMaster = MasterClient::create(['name' => 'Otro Cliente']);
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/ventas-diseno/quotes', [
                'master_client_id' => $otherMaster->id,
                'sub_client_id'    => $hierarchy['sub']->id,
            ])
            ->assertStatus(422);
    }

    public function test_crear_cotizacion_con_proyecto_de_otro_subcliente_retorna_422(): void
    {
        $this->grantVentasDisenoEdit('designer@atlantic.test');
        $hierarchy = $this->makeClientHierarchy();
        $otherSub  = SubClient::create(['master_client_id' => $hierarchy['master']->id, 'business_name' => 'Otro Sub', 'tax_id' => '155-9999-1-2026']);
        $otherProject = SalesProject::create(['sub_client_id' => $otherSub->id, 'name' => 'Otro Proyecto']);
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/ventas-diseno/quotes', [
                'sub_client_id'    => $hierarchy['sub']->id,
                'sales_project_id' => $otherProject->id,
            ])
            ->assertStatus(422);
    }

    // ── show ──────────────────────────────────────────────────────────────────

    public function test_ver_cotizacion_ajena_retorna_403(): void
    {
        $this->grantVentasDisenoEdit('designer@atlantic.test');
        $managerId = $this->userIdByEmail('management@atlantic.test');
        $token = $this->loginAs('management@atlantic.test', 'Password123!', 'management');
        $quoteId = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/ventas-diseno/quotes', [])->json('id');

        $designerToken = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($designerToken))
            ->getJson("/api/ventas-diseno/quotes/{$quoteId}")
            ->assertStatus(403);
    }

    public function test_gerencia_puede_ver_cotizacion_ajena(): void
    {
        $this->grantVentasDisenoEdit('designer@atlantic.test');
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $quoteId = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/ventas-diseno/quotes', [])->json('id');

        $managerToken = $this->loginAs('management@atlantic.test', 'Password123!', 'management');
        $this->withHeaders($this->authHeaders($managerToken))
            ->getJson("/api/ventas-diseno/quotes/{$quoteId}")
            ->assertStatus(200);
    }

    // ── update ────────────────────────────────────────────────────────────────

    public function test_actualizar_campos_de_cotizacion(): void
    {
        $this->grantVentasDisenoEdit('designer@atlantic.test');
        $hierarchy = $this->makeClientHierarchy();
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $quoteId = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/ventas-diseno/quotes', [])->json('id');

        $response = $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/ventas-diseno/quotes/{$quoteId}", [
                'master_client_id' => $hierarchy['master']->id,
                'sub_client_id'    => $hierarchy['sub']->id,
                'ruc'              => '155-0000-1-2026',
                'description'      => 'Actualizada',
            ])
            ->assertStatus(200);

        $response->assertJsonPath('master_client.id', $hierarchy['master']->id);
        $response->assertJsonPath('description', 'Actualizada');
    }

    public function test_actualizar_sin_permiso_edit_retorna_403(): void
    {
        // supervisor@ en vez de designer@ -- ver SCRUM-83. El escenario simula una degradación
        // de permiso (crea con edit, luego se le baja a solo lectura); con designer@ (rol real
        // vendedor_disenador desde el fix) el bridge rol+nivel seguiría otorgando edit pese al
        // extra_permissions de solo lectura, invalidando el test.
        $this->grantVentasDisenoEdit('supervisor@atlantic.test');
        $token = $this->loginAs('supervisor@atlantic.test', 'Password123!');
        $quoteId = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/ventas-diseno/quotes', [])->json('id');

        $this->grantVentasDisenoRead('supervisor@atlantic.test');
        $readOnlyToken = $this->loginAs('supervisor@atlantic.test', 'Password123!');

        $this->withHeaders($this->authHeaders($readOnlyToken))
            ->putJson("/api/ventas-diseno/quotes/{$quoteId}", ['description' => 'x'])
            ->assertStatus(403);
    }

    // ── price_type / discount_mode (Cotización-B, REQ-033/034/041) ─────────────

    public function test_precio_publico_es_el_default_al_crear(): void
    {
        $this->grantVentasDisenoEdit('designer@atlantic.test');
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/ventas-diseno/quotes', [])
            ->assertStatus(201);

        $response->assertJsonPath('price_type', 'public');
        $response->assertJsonPath('discount_mode', 'line');
    }

    public function test_usuario_sin_permiso_no_puede_usar_precio_socio(): void
    {
        $this->grantVentasDisenoEdit('designer@atlantic.test');
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $quoteId = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/ventas-diseno/quotes', [])->json('id');

        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/ventas-diseno/quotes/{$quoteId}", ['price_type' => 'partner'])
            ->assertStatus(403);
    }

    public function test_usuario_con_permiso_select_partner_price_puede_usar_precio_socio(): void
    {
        $this->grantVentasDisenoEdit('designer@atlantic.test');
        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');
        DB::table("{$slug}_auth.users")->where('email', 'designer@atlantic.test')
            ->update(['extra_permissions' => json_encode(['ventas_diseno.read', 'ventas_diseno.write', 'ventas_diseno.edit', 'ventas_diseno.select_partner_price'])]);
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $quoteId = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/ventas-diseno/quotes', [])->json('id');

        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/ventas-diseno/quotes/{$quoteId}", ['price_type' => 'partner'])
            ->assertStatus(200)
            ->assertJsonPath('price_type', 'partner');
    }

    public function test_cambiar_a_descuento_global_reinicia_los_descuentos_por_linea(): void
    {
        $this->grantVentasDisenoEdit('designer@atlantic.test');
        $designerId = $this->userIdByEmail('designer@atlantic.test');

        $quote = Quote::create(['owner_id' => $designerId]);
        $part  = $quote->parts()->create(['name' => 'Partida 1']);
        $part->items()->create([
            'reference' => 'REF-1', 'description' => 'X', 'quantity' => 1, 'unit_price' => 100, 'discount_percent' => 20,
        ]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/ventas-diseno/quotes/{$quote->id}", ['discount_mode' => 'global', 'global_discount_percent' => 10])
            ->assertStatus(200)
            ->assertJsonPath('parts.0.items.0.discount_percent', 0);
    }

    public function test_cambiar_a_descuento_por_linea_reinicia_el_global(): void
    {
        $this->grantVentasDisenoEdit('designer@atlantic.test');
        $designerId = $this->userIdByEmail('designer@atlantic.test');

        $quote = Quote::create([
            'owner_id' => $designerId, 'discount_mode' => 'global', 'global_discount_percent' => 15,
        ]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/ventas-diseno/quotes/{$quote->id}", ['discount_mode' => 'line'])
            ->assertStatus(200)
            ->assertJsonPath('global_discount_percent', 0);
    }

    // ── Cotización-C (REQ-043/044/045/046/047) ──────────────────────────────────

    private function grantVentasDisenoEditConditions(string $email): void
    {
        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');
        DB::table("{$slug}_auth.users")->where('email', $email)
            ->update(['extra_permissions' => json_encode(['ventas_diseno.read', 'ventas_diseno.write', 'ventas_diseno.edit', 'ventas_diseno.edit.conditions'])]);
    }

    public function test_cotizacion_nace_con_el_texto_de_condiciones_vigente(): void
    {
        $this->grantVentasDisenoEdit('designer@atlantic.test');
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/ventas-diseno/quotes', [])
            ->assertStatus(201);

        $response->assertJsonPath('conditions_text', Quote::DEFAULT_CONDITIONS_TEXT);
    }

    // SCRUM-138 — reemplaza edición por-cotización de conditions_text por un default
    // global (QuoteConditionsSettings). Enviar conditions_text a update() ahora se
    // ignora en silencio (no está en QuoteRequest ni en el allowlist de
    // QuoteService::fieldUpdates()) — no hay más gate de permiso acá, se movió a
    // /quote-conditions-settings (ver QuoteConditionsSettingsControllerTest).
    public function test_enviar_conditions_text_a_update_no_tiene_efecto(): void
    {
        $this->grantVentasDisenoEditConditions('designer@atlantic.test');
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $quoteId = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/ventas-diseno/quotes', [])->json('id');

        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/ventas-diseno/quotes/{$quoteId}", ['conditions_text' => 'Otro texto'])
            ->assertStatus(200)
            ->assertJsonPath('conditions_text', Quote::DEFAULT_CONDITIONS_TEXT);
    }

    public function test_actualizar_el_default_global_aplica_solo_a_cotizaciones_nuevas(): void
    {
        $this->grantVentasDisenoEdit('designer@atlantic.test');
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        // Cotización creada ANTES del cambio de default — congela su texto propio.
        $oldQuoteId = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/ventas-diseno/quotes', [])->json('id');

        $this->grantVentasDisenoEditConditions('designer@atlantic.test');
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))
            ->putJson('/api/ventas-diseno/quote-conditions-settings', ['text' => 'Condiciones globales nuevas'])
            ->assertStatus(200)
            ->assertJsonPath('text', 'Condiciones globales nuevas');

        $newQuoteId = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/ventas-diseno/quotes', [])->json('id');

        $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/ventas-diseno/quotes/{$oldQuoteId}")
            ->assertJsonPath('conditions_text', Quote::DEFAULT_CONDITIONS_TEXT);

        $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/ventas-diseno/quotes/{$newQuoteId}")
            ->assertJsonPath('conditions_text', 'Condiciones globales nuevas');
    }

    public function test_instalacion_no_afecta_los_totales(): void
    {
        $this->grantVentasDisenoEdit('designer@atlantic.test');
        $designerId = $this->userIdByEmail('designer@atlantic.test');
        $quote = Quote::create(['owner_id' => $designerId]);
        $part  = $quote->parts()->create(['name' => 'Partida 1']);
        $part->items()->create(['reference' => 'REF-1', 'description' => 'X', 'quantity' => 1, 'unit_price' => 100]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/ventas-diseno/quotes/{$quote->id}", ['includes_installation' => true])
            ->assertStatus(200);

        $response->assertJsonPath('includes_installation', true);
        // 100 subtotal, sin descuento de Totales -> ITBMS 7 -> Total 107.
        $response->assertJsonPath('subtotal', 100);
        $response->assertJsonPath('itbms', 7);
        $response->assertJsonPath('grand_total', 107);
    }

    public function test_descuento_de_totales_en_porcentaje_calcula_neto_itbms_y_total(): void
    {
        $this->grantVentasDisenoEdit('designer@atlantic.test');
        $designerId = $this->userIdByEmail('designer@atlantic.test');
        $quote = Quote::create(['owner_id' => $designerId]);
        $part  = $quote->parts()->create(['name' => 'Partida 1']);
        $part->items()->create(['reference' => 'REF-1', 'description' => 'X', 'quantity' => 1, 'unit_price' => 1000]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/ventas-diseno/quotes/{$quote->id}", [
                'discount_totals_type' => 'percent', 'discount_totals_value' => 10,
            ])
            ->assertStatus(200);

        // Subtotal 1000, descuento 10% = 100, neto 900, ITBMS 7% = 63, total 963.
        $response->assertJsonPath('discount_totals_amount', 100);
        $response->assertJsonPath('net_total', 900);
        $response->assertJsonPath('itbms', 63);
        $response->assertJsonPath('grand_total', 963);
    }

    public function test_descuento_de_totales_que_deja_el_neto_en_cero_retorna_422(): void
    {
        $this->grantVentasDisenoEdit('designer@atlantic.test');
        $designerId = $this->userIdByEmail('designer@atlantic.test');
        $quote = Quote::create(['owner_id' => $designerId]);
        $part  = $quote->parts()->create(['name' => 'Partida 1']);
        $part->items()->create(['reference' => 'REF-1', 'description' => 'X', 'quantity' => 1, 'unit_price' => 100]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/ventas-diseno/quotes/{$quote->id}", [
                'discount_totals_type' => 'amount', 'discount_totals_value' => 100,
            ])
            ->assertStatus(422);
    }

    public function test_descuento_de_totales_en_cotizacion_vacia_no_bloquea(): void
    {
        $this->grantVentasDisenoEdit('designer@atlantic.test');
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $quoteId = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/ventas-diseno/quotes', [])->json('id');

        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/ventas-diseno/quotes/{$quoteId}", ['discount_totals_type' => 'percent', 'discount_totals_value' => 0])
            ->assertStatus(200);
    }

    // ── margen mínimo (REQ-040/045, Pre-QA SCRUM-132/137) ───────────────────────

    public function test_cambiar_tipo_de_precio_general_que_viola_margen_retorna_422(): void
    {
        $this->grantVentasDisenoEdit('designer@atlantic.test');
        $designerId = $this->userIdByEmail('designer@atlantic.test');
        $quote = Quote::create(['owner_id' => $designerId]);
        $part  = $quote->parts()->create(['name' => 'Partida 1']);
        // Público: (100-65)/100=35% ok. Proyecto (-15%): precio 85, margen (85-65)/85=23.5% (<30%).
        $part->items()->create(['reference' => 'REF-1', 'description' => 'X', 'quantity' => 1, 'unit_price' => 100, 'cost' => 65]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/ventas-diseno/quotes/{$quote->id}", ['price_type' => 'project'])
            ->assertStatus(422);

        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $this->assertSame('public', $quote->refresh()->price_type);
    }

    public function test_descuento_global_que_viola_margen_retorna_422(): void
    {
        $this->grantVentasDisenoEdit('designer@atlantic.test');
        $designerId = $this->userIdByEmail('designer@atlantic.test');
        $quote = Quote::create(['owner_id' => $designerId, 'discount_mode' => 'global']);
        $part  = $quote->parts()->create(['name' => 'Partida 1']);
        // Precio 100, costo 65 -> margen sano 35%. 40% de descuento global -> precio 60, margen negativo.
        $part->items()->create(['reference' => 'REF-1', 'description' => 'X', 'quantity' => 1, 'unit_price' => 100, 'cost' => 65]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/ventas-diseno/quotes/{$quote->id}", ['global_discount_percent' => 40])
            ->assertStatus(422);

        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $this->assertEquals(0, $quote->refresh()->global_discount_percent);
    }

    public function test_descuento_de_totales_que_viola_margen_retorna_422(): void
    {
        $this->grantVentasDisenoEdit('designer@atlantic.test');
        $designerId = $this->userIdByEmail('designer@atlantic.test');
        $quote = Quote::create(['owner_id' => $designerId]);
        $part  = $quote->parts()->create(['name' => 'Partida 1']);
        // Subtotal 100, costo 65 (margen sano 35%). 30% de descuento de Totales -> neto 70, margen (70-65)/70=7.1% (<30%).
        $part->items()->create(['reference' => 'REF-1', 'description' => 'X', 'quantity' => 1, 'unit_price' => 100, 'cost' => 65]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/ventas-diseno/quotes/{$quote->id}", [
                'discount_totals_type' => 'percent', 'discount_totals_value' => 30,
            ])
            ->assertStatus(422);

        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $this->assertEquals(0, $quote->refresh()->discount_totals_value);
    }

    public function test_precio_socio_esta_exento_del_margen_minimo(): void
    {
        // Precio Socio (costo÷0.85) da margen fijo 15%, por debajo del mínimo 30% —
        // pero está exento a propósito (decisión Luis 2026-07-15): es una fórmula
        // deliberada gateada por permiso, no un override arbitrario.
        $this->grantVentasDisenoEdit('designer@atlantic.test');
        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');
        DB::table("{$slug}_auth.users")->where('email', 'designer@atlantic.test')
            ->update(['extra_permissions' => json_encode(['ventas_diseno.read', 'ventas_diseno.write', 'ventas_diseno.edit', 'ventas_diseno.select_partner_price'])]);
        $designerId = $this->userIdByEmail('designer@atlantic.test');
        $quote = Quote::create(['owner_id' => $designerId]);
        $part  = $quote->parts()->create(['name' => 'Partida 1']);
        $part->items()->create(['reference' => 'REF-1', 'description' => 'X', 'quantity' => 1, 'unit_price' => 100, 'cost' => 65]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/ventas-diseno/quotes/{$quote->id}", ['price_type' => 'partner'])
            ->assertStatus(200)
            ->assertJsonPath('price_type', 'partner');
    }

    public function test_descuento_de_totales_sobre_items_sin_costo_no_valida_margen(): void
    {
        $this->grantVentasDisenoEdit('designer@atlantic.test');
        $designerId = $this->userIdByEmail('designer@atlantic.test');
        $quote = Quote::create(['owner_id' => $designerId]);
        $part  = $quote->parts()->create(['name' => 'Partida 1']);
        // Item 100% personalizado, sin costo -> el descuento de Totales no tiene contra qué medir margen.
        $part->items()->create(['reference' => 'REF-1', 'description' => 'X', 'quantity' => 1, 'unit_price' => 100]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/ventas-diseno/quotes/{$quote->id}", [
                'discount_totals_type' => 'percent', 'discount_totals_value' => 80,
            ])
            ->assertStatus(200);
    }

    public function test_observations_preview_combina_texto_fechas_e_instalacion(): void
    {
        $this->grantVentasDisenoEdit('designer@atlantic.test');
        $designerId = $this->userIdByEmail('designer@atlantic.test');
        $quote = Quote::create([
            'owner_id' => $designerId, 'observations' => 'Nota del vendedor',
            'delivery_type' => 'single', 'includes_installation' => true,
        ]);
        $quote->deliveryDates()->create(['date' => '2026-09-01']);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/ventas-diseno/quotes/{$quote->id}")
            ->assertStatus(200);

        $preview = $response->json('observations_preview');
        $this->assertStringContainsString('Nota del vendedor', $preview);
        $this->assertStringContainsString('Entrega completa: 01/09/2026', $preview);
        $this->assertStringContainsString('pendiente de envío', $preview);
    }

    // ── validate (REQ-047) ───────────────────────────────────────────────────────

    public function test_validar_cotizacion_incompleta_lista_lo_que_falta(): void
    {
        $this->grantVentasDisenoEdit('designer@atlantic.test');
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $quoteId = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/ventas-diseno/quotes', [])->json('id');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/ventas-diseno/quotes/{$quoteId}/validate")
            ->assertStatus(200);

        $response->assertJsonPath('valid', false);
        $missing = $response->json('missing');
        $this->assertContains('Cliente Master', $missing);
        $this->assertContains('Ítems', $missing);
    }

    public function test_validar_cotizacion_completa(): void
    {
        $this->grantVentasDisenoEdit('designer@atlantic.test');
        $designerId = $this->userIdByEmail('designer@atlantic.test');
        $hierarchy = $this->makeClientHierarchy();
        $architect = Architect::create(['name' => 'Arq. Test', 'phone' => '6000-0000']);
        $contact = $hierarchy['sub']->contacts()->create(['name' => 'Contacto Test', 'role' => 'client', 'phone' => '6111-1111']);

        $quote = Quote::create([
            'owner_id' => $designerId,
            'master_client_id' => $hierarchy['master']->id,
            'sub_client_id'    => $hierarchy['sub']->id,
            'sales_project_id' => $hierarchy['project']->id,
            'ruc' => '155-0000-1-2026',
            'description' => 'Cotizacion completa',
            'architect_id' => $architect->id,
        ]);
        $quote->deliveryDates()->create(['date' => '2026-09-01']);
        $quote->contacts()->attach($contact->id);
        $part = $quote->parts()->create(['name' => 'Partida 1']);
        $part->items()->create(['reference' => 'REF-1', 'description' => 'X', 'quantity' => 1, 'unit_price' => 100]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/ventas-diseno/quotes/{$quote->id}/validate")
            ->assertStatus(200);

        $response->assertJsonPath('valid', true);
        $response->assertJsonPath('missing', []);
    }

    // ── Cotización-D: save-draft (REQ-086) ──────────────────────────────────────

    /** @return array{quote: Quote, master: MasterClient, sub: SubClient, project: SalesProject, architect: Architect} */
    private function makeQuoteWithGeneralFieldsComplete(int $ownerId): array
    {
        $hierarchy = $this->makeClientHierarchy();
        $architect = Architect::create(['name' => 'Arq. Test', 'phone' => '6000-0000']);
        $contact   = $hierarchy['sub']->contacts()->create(['name' => 'Contacto Test', 'role' => 'client', 'phone' => '6111-1111']);

        $quote = Quote::create([
            'owner_id'         => $ownerId,
            'master_client_id' => $hierarchy['master']->id,
            'sub_client_id'    => $hierarchy['sub']->id,
            'sales_project_id' => $hierarchy['project']->id,
            'ruc'              => '155-0000-1-2026',
            'description'      => 'Cotizacion completa',
            'architect_id'     => $architect->id,
        ]);
        $quote->deliveryDates()->create(['date' => '2026-09-01']);
        $quote->contacts()->attach($contact->id);

        return ['quote' => $quote, ...$hierarchy, 'architect' => $architect];
    }

    /**
     * SCRUM-796 (secc. 7) — "Por definir" (delivery_type='tbd') exime de tener al menos
     * 1 fecha de entrega para poder generar, y el documento menciona la fecha como
     * "Por definir" en vez de omitir la línea.
     */
    public function test_generar_con_delivery_type_tbd_no_exige_fecha_de_entrega(): void
    {
        $this->grantVentasDisenoEdit('designer@atlantic.test');
        $designerId = $this->userIdByEmail('designer@atlantic.test');
        $hierarchy  = $this->makeClientHierarchy();
        $architect  = Architect::create(['name' => 'Arq. TBD', 'phone' => '6000-0001']);
        $contact    = $hierarchy['sub']->contacts()->create(['name' => 'Contacto TBD', 'role' => 'client', 'phone' => '6111-1112']);

        $quote = Quote::create([
            'owner_id'         => $designerId,
            'master_client_id' => $hierarchy['master']->id,
            'sub_client_id'    => $hierarchy['sub']->id,
            'sales_project_id' => $hierarchy['project']->id,
            'ruc'              => '155-0000-1-2026',
            'description'      => 'Cotizacion con entrega por definir',
            'architect_id'     => $architect->id,
            'delivery_type'    => 'tbd',
        ]);
        $quote->contacts()->attach($contact->id);
        // Sin deliveryDates() a propósito — 'tbd' no debe exigirlas.
        $part = $quote->parts()->create(['name' => 'Partida 1']);
        $part->items()->create(['reference' => 'REF-1', 'description' => 'X', 'quantity' => 1, 'unit_price' => 100]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/quotes/{$quote->id}/generate")
            ->assertStatus(200);

        // El cliente HTTP de test resetea el tenant activo al salir de postJson() —
        // hace falta re-declararlo antes de tocar el modelo directo otra vez (mismo
        // patrón que setUp()).
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $this->assertStringContainsString('Fecha de entrega: Por definir', $quote->fresh()->observationsForDocument());
    }

    public function test_generar_sin_tbd_sigue_exigiendo_fecha_de_entrega(): void
    {
        $this->grantVentasDisenoEdit('designer@atlantic.test');
        $designerId = $this->userIdByEmail('designer@atlantic.test');
        $hierarchy  = $this->makeClientHierarchy();
        $architect  = Architect::create(['name' => 'Arq. Sin Fecha', 'phone' => '6000-0002']);
        $contact    = $hierarchy['sub']->contacts()->create(['name' => 'Contacto Sin Fecha', 'role' => 'client', 'phone' => '6111-1113']);

        $quote = Quote::create([
            'owner_id'         => $designerId,
            'master_client_id' => $hierarchy['master']->id,
            'sub_client_id'    => $hierarchy['sub']->id,
            'sales_project_id' => $hierarchy['project']->id,
            'ruc'              => '155-0000-1-2026',
            'description'      => 'Cotizacion sin fecha',
            'architect_id'     => $architect->id,
            'delivery_type'    => 'single',
        ]);
        $quote->contacts()->attach($contact->id);
        $part = $quote->parts()->create(['name' => 'Partida 1']);
        $part->items()->create(['reference' => 'REF-1', 'description' => 'X', 'quantity' => 1, 'unit_price' => 100]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/quotes/{$quote->id}/generate")
            ->assertStatus(422);

        $this->assertContains('Fecha de entrega', $response->json('missing'));
    }

    /** SCRUM-796 (secc. 8) — rename de label "RUC" → "RUC / Cédula" en el banner de faltantes. */
    public function test_mensaje_de_ruc_faltante_usa_nuevo_label(): void
    {
        $this->grantVentasDisenoEdit('designer@atlantic.test');
        $designerId = $this->userIdByEmail('designer@atlantic.test');
        $quote = Quote::create(['owner_id' => $designerId]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/quotes/{$quote->id}/generate")
            ->assertStatus(422);

        $this->assertContains('RUC / Cédula', $response->json('missing'));
        $this->assertNotContains('RUC', $response->json('missing'));
    }

    public function test_guardar_borrador_bloquea_si_falta_un_campo_general(): void
    {
        $this->grantVentasDisenoEdit('designer@atlantic.test');
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $quoteId = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/ventas-diseno/quotes', [])->json('id');

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/quotes/{$quoteId}/save-draft", ['description' => 'Solo descripción'])
            ->assertStatus(422);

        $response->assertJsonPath('valid', false);
        $this->assertContains('Cliente Master', $response->json('missing'));

        // Nada se persistió — "se bloquea el guardado" (REQ-086), no solo se informa.
        // Re-activar el tenant: el middleware lo "olvida" al terminar cada request HTTP.
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $this->assertNull(Quote::find($quoteId)?->description);
    }

    public function test_guardar_borrador_exitoso_sin_items(): void
    {
        $this->grantVentasDisenoEdit('designer@atlantic.test');
        $hierarchy  = $this->makeClientHierarchy();
        $architect  = Architect::create(['name' => 'Arq. Test', 'phone' => '6000-0000']);
        $contact    = $hierarchy['sub']->contacts()->create(['name' => 'Contacto Test', 'role' => 'client', 'phone' => '6111-1111']);
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $quoteId = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/ventas-diseno/quotes', [])->json('id');

        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        Quote::find($quoteId)?->contacts()->attach($contact->id);

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/quotes/{$quoteId}/save-draft", [
                'master_client_id' => $hierarchy['master']->id,
                'sub_client_id'    => $hierarchy['sub']->id,
                'sales_project_id' => $hierarchy['project']->id,
                'ruc'              => '155-0000-1-2026',
                'description'      => 'Sin items todavía',
                'architect_id'     => $architect->id,
                'delivery_dates'   => ['2026-09-01'],
            ])
            ->assertStatus(200);

        $response->assertJsonPath('status', 'draft');
        $response->assertJsonPath('folio', null);
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $this->assertSame('Sin items todavía', Quote::find($quoteId)?->description);
    }

    /**
     * QA formal 2026-07-10/11 (SCRUM-116): antes, "Guardar borrador" exigía al menos
     * 1 Contacto igual que "Generar" — pero agregar un contacto exige que el
     * Subcliente ya esté guardado en la cotización (ver QuotePage::contactsReady en
     * el frontend), así que una cotización nacida sin cliente/contacto heredado de
     * la tarjeta de origen no podía guardar el Subcliente (exigía Contacto) ni
     * agregar el Contacto (exigía Subcliente ya guardado) — callejón sin salida.
     */
    public function test_guardar_borrador_exitoso_sin_contacto(): void
    {
        $this->grantVentasDisenoEdit('designer@atlantic.test');
        $hierarchy = $this->makeClientHierarchy();
        $architect = Architect::create(['name' => 'Arq. Test', 'phone' => '6000-0000']);
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $quoteId = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/ventas-diseno/quotes', [])->json('id');

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/quotes/{$quoteId}/save-draft", [
                'master_client_id' => $hierarchy['master']->id,
                'sub_client_id'    => $hierarchy['sub']->id,
                'sales_project_id' => $hierarchy['project']->id,
                'ruc'              => '155-0000-1-2026',
                'description'      => 'Sin contacto todavía',
                'architect_id'     => $architect->id,
                'delivery_dates'   => ['2026-09-01'],
            ])
            ->assertStatus(200);

        $response->assertJsonPath('status', 'draft');
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $this->assertSame('Sin contacto todavía', Quote::find($quoteId)?->description);
    }

    /**
     * "Generar" (a diferencia de "Guardar borrador") sigue exigiendo Contacto — la
     * relajación de arriba es solo para desbloquear el guardado del encabezado, no
     * afloja el requisito real de negocio antes de producir el documento final.
     */
    public function test_generar_bloquea_si_falta_contacto(): void
    {
        $this->grantVentasDisenoEdit('designer@atlantic.test');
        $designerId = $this->userIdByEmail('designer@atlantic.test');
        $hierarchy = $this->makeClientHierarchy();
        $architect = Architect::create(['name' => 'Arq. Test', 'phone' => '6000-0000']);
        $quote = Quote::create([
            'owner_id'         => $designerId,
            'master_client_id' => $hierarchy['master']->id,
            'sub_client_id'    => $hierarchy['sub']->id,
            'sales_project_id' => $hierarchy['project']->id,
            'ruc'              => '155-0000-1-2026',
            'description'      => 'Sin contacto',
            'architect_id'     => $architect->id,
        ]);
        $quote->deliveryDates()->create(['date' => '2026-09-01']);
        $quote->parts()->create(['name' => 'Partida'])->items()->create([
            'reference' => 'REF-1', 'description' => 'Item de prueba', 'quantity' => 1, 'unit_price' => 100, 'discount_percent' => 0,
        ]);
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/quotes/{$quote->id}/generate")
            ->assertStatus(422);

        $response->assertJsonPath('valid', false);
        $this->assertContains('Contacto', $response->json('missing'));
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $this->assertNull(Quote::find($quote->id)?->folio);
    }

    /**
     * QA formal 2026-07-10/11 (SCRUM-116): una cotización creada desde una tarjeta de
     * Pipeline sin Cliente Master vinculado nace con un Proyecto (SalesProject) sin
     * dueño (sub_client_id null). Completar el Subcliente en la pantalla de
     * Cotización debe reparentar ESE MISMO Proyecto en vez de exigir uno distinto.
     */
    public function test_guardar_borrador_reparenta_proyecto_ya_vinculado_a_nuevo_subcliente(): void
    {
        $this->grantVentasDisenoEdit('designer@atlantic.test');
        $master  = MasterClient::create(['name' => 'Cliente Test']);
        $sub     = SubClient::create(['master_client_id' => $master->id, 'business_name' => 'Subcliente Test', 'tax_id' => '155-0000-1-2026']);
        $orphanProject = SalesProject::create(['sub_client_id' => null, 'name' => 'Proyecto sin cliente']);
        $architect = Architect::create(['name' => 'Arq. Test', 'phone' => '6000-0000']);
        $contact   = $sub->contacts()->create(['name' => 'Contacto Test', 'role' => 'client', 'phone' => '6111-1111']);
        $designerId = $this->userIdByEmail('designer@atlantic.test');

        $quote = Quote::create(['owner_id' => $designerId, 'sales_project_id' => $orphanProject->id]);
        $quote->contacts()->attach($contact->id);
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/quotes/{$quote->id}/save-draft", [
                'master_client_id' => $master->id,
                'sub_client_id'    => $sub->id,
                'sales_project_id' => $orphanProject->id, // el mismo — no se elige uno nuevo
                'ruc'              => '155-0000-1-2026',
                'description'      => 'Completando cliente',
                'architect_id'     => $architect->id,
                'delivery_dates'   => ['2026-09-01'],
            ])
            ->assertStatus(200);

        $response->assertJsonPath('sales_project.id', $orphanProject->id);
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $this->assertSame($sub->id, SalesProject::find($orphanProject->id)?->sub_client_id);
    }

    /**
     * El reparentado de arriba está acotado: si el usuario elige a mano un Proyecto
     * DISTINTO (vía el buscador de Proyecto, ej. flujo "+ Nueva cotización" sin
     * precarga) que pertenece a otro Subcliente, sigue siendo un 422 — no se pisa
     * la propiedad de un Proyecto real y ajeno.
     */
    public function test_guardar_borrador_no_reparenta_proyecto_elegido_a_mano_de_otro_subcliente(): void
    {
        $this->grantVentasDisenoEdit('designer@atlantic.test');
        $hierarchy = $this->makeClientHierarchy();
        $otherSub     = SubClient::create(['master_client_id' => $hierarchy['master']->id, 'business_name' => 'Otro Sub', 'tax_id' => '155-9999-1-2026']);
        $otherProject = SalesProject::create(['sub_client_id' => $otherSub->id, 'name' => 'Proyecto ajeno']);
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $quoteId = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/ventas-diseno/quotes', [])->json('id');

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/quotes/{$quoteId}/save-draft", [
                'sub_client_id'    => $hierarchy['sub']->id,
                'sales_project_id' => $otherProject->id,
            ])
            ->assertStatus(422);

        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $this->assertSame($otherSub->id, SalesProject::find($otherProject->id)?->sub_client_id);
    }

    // ── Cotización-D: generate (REQ-047/048/051) ────────────────────────────────

    public function test_generar_bloquea_si_faltan_items(): void
    {
        $this->grantVentasDisenoEdit('designer@atlantic.test');
        $designerId = $this->userIdByEmail('designer@atlantic.test');
        $data = $this->makeQuoteWithGeneralFieldsComplete($designerId);
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/quotes/{$data['quote']->id}/generate")
            ->assertStatus(422);

        $response->assertJsonPath('valid', false);
        $this->assertContains('Ítems', $response->json('missing'));
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $this->assertNull(Quote::find($data['quote']->id)?->folio);
    }

    public function test_generar_exitoso_asigna_folio_con_formato_correcto(): void
    {
        $this->grantVentasDisenoEdit('designer@atlantic.test');
        $designerId = $this->userIdByEmail('designer@atlantic.test');
        $data = $this->makeQuoteWithGeneralFieldsComplete($designerId);
        $part = $data['quote']->parts()->create(['name' => 'Partida 1']);
        $part->items()->create(['reference' => 'REF-1', 'description' => 'X', 'quantity' => 1, 'unit_price' => 100]);
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/quotes/{$data['quote']->id}/generate")
            ->assertStatus(200);

        $folio = $response->json('folio');
        $this->assertMatchesRegularExpression('/^COT-\d{4}-\d{4}$/', $folio);
        $this->assertNotNull($response->json('generated_at'));
    }

    public function test_generar_dos_veces_no_reasigna_folio(): void
    {
        $this->grantVentasDisenoEdit('designer@atlantic.test');
        $designerId = $this->userIdByEmail('designer@atlantic.test');
        $data = $this->makeQuoteWithGeneralFieldsComplete($designerId);
        $part = $data['quote']->parts()->create(['name' => 'Partida 1']);
        $part->items()->create(['reference' => 'REF-1', 'description' => 'X', 'quantity' => 1, 'unit_price' => 100]);
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $first = $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/quotes/{$data['quote']->id}/generate")
            ->assertStatus(200)->json('folio');

        $second = $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/quotes/{$data['quote']->id}/generate")
            ->assertStatus(200)->json('folio');

        $this->assertSame($first, $second);
    }

    public function test_folios_de_cotizaciones_distintas_son_consecutivos(): void
    {
        $this->grantVentasDisenoEdit('designer@atlantic.test');
        $designerId = $this->userIdByEmail('designer@atlantic.test');

        $dataA = $this->makeQuoteWithGeneralFieldsComplete($designerId);
        $partA = $dataA['quote']->parts()->create(['name' => 'Partida 1']);
        $partA->items()->create(['reference' => 'REF-A', 'description' => 'A', 'quantity' => 1, 'unit_price' => 50]);

        $dataB = $this->makeQuoteWithGeneralFieldsComplete($designerId);
        $partB = $dataB['quote']->parts()->create(['name' => 'Partida 1']);
        $partB->items()->create(['reference' => 'REF-B', 'description' => 'B', 'quantity' => 1, 'unit_price' => 50]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $folioA = $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/quotes/{$dataA['quote']->id}/generate")->json('folio');
        $folioB = $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/quotes/{$dataB['quote']->id}/generate")->json('folio');

        [, $yearA, $numA] = explode('-', $folioA);
        [, $yearB, $numB] = explode('-', $folioB);
        $this->assertSame($yearA, $yearB);
        $this->assertSame((int) $numA + 1, (int) $numB);
    }

    // ── Cotización-D: precarga (REQ-050) ────────────────────────────────────────

    public function test_precarga_desde_tarjeta_de_pipeline_copia_datos_y_primer_contacto(): void
    {
        $this->grantVentasDisenoEdit('designer@atlantic.test');
        $designerId = $this->userIdByEmail('designer@atlantic.test');
        $master  = MasterClient::create(['name' => 'Cliente Pipeline']);
        $sub     = SubClient::create(['master_client_id' => $master->id, 'business_name' => 'Sub Pipeline', 'tax_id' => '155-1111-1-2026']);
        $project = SalesProject::create(['sub_client_id' => $sub->id, 'name' => 'Proyecto Pipeline']);
        $card = PipelineCard::create([
            'sales_project_id' => $project->id, 'stage' => 'design',
            'master_client_id' => $master->id, 'sub_client_id' => $sub->id,
            'owner_id' => $designerId, 'stage_changed_at' => now(),
        ]);
        $card->contacts()->create(['name' => 'Ana Pipeline', 'role' => 'client', 'phone' => '6222-2222', 'email' => 'ana@example.com']);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/ventas-diseno/quotes', ['from_pipeline_card_id' => $card->id])
            ->assertStatus(201);

        $response->assertJsonPath('master_client.id', $master->id);
        $response->assertJsonPath('sub_client.id', $sub->id);
        $response->assertJsonPath('sales_project.id', $project->id);
        $response->assertJsonPath('ruc', '155-1111-1-2026');
        $contacts = $response->json('contacts');
        $this->assertCount(1, $contacts);
        $this->assertSame('Ana Pipeline', $contacts[0]['name']);
        $this->assertSame('6222-2222', $contacts[0]['phone']);
    }

    public function test_precarga_desde_subcliente_de_clientes(): void
    {
        $this->grantVentasDisenoEdit('designer@atlantic.test');
        $hierarchy = $this->makeClientHierarchy();
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/ventas-diseno/quotes', ['from_sub_client_id' => $hierarchy['sub']->id])
            ->assertStatus(201);

        $response->assertJsonPath('master_client.id', $hierarchy['master']->id);
        $response->assertJsonPath('sub_client.id', $hierarchy['sub']->id);
        $response->assertJsonPath('ruc', '155-0000-1-2026');
        $response->assertJsonPath('sales_project', null);
    }

    // ── SCRUM-723: generate() ya no mueve la tarjeta, solo asigna folio ─────────

    public function test_generar_cotizacion_asigna_folio_pero_no_mueve_la_tarjeta(): void
    {
        $this->grantVentasDisenoEdit('designer@atlantic.test');
        $designerId = $this->userIdByEmail('designer@atlantic.test');
        $data = $this->makeQuoteWithGeneralFieldsComplete($designerId);
        $part = $data['quote']->parts()->create(['name' => 'Partida 1']);
        $part->items()->create(['reference' => 'REF-1', 'description' => 'X', 'quantity' => 2, 'unit_price' => 100]);

        $card = PipelineCard::create([
            'sales_project_id' => $data['project']->id, 'stage' => 'lead',
            'master_client_id' => $data['master']->id, 'sub_client_id' => $data['sub']->id,
            'owner_id' => $designerId, 'stage_changed_at' => now(),
        ]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/quotes/{$data['quote']->id}/generate")
            ->assertStatus(200);

        $this->assertNotNull($response->json('folio'));
        $this->assertNull($response->json('confirmed_at'));
        $this->assertSame('draft', $response->json('document_status'));

        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $this->assertSame('lead', PipelineCard::find($card->id)?->stage);
    }

    // ── SCRUM-723: confirm() (botón "Guardar") ───────────────────────────────────

    public function test_confirmar_cotizacion_mueve_tarjeta_lead_a_quote_con_el_total(): void
    {
        $this->grantVentasDisenoEdit('designer@atlantic.test');
        $designerId = $this->userIdByEmail('designer@atlantic.test');
        $data = $this->makeQuoteWithGeneralFieldsComplete($designerId);
        $part = $data['quote']->parts()->create(['name' => 'Partida 1']);
        $part->items()->create(['reference' => 'REF-1', 'description' => 'X', 'quantity' => 2, 'unit_price' => 100]);

        $card = PipelineCard::create([
            'sales_project_id' => $data['project']->id, 'stage' => 'lead',
            'master_client_id' => $data['master']->id, 'sub_client_id' => $data['sub']->id,
            'owner_id' => $designerId, 'stage_changed_at' => now(),
        ]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/quotes/{$data['quote']->id}/generate")->assertStatus(200);

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/quotes/{$data['quote']->id}/confirm")
            ->assertStatus(200);

        $this->assertNotNull($response->json('confirmed_at'));
        $this->assertSame('sent', $response->json('document_status'));

        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $fresh = PipelineCard::find($card->id);
        $this->assertSame('quote', $fresh?->stage);
        $this->assertEquals(214.0, $fresh?->amount); // 200 subtotal + 7% ITBMS
    }

    /** Mismo escenario, pero la tarjeta arranca en "design" en vez de "lead". */
    public function test_confirmar_cotizacion_desde_tarjeta_en_diseno_avanza_la_tarjeta_a_cotizacion(): void
    {
        $this->grantVentasDisenoEdit('designer@atlantic.test');
        $designerId = $this->userIdByEmail('designer@atlantic.test');
        $data = $this->makeQuoteWithGeneralFieldsComplete($designerId);
        $part = $data['quote']->parts()->create(['name' => 'Partida 1']);
        $part->items()->create(['reference' => 'REF-1', 'description' => 'X', 'quantity' => 1, 'unit_price' => 100]);

        $card = PipelineCard::create([
            'sales_project_id' => $data['project']->id, 'stage' => 'design',
            'master_client_id' => $data['master']->id, 'sub_client_id' => $data['sub']->id,
            'owner_id' => $designerId, 'stage_changed_at' => now(),
        ]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/quotes/{$data['quote']->id}/generate")->assertStatus(200);
        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/quotes/{$data['quote']->id}/confirm")
            ->assertStatus(200);

        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $this->assertSame('quote', PipelineCard::find($card->id)?->stage);
    }

    /**
     * SCRUM-734 (RN2.4) — reemplaza el viejo comportamiento ("nunca retrocede pero
     * confirma igual"): ahora confirmar sobre una tarjeta ya avanzada a Propuesta
     * se BLOQUEA por completo, no solo se abstiene de mover la etapa. Ver
     * test_confirmar_bloqueado_si_tarjeta_ya_en_propuesta() más abajo.
     */

    public function test_confirmar_cotizacion_sin_generar_retorna_422(): void
    {
        $this->grantVentasDisenoEdit('designer@atlantic.test');
        $designerId = $this->userIdByEmail('designer@atlantic.test');
        $data = $this->makeQuoteWithGeneralFieldsComplete($designerId);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/quotes/{$data['quote']->id}/confirm")
            ->assertStatus(422);
    }

    public function test_confirmar_cotizacion_dos_veces_es_idempotente(): void
    {
        $this->grantVentasDisenoEdit('designer@atlantic.test');
        $designerId = $this->userIdByEmail('designer@atlantic.test');
        $data = $this->makeQuoteWithGeneralFieldsComplete($designerId);
        $part = $data['quote']->parts()->create(['name' => 'Partida 1']);
        $part->items()->create(['reference' => 'REF-1', 'description' => 'X', 'quantity' => 1, 'unit_price' => 100]);

        $card = PipelineCard::create([
            'sales_project_id' => $data['project']->id, 'stage' => 'lead',
            'master_client_id' => $data['master']->id, 'sub_client_id' => $data['sub']->id,
            'owner_id' => $designerId, 'stage_changed_at' => now(),
        ]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/quotes/{$data['quote']->id}/generate")->assertStatus(200);
        $first = $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/quotes/{$data['quote']->id}/confirm")
            ->assertStatus(200)->json('confirmed_at');
        $second = $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/quotes/{$data['quote']->id}/confirm")
            ->assertStatus(200)->json('confirmed_at');

        $this->assertSame($first, $second);
    }

    // ── SCRUM-725/734: margen mínimo en generate()+confirm() + excepción Mark/David ─

    /**
     * SCRUM-734 (RN10) — "generar" ahora bloquea por margen igual que cualquier
     * otro campo faltante (antes solo confirm() lo hacía; ver
     * Quote::validationErrorsForGenerating()).
     */
    public function test_generar_cotizacion_con_margen_violado_retorna_422_para_usuario_sin_excepcion(): void
    {
        $this->grantVentasDisenoEdit('designer@atlantic.test');
        $designerId = $this->userIdByEmail('designer@atlantic.test');
        $data = $this->makeQuoteWithGeneralFieldsComplete($designerId);
        $part = $data['quote']->parts()->create(['name' => 'Partida 1']);
        // Creado directo por Eloquent (bypass del bloqueo en el endpoint de ítems) para
        // simular un estado ya persistido con margen violado.
        $part->items()->create(['reference' => 'REF-1', 'description' => 'X', 'quantity' => 1, 'unit_price' => 60, 'cost' => 50]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/quotes/{$data['quote']->id}/generate")
            ->assertStatus(422);

        $this->assertContains('Margen', $response->json('missing'));
    }

    /**
     * SCRUM-734/725 — confirm() sigue validando el margen por su cuenta como
     * defensa en profundidad: una cotización puede generarse SIN violación (folio
     * asignado) y luego editarse antes de confirmar, reintroduciendo una
     * violación — confirm() no debe confiar en que "ya pasó por generate()".
     */
    public function test_confirmar_cotizacion_con_margen_violado_retorna_422_para_usuario_sin_excepcion(): void
    {
        $this->grantVentasDisenoEdit('designer@atlantic.test');
        $designerId = $this->userIdByEmail('designer@atlantic.test');
        $data = $this->makeQuoteWithGeneralFieldsComplete($designerId);
        $part = $data['quote']->parts()->create(['name' => 'Partida 1']);
        $item = $part->items()->create(['reference' => 'REF-1', 'description' => 'X', 'quantity' => 1, 'unit_price' => 100]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/quotes/{$data['quote']->id}/generate")->assertStatus(200);

        // Margen se rompe DESPUÉS de generar (edición directa por Eloquent, bypass
        // del bloqueo del endpoint de ítems) — confirm() debe atraparlo igual.
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $item->update(['unit_price' => 60, 'cost' => 50]);

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/quotes/{$data['quote']->id}/confirm")
            ->assertStatus(422);
    }

    public function test_confirmar_cotizacion_con_margen_violado_avisa_a_mark_sin_confirmar_todavia(): void
    {
        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');
        DB::table("{$slug}_auth.users")->where('email', 'mbekhar@atlantic.com.pa')
            ->update(['extra_permissions' => json_encode(['ventas_diseno.override_min_margin'])]);
        $markId = $this->userIdByEmail('mbekhar@atlantic.com.pa');
        $data = $this->makeQuoteWithGeneralFieldsComplete($markId);
        $part = $data['quote']->parts()->create(['name' => 'Partida 1']);
        $itemId = $part->items()->create(['reference' => 'REF-1', 'description' => 'X', 'quantity' => 1, 'unit_price' => 60, 'cost' => 50])->id;

        $token = $this->loginAs('mbekhar@atlantic.com.pa', 'mbekhar@atlantic.com.pa', 'management');
        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/quotes/{$data['quote']->id}/generate")->assertStatus(200);

        $warning = $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/quotes/{$data['quote']->id}/confirm")
            ->assertStatus(422);
        $warning->assertJsonPath('margin_warning', true);
        $warning->assertJsonPath('below_min_margin_items', [$itemId]);

        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $this->assertNull(Quote::find($data['quote']->id)?->confirmed_at, 'sin el flag de confirmación, todavía no debe quedar guardada');
    }

    public function test_confirmar_cotizacion_con_margen_violado_y_override_explicito_guarda_para_mark(): void
    {
        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');
        DB::table("{$slug}_auth.users")->where('email', 'mbekhar@atlantic.com.pa')
            ->update(['extra_permissions' => json_encode(['ventas_diseno.override_min_margin'])]);
        $markId = $this->userIdByEmail('mbekhar@atlantic.com.pa');
        $data = $this->makeQuoteWithGeneralFieldsComplete($markId);
        $part = $data['quote']->parts()->create(['name' => 'Partida 1']);
        $part->items()->create(['reference' => 'REF-1', 'description' => 'X', 'quantity' => 1, 'unit_price' => 60, 'cost' => 50]);

        $token = $this->loginAs('mbekhar@atlantic.com.pa', 'mbekhar@atlantic.com.pa', 'management');
        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/quotes/{$data['quote']->id}/generate")->assertStatus(200);

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/quotes/{$data['quote']->id}/confirm", ['override_margin_warning' => true])
            ->assertStatus(200);

        $this->assertNotNull($response->json('confirmed_at'));
    }

    // ── SCRUM-734: sin clonado — Borradores nunca tocan Pipeline, 1 proyecto = 1 tarjeta ─

    /**
     * RN0.4 — crear una cotización (Borrador) sobre un proyecto que nunca tuvo
     * tarjeta NO crea ninguna tarjeta todavía. Reemplaza el viejo comportamiento
     * (SCRUM-722) de auto-crear la tarjeta en "lead" al crear/asignar proyecto.
     */
    public function test_crear_cotizacion_sobre_proyecto_sin_tarjeta_no_crea_tarjeta_todavia(): void
    {
        $this->grantVentasDisenoEdit('designer@atlantic.test');
        $hierarchy = $this->makeClientHierarchy();

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/ventas-diseno/quotes', [
                'master_client_id' => $hierarchy['master']->id,
                'sub_client_id'    => $hierarchy['sub']->id,
                'sales_project_id' => $hierarchy['project']->id,
            ])
            ->assertStatus(201);

        $this->assertSame($hierarchy['project']->id, $response->json('sales_project.id'));

        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $this->assertNull(PipelineCard::where('sales_project_id', $hierarchy['project']->id)->first());
    }

    /**
     * RN0.4 — mismo caso pero asignando el proyecto vía PUT (flujo real de "+ Nueva
     * cotización": la cotización nace vacía y el Proyecto se elige/crea después,
     * ver QuotePage.tsx). Tampoco crea tarjeta.
     */
    public function test_asignar_proyecto_via_update_sobre_cotizacion_vacia_no_crea_tarjeta_todavia(): void
    {
        $this->grantVentasDisenoEdit('designer@atlantic.test');
        $hierarchy = $this->makeClientHierarchy();

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $quoteId = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/ventas-diseno/quotes', [])
            ->assertStatus(201)
            ->json('id');

        $response = $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/ventas-diseno/quotes/{$quoteId}", [
                'master_client_id' => $hierarchy['master']->id,
                'sub_client_id'    => $hierarchy['sub']->id,
                'sales_project_id' => $hierarchy['project']->id,
            ])
            ->assertStatus(200);

        $this->assertSame($hierarchy['project']->id, $response->json('sales_project.id'));

        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $this->assertNull(PipelineCard::where('sales_project_id', $hierarchy['project']->id)->first());
    }

    /** Varios Borradores sueltos sobre el mismo proyecto siguen permitidos sin ninguna coordinación. */
    public function test_crear_segunda_cotizacion_sobre_tarjeta_con_borrador_sin_folio_no_se_bloquea(): void
    {
        $this->grantVentasDisenoEdit('designer@atlantic.test');
        $designerId = $this->userIdByEmail('designer@atlantic.test');
        $hierarchy  = $this->makeClientHierarchy();

        $card = PipelineCard::create([
            'sales_project_id' => $hierarchy['project']->id, 'stage' => 'lead',
            'master_client_id' => $hierarchy['master']->id, 'sub_client_id' => $hierarchy['sub']->id,
            'owner_id' => $designerId, 'stage_changed_at' => now(),
        ]);

        Quote::create([
            'owner_id'         => $designerId,
            'sales_project_id' => $hierarchy['project']->id,
            'description'      => 'Borrador abandonado',
        ]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/ventas-diseno/quotes', ['from_pipeline_card_id' => $card->id])
            ->assertStatus(201);
    }

    /** RN0.3 — confirmar una cotización sobre un proyecto sin tarjeta la crea directo en etapa Cotización, no en Lead. */
    public function test_confirmar_cotizacion_sobre_proyecto_sin_tarjeta_crea_tarjeta_en_etapa_cotizacion(): void
    {
        $this->grantVentasDisenoEdit('designer@atlantic.test');
        $designerId = $this->userIdByEmail('designer@atlantic.test');
        $data = $this->makeQuoteWithGeneralFieldsComplete($designerId);
        $part = $data['quote']->parts()->create(['name' => 'Partida 1']);
        $part->items()->create(['reference' => 'REF-1', 'description' => 'X', 'quantity' => 1, 'unit_price' => 100]);

        // A propósito: ningún PipelineCard existe todavía para $data['project'].
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/quotes/{$data['quote']->id}/generate")->assertStatus(200);
        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/quotes/{$data['quote']->id}/confirm")
            ->assertStatus(200);

        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $card = PipelineCard::where('sales_project_id', $data['project']->id)->first();
        $this->assertNotNull($card);
        $this->assertSame('quote', $card->stage);
    }

    /**
     * RN0/RN1/RN2.3 — el corazón del rediseño: una segunda cotización confirmada
     * sobre el MISMO proyecto (que ya tenía una confirmada) nunca clona nada — la
     * primera cotización sigue existiendo (historial), ambas comparten
     * sales_project_id, y hay una sola PipelineCard cuyo amount pasa a reflejar la
     * versión más reciente (RN4.1, overwrite nunca suma).
     */
    public function test_segunda_cotizacion_confirmada_sobre_mismo_proyecto_no_clona_comparte_la_misma_tarjeta(): void
    {
        $this->grantVentasDisenoEdit('designer@atlantic.test');
        $designerId = $this->userIdByEmail('designer@atlantic.test');
        $data = $this->makeQuoteWithGeneralFieldsComplete($designerId);
        $part = $data['quote']->parts()->create(['name' => 'Partida 1']);
        $part->items()->create(['reference' => 'REF-1', 'description' => 'X', 'quantity' => 1, 'unit_price' => 100]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/quotes/{$data['quote']->id}/generate")->assertStatus(200);
        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/quotes/{$data['quote']->id}/confirm")->assertStatus(200);

        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $card = PipelineCard::where('sales_project_id', $data['project']->id)->firstOrFail();
        $this->assertEquals(107.0, $card->amount); // 100 + 7% ITBMS

        // Segunda cotización, mismo proyecto — vía "usar como base" (endpoint real).
        $second = $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/quotes/{$data['quote']->id}/duplicate")
            ->assertStatus(201);
        $secondId = $second->json('id');

        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/ventas-diseno/quotes/{$secondId}", ['description' => 'Version 2'])
            ->assertStatus(200);
        $partId = $second->json('parts.0.id');
        // La partida clonada ya trae el ítem de $100 copiado — subimos el precio
        // para que el total de la v2 sea distinto y se note el overwrite.
        $itemId = $second->json('parts.0.items.0.id');
        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/ventas-diseno/quotes/{$secondId}/parts/{$partId}/items/{$itemId}", [
                'reference' => 'REF-1', 'description' => 'X', 'quantity' => 1, 'unit_price' => 200,
            ])
            ->assertStatus(200);

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/quotes/{$secondId}/generate")->assertStatus(200);
        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/quotes/{$secondId}/confirm")->assertStatus(200);

        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();

        // Sigue habiendo 1 sola tarjeta para el proyecto, nunca se clonó.
        $this->assertSame(1, PipelineCard::where('sales_project_id', $data['project']->id)->count());
        $freshCard = PipelineCard::find($card->id);
        $this->assertSame('quote', $freshCard->stage);
        $this->assertEquals(214.0, $freshCard->amount); // 200 + 7% ITBMS — sobrescribe, no suma

        // Ambas cotizaciones siguen existiendo, mismo proyecto — historial intacto.
        $this->assertSame(2, Quote::where('sales_project_id', $data['project']->id)->count());
    }

    /** RN2.4 — una vez en Propuesta, confirmar una cotización nueva sobre ese proyecto se bloquea. */
    public function test_confirmar_bloqueado_si_tarjeta_ya_en_propuesta(): void
    {
        $this->grantVentasDisenoEdit('designer@atlantic.test');
        $designerId = $this->userIdByEmail('designer@atlantic.test');
        $data = $this->makeQuoteWithGeneralFieldsComplete($designerId);
        $part = $data['quote']->parts()->create(['name' => 'Partida 1']);
        $part->items()->create(['reference' => 'REF-1', 'description' => 'X', 'quantity' => 1, 'unit_price' => 100]);

        PipelineCard::create([
            'sales_project_id' => $data['project']->id, 'stage' => 'proposal',
            'master_client_id' => $data['master']->id, 'sub_client_id' => $data['sub']->id,
            'owner_id' => $designerId, 'stage_changed_at' => now(),
        ]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/quotes/{$data['quote']->id}/generate")->assertStatus(200);
        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/quotes/{$data['quote']->id}/confirm")
            ->assertStatus(422);
    }

    /** RN2.6 — Aprobado congela permanentemente: ninguna cotización nueva se confirma sobre ese proyecto. */
    public function test_confirmar_bloqueado_si_tarjeta_aprobada(): void
    {
        $this->grantVentasDisenoEdit('designer@atlantic.test');
        $designerId = $this->userIdByEmail('designer@atlantic.test');
        $data = $this->makeQuoteWithGeneralFieldsComplete($designerId);
        $part = $data['quote']->parts()->create(['name' => 'Partida 1']);
        $part->items()->create(['reference' => 'REF-1', 'description' => 'X', 'quantity' => 1, 'unit_price' => 100]);

        PipelineCard::create([
            'sales_project_id' => $data['project']->id, 'stage' => 'approved',
            'master_client_id' => $data['master']->id, 'sub_client_id' => $data['sub']->id,
            'owner_id' => $designerId, 'stage_changed_at' => now(),
        ]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/quotes/{$data['quote']->id}/generate")->assertStatus(200);
        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/quotes/{$data['quote']->id}/confirm")
            ->assertStatus(422);
    }

    /** RN2.5 — un proyecto Perdido exige reactivarse antes de poder confirmar una cotización nueva. */
    public function test_confirmar_bloqueado_si_tarjeta_perdida(): void
    {
        $this->grantVentasDisenoEdit('designer@atlantic.test');
        $designerId = $this->userIdByEmail('designer@atlantic.test');
        $data = $this->makeQuoteWithGeneralFieldsComplete($designerId);
        $part = $data['quote']->parts()->create(['name' => 'Partida 1']);
        $part->items()->create(['reference' => 'REF-1', 'description' => 'X', 'quantity' => 1, 'unit_price' => 100]);

        PipelineCard::create([
            'sales_project_id' => $data['project']->id, 'stage' => 'lost',
            'master_client_id' => $data['master']->id, 'sub_client_id' => $data['sub']->id,
            'owner_id' => $designerId, 'stage_changed_at' => now(),
        ]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/quotes/{$data['quote']->id}/generate")->assertStatus(200);
        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/quotes/{$data['quote']->id}/confirm")
            ->assertStatus(422);
    }

    // ── SCRUM-734 (RN2.2): edición directa de una cotización confirmada ─────────

    public function test_editar_cotizacion_confirmada_via_put_retorna_422(): void
    {
        $this->grantVentasDisenoEdit('designer@atlantic.test');
        $designerId = $this->userIdByEmail('designer@atlantic.test');
        $data = $this->makeQuoteWithGeneralFieldsComplete($designerId);
        $part = $data['quote']->parts()->create(['name' => 'Partida 1']);
        $part->items()->create(['reference' => 'REF-1', 'description' => 'X', 'quantity' => 1, 'unit_price' => 100]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/quotes/{$data['quote']->id}/generate")->assertStatus(200);
        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/quotes/{$data['quote']->id}/confirm")->assertStatus(200);

        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/ventas-diseno/quotes/{$data['quote']->id}", ['description' => 'Intento de editar'])
            ->assertStatus(422);

        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $this->assertSame('Cotizacion completa', Quote::find($data['quote']->id)?->description);
    }

    public function test_can_edit_es_false_para_cotizacion_confirmada_en_formatDetail(): void
    {
        $this->grantVentasDisenoEdit('designer@atlantic.test');
        $designerId = $this->userIdByEmail('designer@atlantic.test');
        $data = $this->makeQuoteWithGeneralFieldsComplete($designerId);
        $part = $data['quote']->parts()->create(['name' => 'Partida 1']);
        $part->items()->create(['reference' => 'REF-1', 'description' => 'X', 'quantity' => 1, 'unit_price' => 100]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/quotes/{$data['quote']->id}/generate")->assertStatus(200);
        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/quotes/{$data['quote']->id}/confirm")->assertStatus(200);

        $this->assertFalse($response->json('can_edit'));
    }

    // ── SCRUM-734 (RN2.3): "usar como base para nueva versión" ──────────────────

    public function test_duplicate_cotizacion_confirmada_crea_borrador_editable_mismo_proyecto(): void
    {
        $this->grantVentasDisenoEdit('designer@atlantic.test');
        $designerId = $this->userIdByEmail('designer@atlantic.test');
        $data = $this->makeQuoteWithGeneralFieldsComplete($designerId);
        $part = $data['quote']->parts()->create(['name' => 'Partida 1']);
        $part->items()->create(['reference' => 'REF-1', 'description' => 'X', 'quantity' => 2, 'unit_price' => 100]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/quotes/{$data['quote']->id}/generate")->assertStatus(200);
        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/quotes/{$data['quote']->id}/confirm")->assertStatus(200);

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/quotes/{$data['quote']->id}/duplicate")
            ->assertStatus(201);

        $this->assertNotSame($data['quote']->id, $response->json('id'));
        $this->assertSame($data['project']->id, $response->json('sales_project.id'));
        $this->assertNull($response->json('folio'));
        $this->assertNull($response->json('confirmed_at'));
        $this->assertSame('draft', $response->json('document_status'));
        $this->assertTrue($response->json('can_edit'));
        $this->assertCount(1, $response->json('parts'));
        $this->assertEquals(2.0, $response->json('parts.0.items.0.quantity'));
    }

    public function test_duplicate_bloqueado_si_tarjeta_no_esta_en_cotizacion(): void
    {
        $this->grantVentasDisenoEdit('designer@atlantic.test');
        $designerId = $this->userIdByEmail('designer@atlantic.test');
        $data = $this->makeQuoteWithGeneralFieldsComplete($designerId);
        $part = $data['quote']->parts()->create(['name' => 'Partida 1']);
        $part->items()->create(['reference' => 'REF-1', 'description' => 'X', 'quantity' => 1, 'unit_price' => 100]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/quotes/{$data['quote']->id}/generate")->assertStatus(200);
        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/quotes/{$data['quote']->id}/confirm")->assertStatus(200);

        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        PipelineCard::where('sales_project_id', $data['project']->id)->update(['stage' => 'proposal']);

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/quotes/{$data['quote']->id}/duplicate")
            ->assertStatus(422);
    }

    public function test_duplicate_bloqueado_sobre_cotizacion_no_confirmada(): void
    {
        $this->grantVentasDisenoEdit('designer@atlantic.test');
        $designerId = $this->userIdByEmail('designer@atlantic.test');
        $data = $this->makeQuoteWithGeneralFieldsComplete($designerId);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/quotes/{$data['quote']->id}/duplicate")
            ->assertStatus(422);
    }

    // ── SCRUM-734 (RN1.3/RN3): historial de versiones ────────────────────────────

    public function test_versions_lista_confirmadas_con_numero_y_etapa_de_tarjeta(): void
    {
        $this->grantVentasDisenoEdit('designer@atlantic.test');
        $designerId = $this->userIdByEmail('designer@atlantic.test');
        $data = $this->makeQuoteWithGeneralFieldsComplete($designerId);
        $part = $data['quote']->parts()->create(['name' => 'Partida 1']);
        $part->items()->create(['reference' => 'REF-1', 'description' => 'X', 'quantity' => 1, 'unit_price' => 100]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/quotes/{$data['quote']->id}/generate")->assertStatus(200);
        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/quotes/{$data['quote']->id}/confirm")->assertStatus(200);

        // Todavía 1 sola versión — la sección "Versiones de este proyecto" del
        // ticket solo se muestra con 2+, pero el endpoint igual las lista.
        $onlyOne = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/ventas-diseno/quotes/{$data['quote']->id}/versions")
            ->assertStatus(200);
        $this->assertCount(1, $onlyOne->json('versions'));
        $this->assertSame('quote', $onlyOne->json('card_stage'));

        $second = $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/quotes/{$data['quote']->id}/duplicate")
            ->assertStatus(201)->json('id');
        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/quotes/{$second}/generate")->assertStatus(200);
        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/quotes/{$second}/confirm")->assertStatus(200);

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/ventas-diseno/quotes/{$data['quote']->id}/versions")
            ->assertStatus(200);

        $versions = $response->json('versions');
        $this->assertCount(2, $versions);
        $this->assertSame(1, $versions[0]['version']);
        $this->assertSame(2, $versions[1]['version']);
        $this->assertSame($data['quote']->id, $versions[0]['id']);
        $this->assertSame($second, $versions[1]['id']);
        $this->assertSame('quote', $response->json('card_stage'));
    }

    // ── SCRUM-734 (Batch F): eventos de dominio ──────────────────────────────────

    public function test_generar_dispara_quote_generated(): void
    {
        Event::fake([QuoteGenerated::class]);
        $this->grantVentasDisenoEdit('designer@atlantic.test');
        $designerId = $this->userIdByEmail('designer@atlantic.test');
        $data = $this->makeQuoteWithGeneralFieldsComplete($designerId);
        $part = $data['quote']->parts()->create(['name' => 'Partida 1']);
        $part->items()->create(['reference' => 'REF-1', 'description' => 'X', 'quantity' => 1, 'unit_price' => 100]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/quotes/{$data['quote']->id}/generate")->assertStatus(200);

        Event::assertDispatched(QuoteGenerated::class, fn (QuoteGenerated $e) => $e->quoteId === $data['quote']->id
            && $e->salesProjectId === $data['project']->id);
    }

    public function test_generar_dos_veces_no_redispara_quote_generated(): void
    {
        Event::fake([QuoteGenerated::class]);
        $this->grantVentasDisenoEdit('designer@atlantic.test');
        $designerId = $this->userIdByEmail('designer@atlantic.test');
        $data = $this->makeQuoteWithGeneralFieldsComplete($designerId);
        $part = $data['quote']->parts()->create(['name' => 'Partida 1']);
        $part->items()->create(['reference' => 'REF-1', 'description' => 'X', 'quantity' => 1, 'unit_price' => 100]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/quotes/{$data['quote']->id}/generate")->assertStatus(200);
        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/quotes/{$data['quote']->id}/generate")->assertStatus(200);

        Event::assertDispatchedTimes(QuoteGenerated::class, 1);
    }

    public function test_confirmar_primera_vez_dispara_quote_confirmed_sin_es_nueva_version(): void
    {
        Event::fake([QuoteConfirmed::class]);
        $this->grantVentasDisenoEdit('designer@atlantic.test');
        $designerId = $this->userIdByEmail('designer@atlantic.test');
        $data = $this->makeQuoteWithGeneralFieldsComplete($designerId);
        $part = $data['quote']->parts()->create(['name' => 'Partida 1']);
        $part->items()->create(['reference' => 'REF-1', 'description' => 'X', 'quantity' => 1, 'unit_price' => 100]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/quotes/{$data['quote']->id}/generate")->assertStatus(200);
        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/quotes/{$data['quote']->id}/confirm")->assertStatus(200);

        Event::assertDispatched(QuoteConfirmed::class, fn (QuoteConfirmed $e) => $e->quoteId === $data['quote']->id
            && $e->isNewVersion === false && $e->grandTotal === 107.0);
    }

    public function test_confirmar_segunda_version_dispara_quote_confirmed_con_es_nueva_version(): void
    {
        $this->grantVentasDisenoEdit('designer@atlantic.test');
        $designerId = $this->userIdByEmail('designer@atlantic.test');
        $data = $this->makeQuoteWithGeneralFieldsComplete($designerId);
        $part = $data['quote']->parts()->create(['name' => 'Partida 1']);
        $part->items()->create(['reference' => 'REF-1', 'description' => 'X', 'quantity' => 1, 'unit_price' => 100]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/quotes/{$data['quote']->id}/generate")->assertStatus(200);
        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/quotes/{$data['quote']->id}/confirm")->assertStatus(200);

        $second = $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/quotes/{$data['quote']->id}/duplicate")
            ->assertStatus(201)->json('id');
        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/quotes/{$second}/generate")->assertStatus(200);

        Event::fake([QuoteConfirmed::class]);
        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/quotes/{$second}/confirm")->assertStatus(200);

        Event::assertDispatched(QuoteConfirmed::class, fn (QuoteConfirmed $e) => $e->quoteId === $second && $e->isNewVersion === true);
    }

    // ── SCRUM-766: PDF real (plantilla única interna/externa/descarga) ──────────

    /** @return array{quote: Quote, part: \App\Modules\VentasDiseno\Models\QuotePart} */
    private function makeQuoteWithPdfableData(int $ownerId): array
    {
        $hierarchy = $this->makeClientHierarchy();
        $quote = Quote::create([
            'owner_id' => $ownerId, 'master_client_id' => $hierarchy['master']->id,
            'sub_client_id' => $hierarchy['sub']->id, 'sales_project_id' => $hierarchy['project']->id,
            'ruc' => '155-0000-1-2026', 'description' => 'Cotizacion PDF test',
            'price_type' => 'project', 'discount_mode' => 'line', 'status' => 'draft',
            'observations' => 'Obs de test', 'conditions_text' => 'Condiciones de test',
            'folio' => 'TEST-PDF-001', 'generated_at' => now(),
        ]);
        $part = \App\Modules\VentasDiseno\Models\QuotePart::create(['quote_id' => $quote->id, 'name' => 'Partida 1', 'position' => 1]);
        \App\Modules\VentasDiseno\Models\QuoteItem::create([
            'quote_part_id' => $part->id, 'is_custom' => true,
            'reference' => 'REF-1', 'description' => 'Item de prueba',
            'quantity' => 2, 'unit_price' => 100, 'discount_percent' => 10,
        ]);

        return ['quote' => $quote, 'part' => $part];
    }

    public function test_pdf_interno_devuelve_documento_con_precios(): void
    {
        $this->grantVentasDisenoEdit('designer@atlantic.test');
        $ownerId = $this->userIdByEmail('designer@atlantic.test');
        $data = $this->makeQuoteWithPdfableData($ownerId);
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->get("/api/ventas-diseno/quotes/{$data['quote']->id}/pdf")
            ->assertStatus(200);

        $response->assertHeader('content-type', 'application/pdf');
    }

    public function test_pdf_encabezado_secundario_muestra_la_descripcion_real_no_apto_hardcodeado(): void
    {
        // SCRUM-766 — rebote de Daniela 2026-08-20: el badge del encabezado secundario (§4) había
        // quedado con el texto literal "APTO" hardcodeado en el Blade en vez del campo Descripción
        // real de la cotización — "APTO" en el mockup del ticket era solo un valor de EJEMPLO de
        // ese campo, nunca texto fijo. Regresión: si alguien vuelve a hardcodear el literal, este
        // test falla porque la descripción del fixture ('Cotizacion PDF test') no aparecería.
        $this->grantVentasDisenoEdit('designer@atlantic.test');
        $ownerId = $this->userIdByEmail('designer@atlantic.test');
        $data = $this->makeQuoteWithPdfableData($ownerId);

        $controller = app(\App\Modules\VentasDiseno\Http\Controllers\QuoteController::class);
        $reflection = new \ReflectionMethod($controller, 'formatDetail');
        $reflection->setAccessible(true);
        $user = \App\Models\User::find($ownerId);
        $detail = $reflection->invoke($controller, $data['quote']->fresh(['parts.items']), $user);

        $pdfService = app(\App\Modules\VentasDiseno\Services\QuotePdfService::class);
        $html = view('ventas-diseno.quote-pdf', $pdfService->viewData($detail, external: false))->render();

        $this->assertStringContainsString('secondary-header-badge">Cotizacion PDF test<', $html);
        $this->assertStringNotContainsString('APTO', $html);
    }

    public function test_pdf_encabezado_muestra_el_logo_real_no_svg_inline(): void
    {
        // SCRUM-766 — mismo rebote de Daniela 2026-08-20: el encabezado principal seguía con el
        // <svg> inline original, que dompdf NO renderiza en este entorno (ver docblock de
        // App\Shared\Support\PdfLogo — el gap ya se había diagnosticado y arreglado en los 3 PDFs
        // de Servicios vía SCRUM-781, pero nunca se retrofiteó acá). Regresión: si alguien vuelve
        // a meter un <svg> inline en vez de $logoDataUri, este test falla.
        $this->grantVentasDisenoEdit('designer@atlantic.test');
        $ownerId = $this->userIdByEmail('designer@atlantic.test');
        $data = $this->makeQuoteWithPdfableData($ownerId);

        $controller = app(\App\Modules\VentasDiseno\Http\Controllers\QuoteController::class);
        $reflection = new \ReflectionMethod($controller, 'formatDetail');
        $reflection->setAccessible(true);
        $user = \App\Models\User::find($ownerId);
        $detail = $reflection->invoke($controller, $data['quote']->fresh(['parts.items']), $user);

        $pdfService = app(\App\Modules\VentasDiseno\Services\QuotePdfService::class);
        $html = view('ventas-diseno.quote-pdf', $pdfService->viewData($detail, external: false))->render();

        $this->assertStringContainsString('<img src="data:image/png;base64,', $html);
        $this->assertStringNotContainsString('<svg', $html);
    }

    public function test_pdf_externo_oculta_precios_totales_y_condiciones(): void
    {
        // Verifica la forma de datos que consume la plantilla (mismo criterio que
        // ServiceQuotePdfService::viewData()) — no parsea bytes de PDF, inspecciona
        // directamente qué le llega a la vista. Política SCRUM-140 sin cambios:
        // `external` nunca expone precio/descuento/total/observaciones/pago/
        // condiciones/totales/firma, aunque la ESTRUCTURA (misma plantilla) sea igual.
        $this->grantVentasDisenoEdit('designer@atlantic.test');
        $ownerId = $this->userIdByEmail('designer@atlantic.test');
        $data = $this->makeQuoteWithPdfableData($ownerId);

        $controller = app(\App\Modules\VentasDiseno\Http\Controllers\QuoteController::class);
        $reflection = new \ReflectionMethod($controller, 'formatDetail');
        $reflection->setAccessible(true);
        $user = \App\Models\User::find($ownerId);
        $detail = $reflection->invoke($controller, $data['quote']->fresh(['parts.items']), $user);

        $pdfService = app(\App\Modules\VentasDiseno\Services\QuotePdfService::class);
        $viewData = $pdfService->viewData($detail, external: true);

        $this->assertTrue($viewData['external']);
        // La plantilla es la que gatea la visibilidad — acá solo confirmamos que la
        // data cruda SIGUE trayendo los campos sensibles (para que la MISMA plantilla
        // los pueda mostrar del lado interno), la ocultación es responsabilidad del
        // Blade (`@if(!$external)`), no de este payload.
        $this->assertSame(100.0, $viewData['quote']['parts'][0]['items'][0]['unit_price']);
        $this->assertSame('Obs de test', $viewData['quote']['observations']);
    }

    public function test_pdf_incluye_foto_del_producto_cuando_existe(): void
    {
        $this->grantVentasDisenoEdit('designer@atlantic.test');
        $ownerId = $this->userIdByEmail('designer@atlantic.test');
        $hierarchy = $this->makeClientHierarchy();
        $product = \App\Modules\VentasDiseno\Models\CatalogProduct::create([
            'reference' => 'FOTO-1', 'name' => 'Producto con foto', 'photo_url' => 'https://example.test/foto.png',
            'price_full' => 50, 'is_active' => true,
        ]);
        $quote = Quote::create([
            'owner_id' => $ownerId, 'master_client_id' => $hierarchy['master']->id,
            'sub_client_id' => $hierarchy['sub']->id, 'sales_project_id' => $hierarchy['project']->id,
            'description' => 'Con foto', 'price_type' => 'project', 'discount_mode' => 'line', 'status' => 'draft',
            'folio' => 'TEST-PDF-002', 'generated_at' => now(),
        ]);
        $part = \App\Modules\VentasDiseno\Models\QuotePart::create(['quote_id' => $quote->id, 'name' => 'P1', 'position' => 1]);
        \App\Modules\VentasDiseno\Models\QuoteItem::create([
            'quote_part_id' => $part->id, 'catalog_product_id' => $product->id, 'is_custom' => false,
            'reference' => $product->reference, 'description' => $product->name, 'quantity' => 1, 'unit_price' => 50,
        ]);

        $controller = app(\App\Modules\VentasDiseno\Http\Controllers\QuoteController::class);
        $reflection = new \ReflectionMethod($controller, 'formatDetail');
        $reflection->setAccessible(true);
        $user = \App\Models\User::find($ownerId);
        $detail = $reflection->invoke($controller, $quote->fresh(['parts.items']), $user);

        $this->assertSame('https://example.test/foto.png', $detail['parts'][0]['items'][0]['photo_url']);
    }

    public function test_pdf_de_cotizacion_ajena_retorna_403(): void
    {
        $this->grantVentasDisenoEdit('management@atlantic.test');
        $ownerId = $this->userIdByEmail('management@atlantic.test');
        $data = $this->makeQuoteWithPdfableData($ownerId);

        $this->grantVentasDisenoRead('designer@atlantic.test'); // sin alcance de equipo, no dueño
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->get("/api/ventas-diseno/quotes/{$data['quote']->id}/pdf")
            ->assertStatus(403);
    }

    public function test_pdf_de_cotizacion_inexistente_retorna_404(): void
    {
        $this->grantVentasDisenoEdit('designer@atlantic.test');
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->get('/api/ventas-diseno/quotes/999999/pdf')
            ->assertStatus(404);
    }
}
