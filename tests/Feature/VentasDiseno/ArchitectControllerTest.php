<?php

declare(strict_types=1);

namespace Tests\Feature\VentasDiseno;

use App\Modules\VentasDiseno\Models\Architect;
use App\Modules\VentasDiseno\Models\MasterClient;
use App\Modules\VentasDiseno\Models\SubClient;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Feature\FeatureTestCase;

// REQ-030 / SCRUM-122: buscar/crear Arquitecto acotado a un Subcliente, desde Cotización.
class ArchitectControllerTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'illuminations')->first()?->makeCurrent();
    }

    private function grantVentasDiseno(string $email, string ...$permissions): void
    {
        $slug = (string) Tenant::where('slug', 'illuminations')->value('slug');
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

    public function test_buscar_arquitectos_sin_autenticar_retorna_401(): void
    {
        $this->getJson('/api/ventas-diseno/architects')->assertStatus(401);
    }

    public function test_buscar_arquitectos_requiere_sub_client_id(): void
    {
        $this->grantVentasDiseno('designer@illuminations.test');
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/architects')
            ->assertStatus(422);
    }

    public function test_buscar_arquitectos_acotado_al_subcliente(): void
    {
        $this->grantVentasDiseno('designer@illuminations.test');
        [$sub, $other] = $this->makeTwoSubClients();
        Architect::create(['sub_client_id' => $sub->id, 'name' => 'Arq. Fernando Solís', 'phone' => '6000-0000']);
        Architect::create(['sub_client_id' => $other->id, 'name' => 'Arq. De Otro Sub', 'email' => 'otro@example.com']);

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/ventas-diseno/architects?sub_client_id={$sub->id}")
            ->assertStatus(200);

        $names = collect($response->json('data'))->pluck('name');
        $this->assertTrue($names->contains('Arq. Fernando Solís'));
        $this->assertFalse($names->contains('Arq. De Otro Sub'));
    }

    public function test_buscar_arquitectos_por_nombre_dentro_del_subcliente(): void
    {
        $this->grantVentasDiseno('designer@illuminations.test');
        [$sub] = $this->makeTwoSubClients();
        Architect::create(['sub_client_id' => $sub->id, 'name' => 'Arq. Fernando Solís', 'phone' => '6000-0000']);
        Architect::create(['sub_client_id' => $sub->id, 'name' => 'Arq. Otro', 'email' => 'otro@example.com']);

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/ventas-diseno/architects?sub_client_id={$sub->id}&search=Fernando")
            ->assertStatus(200);

        $names = collect($response->json('data'))->pluck('name');
        $this->assertTrue($names->contains('Arq. Fernando Solís'));
        $this->assertFalse($names->contains('Arq. Otro'));
    }

    public function test_arquitecto_huerfano_sin_subcliente_no_aparece_en_ninguna_busqueda(): void
    {
        $this->grantVentasDiseno('designer@illuminations.test');
        [$sub] = $this->makeTwoSubClients();
        // Simula un registro creado antes de SCRUM-122 (directorio global, sin sub_client_id).
        Architect::create(['name' => 'Arq. Legacy Global', 'phone' => '6000-9999']);

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/ventas-diseno/architects?sub_client_id={$sub->id}")
            ->assertStatus(200);

        $names = collect($response->json('data'))->pluck('name');
        $this->assertFalse($names->contains('Arq. Legacy Global'));
    }

    public function test_crear_arquitecto_sin_sub_client_id_retorna_422(): void
    {
        $this->grantVentasDiseno('designer@illuminations.test', 'ventas_diseno.write');
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/ventas-diseno/architects', ['name' => 'Arq. Sin Sub', 'phone' => '6000-0000'])
            ->assertStatus(422);
    }

    public function test_crear_arquitecto_sin_telefono_ni_correo_retorna_422(): void
    {
        $this->grantVentasDiseno('designer@illuminations.test', 'ventas_diseno.write');
        [$sub] = $this->makeTwoSubClients();
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/ventas-diseno/architects', ['sub_client_id' => $sub->id, 'name' => 'Arq. Sin Contacto'])
            ->assertStatus(422);
    }

    public function test_crear_arquitecto(): void
    {
        $this->grantVentasDiseno('designer@illuminations.test', 'ventas_diseno.write');
        [$sub] = $this->makeTwoSubClients();
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/ventas-diseno/architects', [
                'sub_client_id' => $sub->id, 'name' => 'Arq. Nuevo', 'phone' => '6000-1111',
            ])
            ->assertStatus(201);

        $response->assertJsonPath('name', 'Arq. Nuevo');
    }

    public function test_crear_arquitecto_solo_con_correo_sin_telefono(): void
    {
        $this->grantVentasDiseno('designer@illuminations.test', 'ventas_diseno.write');
        [$sub] = $this->makeTwoSubClients();
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

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
        $this->grantVentasDiseno('designer@illuminations.test', 'ventas_diseno.edit');
        [$sub] = $this->makeTwoSubClients();
        $architect = Architect::create(['sub_client_id' => $sub->id, 'name' => 'Arq. Fernando Solís', 'phone' => '6000-0000']);
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/ventas-diseno/architects/{$architect->id}", [
                'name' => 'Arq. Fernando Solís', 'phone' => '6000-9999', 'email' => 'fernando@example.com',
            ])
            ->assertStatus(200);

        $response->assertJsonPath('phone', '6000-9999');
        $response->assertJsonPath('email', 'fernando@example.com');

        Tenant::where('slug', 'illuminations')->first()?->makeCurrent();
        $this->assertSame('6000-9999', Architect::find($architect->id)?->phone);
    }

    public function test_editar_arquitecto_sin_permiso_edit_retorna_403(): void
    {
        // supervisor@ (rol sin bridge de edit por default) en vez de designer@ —
        // mismo motivo que QuoteControllerTest::test_actualizar_sin_permiso_edit_retorna_403.
        $this->grantVentasDiseno('supervisor@illuminations.test');
        [$sub] = $this->makeTwoSubClients();
        $architect = Architect::create(['sub_client_id' => $sub->id, 'name' => 'Arq. Fernando Solís', 'phone' => '6000-0000']);
        $token = $this->loginAs('supervisor@illuminations.test', 'Password123!');

        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/ventas-diseno/architects/{$architect->id}", ['name' => 'Arq. Fernando Solís', 'phone' => '6000-9999'])
            ->assertStatus(403);
    }

    public function test_editar_arquitecto_inexistente_retorna_404(): void
    {
        $this->grantVentasDiseno('designer@illuminations.test', 'ventas_diseno.edit');
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->putJson('/api/ventas-diseno/architects/999999', ['name' => 'X', 'phone' => '6000-0000'])
            ->assertStatus(404);
    }

    public function test_editar_arquitecto_sin_telefono_ni_correo_retorna_422(): void
    {
        $this->grantVentasDiseno('designer@illuminations.test', 'ventas_diseno.edit');
        [$sub] = $this->makeTwoSubClients();
        $architect = Architect::create(['sub_client_id' => $sub->id, 'name' => 'Arq. Fernando Solís', 'phone' => '6000-0000']);
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/ventas-diseno/architects/{$architect->id}", ['name' => 'Arq. Fernando Solís'])
            ->assertStatus(422);
    }
}
