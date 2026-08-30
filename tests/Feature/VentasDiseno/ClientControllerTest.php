<?php

declare(strict_types=1);

namespace Tests\Feature\VentasDiseno;

use App\Modules\VentasDiseno\Models\MasterClient;
use App\Modules\VentasDiseno\Models\PipelineCard;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Feature\FeatureTestCase;

class ClientControllerTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'illuminations')->first()?->makeCurrent();
    }

    private function grantVentasDiseno(string $email, string $permission): void
    {
        $slug = (string) Tenant::where('slug', 'illuminations')->value('slug');
        DB::table("{$slug}_auth.users")
            ->where('email', $email)
            ->update(['extra_permissions' => json_encode(['ventas_diseno.read', $permission])]);
    }

    public function test_buscar_clientes_master_por_nombre(): void
    {
        $this->grantVentasDiseno('designer@illuminations.test', 'ventas_diseno.write');
        MasterClient::create(['name' => 'Grupo Delta']);
        MasterClient::create(['name' => 'Otro Cliente']);

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/master-clients?search=Delta')
            ->assertStatus(200);

        $names = collect($response->json('data'))->pluck('name');
        $this->assertTrue($names->contains('Grupo Delta'));
        $this->assertFalse($names->contains('Otro Cliente'));
    }

    public function test_crear_cliente_master(): void
    {
        $this->grantVentasDiseno('designer@illuminations.test', 'ventas_diseno.write');
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/ventas-diseno/master-clients', ['name' => 'Cliente Nuevo'])
            ->assertStatus(201)
            ->assertJsonPath('name', 'Cliente Nuevo');
    }

    public function test_crear_subcliente_requiere_master_client_existente(): void
    {
        $this->grantVentasDiseno('designer@illuminations.test', 'ventas_diseno.write');
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/ventas-diseno/sub-clients', [
                'master_client_id' => 999999, 'business_name' => 'X', 'tax_id' => '155-0000-1-2026',
            ])
            ->assertStatus(422);
    }

    public function test_crear_subcliente_dispara_tarjeta_lead_automatica(): void
    {
        // REQ-068 en sentido inverso (SCRUM-79): un Subcliente nuevo creado desde el
        // flujo de vinculación de Pipeline también dispara su tarjeta Lead, igual que
        // si hubiera nacido desde la pantalla de Clientes (Grupo 2).
        $this->grantVentasDiseno('designer@illuminations.test', 'ventas_diseno.write');
        $master = MasterClient::create(['name' => 'Grupo Delta']);
        $token  = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/ventas-diseno/sub-clients', [
                'master_client_id' => $master->id, 'business_name' => 'Delta Residencial', 'tax_id' => '155-0000-1-2026',
            ])
            ->assertStatus(201);

        $subClientId = $response->json('id');

        Tenant::where('slug', 'illuminations')->first()?->makeCurrent();
        $card = PipelineCard::where('sub_client_id', $subClientId)->first();
        $this->assertNotNull($card);
        $this->assertSame('lead', $card->stage);
        $this->assertSame($master->id, $card->master_client_id);
    }

    public function test_buscar_subclientes_acotado_al_master_client(): void
    {
        $this->grantVentasDiseno('designer@illuminations.test', 'ventas_diseno.write');
        $master = MasterClient::create(['name' => 'Grupo Delta']);
        $other  = MasterClient::create(['name' => 'Otro Grupo']);
        $master->subClients()->create(['business_name' => 'Delta Residencial', 'tax_id' => '155-0000-1-2026']);
        $other->subClients()->create(['business_name' => 'Otro Residencial', 'tax_id' => '155-1111-1-2026']);

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/ventas-diseno/sub-clients?master_client_id={$master->id}")
            ->assertStatus(200);

        $names = collect($response->json('data'))->pluck('business_name');
        $this->assertTrue($names->contains('Delta Residencial'));
        $this->assertFalse($names->contains('Otro Residencial'));
    }

    public function test_buscar_subclientes_incluye_contacts_count(): void
    {
        // REQ-020 (Grupo 1d): el frontend usa contacts_count para advertir antes de
        // crear un proyecto en Diseño vinculado a un Subcliente sin contactos.
        $this->grantVentasDiseno('designer@illuminations.test', 'ventas_diseno.write');
        $master   = MasterClient::create(['name' => 'Grupo Delta']);
        $withContact = $master->subClients()->create(['business_name' => 'Con Contacto', 'tax_id' => '155-2222-1-2026']);
        $withContact->contacts()->create(['name' => 'Juan Pérez', 'role' => 'client', 'phone' => '6000-0000']);
        $master->subClients()->create(['business_name' => 'Sin Contacto', 'tax_id' => '155-3333-1-2026']);

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/ventas-diseno/sub-clients?master_client_id={$master->id}")
            ->assertStatus(200);

        $rows = collect($response->json('data'))->keyBy('business_name');
        $this->assertSame(1, $rows['Con Contacto']['contacts_count']);
        $this->assertSame(0, $rows['Sin Contacto']['contacts_count']);
    }
}
