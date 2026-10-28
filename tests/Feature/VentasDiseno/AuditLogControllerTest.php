<?php

declare(strict_types=1);

namespace Tests\Feature\VentasDiseno;

use App\Modules\VentasDiseno\Models\MasterClient;
use App\Modules\VentasDiseno\Models\SubClient;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Feature\FeatureTestCase;

/**
 * REQ-053 (SCRUM-59). El grabado en sí lo cubre LogsAuditTrail (aplicado a los
 * modelos del módulo) — estos tests validan el endpoint de lectura: alcance
 * ventas_diseno.*, filtros y el gate de permiso.
 */
class AuditLogControllerTest extends FeatureTestCase
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

    public function test_sin_autenticar_retorna_401(): void
    {
        $this->getJson('/api/ventas-diseno/audit-log')->assertStatus(401);
    }

    public function test_sin_permiso_audit_retorna_403(): void
    {
        $this->grantVentasDiseno('vendedor@atlantic.test', 'ventas_diseno.write');
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/audit-log')
            ->assertStatus(403);
    }

    public function test_crear_un_cliente_queda_registrado_en_el_historial(): void
    {
        $this->grantVentasDiseno('management@atlantic.test', 'ventas_diseno.audit');
        $master = MasterClient::create(['name' => '[Audit] Cliente Test']);
        $token  = $this->loginAs('management@atlantic.test', 'Password123!', 'management');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/ventas-diseno/audit-log?entity_type=master_client&entity_id={$master->id}")
            ->assertStatus(200);

        $rows = collect($response->json('data'));
        $created = $rows->first(fn (array $r) => $r['action'] === 'ventas_diseno.master_client.created');
        $this->assertNotNull($created);
        $this->assertSame('[Audit] Cliente Test', $created['new_values']['name']);
    }

    public function test_editar_categoria_de_subcliente_registra_valor_anterior_y_nuevo(): void
    {
        $this->grantVentasDiseno('management@atlantic.test', 'ventas_diseno.audit');
        $master = MasterClient::create(['name' => 'Grupo Delta']);
        $sub    = SubClient::create(['master_client_id' => $master->id, 'business_name' => 'Delta Residencial', 'tax_id' => '155-0000-1-2026', 'category' => 'a_walkin']);
        $sub->update(['category' => 'b_architect_designer']);

        $token = $this->loginAs('management@atlantic.test', 'Password123!', 'management');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/ventas-diseno/audit-log?entity_type=sub_client&entity_id={$sub->id}")
            ->assertStatus(200);

        $rows = collect($response->json('data'));
        $updated = $rows->first(fn (array $r) => $r['action'] === 'ventas_diseno.sub_client.updated');
        $this->assertNotNull($updated);
        $this->assertSame('a_walkin', $updated['old_values']['category']);
        $this->assertSame('b_architect_designer', $updated['new_values']['category']);
    }

    public function test_filtro_por_usuario(): void
    {
        $this->grantVentasDiseno('management@atlantic.test', 'ventas_diseno.audit');
        $managerId = (int) DB::table('atlantic_auth.users')->where('email', 'management@atlantic.test')->value('id');

        // El audit log de "created" atribuye el cambio al usuario del JWT en la
        // request — se necesita loguear PRIMERO y crear el fixture DESPUÉS (vía un
        // endpoint real), no con Eloquent directo (que no tiene contexto de request).
        $token = $this->loginAs('management@atlantic.test', 'Password123!', 'management');
        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/ventas-diseno/master-clients', ['name' => '[Audit] Otro Cliente'])
            ->assertStatus(201);

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/ventas-diseno/audit-log?user_id={$managerId}")
            ->assertStatus(200);

        $rows = collect($response->json('data'));
        $this->assertTrue($rows->every(fn (array $r) => $r['user']['id'] === $managerId));
        $this->assertTrue($rows->isNotEmpty());
    }
}
