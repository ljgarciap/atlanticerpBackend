<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Modules\Auth\Models\AuditLog;
use App\Modules\Auth\Models\ImpersonationSession;
use App\Shared\Multitenancy\Tenant;
use App\Shared\Services\AuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-1158 (T5) — pantalla «Sesiones de soporte»: `GET /api/admin/security/impersonations` y `POST .../{id}/revoke` (solo superadmin).
 */
final class ImpersonationAdminTest extends FeatureTestCase
{
    private const PASSWORD = 'Password123!';
    private const URL      = '/api/admin/security/impersonations';

    protected function setUp(): void
    {
        parent::setUp();
        $this->reMakeCurrent();
    }

    private function reMakeCurrent(): void
    {
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    private function userId(string $email): int
    {
        $this->reMakeCurrent();

        return (int) User::where('email', $email)->value('id');
    }

    /** @return array<string, string> */
    private function superadmin(): array
    {
        return $this->authHeaders($this->loginAs('superadmin@atlantic.test', self::PASSWORD, 'superadmin'));
    }

    /** @param array<string, mixed> $overrides */
    private function insertSession(array $overrides = []): string
    {
        $id = (string) Str::uuid();
        DB::table('atlantic_auth.impersonation_sessions')->insert($overrides + [
            'id' => $id, 'actor_id' => $this->userId('superadmin@atlantic.test'), 'target_id' => $this->userId('vendedor@atlantic.test'),
            'reason' => 'SCRUM-1158 prueba', 'started_at' => now(), 'expires_at' => now()->addMinutes(30),
        ]);

        return (string) ($overrides['id'] ?? $id);
    }

    /** @return array{0: array<string, string>, 1: string} */
    private function beginImpersonation(): array
    {
        $response = $this->postJson('/api/users/' . $this->userId('vendedor@atlantic.test') . '/impersonate', [
            'reason' => 'SCRUM-1158 reproducir pantalla', 'operator_password' => self::PASSWORD,
        ], $this->superadmin())->assertStatus(201);

        return [$this->authHeaders((string) $response->json('token')), (string) $response->json('user.impersonation.id')];
    }

    // ── Listado ───────────────────────────────────────────────────────────────────────────────────────────

    public function test_el_listado_trae_el_contrato_que_consume_el_frontend(): void
    {
        $id = $this->insertSession();

        $response = $this->getJson(self::URL, $this->superadmin())->assertOk();

        $row = collect($response->json('data'))->firstWhere('id', $id);
        $this->assertNotNull($row);
        $this->assertEqualsCanonicalizing(
            ['id', 'actor', 'target', 'reason', 'started_at', 'expires_at', 'ended_at', 'ended_reason', 'status'],
            array_keys($row),
        );
        $this->assertSame(['id' => $this->userId('superadmin@atlantic.test'), 'name' => 'Super Admin'], $row['actor']);
        $this->assertSame('Vendedor Demo', $row['target']['name']);
        $this->assertSame('active', $row['status']);
        $this->assertNull($row['ended_at']);
        $this->assertEqualsCanonicalizing(['current_page', 'per_page', 'total', 'last_page'], array_keys($response->json('meta')));
    }

    public function test_el_estado_se_calcula_activa_terminada_o_vencida(): void
    {
        $active  = $this->insertSession();
        $expired = $this->insertSession(['expires_at' => now()->subMinute(), 'started_at' => now()->subHour()]);
        $ended   = $this->insertSession(['ended_at' => now(), 'ended_reason' => 'manual']);
        $headers = $this->superadmin();

        foreach (['active' => $active, 'expired' => $expired, 'ended' => $ended] as $status => $expectedId) {
            $ids = collect($this->getJson(self::URL . "?status={$status}", $headers)->assertOk()->json('data'))->pluck('id')->all();
            $this->assertSame([$expectedId], $ids, "filtro status={$status}");
        }
    }

    public function test_filtra_por_actor_y_objetivo_y_ordena_de_la_mas_reciente_a_la_mas_antigua(): void
    {
        $old = $this->insertSession(['started_at' => now()->subDay(), 'expires_at' => now()->subDay()->addMinutes(30)]);
        $new = $this->insertSession(['target_id' => $this->userId('nivel5@atlantic.test')]);
        $headers = $this->superadmin();

        $this->assertSame([$new, $old], collect($this->getJson(self::URL, $headers)->json('data'))->pluck('id')->all());
        $this->assertSame([$new], collect($this->getJson(self::URL . '?target_id=' . $this->userId('nivel5@atlantic.test'), $headers)->json('data'))->pluck('id')->all());
        $this->assertSame([], $this->getJson(self::URL . '?actor_id=' . $this->userId('vendedor@atlantic.test'), $headers)->json('data'));
    }

    public function test_pagina_con_per_page_y_page(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->insertSession(['started_at' => now()->subMinutes($i)]);
        }
        $headers = $this->superadmin();

        $first = $this->getJson(self::URL . '?per_page=2&page=1', $headers)->assertOk();
        $third = $this->getJson(self::URL . '?per_page=2&page=3', $headers)->assertOk();

        $this->assertCount(2, $first->json('data'));
        $this->assertSame(5, $first->json('meta.total'));
        $this->assertSame(2, $first->json('meta.per_page'));
        $this->assertSame(3, $first->json('meta.last_page'));
        $this->assertSame(3, $third->json('meta.current_page'));
        $this->assertCount(1, $third->json('data'));
    }

