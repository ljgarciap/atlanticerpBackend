<?php

declare(strict_types=1);

namespace Tests\Feature\VentasDiseno;

use App\Modules\VentasDiseno\Models\MasterClient;
use App\Modules\VentasDiseno\Models\PipelineCard;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Feature\FeatureTestCase;

/**
 * Grupo 2 del plan de PM — pantalla Clientes (REQ-063 a REQ-070). Ver
 * memory/project_backlog_ventas_diseno_req.md.
 */
class ClientsControllerTest extends FeatureTestCase
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

    /** @return array{name: string, business_name: string, tax_id: string, contacts: list<array<string, string>>} */
    private function validClientPayload(string $suffix): array
    {
        return [
            'name'          => "Cliente Test {$suffix}",
            'business_name' => "Subcliente Test {$suffix}",
            'tax_id'        => '155-0000-1-2026',
            'contacts'      => [
                ['name' => 'Ana Pérez', 'role' => 'client', 'phone' => '6000-0000'],
            ],
        ];
    }

    // ── GET /clients (REQ-063) ──────────────────────────────────────────────

    public function test_listar_clientes_retorna_metricas_agregadas_desde_pipeline(): void
    {
        $this->grantVentasDiseno('designer@illuminations.test', 'ventas_diseno.write');
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/ventas-diseno/clients', $this->validClientPayload('Metricas'))
            ->assertStatus(201);

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/clients?search=Cliente Test Metricas')
            ->assertStatus(200);

        $row = collect($response->json('data'))->firstWhere('name', 'Cliente Test Metricas');
        $this->assertNotNull($row);
        $this->assertSame(1, $row['active_projects']);
        $this->assertSame(0, $row['lost_projects']);
        $this->assertSame(1, $row['total_projects']);
        // json_decode no distingue 0 (int) de 0.0 (float) cuando no hay parte decimal —
        // assertEquals en vez de assertSame para no acoplar el test a ese detalle de
        // serialización.
        $this->assertEquals(0.0, $row['total_amount']);
        $this->assertEquals(0.0, $row['closed_amount']);
        $this->assertSame(0, $row['last_contact_days']);
    }

    public function test_filtro_por_categoria_solo_muestra_clientes_con_esa_categoria(): void
    {
        $this->grantVentasDiseno('designer@illuminations.test', 'ventas_diseno.write');
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $payloadA = $this->validClientPayload('CatA');
        $payloadA['category'] = 'a_walkin';
        $payloadB = $this->validClientPayload('CatB');
        $payloadB['category'] = 'c_bidding';

        $this->withHeaders($this->authHeaders($token))->postJson('/api/ventas-diseno/clients', $payloadA)->assertStatus(201);
        $this->withHeaders($this->authHeaders($token))->postJson('/api/ventas-diseno/clients', $payloadB)->assertStatus(201);

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/clients?category=a_walkin')
            ->assertStatus(200);

        $names = collect($response->json('data'))->pluck('name');
        $this->assertTrue($names->contains('Cliente Test CatA'));
        $this->assertFalse($names->contains('Cliente Test CatB'));
    }

    public function test_filtro_por_multiples_categorias_combina_con_or_scrum_93(): void
    {
        $this->grantVentasDiseno('designer@illuminations.test', 'ventas_diseno.write');
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $payloadA = $this->validClientPayload('MultiA');
        $payloadA['category'] = 'a_walkin';
        $payloadC = $this->validClientPayload('MultiC');
        $payloadC['category'] = 'c_bidding';
        $payloadD = $this->validClientPayload('MultiD');
        $payloadD['category'] = 'd_referred';

        $this->withHeaders($this->authHeaders($token))->postJson('/api/ventas-diseno/clients', $payloadA)->assertStatus(201);
        $this->withHeaders($this->authHeaders($token))->postJson('/api/ventas-diseno/clients', $payloadC)->assertStatus(201);
        $this->withHeaders($this->authHeaders($token))->postJson('/api/ventas-diseno/clients', $payloadD)->assertStatus(201);

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/clients?'.http_build_query(['category' => ['a_walkin', 'c_bidding']]))
            ->assertStatus(200);

        $names = collect($response->json('data'))->pluck('name');
        $this->assertTrue($names->contains('Cliente Test MultiA'));
        $this->assertTrue($names->contains('Cliente Test MultiC'));
        $this->assertFalse($names->contains('Cliente Test MultiD'));
    }

    public function test_pastillas_de_categoria_son_union_de_subclientes(): void
    {
        $this->grantVentasDiseno('designer@illuminations.test', 'ventas_diseno.write');
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $payload = $this->validClientPayload('Union');
        $payload['category'] = 'b_architect_designer';
        $create = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/ventas-diseno/clients', $payload)
            ->assertStatus(201);
        $masterId = $create->json('id');

        $second = $this->validClientPayload('UnionSub2');
        unset($second['name']);
        $second['category'] = 'd_referred';
        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/clients/{$masterId}/sub-clients", $second)
            ->assertStatus(201);

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/clients?search=Cliente Test Union')
            ->assertStatus(200);

        $row = collect($response->json('data'))->firstWhere('name', 'Cliente Test Union');
        $this->assertEqualsCanonicalizing(['b_architect_designer', 'd_referred'], $row['categories']);
    }

    public function test_filtro_sin_contacto_60_dias_incluye_clientes_con_stage_changed_at_antiguo(): void
    {
        $this->grantVentasDiseno('designer@illuminations.test', 'ventas_diseno.write');
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $create = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/ventas-diseno/clients', $this->validClientPayload('Stale'))
            ->assertStatus(201);

        // AuthenticateJwt hace Tenant::forgetCurrent() en su finally al terminar cada
        // request — hay que reactivar el tenant antes de cualquier consulta directa a
        // un modelo de tenant entre dos llamadas HTTP (ver PipelineFileUploadTest).
        Tenant::where('slug', 'illuminations')->first()?->makeCurrent();
        PipelineCard::where('master_client_id', $create->json('id'))
            ->update(['stage_changed_at' => now()->subDays(90)]);

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/clients?stale=1')
            ->assertStatus(200);

        $names = collect($response->json('data'))->pluck('name');
        $this->assertTrue($names->contains('Cliente Test Stale'));
    }

    // ── POST /clients — REQ-067 (modo "crear cliente") + REQ-068 ───────────

    public function test_crear_cliente_crea_master_subcliente_contactos_y_lead_automatico(): void
    {
        $this->grantVentasDiseno('designer@illuminations.test', 'ventas_diseno.write');
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/ventas-diseno/clients', $this->validClientPayload('Lead'))
            ->assertStatus(201);

        $response->assertJsonPath('name', 'Cliente Test Lead');
        $subClientId = $response->json('sub_clients.0.id');
        $this->assertNotNull($subClientId);
        $response->assertJsonPath('sub_clients.0.contacts.0.name', 'Ana Pérez');

        Tenant::where('slug', 'illuminations')->first()?->makeCurrent();
        $card = PipelineCard::where('master_client_id', $response->json('id'))->first();
        $this->assertNotNull($card);
        $this->assertSame('lead', $card->stage);
        $this->assertSame($subClientId, $card->sub_client_id);
        $this->assertCount(1, $card->contacts);
        $this->assertSame('Ana Pérez', $card->contacts->first()->name);
    }

    public function test_crear_cliente_sin_contactos_retorna_422(): void
    {
        $this->grantVentasDiseno('designer@illuminations.test', 'ventas_diseno.write');
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $payload = $this->validClientPayload('SinContacto');
        $payload['contacts'] = [];

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/ventas-diseno/clients', $payload)
            ->assertStatus(422);
    }

    public function test_crear_cliente_con_precio_socio_sin_permiso_retorna_403(): void
    {
        $this->grantVentasDiseno('designer@illuminations.test', 'ventas_diseno.write');
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $payload = $this->validClientPayload('SocioNegado');
        $payload['default_price_type'] = 'partner';

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/ventas-diseno/clients', $payload)
            ->assertStatus(403);
    }

    public function test_crear_cliente_con_precio_socio_y_permiso_retorna_201(): void
    {
        $this->grantVentasDiseno('designer@illuminations.test', 'ventas_diseno.write', 'ventas_diseno.select_partner_price');
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $payload = $this->validClientPayload('SocioOk');
        $payload['default_price_type'] = 'partner';

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/ventas-diseno/clients', $payload)
            ->assertStatus(201)
            ->assertJsonPath('default_price_type', 'partner');
    }

    // ── POST /clients/{id}/sub-clients — REQ-067 (modo "crear subcliente") ─

    public function test_crear_subcliente_adicional_tambien_crea_lead_automatico(): void
    {
        $this->grantVentasDiseno('designer@illuminations.test', 'ventas_diseno.write');
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $create = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/ventas-diseno/clients', $this->validClientPayload('MasterExistente'))
            ->assertStatus(201);
        $masterId = $create->json('id');

        $second = [
            'business_name' => 'Subcliente Adicional',
            'tax_id'        => '155-1111-1-2026',
            'contacts'      => [['name' => 'Beto Ruiz', 'role' => 'architect', 'email' => 'beto@example.com']],
        ];

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/clients/{$masterId}/sub-clients", $second)
            ->assertStatus(201);

        $subClientId = $response->json('id');
        Tenant::where('slug', 'illuminations')->first()?->makeCurrent();
        $card = PipelineCard::where('sub_client_id', $subClientId)->first();
        $this->assertNotNull($card);
        $this->assertSame('lead', $card->stage);
        $this->assertSame($masterId, $card->master_client_id);
    }

    // ── GET /clients/{id} — REQ-064 ──────────────────────────────────────────

    public function test_ver_detalle_de_cliente_incluye_subclientes_y_contactos(): void
    {
        $this->grantVentasDiseno('designer@illuminations.test', 'ventas_diseno.write');
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $create = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/ventas-diseno/clients', $this->validClientPayload('Detalle'))
            ->assertStatus(201);

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/ventas-diseno/clients/{$create->json('id')}")
            ->assertStatus(200);

        $response->assertJsonPath('name', 'Cliente Test Detalle');
        $response->assertJsonPath('sub_clients.0.business_name', 'Subcliente Test Detalle');
        $response->assertJsonPath('sub_clients.0.contacts.0.name', 'Ana Pérez');
    }

    // ── PATCH /clients/sub-clients/{id}/category — REQ-064 ──────────────────

    public function test_actualizar_categoria_de_subcliente(): void
    {
        $this->grantVentasDiseno('designer@illuminations.test', 'ventas_diseno.write', 'ventas_diseno.edit');
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $create = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/ventas-diseno/clients', $this->validClientPayload('Categoria'))
            ->assertStatus(201);
        $subClientId = $create->json('sub_clients.0.id');

        $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/ventas-diseno/clients/sub-clients/{$subClientId}/category", ['category' => 'c_bidding'])
            ->assertStatus(200)
            ->assertJsonPath('category', 'c_bidding');
    }

    // ── GET /clients/{id}/projects — REQ-065 ────────────────────────────────

    public function test_ver_proyectos_devuelve_tarjetas_de_todos_los_subclientes(): void
    {
        $this->grantVentasDiseno('designer@illuminations.test', 'ventas_diseno.write');
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $create = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/ventas-diseno/clients', $this->validClientPayload('Proyectos'))
            ->assertStatus(201);
        $masterId = $create->json('id');

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/clients/{$masterId}/sub-clients", [
                'business_name' => 'Subcliente Proyectos 2',
                'tax_id'        => '155-2222-1-2026',
                'contacts'      => [['name' => 'Caro Diaz', 'role' => 'client', 'phone' => '6111-1111']],
            ])
            ->assertStatus(201);

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/ventas-diseno/clients/{$masterId}/projects")
            ->assertStatus(200);

        $this->assertCount(2, $response->json('data'));
        $subClientNames = collect($response->json('data'))->pluck('sub_client.business_name');
        $this->assertTrue($subClientNames->contains('Subcliente Test Proyectos'));
        $this->assertTrue($subClientNames->contains('Subcliente Proyectos 2'));
    }

    // ── contactos de subcliente — REQ-066 ────────────────────────────────────

    public function test_buscar_contactos_de_subcliente_por_nombre(): void
    {
        // REQ-031 (Cotización-A): el buscador de contactos de una cotización reusa
        // este mismo endpoint, acotado al Subcliente de la cotización.
        $this->grantVentasDiseno('designer@illuminations.test', 'ventas_diseno.write', 'ventas_diseno.edit');
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $create = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/ventas-diseno/clients', $this->validClientPayload('BuscarContacto'))
            ->assertStatus(201);
        $subClientId = $create->json('sub_clients.0.id');

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/sub-clients/{$subClientId}/contacts", [
                'name' => 'Beto Ruiz', 'role' => 'architect', 'phone' => '6222-2222',
            ])
            ->assertStatus(201);

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/ventas-diseno/sub-clients/{$subClientId}/contacts?search=Beto")
            ->assertStatus(200);

        $names = collect($response->json('data'))->pluck('name');
        $this->assertTrue($names->contains('Beto Ruiz'));
        $this->assertFalse($names->contains('Ana Pérez'));
    }

    public function test_agregar_contacto_sin_telefono_ni_correo_retorna_422(): void
    {
        $this->grantVentasDiseno('designer@illuminations.test', 'ventas_diseno.write', 'ventas_diseno.edit');
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $create = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/ventas-diseno/clients', $this->validClientPayload('ContactoInvalido'))
            ->assertStatus(201);
        $subClientId = $create->json('sub_clients.0.id');

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/sub-clients/{$subClientId}/contacts", [
                'name' => 'Sin Contacto', 'role' => 'client',
            ])
            ->assertStatus(422);
    }

    public function test_agregar_contacto_con_nombre_puramente_numerico_retorna_422(): void
    {
        // SCRUM-96 (hallazgo Marly 2026-07-15): antes se aceptaba y guardaba sin aviso.
        $this->grantVentasDiseno('designer@illuminations.test', 'ventas_diseno.write', 'ventas_diseno.edit');
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $create = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/ventas-diseno/clients', $this->validClientPayload('NombreNumerico'))
            ->assertStatus(201);
        $subClientId = $create->json('sub_clients.0.id');

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/sub-clients/{$subClientId}/contacts", [
                'name' => '858252', 'role' => 'client', 'phone' => '6111-1111',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');
    }

    public function test_agregar_contacto_con_telefono_con_letras_retorna_422(): void
    {
        // SCRUM-96 (hallazgo Marly 2026-07-15): antes se aceptaba y guardaba sin aviso.
        $this->grantVentasDiseno('designer@illuminations.test', 'ventas_diseno.write', 'ventas_diseno.edit');
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $create = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/ventas-diseno/clients', $this->validClientPayload('TelefonoInvalido'))
            ->assertStatus(201);
        $subClientId = $create->json('sub_clients.0.id');

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/sub-clients/{$subClientId}/contacts", [
                'name' => 'Contacto Valido', 'role' => 'client', 'phone' => 'abcdef',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('phone');
    }

    public function test_agregar_contacto_a_subcliente_existente(): void
    {
        $this->grantVentasDiseno('designer@illuminations.test', 'ventas_diseno.write', 'ventas_diseno.edit');
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $create = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/ventas-diseno/clients', $this->validClientPayload('Contactos'))
            ->assertStatus(201);
        $subClientId = $create->json('sub_clients.0.id');

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/sub-clients/{$subClientId}/contacts", [
                'name' => 'Segundo Contacto', 'role' => 'other', 'email' => 'segundo@example.com',
            ])
            ->assertStatus(201)
            ->assertJsonPath('name', 'Segundo Contacto');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/ventas-diseno/clients/{$create->json('id')}")
            ->assertStatus(200);

        $names = collect($response->json('sub_clients.0.contacts'))->pluck('name');
        $this->assertTrue($names->contains('Segundo Contacto'));
    }

    public function test_editar_contacto_de_subcliente_no_lo_duplica(): void
    {
        $this->grantVentasDiseno('designer@illuminations.test', 'ventas_diseno.write', 'ventas_diseno.edit');
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $create = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/ventas-diseno/clients', $this->validClientPayload('EditarContacto'))
            ->assertStatus(201);
        $subClientId = $create->json('sub_clients.0.id');
        $contactId   = $create->json('sub_clients.0.contacts.0.id');

        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/ventas-diseno/sub-clients/{$subClientId}/contacts/{$contactId}", [
                'name' => 'Ana Pérez Editada', 'role' => 'client', 'phone' => '6000-0000',
            ])
            ->assertStatus(200)
            ->assertJsonPath('name', 'Ana Pérez Editada');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/ventas-diseno/clients/{$create->json('id')}")
            ->assertStatus(200);

        $this->assertCount(1, $response->json('sub_clients.0.contacts'));
    }

    public function test_borrar_contacto_de_subcliente_requiere_permiso_edit(): void
    {
        // supervisor@ en vez de designer@ -- ver SCRUM-83. El escenario necesita write SIN edit;
        // con designer@ (rol real vendedor_disenador desde el fix) el bridge rol+nivel otorga
        // ambos juntos, invalidando el test.
        $this->grantVentasDiseno('supervisor@illuminations.test', 'ventas_diseno.write');
        $token = $this->loginAs('supervisor@illuminations.test', 'Password123!');

        $create = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/ventas-diseno/clients', $this->validClientPayload('BorrarContacto'))
            ->assertStatus(201);
        $subClientId = $create->json('sub_clients.0.id');
        $contactId   = $create->json('sub_clients.0.contacts.0.id');

        $this->withHeaders($this->authHeaders($token))
            ->deleteJson("/api/ventas-diseno/sub-clients/{$subClientId}/contacts/{$contactId}")
            ->assertStatus(403);
    }
}
