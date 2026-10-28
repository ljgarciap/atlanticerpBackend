<?php

declare(strict_types=1);

namespace Tests\Feature\CRM;

use Tests\Feature\FeatureTestCase;

class DirectoryControllerTest extends FeatureTestCase
{
    // ── Helpers ───────────────────────────────────────────────────────────────

    private function createProject(string $token, array $extra = []): int
    {
        $payload = array_merge([
            'nombre'       => 'Proyecto Directorio',
            'tipo'         => 'diseno',
            'dialux'       => false,
            'assignee_ids' => [],
        ], $extra);

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/crm/projects', $payload);

        $response->assertStatus(201);

        return (int) $response->json('id');
    }

    private function userIdFromToken(string $token): int
    {
        $parts   = explode('.', $token);
        $payload = (array) json_decode((string) base64_decode(str_pad(strtr($parts[1], '-_', '+/'), strlen($parts[1]) % 4 === 0 ? strlen($parts[1]) : strlen($parts[1]) + 4 - strlen($parts[1]) % 4, '=')), true);
        return (int) ($payload['sub'] ?? 0);
    }

    private function assignProject(string $mgmtToken, int $projectId, int $userId): void
    {
        $this->withHeaders($this->authHeaders($mgmtToken))
            ->putJson("/api/crm/projects/{$projectId}", [
                'nombre'       => 'Proyecto Directorio',
                'tipo'         => 'diseno',
                'dialux'       => false,
                'assignee_ids' => [$userId],
            ])
            ->assertStatus(200);
    }

    // ── GET /api/crm/directory ────────────────────────────────────────────────

    public function test_retorna_401_sin_autenticar(): void
    {
        $this->getJson('/api/crm/directory?type=developer')->assertStatus(401);
    }

    public function test_retorna_422_con_tipo_invalido(): void
    {
        $token = $this->loginAs();

        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/crm/directory?type=INVALIDO')
            ->assertStatus(422);
    }

    public function test_retorna_422_sin_parametro_type(): void
    {
        $token = $this->loginAs();

        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/crm/directory')
            ->assertStatus(422);
    }

    public function test_management_ve_todos_los_contactos(): void
    {
        $token = $this->loginAs();

        // Two projects with different developers — management sees both
        $this->createProject($token, ['developer' => 'Alice Martin']);
        $this->createProject($token, ['developer' => 'Bob Torres']);

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/crm/directory?type=developer');

        $response->assertStatus(200);

        $names = collect($response->json('entries'))->pluck('nombre')->all();

        $this->assertContains('Alice Martin', $names);
        $this->assertContains('Bob Torres', $names);
    }

    public function test_designer_solo_ve_contactos_de_sus_proyectos(): void
    {
        $mgmtToken     = $this->loginAs();
        $designerToken = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $designerId = $this->userIdFromToken($designerToken);

        // Project assigned to designer
        $p1 = $this->createProject($mgmtToken, ['developer' => 'Alice Martin']);
        $this->assignProject($mgmtToken, $p1, $designerId);

        // Project NOT assigned to designer
        $this->createProject($mgmtToken, ['developer' => 'Bob Torres']);

        $response = $this->withHeaders($this->authHeaders($designerToken))
            ->getJson('/api/crm/directory?type=developer');

        $response->assertStatus(200);

        $names = collect($response->json('entries'))->pluck('nombre')->all();

        $this->assertContains('Alice Martin', $names);
        $this->assertNotContains('Bob Torres', $names);
    }

    public function test_estructura_de_respuesta_correcta(): void
    {
        $token = $this->loginAs();

        $this->createProject($token, [
            'developer'  => 'Dev Ejemplo',
            'ubicacion'  => 'Ciudad de Panamá',
            'valor'      => 50000,
            'etapa'      => 'cerrado',
        ]);

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/crm/directory?type=developer');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'stats' => ['total_entries', 'total_projects', 'total_valor', 'total_cerrado'],
                'entries' => [
                    '*' => [
                        'nombre',
                        'project_count',
                        'areas',
                        'ubicaciones',
                        'valor_total',
                        'valor_cerrado',
                        'emails',
                        'telefonos',
                        'ultima_actividad',
                    ],
                ],
            ]);

        $entry = collect($response->json('entries'))->firstWhere('nombre', 'Dev Ejemplo');
        $this->assertNotNull($entry);
        $this->assertSame(1, $entry['project_count']);
        $this->assertSame(50000.0, (float) $entry['valor_cerrado']);
        $this->assertContains('Ciudad de Panamá', $entry['ubicaciones']);
    }

    public function test_busqueda_filtra_por_nombre(): void
    {
        $token = $this->loginAs();

        $this->createProject($token, ['arquitecto' => 'Tecnico Ruiz']);
        $this->createProject($token, ['arquitecto' => 'Diana Lopez']);

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/crm/directory?type=arquitecto&q=tecnico');

        $response->assertStatus(200);

        $names = collect($response->json('entries'))->pluck('nombre')->all();

        $this->assertContains('Tecnico Ruiz', $names);
        $this->assertNotContains('Diana Lopez', $names);
    }
}
