<?php

declare(strict_types=1);

namespace Tests\Feature\CRM;

use Tests\Feature\FeatureTestCase;

class ContactControllerTest extends FeatureTestCase
{
    // ── Helpers ───────────────────────────────────────────────────────────────

    private function createContact(string $token, array $extra = []): int
    {
        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/crm/contacts', array_merge([
                'nombre' => 'Grupo Lafayette',
                'tipo'   => 'developer',
            ], $extra));

        $response->assertStatus(201);

        return (int) $response->json('id');
    }

    private function createProject(string $token, array $extra = []): int
    {
        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/crm/projects', array_merge([
                'nombre'       => 'Proyecto Contacto',
                'tipo'         => 'diseno',
                'dialux'       => false,
                'assignee_ids' => [],
            ], $extra));

        $response->assertStatus(201);

        return (int) $response->json('id');
    }

    // ── GET /api/crm/contacts ─────────────────────────────────────────────────

    public function test_index_retorna_401_sin_autenticar(): void
    {
        $this->getJson('/api/crm/contacts')->assertStatus(401);
    }

    public function test_index_retorna_lista_vacia_sin_contactos(): void
    {
        $token = $this->loginAs();

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/crm/contacts');

        $response->assertStatus(200);
        $response->assertJsonStructure(['stats', 'entries']);
        $this->assertCount(0, $response->json('entries'));
    }

    public function test_index_retorna_contactos_creados(): void
    {
        $token = $this->loginAs();
        $this->createContact($token, ['nombre' => 'Developer A', 'tipo' => 'developer']);
        $this->createContact($token, ['nombre' => 'Arquitecto B', 'tipo' => 'arquitecto']);

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/crm/contacts');

        $response->assertStatus(200);
        $this->assertCount(2, $response->json('entries'));
    }

    public function test_index_filtra_por_tipo(): void
    {
        $token = $this->loginAs();
        $this->createContact($token, ['nombre' => 'Dev Alpha', 'tipo' => 'developer']);
        $this->createContact($token, ['nombre' => 'Arq Beta',  'tipo' => 'arquitecto']);

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/crm/contacts?type=developer');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('entries'));
        $this->assertSame('developer', $response->json('entries.0.tipo'));
    }

    public function test_index_filtra_por_busqueda(): void
    {
        $token = $this->loginAs();
        $this->createContact($token, ['nombre' => 'Grupo Lafayette', 'tipo' => 'developer']);
        $this->createContact($token, ['nombre' => 'Estudio Omega',   'tipo' => 'developer']);

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/crm/contacts?q=lafayette');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('entries'));
        $this->assertSame('Grupo Lafayette', $response->json('entries.0.nombre'));
    }

    public function test_index_rechaza_tipo_invalido(): void
    {
        $token = $this->loginAs();

        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/crm/contacts?type=invalido')
            ->assertStatus(422);
    }

    public function test_index_incluye_stats_de_proyectos(): void
    {
        $token = $this->loginAs();
        $this->createContact($token, ['nombre' => 'Dev Con Proyecto', 'tipo' => 'developer']);
        $this->createProject($token, ['developer' => 'Dev Con Proyecto', 'valor' => 50000.0]);

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/crm/contacts?type=developer');

        $response->assertStatus(200);
        $entry = $response->json('entries.0');
        $this->assertSame(1, $entry['project_count']);
        $this->assertEquals(50000.0, $entry['valor_total']);
        $this->assertSame('contacts', $entry['source']);
    }

    // ── POST /api/crm/contacts ────────────────────────────────────────────────

    public function test_store_retorna_401_sin_autenticar(): void
    {
        $this->postJson('/api/crm/contacts', ['nombre' => 'X', 'tipo' => 'developer'])
            ->assertStatus(401);
    }

    public function test_store_crea_contacto_con_campos_minimos(): void
    {
        $token = $this->loginAs();

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/crm/contacts', [
                'nombre' => 'Constructora XYZ',
                'tipo'   => 'developer',
            ]);

        $response->assertStatus(201);
        $response->assertJsonFragment(['nombre' => 'Constructora XYZ', 'tipo' => 'developer']);
        $this->assertNotNull($response->json('id'));
    }

    public function test_store_crea_contacto_con_todos_los_campos(): void
    {
        $token = $this->loginAs();

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/crm/contacts', [
                'nombre'   => 'Arq. María López',
                'tipo'     => 'arquitecto',
                'email'    => 'maria@estudio.mx',
                'telefono' => '+52 55 1234 5678',
                'empresa'  => 'Estudio López',
                'notas'    => 'Especialista en diseño biofílico',
            ]);

        $response->assertStatus(201);
        $response->assertJsonFragment([
            'nombre'  => 'Arq. María López',
            'tipo'    => 'arquitecto',
            'email'   => 'maria@estudio.mx',
            'empresa' => 'Estudio López',
        ]);
    }

    public function test_store_falla_sin_nombre(): void
    {
        $token = $this->loginAs();

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/crm/contacts', ['tipo' => 'developer'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['nombre']);
    }

    public function test_store_falla_con_tipo_invalido(): void
    {
        $token = $this->loginAs();

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/crm/contacts', ['nombre' => 'Test', 'tipo' => 'cliente'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['tipo']);
    }

    // ── PUT /api/crm/contacts/{id} ────────────────────────────────────────────

    public function test_update_retorna_401_sin_autenticar(): void
    {
        $this->putJson('/api/crm/contacts/1', [])->assertStatus(401);
    }

    public function test_update_retorna_404_si_no_existe(): void
    {
        $token = $this->loginAs();

        $this->withHeaders($this->authHeaders($token))
            ->putJson('/api/crm/contacts/99999', ['nombre' => 'X'])
            ->assertStatus(404);
    }

    public function test_update_modifica_campos(): void
    {
        $token = $this->loginAs();
        $id    = $this->createContact($token, ['nombre' => 'Original', 'tipo' => 'encargado']);

        $response = $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/crm/contacts/{$id}", [
                'nombre'  => 'Actualizado',
                'empresa' => 'Nueva Empresa',
            ]);

        $response->assertStatus(200);
        $response->assertJsonFragment(['nombre' => 'Actualizado', 'empresa' => 'Nueva Empresa']);
    }

    // ── DELETE /api/crm/contacts/{id} ────────────────────────────────────────

    public function test_destroy_retorna_401_sin_autenticar(): void
    {
        $this->deleteJson('/api/crm/contacts/1')->assertStatus(401);
    }

    public function test_destroy_retorna_404_si_no_existe(): void
    {
        $token = $this->loginAs();

        $this->withHeaders($this->authHeaders($token))
            ->deleteJson('/api/crm/contacts/99999')
            ->assertStatus(404);
    }

    public function test_destroy_elimina_contacto_sin_proyectos_activos(): void
    {
        $token = $this->loginAs();
        $id    = $this->createContact($token);

        $this->withHeaders($this->authHeaders($token))
            ->deleteJson("/api/crm/contacts/{$id}")
            ->assertStatus(204);

        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/crm/contacts')
            ->assertJsonPath('stats.total', 0);
    }

    public function test_destroy_retorna_409_si_tiene_proyectos_activos(): void
    {
        $token = $this->loginAs();
        $id    = $this->createContact($token, ['nombre' => 'Dev Activo', 'tipo' => 'developer']);

        // Create a project with an active stage linked to this contact by name
        $this->createProject($token, ['developer' => 'Dev Activo', 'etapa' => 'lead']);

        $response = $this->withHeaders($this->authHeaders($token))
            ->deleteJson("/api/crm/contacts/{$id}");

        $response->assertStatus(409);
        $response->assertJsonStructure(['message', 'active_projects']);
        $this->assertSame(1, $response->json('active_projects'));
    }

    // ── POST /api/crm/contacts/import ─────────────────────────────────────────

    public function test_import_retorna_401_sin_autenticar(): void
    {
        $this->postJson('/api/crm/contacts/import')->assertStatus(401);
    }

    public function test_import_retorna_403_para_designer(): void
    {
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/crm/contacts/import')
            ->assertStatus(403);
    }

    public function test_import_crea_contactos_desde_proyectos(): void
    {
        $token = $this->loginAs();
        $this->createProject($token, ['developer' => 'Dev Importado', 'arquitecto' => 'Arq Importado']);

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/crm/contacts/import');

        $response->assertStatus(200);
        $response->assertJsonStructure(['created', 'skipped']);
        $this->assertGreaterThanOrEqual(2, $response->json('created'));
    }

    public function test_import_no_duplica_existentes(): void
    {
        $token = $this->loginAs();
        $this->createProject($token, ['developer' => 'Dev Único']);
        $this->createContact($token, ['nombre' => 'Dev Único', 'tipo' => 'developer']);

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/crm/contacts/import');

        $response->assertStatus(200);
        $this->assertGreaterThanOrEqual(1, $response->json('skipped'));
    }

    public function test_import_copia_email_y_celular_del_proyecto_para_tipo_contacto(): void
    {
        $token = $this->loginAs();
        $this->createProject($token, [
            'contacto' => 'Contacto Import Test',
            'email'    => 'maria@ejemplo.com',
            'celular'  => '+507 6123-4567',
        ]);

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/crm/contacts/import');
        $response->assertStatus(200);

        $list = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/crm/contacts?type=contacto');

        $created = collect($list->json('entries'))->firstWhere('nombre', 'Contacto Import Test');
        $this->assertNotNull($created);
        $this->assertSame('maria@ejemplo.com', $created['email']);
        $this->assertSame('+507 6123-4567', $created['telefono']);
    }

    public function test_import_rellena_email_y_celular_de_contacto_existente_vacio(): void
    {
        $token = $this->loginAs();

        // Contacto ya existe sin email/telefono (ej. importado antes de este fix)
        $this->createContact($token, ['nombre' => 'Contacto Import Test', 'tipo' => 'contacto']);

        $this->createProject($token, [
            'contacto' => 'Contacto Import Test',
            'email'    => 'maria@ejemplo.com',
            'celular'  => '+507 6123-4567',
        ]);

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/crm/contacts/import');

        $response->assertStatus(200);
        $this->assertGreaterThanOrEqual(1, $response->json('updated'));

        $list = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/crm/contacts?type=contacto');

        $updated = collect($list->json('entries'))->firstWhere('nombre', 'Contacto Import Test');
        $this->assertSame('maria@ejemplo.com', $updated['email']);
        $this->assertSame('+507 6123-4567', $updated['telefono']);
    }

    public function test_import_no_sobrescribe_email_ya_cargado_en_contacto_existente(): void
    {
        $token = $this->loginAs();

        // Contacto con email propio, distinto al del proyecto — no debe pisarse
        $this->createContact($token, [
            'nombre' => 'Contacto Import Test',
            'tipo'   => 'contacto',
            'email'  => 'correcto@ejemplo.com',
        ]);

        $this->createProject($token, [
            'contacto' => 'Contacto Import Test',
            'email'    => 'otro@ejemplo.com',
            'celular'  => '+507 6123-4567',
        ]);

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/crm/contacts/import')
            ->assertStatus(200);

        $list = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/crm/contacts?type=contacto');

        $contact = collect($list->json('entries'))->firstWhere('nombre', 'Contacto Import Test');
        $this->assertSame('correcto@ejemplo.com', $contact['email']);
        // El teléfono sí estaba vacío, ese sí se rellena
        $this->assertSame('+507 6123-4567', $contact['telefono']);
    }
}
