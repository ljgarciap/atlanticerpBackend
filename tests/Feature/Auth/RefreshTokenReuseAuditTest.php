<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Shared\Services\AuditService;
use Illuminate\Support\Facades\DB;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-1033 (A-03, alcance mínimo): reusar un refresh token ya REVOCADO deja `auth.refresh_token_reuse` en audit_logs
 * (lo lee SecurityAnalysisJob); un token inexistente o inválido no. El cliente recibe siempre el mismo 401.
 */
class RefreshTokenReuseAuditTest extends FeatureTestCase
{
    private function eventos(): int
    {
        return DB::table('atlantic_auth.audit_logs')->where('action', AuditService::AUTH_REFRESH_TOKEN_REUSE)->count();
    }

    /** @return array<string, mixed> */
    private function login(): array
    {
        return $this->postJson('/api/auth/login', ['email' => 'vendedor@atlantic.test', 'password' => 'Password123!'])
            ->assertStatus(200)->json();
    }

    public function test_reusar_un_refresh_token_rotado_registra_el_evento_y_responde_401(): void
    {
        $original = (string) $this->login()['refresh_token'];
        $hijo = $this->postJson('/api/auth/refresh', ['refresh_token' => $original])->assertStatus(200)->json('refresh_token');
        $this->assertSame(0, $this->eventos());

        $this->postJson('/api/auth/refresh', ['refresh_token' => $original])
            ->assertStatus(401)
            ->assertJsonPath('message', trans('auth.token_invalid'));

        $this->assertSame(1, $this->eventos());
        $log = DB::table('atlantic_auth.audit_logs')->where('action', AuditService::AUTH_REFRESH_TOKEN_REUSE)->first();
        $this->assertNotNull($log->user_id);
        $this->assertSame('user', $log->entity_type);

        // El token hijo legítimo no cambia de estado (alcance mínimo: no se revoca la cadena, decisión D-2).
        $this->postJson('/api/auth/refresh', ['refresh_token' => $hijo])->assertStatus(200);
    }

    public function test_un_token_inexistente_no_registra_el_evento(): void
    {
        $this->postJson('/api/auth/refresh', ['refresh_token' => str_repeat('a', 64)])->assertStatus(401);

        $this->assertSame(0, $this->eventos());
    }

    public function test_un_token_vencido_por_inactividad_no_revocado_no_registra_el_evento(): void
    {
        $token = (string) $this->login()['refresh_token'];
        DB::table('atlantic_auth.refresh_tokens')->where('token_hash', hash('sha256', $token))
            ->update(['last_activity_at' => now()->subDays(40)]);

        $this->postJson('/api/auth/refresh', ['refresh_token' => $token])->assertStatus(401);

        $this->assertSame(0, $this->eventos());
    }
}
