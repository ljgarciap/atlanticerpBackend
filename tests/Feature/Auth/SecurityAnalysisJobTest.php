<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Modules\Auth\Jobs\SecurityAnalysisJob;
use App\Modules\Auth\Mail\SecurityAnomalyMail;
use App\Shared\Multitenancy\Tenant;
use App\Shared\Services\AuditService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Tests\Feature\FeatureTestCase;

/**
 * Epic 11 — Seguridad Agéntica. Ver docs/ai/security-analysis-job-prompt.md para el contrato
 * completo. El job nunca bloquea por sí mismo — solo crea sugerencias en security_anomalies.
 *
 * La ventana que analiza el job es el día calendario anterior completo (ver fix de revisión
 * de código en SecurityAnalysisJob::handle() — Carbon::yesterday()->startOfDay() a
 * Carbon::today()->startOfDay(), no Carbon::now()). Por eso el reloj se congela en setUp() a
 * un instante fijo, y los audit_logs de fixture se insertan explícitamente "ayer" respecto a
 * ese instante — insertarlos con now() caería en la ventana de HOY, fuera de lo que el job mira.
 */
class SecurityAnalysisJobTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.anthropic.api_key' => 'test-anthropic-key']);
        Redis::del('cb:anthropic:failures', 'cb:anthropic:opened_at');
        Carbon::setTestNow(Carbon::parse('2026-07-05 03:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function withTenant(\Closure $fn): mixed
    {
        $tenant = Tenant::where('slug', 'illuminations')->firstOrFail();
        $tenant->makeCurrent();

        try {
            return $fn();
        } finally {
            Tenant::forgetCurrent();
        }
    }

    private function findUser(string $email): User
    {
        return $this->withTenant(fn () => User::where('email', $email)->firstOrFail());
    }

    private function refreshUser(User $user): void
    {
        $this->withTenant(fn () => $user->refresh());
    }

    /**
     * spatie/laravel-multitenancy trata todo Job como tenant-aware por defecto y necesita
     * el tenant activo AL MOMENTO DEL DISPATCH para inyectar tenantId en el payload — no
     * alcanza con que el job lo resuelva por slug dentro de su propio handle(). Mismo fix
     * aplicado a SecurityAnalyzeCommand.
     */
    private function dispatchJob(): void
    {
        $this->withTenant(fn () => SecurityAnalysisJob::dispatchSync('illuminations'));
    }

    /** @param array<string, mixed> $body */
    private function fakeClaudeResponse(array $body): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'text', 'text' => json_encode($body, JSON_THROW_ON_ERROR)]],
            ], 200),
        ]);
    }

    /** Por defecto cae a media tarde de ayer — cómodamente dentro de la ventana que analiza el job. */
    private function insertAuditLog(int $userId, string $action, ?string $newValues = null, ?Carbon $createdAt = null): void
    {
        DB::table('illuminations_auth.audit_logs')->insert([
            'user_id'    => $userId,
            'action'     => $action,
            'new_values' => $newValues,
            'request_id' => (string) Str::uuid(),
            'metadata'   => '{}',
            'created_at' => $createdAt ?? Carbon::yesterday()->setTime(14, 0),
        ]);
    }

    public function test_sin_actividad_no_crea_ninguna_anomalia_ni_llama_a_claude(): void
    {
        Http::fake();

        $this->dispatchJob();

        Http::assertNothingSent();
        $this->assertSame(0, DB::table('illuminations_auth.security_anomalies')->count());
    }

    public function test_actividad_de_hoy_no_se_analiza_todavia(): void
    {
        // Confirma la semántica del fix: el job mira el día calendario ANTERIOR, no lo que
        // pasó "hoy" hasta el momento en que corre.
        $user = $this->findUser('designer@illuminations.test');
        $this->insertAuditLog($user->id, AuditService::AUTH_FAILED, createdAt: Carbon::today()->setTime(1, 0));

        Http::fake();

        $this->dispatchJob();

        Http::assertNothingSent();
        $this->assertSame(0, DB::table('illuminations_auth.security_anomalies')->count());
    }

    public function test_detecta_anomalia_la_persiste_y_notifica_a_superadmins(): void
    {
        $user = $this->findUser('designer@illuminations.test');
        $this->insertAuditLog($user->id, AuditService::AUTH_FAILED);

        $this->fakeClaudeResponse([
            'anomalies' => [[
                'affected_user_id' => $user->id,
                'severity'         => 'high',
                'reasoning'        => 'Múltiples intentos fallidos de login en poco tiempo.',
            ]],
        ]);

        $this->dispatchJob();

        $row = DB::table('illuminations_auth.security_anomalies')->first();
        $this->assertNotNull($row);
        $this->assertSame('high', $row->severity);
        $this->assertSame($user->id, $row->affected_user_id);
        $this->assertSame('pending', $row->status);

        Mail::assertSent(SecurityAnomalyMail::class);

        // El job nunca bloquea por sí mismo.
        $this->refreshUser($user);
        $this->assertFalse($user->isSecurityBlocked());
    }

    public function test_hallazgo_con_user_id_fuera_del_resumen_enviado_se_descarta(): void
    {
        $user = $this->findUser('designer@illuminations.test');
        $this->insertAuditLog($user->id, AuditService::AUTH_FAILED);

        // Claude "inventa" un user_id que el job nunca le mandó — debe descartarse.
        $this->fakeClaudeResponse([
            'anomalies' => [[
                'affected_user_id' => 999999,
                'severity'         => 'high',
                'reasoning'        => 'Hallazgo inválido.',
            ]],
        ]);

        $this->dispatchJob();

        $this->assertSame(0, DB::table('illuminations_auth.security_anomalies')->count());
    }

    public function test_respuesta_con_schema_invalido_no_crea_filas(): void
    {
        $user = $this->findUser('designer@illuminations.test');
        $this->insertAuditLog($user->id, AuditService::AUTH_FAILED);

        $this->fakeClaudeResponse(['unexpected_key' => 'sin anomalies']);

        $this->dispatchJob();

        $this->assertSame(0, DB::table('illuminations_auth.security_anomalies')->count());
    }

    public function test_upload_a_zona_publica_se_marca_como_hallazgo_deterministico(): void
    {
        $user = $this->findUser('designer@illuminations.test');
        $this->insertAuditLog(
            $user->id,
            AuditService::CRM_DOCUMENT_UPLOADED,
            json_encode(['storage_zone' => 'public']),
        );

        $this->fakeClaudeResponse(['anomalies' => []]);

        $this->dispatchJob();

        $row = DB::table('illuminations_auth.security_anomalies')->first();
        $this->assertNotNull($row);
        $this->assertSame('low', $row->severity);
        $rawResponse = json_decode((string) $row->raw_response, true);
        $this->assertSame('deterministic', $rawResponse['source'] ?? null);
    }

    public function test_no_duplica_si_corre_dos_veces_el_mismo_dia_aunque_pase_el_tiempo(): void
    {
        // Simula el escenario real que motivó el fix (revisión de código): un reintento o
        // re-disparo manual de security:analyze más tarde el mismo día — no dos llamadas en
        // el mismo instante exacto. Avanza el reloj unos minutos entre corridas para probar
        // que el dedupe sigue funcionando (antes del fix, esto SÍ duplicaba).
        $user = $this->findUser('designer@illuminations.test');
        $this->insertAuditLog(
            $user->id,
            AuditService::CRM_DOCUMENT_UPLOADED,
            json_encode(['storage_zone' => 'public']),
        );

        $this->fakeClaudeResponse(['anomalies' => []]);

        $this->dispatchJob();
        $this->assertSame(1, DB::table('illuminations_auth.security_anomalies')->count());

        Carbon::setTestNow(Carbon::parse('2026-07-05 03:07:00')); // mismo día, 7 min después

        $this->dispatchJob();
        $this->assertSame(
            1,
            DB::table('illuminations_auth.security_anomalies')->count(),
            'No debe duplicar hallazgos aunque pase tiempo real entre corridas del mismo día',
        );
    }
}
