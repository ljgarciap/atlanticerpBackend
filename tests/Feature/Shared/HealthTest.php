<?php

namespace Tests\Feature\Shared;

use Tests\Feature\FeatureTestCase;

/**
 * Tests del endpoint GET /api/health.
 */
class HealthTest extends FeatureTestCase
{
    public function test_health_retorna_200_y_status_ok(): void
    {
        $response = $this->getJson('/api/health');

        $response->assertStatus(200);
        $response->assertExactJson(['status' => 'ok']);
    }
}
