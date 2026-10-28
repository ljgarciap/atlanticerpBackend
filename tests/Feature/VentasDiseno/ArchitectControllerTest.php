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
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\FeatureTestCase;

// REQ-030 / SCRUM-122: buscar/crear Arquitecto acotado a un Subcliente, desde Cotización. SCRUM-1121: la base es ahora global
// (ver AssociatesBaseTest); estos tests cubren el modo acotado heredado.
class ArchitectControllerTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    private function grantVentasDiseno(string $email, string ...$permissions): void
    {
        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');
        DB::table("{$slug}_auth.users")
            ->where('email', $email)
            ->update(['extra_permissions' => json_encode(array_merge(['ventas_diseno.read'], $permissions))]);
    }

    /** @return array{0: SubClient, 1: SubClient} */
    private function makeTwoSubClients(): array
    {
        $master = MasterClient::create(['name' => 'Cliente Test']);
        $sub    = SubClient::create(['master_client_id' => $master->id, 'business_name' => 'Sub Test', 'tax_id' => '155-0000-1-2026']);
        $other  = SubClient::create(['master_client_id' => $master->id, 'business_name' => 'Otro Sub', 'tax_id' => '155-1111-1-2026']);

        return [$sub, $other];
    }

    private function userIdByEmail(string $email): int
    {
        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');

        return (int) DB::table("{$slug}_auth.users")->where('email', $email)->value('id');
    }

    private function makeCardForSubClient(SubClient $sub, int $ownerId): PipelineCard
    {
        $project = SalesProject::create(['sub_client_id' => $sub->id, 'name' => 'Proyecto '.uniqid()]);

        return PipelineCard::create([
            'sales_project_id' => $project->id, 'stage' => 'quote', 'master_client_id' => $sub->master_client_id,
            'sub_client_id' => $sub->id, 'owner_id' => $ownerId, 'stage_changed_at' => Carbon::now(),
        ]);
    }

    public function test_buscar_arquitectos_sin_autenticar_retorna_401(): void
    {
        $this->getJson('/api/ventas-diseno/architects')->assertStatus(401);
    }

    public function test_listar_arquitectos_sin_sub_client_id_retorna_el_directorio_global(): void
    {
        // SCRUM-907 — la pantalla "Arquitectos" del menú CRM necesita un listado global, sin
        // acotar a un Subcliente (a diferencia del picker de Cotización, que sí lo exige vía
        // sub_client_id). Mismo endpoint sirve ambos casos.
        $this->grantVentasDiseno('vendedor@atlantic.test');
        [$sub, $other] = $this->makeTwoSubClients();
        Architect::create(['sub_client_id' => $sub->id, 'name' => 'Arq. Fernando Solís', 'phone' => '6000-0000']);
        Architect::create(['sub_client_id' => $other->id, 'name' => 'Arq. De Otro Sub', 'email' => 'otro@example.com']);

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/architects')
            ->assertStatus(200);

        $names = collect($response->json('data'))->pluck('name');
        $this->assertTrue($names->contains('Arq. Fernando Solís'));
        $this->assertTrue($names->contains('Arq. De Otro Sub'));
    }

    public function test_listar_arquitectos_sin_sub_client_id_filtra_por_nombre(): void
    {
        $this->grantVentasDiseno('vendedor@atlantic.test');
        [$sub] = $this->makeTwoSubClients();
        Architect::create(['sub_client_id' => $sub->id, 'name' => 'Arq. Fernando Solís', 'phone' => '6000-0000']);
        Architect::create(['sub_client_id' => $sub->id, 'name' => 'Arq. Otro', 'email' => 'otro@example.com']);

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/architects?search=Fernando')
            ->assertStatus(200);

        $names = collect($response->json('data'))->pluck('name');
        $this->assertTrue($names->contains('Arq. Fernando Solís'));
        $this->assertFalse($names->contains('Arq. Otro'));
    }

    public function test_buscar_arquitectos_acotado_al_subcliente(): void
    {
        $this->grantVentasDiseno('vendedor@atlantic.test');
        [$sub, $other] = $this->makeTwoSubClients();
        Architect::create(['sub_client_id' => $sub->id, 'name' => 'Arq. Fernando Solís', 'phone' => '6000-0000']);
        Architect::create(['sub_client_id' => $other->id, 'name' => 'Arq. De Otro Sub', 'email' => 'otro@example.com']);

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/ventas-diseno/architects?sub_client_id={$sub->id}")
            ->assertStatus(200);

        $names = collect($response->json('data'))->pluck('name');
        $this->assertTrue($names->contains('Arq. Fernando Solís'));
        $this->assertFalse($names->contains('Arq. De Otro Sub'));
    }

    public function test_buscar_arquitectos_por_nombre_dentro_del_subcliente(): void
    {
        $this->grantVentasDiseno('vendedor@atlantic.test');
        [$sub] = $this->makeTwoSubClients();
        Architect::create(['sub_client_id' => $sub->id, 'name' => 'Arq. Fernando Solís', 'phone' => '6000-0000']);
        Architect::create(['sub_client_id' => $sub->id, 'name' => 'Arq. Otro', 'email' => 'otro@example.com']);

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/ventas-diseno/architects?sub_client_id={$sub->id}&search=Fernando")
            ->assertStatus(200);

        $names = collect($response->json('data'))->pluck('name');
        $this->assertTrue($names->contains('Arq. Fernando Solís'));
        $this->assertFalse($names->contains('Arq. Otro'));
    }

    public function test_arquitecto_huerfano_sin_subcliente_no_aparece_en_ninguna_busqueda(): void
    {
        $this->grantVentasDiseno('vendedor@atlantic.test');
        [$sub] = $this->makeTwoSubClients();
        // Simula un registro creado antes de SCRUM-122 (directorio global, sin sub_client_id).
        Architect::create(['name' => 'Arq. Legacy Global', 'phone' => '6000-9999']);

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/ventas-diseno/architects?sub_client_id={$sub->id}")
            ->assertStatus(200);

        $names = collect($response->json('data'))->pluck('name');
        $this->assertFalse($names->contains('Arq. Legacy Global'));
    }

    public function test_crear_arquitecto_sin_sub_client_id_ahora_es_valido_la_base_es_global(): void
    {
        // SCRUM-1121: la base de asociados ya no está atada a un subcliente.
        $this->grantVentasDiseno('vendedor@atlantic.test', 'ventas_diseno.write');
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/ventas-diseno/architects', ['name' => 'Arq. Sin Sub', 'phone' => '6000-0000'])
            ->assertStatus(201)
            ->assertJsonPath('type', 'architect');
    }

    public function test_crear_arquitecto_sin_telefono_ni_correo_retorna_422(): void
    {
        $this->grantVentasDiseno('vendedor@atlantic.test', 'ventas_diseno.write');
        [$sub] = $this->makeTwoSubClients();
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/ventas-diseno/architects', ['sub_client_id' => $sub->id, 'name' => 'Arq. Sin Contacto'])
            ->assertStatus(422);
    }

    public function test_crear_arquitecto(): void
    {
        $this->grantVentasDiseno('vendedor@atlantic.test', 'ventas_diseno.write');
        [$sub] = $this->makeTwoSubClients();
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/ventas-diseno/architects', [
                'sub_client_id' => $sub->id, 'name' => 'Arq. Nuevo', 'phone' => '6000-1111',
            ])
            ->assertStatus(201);

        $response->assertJsonPath('name', 'Arq. Nuevo');
    }

    public function test_crear_arquitecto_solo_con_correo_sin_telefono(): void
    {
        $this->grantVentasDiseno('vendedor@atlantic.test', 'ventas_diseno.write');
        [$sub] = $this->makeTwoSubClients();
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/ventas-diseno/architects', [
                'sub_client_id' => $sub->id, 'name' => 'Arq. Solo Correo', 'email' => 'solo.correo@example.com',
            ])
            ->assertStatus(201);

        $response->assertJsonPath('email', 'solo.correo@example.com');
        $response->assertJsonPath('phone', null);
    }

    // ── SCRUM-734 (RN8.5): edición in-place ──────────────────────────────────────

    public function test_editar_arquitecto_actualiza_el_registro_real(): void
    {
        $this->grantVentasDiseno('vendedor@atlantic.test', 'ventas_diseno.edit');
        [$sub] = $this->makeTwoSubClients();
        $architect = Architect::create(['sub_client_id' => $sub->id, 'name' => 'Arq. Fernando Solís', 'phone' => '6000-0000']);
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/ventas-diseno/architects/{$architect->id}", [
                'name' => 'Arq. Fernando Solís', 'phone' => '6000-9999', 'email' => 'fernando@example.com',
            ])
            ->assertStatus(200);

        $response->assertJsonPath('phone', '6000-9999');
        $response->assertJsonPath('email', 'fernando@example.com');

        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $this->assertSame('6000-9999', Architect::find($architect->id)?->phone);
    }

    public function test_editar_arquitecto_sin_permiso_edit_retorna_403(): void
    {
        // nivel5@ (rol sin bridge de edit por default) en vez de vendedor@ —
        // mismo motivo que QuoteControllerTest::test_actualizar_sin_permiso_edit_retorna_403.
        $this->grantVentasDiseno('nivel5@atlantic.test');
        [$sub] = $this->makeTwoSubClients();
        $architect = Architect::create(['sub_client_id' => $sub->id, 'name' => 'Arq. Fernando Solís', 'phone' => '6000-0000']);
        $token = $this->loginAs('nivel5@atlantic.test', 'Password123!');

        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/ventas-diseno/architects/{$architect->id}", ['name' => 'Arq. Fernando Solís', 'phone' => '6000-9999'])
            ->assertStatus(403);
    }

    public function test_editar_arquitecto_inexistente_retorna_404(): void
    {
        $this->grantVentasDiseno('vendedor@atlantic.test', 'ventas_diseno.edit');
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->putJson('/api/ventas-diseno/architects/999999', ['name' => 'X', 'phone' => '6000-0000'])
            ->assertStatus(404);
    }

    public function test_editar_arquitecto_sin_telefono_ni_correo_retorna_422(): void
    {
        $this->grantVentasDiseno('vendedor@atlantic.test', 'ventas_diseno.edit');
        [$sub] = $this->makeTwoSubClients();
        $architect = Architect::create(['sub_client_id' => $sub->id, 'name' => 'Arq. Fernando Solís', 'phone' => '6000-0000']);
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/ventas-diseno/architects/{$architect->id}", ['name' => 'Arq. Fernando Solís'])
            ->assertStatus(422);
    }

    // ── show (SCRUM-907) ──────────────────────────────────────────────────────────

    public function test_ver_detalle_de_arquitecto_sin_autenticar_retorna_401(): void
    {
        $this->getJson('/api/ventas-diseno/architects/1')->assertStatus(401);
    }

    public function test_ver_detalle_de_arquitecto_inexistente_retorna_404(): void
    {
        $this->grantVentasDiseno('vendedor@atlantic.test');
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/architects/999999')
            ->assertStatus(404);
    }

    public function test_ver_detalle_de_arquitecto_incluye_los_proyectos_asociados(): void
    {
        $this->grantVentasDiseno('vendedor@atlantic.test');
        $ownerId = $this->userIdByEmail('vendedor@atlantic.test');
        [$sub, $otherSub] = $this->makeTwoSubClients();
        $architect = Architect::create(['sub_client_id' => $sub->id, 'name' => 'Arq. Fernando Solís', 'phone' => '6000-0000']);

        $card = $this->makeCardForSubClient($sub, $ownerId);
        Quote::create(['owner_id' => $ownerId, 'architect_id' => $architect->id, 'sales_project_id' => $card->sales_project_id]);

        // Proyecto de otro arquitecto — no debe aparecer en la lista de éste.
        $otherArchitect = Architect::create(['sub_client_id' => $otherSub->id, 'name' => 'Arq. Otro', 'phone' => '6000-1111']);
        $otherCard = $this->makeCardForSubClient($otherSub, $ownerId);
        Quote::create(['owner_id' => $ownerId, 'architect_id' => $otherArchitect->id, 'sales_project_id' => $otherCard->sales_project_id]);

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/ventas-diseno/architects/{$architect->id}")
            ->assertStatus(200);

        $response->assertJsonPath('name', 'Arq. Fernando Solís');
        $projects = $response->json('projects');
        $this->assertCount(1, $projects);
        $this->assertSame($card->id, $projects[0]['id']);
        $this->assertSame('quote', $projects[0]['stage']);
    }

    public function test_ver_detalle_de_arquitecto_sin_proyectos_devuelve_lista_vacia(): void
    {
        $this->grantVentasDiseno('vendedor@atlantic.test');
        [$sub] = $this->makeTwoSubClients();
        $architect = Architect::create(['sub_client_id' => $sub->id, 'name' => 'Arq. Sin Proyectos', 'phone' => '6000-0000']);
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/ventas-diseno/architects/{$architect->id}")
            ->assertStatus(200);

        $this->assertSame([], $response->json('projects'));
    }
}
