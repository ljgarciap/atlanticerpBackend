<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use App\Modules\AI\Models\AiModelPricing;
use Tests\Feature\FeatureTestCase;

class AiModelPricingControllerTest extends FeatureTestCase
{
    public function test_index_requiere_permiso_superadmin(): void
    {
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin/ai/pricing')
            ->assertStatus(403);
    }

    public function test_superadmin_lista_y_actualiza_tarifa(): void
    {
        $token = $this->loginAs('superadmin@illuminations.test', 'Password123!', 'superadmin');

        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin/ai/pricing')
            ->assertStatus(200)
            ->assertJsonFragment(['model' => 'claude-sonnet-5']);

        $this->withHeaders($this->authHeaders($token))
            ->putJson('/api/admin/ai/pricing/claude-sonnet-5', [
                'input_price_per_million_usd'  => 4.50,
                'output_price_per_million_usd' => 20.00,
            ])
            ->assertStatus(200)
            ->assertJsonFragment(['input_price_per_million_usd' => 4.50]);

        $this->assertSame(4.50, (float) AiModelPricing::query()->find('claude-sonnet-5')->input_price_per_million_usd);
    }

    public function test_actualizar_modelo_inexistente_retorna_404(): void
    {
        $token = $this->loginAs('superadmin@illuminations.test', 'Password123!', 'superadmin');

        $this->withHeaders($this->authHeaders($token))
            ->putJson('/api/admin/ai/pricing/claude-inexistente', [
                'input_price_per_million_usd'  => 1,
                'output_price_per_million_usd' => 1,
            ])
            ->assertStatus(404);
    }
}
