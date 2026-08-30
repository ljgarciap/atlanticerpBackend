<?php

declare(strict_types=1);

namespace Tests\Feature\CRM;

use App\Modules\CRM\Models\NotificationRule;
use App\Modules\Notifications\Models\Notification;
use App\Shared\Multitenancy\Tenant;
use Tests\Feature\FeatureTestCase;

class NotificationRuleEngineTest extends FeatureTestCase
{
    private function currentUserId(string $token): int
    {
        return (int) $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/auth/me')
            ->json('id');
    }

    private function createProject(string $token, array $overrides = []): int
    {
        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/crm/projects', array_merge([
                'nombre'       => 'Proyecto Reglas',
                'tipo'         => 'ambos',
                'etapa'        => 'lead',
                'dialux'       => false,
                'assignee_ids' => [],
            ], $overrides));
        $response->assertStatus(201);
        return (int) $response->json('id');
    }

    /**
     * etapa NO es un campo de UpdateProjectRequest — el endpoint genérico PUT lo ignora en
     * silencio (validated() descarta cualquier key fuera de sus rules()). Cambiar de etapa
     * tiene su propio endpoint dedicado, PATCH .../stage (ver routes/crm.php).
     */
    private function changeStage(string $token, int $projectId, string $etapa): void
    {
        $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/crm/projects/{$projectId}/stage", ['etapa' => $etapa])
            ->assertStatus(200);
    }

    /** NotificationRule::create() se llama fuera de un request HTTP — ver nota en NotificationServiceTest (Fase 1). */
    private function activateTenant(): void
    {
        Tenant::where('slug', 'illuminations')->first()->makeCurrent();
    }

    protected function tearDown(): void
    {
        Tenant::forgetCurrent();
        parent::tearDown();
    }

    private function createRule(array $overrides = []): NotificationRule
    {
        $this->activateTenant();

        return NotificationRule::create(array_merge([
            'name'            => 'Regla de prueba',
            'trigger_type'    => 'model_event',
            'trigger_model'   => 'Project',
            'trigger_event'   => 'updated',
            'field'           => 'etapa',
            'operator'        => 'changed_to',
            'value'           => 'cerrado',
            'channels'        => ['in_app'],
            'recipient_type'  => 'user',
            'recipient_value' => [],
            'is_active'       => true,
        ], $overrides));
    }

    public function test_changed_to_dispara_notificacion(): void
    {
        $mgmtToken = $this->loginAs();

        $designerToken = $this->loginAs('designer@illuminations.test', 'Password123!');
        $designerId    = $this->currentUserId($designerToken);

        $this->createRule(['recipient_value' => [$designerId]]);

        $projectId = $this->createProject($mgmtToken);
        $this->changeStage($mgmtToken, $projectId, 'cerrado');

        $this->activateTenant();
        $this->assertSame(1, Notification::where('user_id', $designerId)->count());
    }

    public function test_changed_to_no_dispara_si_cambia_a_otro_valor(): void
    {
        $mgmtToken = $this->loginAs();
        $designerToken = $this->loginAs('designer@illuminations.test', 'Password123!');
        $designerId = $this->currentUserId($designerToken);

        $this->createRule(['recipient_value' => [$designerId]]);

        $projectId = $this->createProject($mgmtToken);
        $this->changeStage($mgmtToken, $projectId, 'propuesta');

        $this->activateTenant();
        $this->assertSame(0, Notification::where('user_id', $designerId)->count());
    }

    public function test_excluye_al_actor_que_hizo_el_cambio(): void
    {
        $mgmtToken = $this->loginAs();
        $mgmtId    = $this->currentUserId($mgmtToken);

        $designerToken = $this->loginAs('designer@illuminations.test', 'Password123!');
        $designerId    = $this->currentUserId($designerToken);

        // regla apunta al propio management (el actor del cambio)
        $this->createRule(['recipient_value' => [$mgmtId, $designerId]]);

        $projectId = $this->createProject($mgmtToken);
        $this->changeStage($mgmtToken, $projectId, 'cerrado');

        $this->activateTenant();
        $this->assertSame(0, Notification::where('user_id', $mgmtId)->count());
        $this->assertSame(1, Notification::where('user_id', $designerId)->count());
    }

    public function test_regla_inactiva_no_dispara(): void
    {
        $mgmtToken = $this->loginAs();
        $designerToken = $this->loginAs('designer@illuminations.test', 'Password123!');
        $designerId = $this->currentUserId($designerToken);

        $this->createRule(['recipient_value' => [$designerId], 'is_active' => false]);

        $projectId = $this->createProject($mgmtToken);
        $this->changeStage($mgmtToken, $projectId, 'cerrado');

        $this->activateTenant();
        $this->assertSame(0, Notification::where('user_id', $designerId)->count());
    }

    public function test_regla_de_otro_modelo_no_dispara_por_cambio_de_project(): void
    {
        $mgmtToken = $this->loginAs();
        $designerToken = $this->loginAs('designer@illuminations.test', 'Password123!');
        $designerId = $this->currentUserId($designerToken);

        $this->createRule([
            'trigger_model'   => 'ProjectActivity',
            'recipient_value' => [$designerId],
        ]);

        $projectId = $this->createProject($mgmtToken);
        $this->changeStage($mgmtToken, $projectId, 'cerrado');

        $this->activateTenant();
        $this->assertSame(0, Notification::where('user_id', $designerId)->count());
    }

    public function test_operador_gt_sobre_valor(): void
    {
        $mgmtToken = $this->loginAs();
        $designerToken = $this->loginAs('designer@illuminations.test', 'Password123!');
        $designerId = $this->currentUserId($designerToken);

        $this->createRule([
            'field' => 'valor', 'operator' => 'gt', 'value' => 100000,
            'recipient_value' => [$designerId],
        ]);

        $projectId = $this->createProject($mgmtToken, ['valor' => 50000]);

        $this->withHeaders($this->authHeaders($mgmtToken))
            ->putJson("/api/crm/projects/{$projectId}", [
                'nombre' => 'Proyecto Reglas', 'tipo' => 'ambos', 'etapa' => 'lead', 'dialux' => false, 'valor' => 150000,
            ])
            ->assertStatus(200);

        $this->activateTenant();
        $this->assertSame(1, Notification::where('user_id', $designerId)->count());
    }

    public function test_operador_gt_no_dispara_si_no_supera_el_valor(): void
    {
        $mgmtToken = $this->loginAs();
        $designerToken = $this->loginAs('designer@illuminations.test', 'Password123!');
        $designerId = $this->currentUserId($designerToken);

        $this->createRule([
            'field' => 'valor', 'operator' => 'gt', 'value' => 100000,
            'recipient_value' => [$designerId],
        ]);

        $projectId = $this->createProject($mgmtToken, ['valor' => 50000]);

        $this->withHeaders($this->authHeaders($mgmtToken))
            ->putJson("/api/crm/projects/{$projectId}", [
                'nombre' => 'Proyecto Reglas', 'tipo' => 'ambos', 'etapa' => 'lead', 'dialux' => false, 'valor' => 90000,
            ])
            ->assertStatus(200);

        $this->activateTenant();
        $this->assertSame(0, Notification::where('user_id', $designerId)->count());
    }

    public function test_recipient_type_role_notifica_a_todos_los_del_rol(): void
    {
        $mgmtToken = $this->loginAs();

        $this->createRule(['recipient_type' => 'role', 'recipient_value' => ['designer']]);

        $projectId = $this->createProject($mgmtToken);
        $this->changeStage($mgmtToken, $projectId, 'cerrado');

        $designerToken = $this->loginAs('designer@illuminations.test', 'Password123!');
        $designerId    = $this->currentUserId($designerToken);

        $this->activateTenant();
        $this->assertSame(1, Notification::where('user_id', $designerId)->count());
    }

    public function test_recipient_type_project_assignees_notifica_a_los_asignados(): void
    {
        $mgmtToken = $this->loginAs();
        $designerToken = $this->loginAs('designer@illuminations.test', 'Password123!');
        $designerId    = $this->currentUserId($designerToken);

        $this->createRule(['recipient_type' => 'project_assignees', 'recipient_value' => null]);

        $projectId = $this->createProject($mgmtToken, ['assignee_ids' => [$designerId]]);
        $this->changeStage($mgmtToken, $projectId, 'cerrado');

        $this->activateTenant();
        $this->assertSame(1, Notification::where('user_id', $designerId)->count());
    }
}
