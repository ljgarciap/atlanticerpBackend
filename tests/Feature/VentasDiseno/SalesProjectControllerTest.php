<?php

declare(strict_types=1);

namespace Tests\Feature\VentasDiseno;

use App\Modules\VentasDiseno\Models\MasterClient;
use App\Modules\VentasDiseno\Models\Quote;
use App\Modules\VentasDiseno\Models\SalesProject;
use App\Modules\VentasDiseno\Models\SubClient;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Feature\FeatureTestCase;

// REQ-027: buscar/crear Proyecto acotado a un Subcliente, desde Cotización.
class SalesProjectControllerTest extends FeatureTestCase
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

    public function test_buscar_proyectos_requiere_sub_client_id(): void
    {
        $this->grantVentasDiseno('designer@atlantic.test');
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/sales-projects')
            ->assertStatus(422);
    }

    public function test_buscar_proyectos_acotado_al_subcliente(): void
    {
        $this->grantVentasDiseno('designer@atlantic.test');
        $master = MasterClient::create(['name' => 'Cliente Test']);
        $sub    = SubClient::create(['master_client_id' => $master->id, 'business_name' => 'Sub Test', 'tax_id' => '155-0000-1-2026']);
        $other  = SubClient::create(['master_client_id' => $master->id, 'business_name' => 'Otro Sub', 'tax_id' => '155-1111-1-2026']);
        SalesProject::create(['sub_client_id' => $sub->id, 'name' => 'Torre Delta']);
        SalesProject::create(['sub_client_id' => $other->id, 'name' => 'Otro Proyecto']);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/ventas-diseno/sales-projects?sub_client_id={$sub->id}")
            ->assertStatus(200);

        $names = collect($response->json('data'))->pluck('name');
        $this->assertTrue($names->contains('Torre Delta'));
        $this->assertFalse($names->contains('Otro Proyecto'));
    }

    public function test_crear_proyecto_sin_nombre_retorna_422(): void
    {
        $this->grantVentasDiseno('designer@atlantic.test', 'ventas_diseno.write');
        $master = MasterClient::create(['name' => 'Cliente Test']);
        $sub    = SubClient::create(['master_client_id' => $master->id, 'business_name' => 'Sub Test', 'tax_id' => '155-0000-1-2026']);
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/ventas-diseno/sales-projects', ['sub_client_id' => $sub->id])
            ->assertStatus(422);
    }

    public function test_crear_proyecto(): void
    {
        $this->grantVentasDiseno('designer@atlantic.test', 'ventas_diseno.write');
        $master = MasterClient::create(['name' => 'Cliente Test']);
        $sub    = SubClient::create(['master_client_id' => $master->id, 'business_name' => 'Sub Test', 'tax_id' => '155-0000-1-2026']);
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/ventas-diseno/sales-projects', [
                'sub_client_id' => $sub->id, 'name' => 'Proyecto Nuevo', 'tag' => 'design',
            ])
            ->assertStatus(201);

        $response->assertJsonPath('name', 'Proyecto Nuevo');
        $response->assertJsonPath('tag', 'design');
    }

    // ── SCRUM-734 (RN5.1/RN5.3) ──────────────────────────────────────────────────

    public function test_buscador_excluye_proyecto_con_borrador(): void
    {
        $this->grantVentasDiseno('designer@atlantic.test');
        $designerId = (int) DB::table((string) Tenant::where('slug', 'atlantic')->value('slug') . '_auth.users')
            ->where('email', 'designer@atlantic.test')->value('id');
        $master  = MasterClient::create(['name' => 'Cliente Test']);
        $sub     = SubClient::create(['master_client_id' => $master->id, 'business_name' => 'Sub Test', 'tax_id' => '155-0000-1-2026']);
        $quoted  = SalesProject::create(['sub_client_id' => $sub->id, 'name' => 'Ya Cotizado']);
        $free    = SalesProject::create(['sub_client_id' => $sub->id, 'name' => 'Sin Cotizar']);
        Quote::create(['owner_id' => $designerId, 'sales_project_id' => $quoted->id, 'description' => 'Borrador']);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/ventas-diseno/sales-projects?sub_client_id={$sub->id}")
            ->assertStatus(200);

        $names = collect($response->json('data'))->pluck('name');
        $this->assertFalse($names->contains('Ya Cotizado'));
        $this->assertTrue($names->contains('Sin Cotizar'));
    }

    public function test_buscador_excluye_proyecto_con_cotizacion_generada(): void
    {
        $this->grantVentasDiseno('designer@atlantic.test');
        $designerId = (int) DB::table((string) Tenant::where('slug', 'atlantic')->value('slug') . '_auth.users')
            ->where('email', 'designer@atlantic.test')->value('id');
        $master = MasterClient::create(['name' => 'Cliente Test']);
        $sub    = SubClient::create(['master_client_id' => $master->id, 'business_name' => 'Sub Test', 'tax_id' => '155-0000-1-2026']);
        $quoted = SalesProject::create(['sub_client_id' => $sub->id, 'name' => 'Ya Cotizado']);
        Quote::create([
            'owner_id' => $designerId, 'sales_project_id' => $quoted->id, 'description' => 'Generada',
            'folio' => 'COT-2026-0099', 'confirmed_at' => now(),
        ]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/ventas-diseno/sales-projects?sub_client_id={$sub->id}")
            ->assertStatus(200);

        $this->assertEmpty($response->json('data'));
    }

    public function test_crear_proyecto_con_nombre_duplicado_ya_cotizado_retorna_422(): void
    {
        $this->grantVentasDiseno('designer@atlantic.test', 'ventas_diseno.write');
        $designerId = (int) DB::table((string) Tenant::where('slug', 'atlantic')->value('slug') . '_auth.users')
            ->where('email', 'designer@atlantic.test')->value('id');
        $master  = MasterClient::create(['name' => 'Cliente Test']);
        $sub     = SubClient::create(['master_client_id' => $master->id, 'business_name' => 'Sub Test', 'tax_id' => '155-0000-1-2026']);
        $project = SalesProject::create(['sub_client_id' => $sub->id, 'name' => 'Torre Delta']);
        Quote::create(['owner_id' => $designerId, 'sales_project_id' => $project->id, 'description' => 'Borrador']);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/ventas-diseno/sales-projects', ['sub_client_id' => $sub->id, 'name' => 'Torre Delta'])
            ->assertStatus(422);

        $this->assertStringContainsString('Ya existe una cotización para este proyecto', (string) $response->json('message'));
    }

    public function test_crear_proyecto_mismo_nombre_pero_sin_cotizacion_no_se_bloquea(): void
    {
        $this->grantVentasDiseno('designer@atlantic.test', 'ventas_diseno.write');
        $master  = MasterClient::create(['name' => 'Cliente Test']);
        $sub     = SubClient::create(['master_client_id' => $master->id, 'business_name' => 'Sub Test', 'tax_id' => '155-0000-1-2026']);
        SalesProject::create(['sub_client_id' => $sub->id, 'name' => 'Torre Delta']); // sin ninguna Quote

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/ventas-diseno/sales-projects', ['sub_client_id' => $sub->id, 'name' => 'Torre Delta'])
            ->assertStatus(201);
    }
}
