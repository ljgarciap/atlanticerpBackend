<?php

declare(strict_types=1);

namespace Tests\Feature\VentasDiseno;

use App\Modules\VentasDiseno\Models\MasterClient;
use App\Modules\VentasDiseno\Models\PipelineCard;
use App\Modules\VentasDiseno\Models\Quote;
use App\Modules\VentasDiseno\Models\SalesProject;
use App\Modules\VentasDiseno\Models\SubClient;
use App\Shared\Multitenancy\Tenant;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-1143 — el Responsable del proyecto (dueño de la tarjeta del Pipeline o en `compartido_con`) puede VER
 * (show / pdf / versions) las versiones confirmadas del proyecto aunque las haya generado otra persona (Gerencia):
 * `quotes.owner_id` de esa fila es Gerencia, pero «Responsable» sigue siendo el Vendedor. Toda ESCRITURA sigue
 * exigiendo ser dueño de la fila o tener visibilidad de equipo, y los borradores ajenos siguen privados.
 */
class QuoteResponsibleViewTest extends FeatureTestCase
{
    private const MARK = 'gerencia3@test.com';

    private const RESPONSABLE = 'vendedordisenador9@test.com';

    private const COMPARTIDO = 'vendedordisenador2@test.com';

    private const AJENO = 'vendedordisenador5@test.com';

    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    private function userIdByEmail(string $email): int
    {
        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');

        return (int) DB::table("{$slug}_auth.users")->where('email', $email)->value('id');
    }

    /**
     * Proyecto con tarjeta cuyo Responsable es Vendedor Disenador, una versión propia confirmada (v1), una versión de Gerencia
     * confirmada (v2, `owner_id` = Gerencia) y un borrador de Gerencia sin confirmar.
     *
     * @return array{card: PipelineCard, own: Quote, gerencia: Quote, draft: Quote}
     */
    private function makeProjectWithGerenciaVersion(array $cardOverrides = []): array
    {
        $bernardo = $this->userIdByEmail(self::RESPONSABLE);
        $approver     = $this->userIdByEmail(self::MARK);

        $master  = MasterClient::create(['name' => 'Cliente 1143 '.uniqid()]);
        $sub     = SubClient::create(['master_client_id' => $master->id, 'business_name' => 'Sub 1143 '.uniqid(), 'tax_id' => '155-0000-1-'.random_int(1000, 9999)]);
        $project = SalesProject::create(['sub_client_id' => $sub->id, 'name' => 'Proyecto 1143 '.uniqid()]);
        $card    = PipelineCard::create([
            'sales_project_id' => $project->id, 'stage' => 'quote', 'master_client_id' => $master->id,
            'sub_client_id' => $sub->id, 'owner_id' => $bernardo, 'stage_changed_at' => Carbon::now(), 'amount' => 500.0,
            ...$cardOverrides,
        ]);

        $make = function (int $ownerId, bool $confirmed) use ($project, $master): Quote {
            $quote = Quote::create([
                'owner_id' => $ownerId, 'sales_project_id' => $project->id, 'master_client_id' => $master->id,
                'folio' => 'COT-1143-'.uniqid(), 'generated_at' => Carbon::now(), 'confirmed_at' => $confirmed ? Carbon::now() : null,
            ]);
            $quote->parts()->create(['name' => 'Partida 1'])->items()->create([
                'reference' => 'REF-1143', 'description' => 'Producto', 'quantity' => 2, 'unit_price' => 100, 'discount_percent' => 25,
            ]);

            return $quote;
        };

        return [
            'card' => $card, 'own' => $make($bernardo, true), 'gerencia' => $make($approver, true), 'draft' => $make($approver, false),
        ];
    }

    private function headersFor(string $email): array
    {
        return $this->authHeaders($this->loginAs($email, $email, 'vendedor_disenador'));
    }

    public function test_el_responsable_ve_la_version_confirmada_de_gerencia(): void
    {
        $f = $this->makeProjectWithGerenciaVersion();
        $headers = $this->headersFor(self::RESPONSABLE);

        $detail = $this->withHeaders($headers)->getJson("/api/ventas-diseno/quotes/{$f['gerencia']->id}")->assertStatus(200);
        $this->assertSame($f['gerencia']->id, $detail->json('id'));
        $this->assertSame('REF-1143', $detail->json('parts.0.items.0.reference'));
        $this->assertEquals(25, $detail->json('parts.0.items.0.discount_percent'));   // contenido completo, tal como lo dejó Gerencia
        $this->assertFalse($detail->json('can_edit'), 'Una versión confirmada nunca es editable.');
    }

    public function test_el_responsable_ve_el_pdf_y_la_lista_de_versiones_del_proyecto(): void
    {
        $f = $this->makeProjectWithGerenciaVersion();
        $headers = $this->headersFor(self::RESPONSABLE);

        $this->withHeaders($headers)->get("/api/ventas-diseno/quotes/{$f['gerencia']->id}/pdf")->assertStatus(200);

        $ids = collect($this->withHeaders($headers)->getJson("/api/ventas-diseno/quotes/{$f['gerencia']->id}/versions")->assertStatus(200)->json('versions'))->pluck('id')->all();
        $this->assertContains($f['own']->id, $ids);
        $this->assertContains($f['gerencia']->id, $ids);
        $this->assertNotContains($f['draft']->id, $ids, 'Los borradores no son versiones.');
    }

