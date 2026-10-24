<?php

declare(strict_types=1);

namespace Tests\Feature\Lumo;

use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\FeatureTestCase;

/** SCRUM-925 — Identity Bridge de LUMO, solo el paso 1 (emisión del código) vive en AtlanticERP. */
class LumoWidgetControllerTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    private function grantExtraPermissions(string $email, array $perms): void
    {
        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');
        DB::table("{$slug}_auth.users")
            ->where('email', $email)
            ->update(['extra_permissions' => json_encode($perms)]);
    }

    public function test_sin_autenticar_retorna_401(): void
    {
        $this->postJson('/api/lumo/bitacoras-code')->assertStatus(401);
    }

    public function test_sin_permiso_lumo_view_retorna_403(): void
    {
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/lumo/bitacoras-code')
            ->assertStatus(403);
    }

    public function test_con_permiso_lumo_view_emite_el_codigo(): void
    {
        $this->grantExtraPermissions('designer@atlantic.test', ['lumo.view']);
        Http::fake([
            'lumo.atlanticerp.ai/*' => Http::response(['code' => 'abc123', 'expires_at' => now()->addSeconds(60)->toIso8601String()], 201),
        ]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/lumo/bitacoras-code')
            ->assertStatus(200);

        $this->assertSame('abc123', $response->json('code'));
    }

    public function test_lumo_caido_retorna_503_generico(): void
    {
        $this->grantExtraPermissions('designer@atlantic.test', ['lumo.view']);
        Http::fake(['lumo.atlanticerp.ai/*' => Http::response([], 500)]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/lumo/bitacoras-code')
            ->assertStatus(503);
    }

    public function test_throttle_20_por_minuto(): void
    {
        $this->grantExtraPermissions('designer@atlantic.test', ['lumo.view']);
        Http::fake(['lumo.atlanticerp.ai/*' => Http::response(['code' => 'x', 'expires_at' => now()->toIso8601String()], 201)]);

        $token   = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $headers = $this->authHeaders($token);

        for ($i = 0; $i < 20; $i++) {
            $this->withHeaders($headers)->postJson('/api/lumo/bitacoras-code')->assertStatus(200);
        }

        $this->withHeaders($headers)->postJson('/api/lumo/bitacoras-code')->assertStatus(429);
    }
}
