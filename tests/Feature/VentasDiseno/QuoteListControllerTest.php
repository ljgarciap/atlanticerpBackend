<?php

declare(strict_types=1);

namespace Tests\Feature\VentasDiseno;

use App\Modules\VentasDiseno\Models\MasterClient;
use App\Modules\VentasDiseno\Models\PipelineCard;
use App\Modules\VentasDiseno\Models\Quote;
use App\Modules\VentasDiseno\Models\SalesProject;
use App\Modules\VentasDiseno\Models\SubClient;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Feature\FeatureTestCase;

/**
 * Grupo 5 (REQ-079 a 085). Ver memory/project_backlog_ventas_diseno_req.md.
 */
class QuoteListControllerTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'illuminations')->first()?->makeCurrent();
    }

    private function grantVentasDisenoRead(string $email): void
    {
        $slug = (string) Tenant::where('slug', 'illuminations')->value('slug');
        DB::table("{$slug}_auth.users")
            ->where('email', $email)
            ->update(['extra_permissions' => json_encode(['ventas_diseno.read'])]);
    }

    private function userIdByEmail(string $email): int
    {
        $slug = (string) Tenant::where('slug', 'illuminations')->value('slug');
        return (int) DB::table("{$slug}_auth.users")->where('email', $email)->value('id');
    }

    /**
     * @param array{folio?: string|null, amount?: float, stage?: string|null, master?: string, sub?: string, project?: string} $opts
     */
    private function makeQuote(int $ownerId, array $opts = []): Quote
    {
        $master  = MasterClient::create(['name' => $opts['master'] ?? 'Cliente Test']);
        $sub     = SubClient::create(['master_client_id' => $master->id, 'business_name' => $opts['sub'] ?? 'Subcliente Test', 'tax_id' => '155-0000-1-2026']);
        $project = SalesProject::create(['sub_client_id' => $sub->id, 'name' => $opts['project'] ?? 'Proyecto Test']);

        // SCRUM-723: confirmed_at (no folio) es lo que ahora decide document_status
        // — este helper simula una cotización ya "guardada" (Enviada/Aprobada), así
        // que confirma junto con el folio, igual que generated_at.
        $isGenerated = array_key_exists('folio', $opts) && $opts['folio'] !== null;
        $quote = Quote::create([
            'owner_id' => $ownerId, 'master_client_id' => $master->id, 'sub_client_id' => $sub->id,
            'sales_project_id' => $project->id, 'folio' => $opts['folio'] ?? null,
            'generated_at' => $isGenerated ? now() : null,
            'confirmed_at' => $isGenerated ? now() : null,
        ]);

        if (array_key_exists('stage', $opts) && $opts['stage'] !== null) {
            $part = $quote->parts()->create(['name' => 'Partida 1']);
            $part->items()->create(['reference' => 'REF-1', 'description' => 'X', 'quantity' => 1, 'unit_price' => $opts['amount'] ?? 1000]);

            PipelineCard::create([
                'sales_project_id' => $project->id, 'stage' => $opts['stage'],
                'master_client_id' => $master->id, 'sub_client_id' => $sub->id,
                'owner_id' => $ownerId, 'stage_changed_at' => now(),
            ]);
        }

        return $quote->fresh(['parts.items']);
    }

    public function test_listado_sin_autenticar_retorna_401(): void
    {
        $this->getJson('/api/ventas-diseno/quotes')->assertStatus(401);
    }

    public function test_alcance_propio_solo_muestra_las_del_usuario(): void
    {
        $this->grantVentasDisenoRead('designer@illuminations.test');
        $designerId = $this->userIdByEmail('designer@illuminations.test');
        $otherId    = $this->userIdByEmail('management@illuminations.test');

        $mine  = $this->makeQuote($designerId, ['folio' => 'COT-2026-0001']);
        $their = $this->makeQuote($otherId, ['folio' => 'COT-2026-0002']);

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/quotes?scope=own')
            ->assertStatus(200);

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertContains($mine->id, $ids);
        $this->assertNotContains($their->id, $ids);
    }

    public function test_estado_documento_borrador_enviada_aprobada(): void
    {
        $this->grantVentasDisenoRead('designer@illuminations.test');
        $designerId = $this->userIdByEmail('designer@illuminations.test');

        $draft    = $this->makeQuote($designerId, []); // sin folio
        $sent     = $this->makeQuote($designerId, ['folio' => 'COT-2026-0010', 'stage' => 'quote', 'amount' => 100]);
        $approved = $this->makeQuote($designerId, ['folio' => 'COT-2026-0011', 'stage' => 'approved', 'amount' => 100]);

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/quotes')
            ->assertStatus(200);

        $rows = collect($response->json('data'))->keyBy('id');
        $this->assertSame('draft', $rows[$draft->id]['document_status']);
        $this->assertSame('sent', $rows[$sent->id]['document_status']);
        $this->assertSame('approved', $rows[$approved->id]['document_status']);
    }

    public function test_resumen_calcula_montos_y_tasa_de_conversion(): void
    {
        $this->grantVentasDisenoRead('designer@illuminations.test');
        $designerId = $this->userIdByEmail('designer@illuminations.test');

        $this->makeQuote($designerId, ['folio' => 'COT-2026-0020', 'stage' => 'quote', 'amount' => 1000]);
        $this->makeQuote($designerId, ['folio' => 'COT-2026-0021', 'stage' => 'approved', 'amount' => 2000]);
        $this->makeQuote($designerId, ['folio' => 'COT-2026-0022', 'stage' => 'lost', 'amount' => 500]);

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/quotes')
            ->assertStatus(200);

        // 1000 * 1.07 (ITBMS) = 1070; 2000*1.07=2140; 500*1.07=535.
        $response->assertJsonPath('summary.pending_amount', 1070);
        $response->assertJsonPath('summary.approved_amount', 2140);
        $response->assertJsonPath('summary.approved_count', 1);
        $response->assertJsonPath('summary.lost_amount', 535);
        $response->assertJsonPath('summary.lost_count', 1);
        $response->assertJsonPath('summary.conversion_rate', 50); // 1/(1+1)*100
    }

    public function test_resumen_excluye_borradores_y_clasifica_por_estado_de_documento(): void
    {
        // SCRUM-102 (hallazgo Marly 2026-07-15): "vigente" mostraba $0 pese a
        // 3 cotizaciones Enviadas (porque el summary viejo solo miraba el
        // stage de PipelineCard, no document_status), y "Aprobado" contaba
        // borradores ($0, sin folio) de proyectos ya en etapa Aprobado.
        $this->grantVentasDisenoRead('designer@illuminations.test');
        $designerId = $this->userIdByEmail('designer@illuminations.test');

        // Enviada, tarjeta todavía en Diseño (etapa temprana no contemplada
        // por el bucketing viejo de "vigente" = solo stage quote/proposal).
        $this->makeQuote($designerId, ['folio' => 'COT-2026-0070', 'stage' => 'design', 'amount' => 100]);
        // Borrador (sin folio) ligado a un proyecto ya Aprobado -- no debe sumar ni contar.
        $this->makeQuote($designerId, ['stage' => 'approved', 'amount' => 999]);
        // Aprobada real, generada.
        $this->makeQuote($designerId, ['folio' => 'COT-2026-0071', 'stage' => 'approved', 'amount' => 200]);

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/quotes')
            ->assertStatus(200);

        // 100*1.07=107; 200*1.07=214.
        $response->assertJsonPath('summary.pending_amount', 107);
        $response->assertJsonPath('summary.approved_amount', 214);
        $response->assertJsonPath('summary.approved_count', 1);
    }

    /**
     * SCRUM-734 (Senior Review retroactivo 2026-08-06, hallazgo CRÍTICO real,
     * reproducido en vivo) — desde que `QuoteService::duplicate()` permite varias
     * versiones CONFIRMADAS del mismo proyecto, el resumen sumaba `grandTotal()`
     * de cada versión (RN4.3: debe leer "el" Valor del proyecto — la versión más
     * reciente — nunca sumar la tabla de cotizaciones).
     *
     * SCRUM-796 (secc. 10, RN1.3 — corrige la definición anterior citada arriba,
     * "cada versión sigue apareciendo como su propia fila"): ahora `data` también
     * dedupea por proyecto — solo 1 fila (la más reciente), igual que `summary`.
     */
    public function test_resumen_no_duplica_monto_cuando_el_proyecto_tiene_varias_versiones_confirmadas(): void
    {
        $this->grantVentasDisenoRead('designer@illuminations.test');
        $designerId = $this->userIdByEmail('designer@illuminations.test');

        $master  = MasterClient::create(['name' => 'Cliente Test']);
        $sub     = SubClient::create(['master_client_id' => $master->id, 'business_name' => 'Sub Test', 'tax_id' => '155-0000-1-2026']);
        $project = SalesProject::create(['sub_client_id' => $sub->id, 'name' => 'Proyecto Test']);
        PipelineCard::create([
            'sales_project_id' => $project->id, 'stage' => 'quote', 'amount' => 1070,
            'master_client_id' => $master->id, 'sub_client_id' => $sub->id,
            'owner_id' => $designerId, 'stage_changed_at' => now(),
        ]);

        // Versión 1 — confirmada primero, total $1000 (+ITBMS = 1070).
        $v1 = Quote::create([
            'owner_id' => $designerId, 'master_client_id' => $master->id, 'sub_client_id' => $sub->id,
            'sales_project_id' => $project->id, 'folio' => 'COT-2026-0080',
            'generated_at' => now()->subMinutes(10), 'confirmed_at' => now()->subMinutes(10),
        ]);
        $v1->parts()->create(['name' => 'Partida 1'])->items()->create([
            'reference' => 'REF-1', 'description' => 'X', 'quantity' => 1, 'unit_price' => 1000,
        ]);

        // Versión 2 — "usar como base para nueva versión", confirmada después,
        // mismo sales_project_id — total distinto, $500 (+ITBMS = 535).
        $v2 = Quote::create([
            'owner_id' => $designerId, 'master_client_id' => $master->id, 'sub_client_id' => $sub->id,
            'sales_project_id' => $project->id, 'folio' => 'COT-2026-0081',
            'generated_at' => now(), 'confirmed_at' => now(),
        ]);
        $v2->parts()->create(['name' => 'Partida 1'])->items()->create([
            'reference' => 'REF-1', 'description' => 'X', 'quantity' => 1, 'unit_price' => 500,
        ]);

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/quotes')
            ->assertStatus(200);

        // SCRUM-796 (RN1.3) — 1 sola fila para el proyecto, la más reciente (v2, $535 con
        // ITBMS) — v1 ($1070) ya no aparece en la lista principal, sigue en el historial.
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertSame($v2->id, $data[0]['id']);

        // El resumen solo cuenta la versión más reciente (535), no la suma de las 2 (1605).
        $response->assertJsonPath('summary.pending_amount', 535);
    }

    /**
     * SCRUM-796 (secc. 10, RN1.3) — un Borrador NUNCA es "versión" en el sentido del
     * ticket (RN2.3: versión = ya generada/confirmada); aunque comparta `sales_project_id`
     * con una confirmada, sigue siendo su propia fila — REQ-082 exige verlo en la lista.
     */
    public function test_borrador_sobre_proyecto_con_version_confirmada_no_se_funde_con_ella(): void
    {
        $this->grantVentasDisenoRead('designer@illuminations.test');
        $designerId = $this->userIdByEmail('designer@illuminations.test');

        $master  = MasterClient::create(['name' => 'Cliente Borrador Test']);
        $sub     = SubClient::create(['master_client_id' => $master->id, 'business_name' => 'Sub Test', 'tax_id' => '155-0000-1-2026']);
        $project = SalesProject::create(['sub_client_id' => $sub->id, 'name' => 'Proyecto Borrador Test']);
        PipelineCard::create([
            'sales_project_id' => $project->id, 'stage' => 'quote',
            'master_client_id' => $master->id, 'sub_client_id' => $sub->id,
            'owner_id' => $designerId, 'stage_changed_at' => now(),
        ]);

        $confirmed = Quote::create([
            'owner_id' => $designerId, 'master_client_id' => $master->id, 'sub_client_id' => $sub->id,
            'sales_project_id' => $project->id, 'folio' => 'COT-2026-0090',
            'generated_at' => now(), 'confirmed_at' => now(),
        ]);
        // Borrador nuevo (sin folio, sin confirmar) sobre el MISMO proyecto — "usar como
        // base para nueva versión", todavía sin generar.
        $draft = Quote::create([
            'owner_id' => $designerId, 'master_client_id' => $master->id, 'sub_client_id' => $sub->id,
            'sales_project_id' => $project->id, 'folio' => null,
            'generated_at' => null, 'confirmed_at' => null,
        ]);

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/quotes')
            ->assertStatus(200);

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertCount(2, $ids);
        $this->assertContains($confirmed->id, $ids);
        $this->assertContains($draft->id, $ids);
    }

    /**
     * SCRUM-796 (secc. 10, RN1.3) — 2 Borradores sueltos de proyectos distintos, sin
     * ninguna versión confirmada todavía: ninguno se agrupa con nada, ambos visibles.
     */
    public function test_dos_borradores_de_proyectos_distintos_aparecen_ambos(): void
    {
        $this->grantVentasDisenoRead('designer@illuminations.test');
        $designerId = $this->userIdByEmail('designer@illuminations.test');

        $draftA = $this->makeQuote($designerId, ['project' => 'Proyecto Borrador A']);
        $draftB = $this->makeQuote($designerId, ['project' => 'Proyecto Borrador B']);

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/quotes')
            ->assertStatus(200);

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertContains($draftA->id, $ids);
        $this->assertContains($draftB->id, $ids);
        $this->assertCount(2, $ids);
    }

    /**
     * SCRUM-796 (secc. 10, RN1.3) — buscar el folio de una versión VIEJA (no la más
     * reciente) de un proyecto ya no la trae en la lista principal: el dedupe corre
     * antes de la búsqueda, solo la fila más reciente del proyecto participa. El folio
     * viejo sigue accesible desde el historial de versiones del detalle (CA3), no desde
     * este endpoint.
     */
    public function test_buscar_folio_de_version_vieja_no_la_encuentra_en_la_lista_principal(): void
    {
        $this->grantVentasDisenoRead('designer@illuminations.test');
        $designerId = $this->userIdByEmail('designer@illuminations.test');

        $master  = MasterClient::create(['name' => 'Cliente Folio Viejo Test']);
        $sub     = SubClient::create(['master_client_id' => $master->id, 'business_name' => 'Sub Test', 'tax_id' => '155-0000-1-2026']);
        $project = SalesProject::create(['sub_client_id' => $sub->id, 'name' => 'Proyecto Folio Viejo Test']);
        PipelineCard::create([
            'sales_project_id' => $project->id, 'stage' => 'quote',
            'master_client_id' => $master->id, 'sub_client_id' => $sub->id,
            'owner_id' => $designerId, 'stage_changed_at' => now(),
        ]);

        Quote::create([
            'owner_id' => $designerId, 'master_client_id' => $master->id, 'sub_client_id' => $sub->id,
            'sales_project_id' => $project->id, 'folio' => 'COT-2026-0091',
            'generated_at' => now()->subMinutes(10), 'confirmed_at' => now()->subMinutes(10),
        ]);
        $latest = Quote::create([
            'owner_id' => $designerId, 'master_client_id' => $master->id, 'sub_client_id' => $sub->id,
            'sales_project_id' => $project->id, 'folio' => 'COT-2026-0092',
            'generated_at' => now(), 'confirmed_at' => now(),
        ]);

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/quotes?search=0091')
            ->assertStatus(200);

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertNotContains($latest->id, $ids); // no matchea el folio buscado (0091 vs 0092)
        $this->assertCount(0, $ids); // el folio viejo (0091) ya no está en la tabla principal
    }

    public function test_tasa_de_conversion_es_0_sin_decididas(): void
    {
        $this->grantVentasDisenoRead('designer@illuminations.test');
        $designerId = $this->userIdByEmail('designer@illuminations.test');
        $this->makeQuote($designerId, ['folio' => 'COT-2026-0030', 'stage' => 'quote', 'amount' => 100]);

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/quotes')
            ->assertStatus(200);

        $response->assertJsonPath('summary.conversion_rate', 0);
    }

    public function test_filtro_por_etapa(): void
    {
        $this->grantVentasDisenoRead('designer@illuminations.test');
        $designerId = $this->userIdByEmail('designer@illuminations.test');
        $quote = $this->makeQuote($designerId, ['folio' => 'COT-2026-0040', 'stage' => 'quote', 'amount' => 100]);
        $this->makeQuote($designerId, ['folio' => 'COT-2026-0041', 'stage' => 'approved', 'amount' => 100]);

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/quotes?stage=quote')
            ->assertStatus(200);

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertContains($quote->id, $ids);
        $this->assertCount(1, $ids);
    }

    public function test_busqueda_exacta_por_folio(): void
    {
        $this->grantVentasDisenoRead('designer@illuminations.test');
        $designerId = $this->userIdByEmail('designer@illuminations.test');
        $quote = $this->makeQuote($designerId, ['folio' => 'COT-2026-0050']);
        $this->makeQuote($designerId, ['folio' => 'COT-2026-0099']);

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/quotes?search=0050')
            ->assertStatus(200);

        $response->assertJsonPath('fuzzy', false);
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertContains($quote->id, $ids);
        $this->assertCount(1, $ids);
    }

    public function test_busqueda_aproximada_cuando_no_hay_coincidencia_exacta(): void
    {
        $this->grantVentasDisenoRead('designer@illuminations.test');
        $designerId = $this->userIdByEmail('designer@illuminations.test');
        $quote = $this->makeQuote($designerId, ['folio' => 'COT-2026-0060', 'master' => 'Torre Delta Constructora']);

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        // Error de tipeo de una sola palabra: "Delta" -> "Delto".
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/quotes?search=Delto')
            ->assertStatus(200);

        $response->assertJsonPath('fuzzy', true);
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertContains($quote->id, $ids);
    }

    public function test_busqueda_aproximada_de_varias_palabras_con_tipo_en_una_sola(): void
    {
        // QA formal 2026-07-12: antes solo se tokenizaba el haystack, nunca el
        // término buscado — un término de 2 palabras con un típo en una sola
        // (ej. "Torre Delto") comparaba el término COMPLETO contra cada
        // palabra suelta del haystack, y la palabra "Torre" que sí calza
        // exacto inflaba la distancia total por encima del máximo permitido.
        // Resultado: 0 resultados, sin banner — en vez de encontrar el fuzzy
        // match esperado.
        $this->grantVentasDisenoRead('designer@illuminations.test');
        $designerId = $this->userIdByEmail('designer@illuminations.test');
        $quote = $this->makeQuote($designerId, ['folio' => 'COT-2026-0061', 'master' => 'Torre Delta Constructora']);

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/quotes?search=Torre Delto')
            ->assertStatus(200);

        $response->assertJsonPath('fuzzy', true);
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertContains($quote->id, $ids);
    }

    public function test_busqueda_aproximada_de_folio_sin_guiones(): void
    {
        // SCRUM-103 (comentario Daniela Amaya, 2026-07-22): buscar el folio sin ninguno
        // de sus 2 guiones ("COT20260006") debe seguir encontrando "COT-2026-0006" por
        // aproximación -- ya cubierto por el fix de tokenización de 2026-07-12 (distancia
        // Levenshtein de 2 inserciones, dentro de la tolerancia), pero sin test dedicado.
        // Reproducido contra dev/test.atlanticerp.ai reales (2026-07-29): el código actual ya
        // resuelve esto correctamente, este test solo fija la regresión.
        $this->grantVentasDisenoRead('designer@illuminations.test');
        $designerId = $this->userIdByEmail('designer@illuminations.test');
        $quote = $this->makeQuote($designerId, ['folio' => 'COT-2026-0006']);
        $this->makeQuote($designerId, ['folio' => 'COT-2026-0099']);

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/quotes?search=COT20260006')
            ->assertStatus(200);

        $response->assertJsonPath('fuzzy', true);
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertContains($quote->id, $ids);
    }
}
