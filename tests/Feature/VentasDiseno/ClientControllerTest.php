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
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    private function grantVentasDiseno(string $email, string $permission): void
    {
        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');
        DB::table("{$slug}_auth.users")
            ->where('email', $email)
            ->update(['extra_permissions' => json_encode(['ventas_diseno.read', $permission])]);
    }

    public function test_buscar_clientes_master_por_nombre(): void
    {
        $this->grantVentasDiseno('designer@atlantic.test', 'ventas_diseno.write');
        MasterClient::create(['name' => 'Grupo Delta']);
        MasterClient::create(['name' => 'Otro Cliente']);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/master-clients?search=Delta')
            ->assertStatus(200);

        $names = collect($response->json('data'))->pluck('name');
        $this->assertTrue($names->contains('Grupo Delta'));
        $this->assertFalse($names->contains('Otro Cliente'));
    }

    public function test_crear_cliente_master(): void
    {
        $this->grantVentasDiseno('designer@atlantic.test', 'ventas_diseno.write');
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/ventas-diseno/master-clients', ['name' => 'Cliente Nuevo'])
            ->assertStatus(201)
            ->assertJsonPath('name', 'Cliente Nuevo');
    }

    public function test_crear_subcliente_requiere_master_client_existente(): void
    {
        $this->grantVentasDiseno('designer@atlantic.test', 'ventas_diseno.write');
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/ventas-diseno/sub-clients', [
                'master_client_id' => 999999, 'business_name' => 'X', 'tax_id' => '155-0000-1-2026', 'tax_id_dv' => '81',
            ])
            ->assertStatus(422);
    }

    public function test_crear_subcliente_no_crea_tarjeta_lead_ni_proyecto(): void
    {
        // SCRUM-1187 reemplaza REQ-068 «en sentido inverso» (SCRUM-79): un Subcliente nuevo creado desde el flujo de vinculación de
        // Pipeline ya no dispara una tarjeta Lead extra ni un proyecto, igual que en la pantalla de Clientes.
        $this->grantVentasDiseno('designer@atlantic.test', 'ventas_diseno.write');
        $master = MasterClient::create(['name' => 'Grupo Delta']);
        $token  = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/ventas-diseno/sub-clients', [
                'master_client_id' => $master->id, 'business_name' => 'Delta Residencial', 'tax_id' => '155-0000-1-2026', 'tax_id_dv' => '81',
            ])
            ->assertStatus(201);

        $subClientId = $response->json('id');

        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $this->assertDatabaseHas('sub_clients', ['id' => $subClientId, 'business_name' => 'Delta Residencial']);
        $this->assertSame(0, PipelineCard::where('sub_client_id', $subClientId)->count());
        $this->assertSame(0, PipelineCard::where('master_client_id', $master->id)->count());
        $this->assertSame(0, \App\Modules\VentasDiseno\Models\SalesProject::where('sub_client_id', $subClientId)->count());
    }

    public function test_1011_crear_subcliente_desde_diseno_no_dispara_tarjeta_lead(): void
    {
        // SCRUM-1011: el modal de nuevo proyecto en Diseño manda from_design_context — el
        // Subcliente se crea igual (visible en Clientes) pero sin la tarjeta Lead automática.
        $this->grantVentasDiseno('designer@atlantic.test', 'ventas_diseno.write');
        $master = MasterClient::create(['name' => 'Grupo Delta']);
        $token  = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/ventas-diseno/sub-clients', [
                'master_client_id' => $master->id, 'business_name' => 'Delta Diseño', 'tax_id' => '155-0000-1-2026', 'tax_id_dv' => '81',
                'from_design_context' => true,
            ])
            ->assertStatus(201);

        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $this->assertDatabaseHas('sub_clients', ['id' => $response->json('id'), 'business_name' => 'Delta Diseño']);
        $this->assertSame(0, PipelineCard::where('sub_client_id', $response->json('id'))->count());
    }

    public function test_buscar_subclientes_acotado_al_master_client(): void
    {
        $this->grantVentasDiseno('designer@atlantic.test', 'ventas_diseno.write');
        $master = MasterClient::create(['name' => 'Grupo Delta']);
        $other  = MasterClient::create(['name' => 'Otro Grupo']);
        $master->subClients()->create(['business_name' => 'Delta Residencial', 'tax_id' => '155-0000-1-2026']);
        $other->subClients()->create(['business_name' => 'Otro Residencial', 'tax_id' => '155-1111-1-2026']);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

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
        $this->grantVentasDiseno('designer@atlantic.test', 'ventas_diseno.write');
        $master   = MasterClient::create(['name' => 'Grupo Delta']);
        $withContact = $master->subClients()->create(['business_name' => 'Con Contacto', 'tax_id' => '155-2222-1-2026']);
        $withContact->contacts()->create(['name' => 'Juan Pérez', 'role' => 'client', 'phone' => '6000-0000']);
        $master->subClients()->create(['business_name' => 'Sin Contacto', 'tax_id' => '155-3333-1-2026']);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/ventas-diseno/sub-clients?master_client_id={$master->id}")
            ->assertStatus(200);

        $rows = collect($response->json('data'))->keyBy('business_name');
        $this->assertSame(1, $rows['Con Contacto']['contacts_count']);
        $this->assertSame(0, $rows['Sin Contacto']['contacts_count']);
    }

    /** SCRUM-928/1140 — el diálogo de subcliente del flujo de Cotización: el DV es opcional (una cédula puede no tenerlo) pero si viene debe ser válido. */
    public function test_928_crear_subcliente_con_dv_opcional_y_lo_persiste(): void
    {
        $this->grantVentasDiseno('designer@atlantic.test', 'ventas_diseno.write');
        $master = MasterClient::create(['name' => 'Grupo Dv']);
        $token  = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $base   = ['master_client_id' => $master->id, 'business_name' => 'Dv Residencial', 'tax_id' => '155-5555-1-2026'];

        // SCRUM-1140: sin DV ya no es un error de validación; queda nulo y la guarda lo exige al facturar solo si la DGI lo trae.
        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/ventas-diseno/sub-clients', ['tax_id' => '8-123-456'] + $base)
            ->assertStatus(201)->assertJsonPath('tax_id_dv', null);

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/ventas-diseno/sub-clients', $base + ['tax_id_dv' => 'x1'])
            ->assertStatus(422)->assertJsonValidationErrors(['tax_id_dv']);

        $ok = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/ventas-diseno/sub-clients', $base + ['tax_id_dv' => '81'])
            ->assertStatus(201)->assertJsonPath('tax_id_dv', '81');

        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $this->assertSame('81', \App\Modules\VentasDiseno\Models\SubClient::findOrFail($ok->json('id'))->tax_id_dv);
    }
}
