<?php

declare(strict_types=1);

namespace Tests\Feature\Soporte;

use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Facades\Http;
use Tests\Feature\FeatureTestCase;

/** SCRUM-923 — Identity Bridge de Illumination Support Tickets, visible a cualquier usuario autenticado. */
class SoporteWidgetControllerTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    public function test_sin_autenticar_retorna_401(): void
    {
        $this->postJson('/api/soporte/widget-code')->assertStatus(401);
    }

    public function test_usuario_autenticado_sin_permiso_especial_emite_el_codigo(): void
    {
        Http::fake([
            'tickets.atlanticerp.ai/*' => Http::response(['code' => 'zzz789', 'expires_at' => now()->addSeconds(60)->toIso8601String()], 201),
        ]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/soporte/widget-code')
            ->assertStatus(200);

        $this->assertSame('zzz789', $response->json('code'));

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'tickets.atlanticerp.ai')
                && str_starts_with((string) $request['external_user_id'], 'atlantic:');
        });
    }

    public function test_tickets_backend_caido_retorna_503_generico(): void
    {
        Http::fake(['tickets.atlanticerp.ai/*' => Http::response([], 500)]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/soporte/widget-code')
            ->assertStatus(503);
    }

    public function test_throttle_20_por_minuto(): void
    {
        Http::fake(['tickets.atlanticerp.ai/*' => Http::response(['code' => 'x', 'expires_at' => now()->toIso8601String()], 201)]);

        $token   = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $headers = $this->authHeaders($token);

        for ($i = 0; $i < 20; $i++) {
            $this->withHeaders($headers)->postJson('/api/soporte/widget-code')->assertStatus(200);
        }

        $this->withHeaders($headers)->postJson('/api/soporte/widget-code')->assertStatus(429);
    }
}
