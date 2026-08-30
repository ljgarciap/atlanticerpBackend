<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Shared\Multitenancy\Tenant;
use App\Shared\Services\AuditService;
use Illuminate\Support\Facades\DB;
use Tests\Feature\FeatureTestCase;

/**
 * Epic 11 — Seguridad Agéntica. Gap encontrado durante la implementación: AuthController
 * nunca loggeaba auth.login/auth.failed (ver ADR-004) — sin esto, SecurityAnalysisJob no
 * puede detectar credential stuffing ni accesos fuera de horario.
 */
class AuditLoginLoggingTest extends FeatureTestCase
{
    private function userId(string $email): int
    {
        $tenant = Tenant::where('slug', 'illuminations')->firstOrFail();
        $tenant->makeCurrent();

        try {
            return User::where('email', $email)->firstOrFail()->id;
        } finally {
            Tenant::forgetCurrent();
        }
    }

    public function test_login_exitoso_registra_auth_login_con_el_user_id_correcto(): void
    {
        $userId = $this->userId('designer@illuminations.test');

        $this->postJson('/api/auth/login', [
            'email'    => 'designer@illuminations.test',
            'password' => 'Password123!',
        ])->assertStatus(200);

        // Query directamente por tabla schema-calificada — el tenant ya se limpió
        // (Tenant::forgetCurrent()) para cuando el response HTTP vuelve al test.
        $log = DB::table('illuminations_auth.audit_logs')
            ->where('action', AuditService::AUTH_LOGIN)
            ->where('user_id', $userId)
            ->first();

        $this->assertNotNull($log, 'Debe existir una entrada de audit con action=auth.login');
    }

    public function test_login_fallido_con_password_incorrecto_registra_auth_failed(): void
    {
        $userId = $this->userId('designer@illuminations.test');

        $this->postJson('/api/auth/login', [
            'email'    => 'designer@illuminations.test',
            'password' => 'PasswordIncorrecto!',
        ])->assertStatus(401);

        $log = DB::table('illuminations_auth.audit_logs')
            ->where('action', AuditService::AUTH_FAILED)
            ->where('user_id', $userId)
            ->first();

        $this->assertNotNull($log, 'Debe existir una entrada de audit con action=auth.failed');
    }

    public function test_login_fallido_con_email_inexistente_registra_intento_sin_user_id(): void
    {
        $this->postJson('/api/auth/login', [
            'email'    => 'no-existe@illuminations.test',
            'password' => 'Password123!',
        ])->assertStatus(401);

        $log = DB::table('illuminations_auth.audit_logs')
            ->where('action', AuditService::AUTH_FAILED)
            ->whereNull('user_id')
            ->latest('created_at')
            ->first();

        $this->assertNotNull($log);
        $metadata = json_decode((string) $log->metadata, true);
        $this->assertSame('no-existe@illuminations.test', $metadata['attempted_email'] ?? null);
    }
}
