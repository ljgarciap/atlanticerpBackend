<?php

declare(strict_types=1);

namespace Tests\Feature\CRM;

use App\Modules\CRM\Models\NotificationRule;
use App\Shared\Multitenancy\Tenant;
use Tests\Feature\FeatureTestCase;

class NotificationRuleControllerTest extends FeatureTestCase
{
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

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'name'            => 'Aviso de prueba',
            'trigger_type'    => 'model_event',
            'trigger_model'   => 'Project',
            'trigger_event'   => 'updated',
            'field'           => 'etapa',
            'operator'        => 'changed_to',
            'value'           => 'cerrado',
            'channels'        => ['in_app'],
            'recipient_type'  => 'user',
            'recipient_value' => [1],
        ], $overrides);
    }

    public function test_listar_reglas_sin_autenticar_retorna_401(): void
    {
        $this->getJson('/api/admin/notification-rules')->assertStatus(401);
    }

    public function test_designer_no_puede_listar_reglas(): void
    {
        $token = $this->loginAs('designer@illuminations.test', 'Password123!');
        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin/notification-rules')
            ->assertStatus(403);
    }

    public function test_superadmin_puede_crear_regla(): void
    {
        $token = $this->loginAs('superadmin@illuminations.test', 'Password123!');

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/admin/notification-rules', $this->validPayload());

        $response->assertStatus(201);
        $this->activateTenant();
        $this->assertDatabaseHas('notification_rules', ['name' => 'Aviso de prueba']);
    }

    public function test_crear_regla_con_campo_no_permitido_para_el_modelo_retorna_422(): void
    {
        $token = $this->loginAs('superadmin@illuminations.test', 'Password123!');

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/admin/notification-rules', $this->validPayload(['field' => 'notas']));

        $response->assertStatus(422)->assertJsonValidationErrors('field');
    }

    public function test_crear_regla_con_operador_invalido_retorna_422(): void
    {
        $token = $this->loginAs('superadmin@illuminations.test', 'Password123!');

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/admin/notification-rules', $this->validPayload(['operator' => 'contains']));

        $response->assertStatus(422)->assertJsonValidationErrors('operator');
    }

    public function test_crear_regla_con_modelo_fuera_de_whitelist_retorna_422(): void
    {
        $token = $this->loginAs('superadmin@illuminations.test', 'Password123!');

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/admin/notification-rules', $this->validPayload(['trigger_model' => 'User']));

        $response->assertStatus(422)->assertJsonValidationErrors('trigger_model');
    }

    public function test_listar_incluye_el_catalogo_de_registry(): void
    {
        $token = $this->loginAs('superadmin@illuminations.test', 'Password123!');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin/notification-rules');

        $response->assertStatus(200);
        $this->assertArrayHasKey('Project', $response->json('registry.models'));
        $this->assertContains('changed_to', $response->json('registry.operators'));
    }

    public function test_actualizar_regla(): void
    {
        $token = $this->loginAs('superadmin@illuminations.test', 'Password123!');
        $this->activateTenant();
        $rule  = NotificationRule::create(array_merge($this->validPayload(), ['value' => 'cerrado']));

        $response = $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/admin/notification-rules/{$rule->id}", $this->validPayload(['name' => 'Actualizada']));

        $response->assertStatus(200);
        $this->activateTenant();
        $this->assertDatabaseHas('notification_rules', ['id' => $rule->id, 'name' => 'Actualizada']);
    }

    public function test_eliminar_regla(): void
    {
        $token = $this->loginAs('superadmin@illuminations.test', 'Password123!');
        $this->activateTenant();
        $rule  = NotificationRule::create(array_merge($this->validPayload(), ['value' => 'cerrado']));

        $this->withHeaders($this->authHeaders($token))
            ->deleteJson("/api/admin/notification-rules/{$rule->id}")
            ->assertStatus(204);

        $this->activateTenant();
        $this->assertDatabaseMissing('notification_rules', ['id' => $rule->id]);
    }
}