    // Pre-QA — el listado de versiones dice por versión si el usuario puede usarla como base (duplicar), para no ofrecer un botón que da 403.
    public function test_el_listado_de_versiones_indica_cuales_puede_usar_como_base(): void
    {
        $f = $this->makeProjectWithGerenciaVersion();

        $versions = collect($this->withHeaders($this->headersFor(self::RESPONSABLE))
            ->getJson("/api/ventas-diseno/quotes/{$f['gerencia']->id}/versions")->assertStatus(200)->json('versions'))->keyBy('id');

        $this->assertTrue($versions[$f['own']->id]['can_use_as_base'], 'Su propia versión sí se puede duplicar.');
        // SCRUM-1195 (decisión de Luis): el Responsable parte también de la versión confirmada que generó Gerencia.
        $this->assertTrue($versions[$f['gerencia']->id]['can_use_as_base'], 'Como Responsable del proyecto puede usar de base la versión de Gerencia.');

        $ajeno = collect($this->withHeaders($this->headersFor(self::AJENO))
            ->getJson("/api/ventas-diseno/quotes/{$f['gerencia']->id}/versions")->json('versions'));
        $this->assertSame(0, $ajeno->count(), 'Un ajeno al proyecto ni siquiera ve las versiones.');
    }

    public function test_un_responsable_compartido_tambien_la_ve(): void
    {
        $f = $this->makeProjectWithGerenciaVersion(['compartido_con' => [$this->userIdByEmail(self::COMPARTIDO)]]);

        $this->withHeaders($this->headersFor(self::COMPARTIDO))
            ->getJson("/api/ventas-diseno/quotes/{$f['gerencia']->id}")->assertStatus(200);
    }

    public function test_un_vendedor_ajeno_al_proyecto_sigue_sin_acceso(): void
    {
        $f = $this->makeProjectWithGerenciaVersion();
        $headers = $this->headersFor(self::AJENO);

        $this->withHeaders($headers)->getJson("/api/ventas-diseno/quotes/{$f['gerencia']->id}")->assertStatus(403);
        $this->withHeaders($headers)->get("/api/ventas-diseno/quotes/{$f['gerencia']->id}/pdf")->assertStatus(403);
        $this->withHeaders($headers)->getJson("/api/ventas-diseno/quotes/{$f['gerencia']->id}/versions")->assertStatus(403);
        $this->withHeaders($headers)->getJson("/api/ventas-diseno/quotes/{$f['own']->id}")->assertStatus(403);
    }

    public function test_el_borrador_de_gerencia_sigue_privado_para_el_responsable(): void
    {
        $f = $this->makeProjectWithGerenciaVersion();
        $headers = $this->headersFor(self::RESPONSABLE);

        $this->withHeaders($headers)->getJson("/api/ventas-diseno/quotes/{$f['draft']->id}")->assertStatus(403);
        $this->withHeaders($headers)->get("/api/ventas-diseno/quotes/{$f['draft']->id}/pdf")->assertStatus(403);
    }

    public function test_el_responsable_no_gana_ninguna_escritura_sobre_la_version_de_gerencia(): void
    {
        $f = $this->makeProjectWithGerenciaVersion();
        $partId = $f['gerencia']->parts()->first()->id;   // antes del login: el round-trip HTTP pierde el tenant actual
        $headers = $this->headersFor(self::RESPONSABLE);
        $base = "/api/ventas-diseno/quotes/{$f['gerencia']->id}";

        $this->withHeaders($headers)->postJson("{$base}/generate")->assertStatus(403);
        $this->withHeaders($headers)->postJson("{$base}/confirm")->assertStatus(403);
        $this->withHeaders($headers)->postJson("{$base}/parts/{$partId}/items", ['reference' => 'X', 'description' => 'Y', 'quantity' => 1, 'unit_price' => 10])->assertStatus(403);
        $this->withHeaders($headers)->putJson($base, ['description' => 'cambiado'])->assertStatus(403);
    }

    public function test_si_dejan_de_ser_responsable_pierden_el_acceso(): void
    {
        $f = $this->makeProjectWithGerenciaVersion();
        $this->withHeaders($this->headersFor(self::RESPONSABLE))->getJson("/api/ventas-diseno/quotes/{$f['gerencia']->id}")->assertStatus(200);

        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $f['card']->update(['owner_id' => $this->userIdByEmail(self::AJENO)]);

        $this->withHeaders($this->headersFor(self::RESPONSABLE))->getJson("/api/ventas-diseno/quotes/{$f['gerencia']->id}")->assertStatus(403);
    }

