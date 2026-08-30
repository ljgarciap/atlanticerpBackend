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

class PipelineCardContactControllerTest extends FeatureTestCase
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

    public function test_crear_contacto_requiere_telefono_o_correo(): void
    {
        $this->grantVentasDisenoEdit('designer@atlantic.test');
        $designerId = $this->userIdByEmail('designer@atlantic.test');
        $card = $this->makeCard($designerId);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/pipeline/{$card->id}/contacts", ['name' => 'Sin contacto', 'role' => 'client'])
            ->assertStatus(422);
    }

    public function test_crear_contacto_de_rol_arquitecto(): void
    {
        $this->grantVentasDisenoEdit('designer@atlantic.test');
        $designerId = $this->userIdByEmail('designer@atlantic.test');
        $card = $this->makeCard($designerId);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/pipeline/{$card->id}/contacts", [
                'name' => 'Arq. Pérez', 'role' => 'architect', 'email' => 'arq@test.com',
            ])
            ->assertStatus(201);

        $response->assertJsonPath('name', 'Arq. Pérez');
        $response->assertJsonPath('role', 'architect');

        $detail = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/ventas-diseno/pipeline/{$card->id}")
            ->assertStatus(200);
        $detail->assertJsonPath('has_architect_contact', true);
    }

    public function test_editar_y_borrar_contacto(): void
    {
        $this->grantVentasDisenoEdit('designer@atlantic.test');
        $designerId = $this->userIdByEmail('designer@atlantic.test');
        $card = $this->makeCard($designerId);
        $contact = $card->contacts()->create(['name' => 'Original', 'role' => 'other', 'phone' => '6000-0000']);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/ventas-diseno/pipeline/{$card->id}/contacts/{$contact->id}", [
                'name' => 'Editado', 'role' => 'other', 'phone' => '6000-1111',
            ])
            ->assertStatus(200)
            ->assertJsonPath('name', 'Editado');

        $this->withHeaders($this->authHeaders($token))
            ->deleteJson("/api/ventas-diseno/pipeline/{$card->id}/contacts/{$contact->id}")
            ->assertStatus(204);

        $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/ventas-diseno/pipeline/{$card->id}")
            ->assertStatus(200)
            ->assertJsonCount(0, 'contacts');
    }

    public function test_no_se_puede_agregar_contacto_a_tarjeta_perdida(): void
    {
        $this->grantVentasDisenoEdit('designer@atlantic.test');
        $designerId = $this->userIdByEmail('designer@atlantic.test');
        $card = $this->makeCard($designerId, 'lost');

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/pipeline/{$card->id}/contacts", ['name' => 'X', 'role' => 'other', 'phone' => '6000-0000'])
            ->assertStatus(403);
    }
}