    public function test_rechaza_filtros_invalidos(): void
    {
        $headers = $this->superadmin();

        $this->getJson(self::URL . '?status=otra', $headers)->assertStatus(422);
        $this->getJson(self::URL . '?per_page=1000', $headers)->assertStatus(422);
        $this->getJson(self::URL . '?actor_id=abc', $headers)->assertStatus(422);
    }

    public function test_solo_superadmin_ve_y_termina_sesiones(): void
    {
        $id = $this->insertSession();
        $designer = $this->authHeaders($this->loginAs('vendedor@atlantic.test', self::PASSWORD, 'designer'));

        $this->getJson(self::URL)->assertStatus(401);
        $this->getJson(self::URL, $designer)->assertStatus(403);
        $this->postJson(self::URL . "/{$id}/revoke", [], $designer)->assertStatus(403);
        $this->assertNull(DB::table('atlantic_auth.impersonation_sessions')->where('id', $id)->value('ended_at'));
    }

    // ── Terminar una sesión ───────────────────────────────────────────────────────────────────────────────

    public function test_terminar_una_sesion_activa_la_cierra_y_el_token_muere_en_la_siguiente_request(): void
    {
        [$tokenHeaders, $id] = $this->beginImpersonation();
        $this->getJson('/api/auth/me', $tokenHeaders)->assertOk();

        $this->postJson(self::URL . "/{$id}/revoke", [], $this->superadmin())->assertNoContent();

        $this->getJson('/api/auth/me', $tokenHeaders)->assertStatus(401)->assertJsonPath('code', 'impersonation_ended');

        $this->reMakeCurrent();
        $session = ImpersonationSession::findOrFail($id);
        $this->assertSame('revoked', $session->ended_reason);
        $this->assertSame($this->userId('superadmin@atlantic.test'), $session->ended_by);
        $this->assertSame('ended', $session->status());
    }

    public function test_terminar_deja_el_evento_de_auditoria_con_quien_lo_hizo(): void
    {
        $id = $this->insertSession();
        $this->postJson(self::URL . "/{$id}/revoke", [], $this->superadmin())->assertNoContent();

        $this->reMakeCurrent();
        $log = AuditLog::query()->where('action', AuditService::AUTH_IMPERSONATION_REVOKED)->firstOrFail();
        $this->assertSame($id, $log->entity_id);
        $this->assertSame($this->userId('superadmin@atlantic.test'), $log->user_id);
        $this->assertSame($this->userId('vendedor@atlantic.test'), $log->metadata['target_user_id']);
        $this->assertArrayNotHasKey('impersonation_id', $log->metadata, 'el revocador no está en una sesión de impersonación');
    }

    public function test_terminar_una_sesion_ya_cerrada_responde_409_y_no_la_modifica(): void
    {
        $id = $this->insertSession(['ended_at' => now()->subMinute(), 'ended_reason' => 'manual']);

        $this->postJson(self::URL . "/{$id}/revoke", [], $this->superadmin())->assertStatus(409)->assertJsonPath('code', 'already_ended');

        $this->assertSame('manual', DB::table('atlantic_auth.impersonation_sessions')->where('id', $id)->value('ended_reason'));
    }

    public function test_terminar_una_sesion_inexistente_o_con_id_invalido_responde_404(): void
    {
        $headers = $this->superadmin();

        $this->postJson(self::URL . '/' . Str::uuid() . '/revoke', [], $headers)->assertStatus(404);
        $this->postJson(self::URL . '/no-es-un-uuid/revoke', [], $headers)->assertStatus(404);
    }

    public function test_una_sesion_de_impersonacion_no_puede_usar_estas_rutas(): void
    {
        [$tokenHeaders, $id] = $this->beginImpersonation();

        $this->getJson(self::URL, $tokenHeaders)->assertStatus(403);
        $this->postJson(self::URL . "/{$id}/revoke", [], $tokenHeaders)->assertStatus(403)->assertJsonPath('code', 'impersonation_read_only');
    }
}