    public function test_una_cotizacion_sin_proyecto_no_abre_acceso_por_responsable(): void
    {
        $f = $this->makeProjectWithGerenciaVersion();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $sinProyecto = Quote::create(['owner_id' => $this->userIdByEmail(self::MARK), 'folio' => 'COT-1143-SP', 'confirmed_at' => Carbon::now()]);

        $this->withHeaders($this->headersFor(self::RESPONSABLE))
            ->getJson("/api/ventas-diseno/quotes/{$sinProyecto->id}")->assertStatus(403);
    }

    // Consistencia ver/mover (COT-2026-0179): para el Responsable ambos se permiten; para un ajeno, ambos se deniegan.
    public function test_ver_la_cotizacion_y_acceder_a_la_tarjeta_son_consistentes(): void
    {
        $f = $this->makeProjectWithGerenciaVersion();

        $resp = $this->headersFor(self::RESPONSABLE);
        $this->withHeaders($resp)->getJson("/api/ventas-diseno/quotes/{$f['gerencia']->id}")->assertStatus(200);
        $this->withHeaders($resp)->getJson("/api/ventas-diseno/pipeline/{$f['card']->id}")->assertStatus(200);

        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $ajeno = $this->headersFor(self::AJENO);
        $this->withHeaders($ajeno)->getJson("/api/ventas-diseno/quotes/{$f['gerencia']->id}")->assertStatus(403);
        $this->withHeaders($ajeno)->getJson("/api/ventas-diseno/pipeline/{$f['card']->id}")->assertStatus(403);
    }

    public function test_el_responsable_y_el_creador_de_la_tarjeta_no_cambian(): void
    {
        $f = $this->makeProjectWithGerenciaVersion();
        $this->withHeaders($this->headersFor(self::RESPONSABLE))->getJson("/api/ventas-diseno/quotes/{$f['gerencia']->id}")->assertStatus(200);

        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $this->assertSame($this->userIdByEmail(self::RESPONSABLE), (int) $f['card']->fresh()->owner_id);
    }

    // ── SCRUM-1195 (decisión de Luis): el Responsable puede «Usar como base» la versión confirmada de Gerencia ────────────

    public function test_el_responsable_crea_una_version_nueva_partiendo_de_la_de_gerencia_y_la_edita_como_suya(): void
    {
        $f = $this->makeProjectWithGerenciaVersion();
        $bernardo = $this->userIdByEmail(self::RESPONSABLE);
        $headers  = $this->headersFor(self::RESPONSABLE);

        $created = $this->withHeaders($headers)->postJson("/api/ventas-diseno/quotes/{$f['gerencia']->id}/duplicate")->assertStatus(201);

        // nace como borrador SUYO con el contenido que dejó Gerencia
        $this->assertSame($bernardo, $created->json('owner.id'));
        $this->assertNull($created->json('confirmed_at'));
        $this->assertSame('REF-1143', $created->json('parts.0.items.0.reference'));
        $this->assertTrue($created->json('can_edit'));

        // y lo edita como cualquier dueño
        $newId = $created->json('id');
        $this->withHeaders($headers)->putJson("/api/ventas-diseno/quotes/{$newId}", ['description' => 'Ajustada por el diseñador'])->assertStatus(200);

        // la versión de Gerencia queda intacta (sigue confirmada, de Gerencia y sin cambios)
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $original = Quote::find($f['gerencia']->id);
        $this->assertNotNull($original->confirmed_at);
        $this->assertSame($this->userIdByEmail(self::MARK), $original->owner_id);
        $this->assertNotSame('Ajustada por el diseñador', $original->description);
    }

    public function test_un_responsable_compartido_tambien_puede_usar_de_base(): void
    {
        $f = $this->makeProjectWithGerenciaVersion(['compartido_con' => [$this->userIdByEmail(self::COMPARTIDO)]]);

        $this->withHeaders($this->headersFor(self::COMPARTIDO))
            ->postJson("/api/ventas-diseno/quotes/{$f['gerencia']->id}/duplicate")->assertStatus(201);
    }

    public function test_un_ajeno_al_proyecto_no_puede_usar_de_base_ni_el_borrador_de_gerencia(): void
    {
        $f = $this->makeProjectWithGerenciaVersion();

        $this->withHeaders($this->headersFor(self::AJENO))->postJson("/api/ventas-diseno/quotes/{$f['gerencia']->id}/duplicate")->assertStatus(403);
        // ni siquiera el Responsable puede partir de un borrador ajeno sin confirmar
        $this->withHeaders($this->headersFor(self::RESPONSABLE))->postJson("/api/ventas-diseno/quotes/{$f['draft']->id}/duplicate")->assertStatus(403);
    }

    public function test_si_dejan_de_ser_responsables_pierden_tambien_el_usar_como_base(): void
    {
        $f = $this->makeProjectWithGerenciaVersion();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $f['card']->update(['owner_id' => $this->userIdByEmail(self::AJENO)]);

        $this->withHeaders($this->headersFor(self::RESPONSABLE))->postJson("/api/ventas-diseno/quotes/{$f['gerencia']->id}/duplicate")->assertStatus(403);
    }
}
