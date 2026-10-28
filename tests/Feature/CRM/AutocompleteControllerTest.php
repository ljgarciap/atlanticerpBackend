<?php

declare(strict_types=1);

namespace Tests\Feature\CRM;

use Tests\Feature\FeatureTestCase;

class AutocompleteControllerTest extends FeatureTestCase
{
    // ── Helpers ───────────────────────────────────────────────────────────────

    // Prefix to avoid collisions with data that may persist from previous test runs
    private const PREFIX = '[AC-TEST]';

    private function createProject(string $token, array $extra = []): void
    {
        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/crm/projects', array_merge([
                'nombre'       => self::PREFIX . ' Proyecto Autocomplete',
                'tipo'         => 'diseno',
                'requires_technical_study'       => false,
                'assignee_ids' => [],
            ], $extra))
            ->assertStatus(201);
    }

    // ── GET /api/crm/autocomplete/fields ─────────────────────────────────────

    public function test_fields_retorna_401_sin_autenticar(): void
    {
        $this->getJson('/api/crm/autocomplete/fields?field=encargado')->assertStatus(401);
    }

    public function test_fields_retorna_422_sin_field(): void
    {
        $token = $this->loginAs();

        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/crm/autocomplete/fields')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['field']);
    }

    public function test_fields_retorna_422_con_field_no_permitido(): void
    {
        $token = $this->loginAs();

        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/crm/autocomplete/fields?field=nombre')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['field']);
    }

    public function test_fields_retorna_200_con_estructura_correcta(): void
    {
        $token = $this->loginAs();

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/crm/autocomplete/fields?field=encargado');

        $response->assertStatus(200);
        $response->assertJsonStructure(['data']);
        $this->assertIsArray($response->json('data'));
    }

    public function test_fields_incluye_valor_recien_creado(): void
    {
        $token          = $this->loginAs();
        $uniqueEncargado = self::PREFIX . ' Encargado-' . uniqid();
        $this->createProject($token, ['encargado' => $uniqueEncargado]);

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/crm/autocomplete/fields?field=encargado');

        $response->assertStatus(200);
        $this->assertContains($uniqueEncargado, $response->json('data'));
    }

    public function test_fields_retorna_valores_distintos(): void
    {
        $token       = $this->loginAs();
        $uniqueName  = self::PREFIX . ' Enc-' . uniqid();

        // Two projects with the same encargado → should appear exactly once
        $this->createProject($token, ['encargado' => $uniqueName]);
        $this->createProject($token, ['encargado' => $uniqueName]);

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/crm/autocomplete/fields?field=encargado');

        $response->assertStatus(200);
        $data  = $response->json('data');
        $count = count(array_filter($data, fn ($v) => $v === $uniqueName));
        $this->assertSame(1, $count, 'Duplicate encargado should only appear once');
    }

    public function test_fields_filtra_por_busqueda(): void
    {
        $token   = $this->loginAs();
        $unique  = 'ZQXV-' . uniqid(); // highly unlikely to collide
        $this->createProject($token, ['encargado' => "Ing. {$unique}"]);
        $this->createProject($token, ['encargado' => 'Arq. Otro Distinto']);

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/crm/autocomplete/fields?field=encargado&q={$unique}");

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertSame("Ing. {$unique}", $data[0]);
    }

    public function test_fields_acepta_todos_los_campos_permitidos(): void
    {
        $token  = $this->loginAs();
        $fields = ['encargado', 'developer', 'arquitecto', 'despacho', 'ubicacion', 'contacto', 'area'];

        foreach ($fields as $field) {
            $this->withHeaders($this->authHeaders($token))
                ->getJson("/api/crm/autocomplete/fields?field={$field}")
                ->assertStatus(200);
        }
    }

    public function test_fields_excluye_valores_nulos_y_vacios(): void
    {
        $token      = $this->loginAs();
        $uniqueReal = self::PREFIX . ' Real-' . uniqid();

        $this->createProject($token, ['encargado' => null]);
        $this->createProject($token, ['encargado' => '']);
        $this->createProject($token, ['encargado' => $uniqueReal]);

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/crm/autocomplete/fields?field=encargado');

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertContains($uniqueReal, $data);
        $this->assertNotContains('', $data);
        $this->assertNotContains(null, $data);
    }

    // ── GET /api/crm/autocomplete/fields?field=area ──────────────────────────

    public function test_area_field_retorna_valores_de_tipo_proyecto(): void
    {
        $token = $this->loginAs();
        $this->createProject($token, ['area' => 'Residencial']);
        $this->createProject($token, ['area' => 'Comercial']);

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/crm/autocomplete/fields?field=area');

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertContains('Residencial', $data);
        $this->assertContains('Comercial', $data);
    }

    public function test_area_field_retorna_valores_distintos(): void
    {
        $token = $this->loginAs();
        $unique = self::PREFIX . '-Area-' . uniqid();

        $this->createProject($token, ['area' => $unique]);
        $this->createProject($token, ['area' => $unique]);

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/crm/autocomplete/fields?field=area&q={$unique}");

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
    }

    public function test_area_field_excluye_nulos_y_vacios(): void
    {
        $token      = $this->loginAs();
        $uniqueArea = self::PREFIX . '-Null-Test-' . uniqid();

        $this->createProject($token, ['area' => null]);
        $this->createProject($token, ['area' => '']);
        $this->createProject($token, ['area' => $uniqueArea]);

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/crm/autocomplete/fields?field=area');

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertContains($uniqueArea, $data);
        $this->assertNotContains('', $data);
        $this->assertNotContains(null, $data);
    }

    // ── GET /api/crm/autocomplete/users ──────────────────────────────────────

    public function test_users_retorna_401_sin_autenticar(): void
    {
        $this->getJson('/api/crm/autocomplete/users')->assertStatus(401);
    }

    public function test_users_retorna_usuarios_activos(): void
    {
        $token = $this->loginAs();

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/crm/autocomplete/users');

        $response->assertStatus(200);
        $response->assertJsonStructure(['data' => [['id', 'first_name', 'last_name', 'email']]]);
        $this->assertGreaterThan(0, count($response->json('data')));
    }

    public function test_users_filtra_por_nombre(): void
    {
        $token = $this->loginAs();

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/crm/autocomplete/users?q=management');

        $response->assertStatus(200);
        $users = $response->json('data');
        $this->assertGreaterThan(0, count($users));

        foreach ($users as $u) {
            $haystack = strtolower($u['first_name'] . ' ' . $u['last_name'] . ' ' . $u['email']);
            $this->assertStringContainsString('management', $haystack);
        }
    }

    public function test_users_retorna_estructura_correcta(): void
    {
        $token = $this->loginAs();

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/crm/autocomplete/users');

        $response->assertStatus(200);

        $user = $response->json('data.0');
        $this->assertArrayHasKey('id', $user);
        $this->assertArrayHasKey('first_name', $user);
        $this->assertArrayHasKey('last_name', $user);
        $this->assertArrayHasKey('email', $user);
        $this->assertArrayNotHasKey('password', $user);
    }
}
