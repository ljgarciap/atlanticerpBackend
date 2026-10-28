<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Modules\Auth\Jobs\SecurityAnalysisJob;
use App\Modules\Auth\Models\GlobalSetting;
use App\Shared\Multitenancy\Tenant;
use App\Shared\Services\AuditService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-1158 (T5, condición C7) — reglas determinísticas de `SecurityAnalysisJob` para el uso inusual de «Ver como usuario». Los umbrales vienen
 * de los ajustes globales (no del código) y la señal salta cuando el valor los SUPERA.
 */
final class ImpersonationAlertsJobTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.anthropic.api_key' => 'test-anthropic-key']);
        Redis::del('cb:anthropic:failures', 'cb:anthropic:opened_at');
        Carbon::setTestNow(Carbon::parse('2026-07-05 03:00:00'));
        Http::fake(['api.anthropic.com/*' => Http::response([
            'content' => [['type' => 'text', 'text' => json_encode(['anomalies' => []])]],
        ], 200)]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function userId(string $email): int
    {
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();

        return (int) User::where('email', $email)->value('id');
    }

    /** Sesión de ayer a la hora indicada (dentro de la ventana que analiza el job). */
    private function insertImpersonation(string $targetEmail, string $time = '14:00', ?string $actorEmail = null): string
    {
        $id = (string) Str::uuid();
        $at = Carbon::yesterday()->setTimeFromTimeString($time);
        DB::table('atlantic_auth.impersonation_sessions')->insert([
            'id' => $id, 'actor_id' => $this->userId($actorEmail ?? 'superadmin@atlantic.test'), 'target_id' => $this->userId($targetEmail),
            'reason' => 'SCRUM-1158 prueba de alertas', 'started_at' => $at, 'expires_at' => $at->copy()->addMinutes(30),
        ]);

        return $id;
    }

    private function runJob(): void
    {
        $tenant = Tenant::where('slug', 'atlantic')->firstOrFail();
        $tenant->makeCurrent();
        SecurityAnalysisJob::dispatchSync('atlantic');
        Tenant::forgetCurrent();
    }

    /** @return list<array<string, mixed>> */
    private function anomalies(string $rule): array
    {
        return DB::table('atlantic_auth.security_anomalies')->get()
            ->map(fn ($row): array => ['severity' => $row->severity, 'user' => $row->affected_user_id, 'raw' => json_decode((string) $row->raw_response, true)])
            ->filter(fn (array $a): bool => ($a['raw']['rule'] ?? null) === $rule)->values()->all();
    }

    private function blockedWrites(string $sessionId, int $count, ?int $actorId = null): void
    {
        for ($i = 0; $i < $count; $i++) {
            DB::table('atlantic_auth.audit_logs')->insert([
                'user_id' => $actorId ?? $this->userId('superadmin@atlantic.test'), 'action' => AuditService::AUTH_IMPERSONATION_WRITE_BLOCKED,
                'request_id' => (string) Str::uuid(), 'created_at' => Carbon::yesterday()->setTime(15, $i % 60),
                'metadata' => json_encode(['impersonation_id' => $sessionId, 'impersonated_user_id' => 1, 'method' => 'POST']),
            ]);
        }
    }

    // ── Sesiones por día ──────────────────────────────────────────────────────────────────────────────────

    public function test_supera_el_umbral_de_sesiones_por_dia(): void
    {
        foreach (['08:00', '09:30', '11:00', '12:30', '14:00', '15:30'] as $time) {
            $this->insertImpersonation('vendedor@atlantic.test', $time);
        }

        $this->runJob();

        $found = $this->anomalies('impersonation_sesiones_por_dia');
        $this->assertCount(1, $found);
        $this->assertSame('medium', $found[0]['severity']);
        $this->assertSame($this->userId('superadmin@atlantic.test'), $found[0]['user']);
        $this->assertSame(6, $found[0]['raw']['sessions']);
        $this->assertSame(5, $found[0]['raw']['threshold']);
        $this->assertSame('deterministic', $found[0]['raw']['source']);
    }

    public function test_en_el_umbral_exacto_no_salta(): void
    {
        foreach (['08:00', '09:30', '11:00', '12:30', '14:00'] as $time) {
            $this->insertImpersonation('vendedor@atlantic.test', $time);
        }

        $this->runJob();

        $this->assertSame([], $this->anomalies('impersonation_sesiones_por_dia'));
    }

    public function test_el_umbral_sale_de_los_ajustes_globales(): void
    {
        GlobalSetting::updateOrCreate(['key' => 'impersonation_alert_sessions_per_day'], ['value' => '2', 'updated_at' => now()]);
        foreach (['08:00', '10:00', '12:00'] as $time) {
            $this->insertImpersonation('vendedor@atlantic.test', $time);
        }

        $this->runJob();

        $found = $this->anomalies('impersonation_sesiones_por_dia');
        $this->assertCount(1, $found);
        $this->assertSame(2, $found[0]['raw']['threshold']);
    }

    public function test_las_sesiones_de_hoy_no_cuentan_todavia(): void
    {
        for ($i = 0; $i < 8; $i++) {
            $id = (string) Str::uuid();
            DB::table('atlantic_auth.impersonation_sessions')->insert([
                'id' => $id, 'actor_id' => $this->userId('superadmin@atlantic.test'), 'target_id' => $this->userId('vendedor@atlantic.test'),
                'reason' => 'SCRUM-1158 hoy', 'started_at' => Carbon::today()->setTime(1, $i), 'expires_at' => Carbon::today()->setTime(2, $i),
            ]);
        }

        $this->runJob();

        $this->assertSame([], $this->anomalies('impersonation_sesiones_por_dia'));
    }

    // ── Personas distintas por hora ───────────────────────────────────────────────────────────────────────

    public function test_supera_el_umbral_de_personas_distintas_en_una_hora(): void
    {
        $this->insertImpersonation('vendedor@atlantic.test', '10:00');
        $this->insertImpersonation('nivel5@atlantic.test', '10:10');
        $this->insertImpersonation('nivel2@atlantic.test', '10:20');
        $this->insertImpersonation('nivel1@atlantic.test', '10:30');

        $this->runJob();

        $found = $this->anomalies('impersonation_objetivos_por_hora');
        $this->assertCount(1, $found);
        $this->assertSame(4, $found[0]['raw']['distinct_targets']);
        $this->assertSame(3, $found[0]['raw']['threshold']);
        $this->assertSame('medium', $found[0]['severity']);
        $this->assertSame($this->userId('superadmin@atlantic.test'), $found[0]['user']);
    }

    public function test_tres_personas_en_una_hora_o_cuatro_repartidas_en_varias_horas_no_saltan(): void
    {
        $this->insertImpersonation('vendedor@atlantic.test', '10:00');
        $this->insertImpersonation('nivel5@atlantic.test', '10:10');
        $this->insertImpersonation('nivel2@atlantic.test', '10:20');
        // La cuarta, fuera de la hora de las anteriores.
        $this->insertImpersonation('nivel1@atlantic.test', '12:30');

        $this->runJob();

        $this->assertSame([], $this->anomalies('impersonation_objetivos_por_hora'));
    }

    public function test_la_misma_persona_varias_veces_no_cuenta_como_personas_distintas(): void
    {
        foreach (['10:00', '10:10', '10:20', '10:30', '10:40'] as $time) {
            $this->insertImpersonation('vendedor@atlantic.test', $time);
        }

        $this->runJob();

        $this->assertSame([], $this->anomalies('impersonation_objetivos_por_hora'));
    }

    // ── Escrituras bloqueadas por sesión ──────────────────────────────────────────────────────────────────

    public function test_muchos_intentos_de_escritura_en_una_sesion_disparan_la_alerta(): void
    {
        $id = $this->insertImpersonation('vendedor@atlantic.test');
        $this->blockedWrites($id, 21);

        $this->runJob();

        $found = $this->anomalies('impersonation_escrituras_bloqueadas');
        $this->assertCount(1, $found);
        $this->assertSame($id, $found[0]['raw']['impersonation_id']);
        $this->assertSame(21, $found[0]['raw']['blocked_writes']);
        $this->assertSame($this->userId('superadmin@atlantic.test'), $found[0]['user']);
    }

    public function test_veinte_intentos_o_repartidos_en_varias_sesiones_no_saltan(): void
    {
        $a = $this->insertImpersonation('vendedor@atlantic.test', '09:00');
        $b = $this->insertImpersonation('nivel5@atlantic.test', '16:00');
        $this->blockedWrites($a, 20);
        $this->blockedWrites($b, 15);

        $this->runJob();

        $this->assertSame([], $this->anomalies('impersonation_escrituras_bloqueadas'));
    }

    // ── Dedupe y aviso ────────────────────────────────────────────────────────────────────────────────────

    public function test_no_duplica_si_el_job_corre_dos_veces(): void
    {
        foreach (['08:00', '09:30', '11:00', '12:30', '14:00', '15:30'] as $time) {
            $this->insertImpersonation('vendedor@atlantic.test', $time);
        }

        $this->runJob();
        Carbon::setTestNow(Carbon::parse('2026-07-05 03:07:00'));
        $this->runJob();

        $this->assertCount(1, $this->anomalies('impersonation_sesiones_por_dia'));
    }

    public function test_sin_sesiones_no_se_crea_ninguna_alerta_de_impersonacion(): void
    {
        $this->runJob();

        $this->assertSame(0, DB::table('atlantic_auth.security_anomalies')->count());
    }
}
