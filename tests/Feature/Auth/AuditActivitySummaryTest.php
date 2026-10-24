<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Modules\Auth\Models\AuditLog;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-1020 — dashboard de actividad sobre audit_logs. Las filas de prueba van con fechas
 * históricas (septiembre 2026) para no mezclarse con los eventos que el propio login del test
 * escribe "ahora".
 */
class AuditActivitySummaryTest extends FeatureTestCase
{
    private const URL = '/api/admin/audit/activity-summary';

    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    /** @return array<string, string> */
    private function superadmin(): array
    {
        return $this->authHeaders($this->loginAs('superadmin@atlantic.test', 'Password123!', 'superadmin'));
    }

    private function userId(string $email): int
    {
        return (int) User::where('email', $email)->firstOrFail()->id;
    }

    /** $at en hora de Panamá (-05:00). */
    private function log(?int $userId, string $action, string $at): void
    {
        DB::table((new AuditLog())->getTable())->insert([
            'user_id' => $userId, 'action' => $action, 'created_at' => $at.'-05:00',
        ]);
    }

    public function test_sin_autenticar_retorna_401(): void
    {
        $this->getJson(self::URL.'?from=2026-09-01&to=2026-09-07')->assertStatus(401);
    }

    public function test_sin_permiso_superadmin_retorna_403(): void
    {
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))->getJson(self::URL.'?from=2026-09-01&to=2026-09-07')->assertStatus(403);
    }

    public function test_valida_el_rango(): void
    {
        $h = $this->superadmin();

        $this->withHeaders($h)->getJson(self::URL)->assertStatus(422);
        $this->withHeaders($h)->getJson(self::URL.'?from=2026-09-01')->assertStatus(422);
        $this->withHeaders($h)->getJson(self::URL.'?from=hoy&to=2026-09-07')->assertStatus(422);
        $this->withHeaders($h)->getJson(self::URL.'?from=2026-09-07&to=2026-09-01')->assertStatus(422);
        // 91 días (1-jun → 30-ago) supera el máximo; 90 días exactos (1-jun → 29-ago) es válido.
        $this->withHeaders($h)->getJson(self::URL.'?from=2026-06-01&to=2026-08-30')->assertStatus(422);
        $this->withHeaders($h)->getJson(self::URL.'?from=2026-06-01&to=2026-08-29')->assertStatus(200)
            ->assertJsonCount(90, 'by_day');
    }

    public function test_agrega_por_usuario_tipo_modulo_y_dia(): void
    {
        $designer = $this->userId('designer@atlantic.test');
        $admin    = $this->userId('superadmin@atlantic.test');

        foreach (['2026-09-10 09:00:00', '2026-09-10 10:00:00', '2026-09-12 11:00:00'] as $at) {
            $this->log($designer, 'crm.project.created', $at);
        }
        $this->log($designer, 'crm.project.updated', '2026-09-12 12:00:00');
        $this->log($admin, 'auth.login', '2026-09-10 08:00:00');
        $this->log($admin, 'auth.failed', '2026-09-10 08:01:00');
        $this->log($admin, 'security.access_denied', '2026-09-11 08:00:00');
        $this->log($admin, 'bodega.order_picking_sheet.exported', '2026-09-11 09:00:00');
        $this->log($admin, 'crm.document.downloaded', '2026-09-11 09:30:00');
        $this->log($admin, 'crm.project.deleted', '2026-09-11 10:00:00');
        $this->log($admin, 'ai.usage.requested', '2026-09-11 11:00:00');
        // Sin usuario (job del sistema).
        $this->log(null, 'ai.usage.requested', '2026-09-11 12:00:00');
        $this->log(null, 'ai.usage.requested', '2026-09-11 13:00:00');
        // Fuera del rango: no cuenta.
        $this->log($designer, 'crm.project.created', '2026-09-20 09:00:00');

        $r = $this->withHeaders($this->superadmin())->getJson(self::URL.'?from=2026-09-10&to=2026-09-12')->assertStatus(200);

        $r->assertJsonPath('totals.interactions', 13)
            ->assertJsonPath('totals.active_users', 2)
            ->assertJsonPath('totals.data_changes', 5)
            ->assertJsonPath('totals.data_changes_pct', 38.5)
            ->assertJsonPath('totals.access_denied', 1)
            ->assertJsonPath('by_type.created', 3)
            ->assertJsonPath('by_type.updated', 1)
            ->assertJsonPath('by_type.deleted', 1)
            ->assertJsonPath('by_type.exported', 1)
            ->assertJsonPath('by_type.downloaded', 1)
            ->assertJsonPath('by_type.login', 1)
            ->assertJsonPath('by_type.login_failed', 1)
            ->assertJsonPath('by_type.access_denied', 1)
            ->assertJsonPath('by_type.other', 3);

        // Por usuario, de mayor a menor; el "Sistema" (user_id null) aparece como su propia fila.
        $this->assertSame([$admin, $designer, null], array_column($r->json('by_user'), 'user_id'));
        $this->assertSame([7, 4, 2], array_column($r->json('by_user'), 'count'));

        $modules = array_column($r->json('by_module'), 'count', 'module');
        $this->assertSame(['crm' => 6, 'ai' => 3, 'auth' => 2, 'bodega' => 1, 'security' => 1], $modules);

        $this->assertSame(
            [['date' => '2026-09-10', 'count' => 4], ['date' => '2026-09-11', 'count' => 7], ['date' => '2026-09-12', 'count' => 2]],
            $r->json('by_day'),
        );
    }

    /** Las fechas se interpretan en hora de Panamá, no en UTC. */
    public function test_el_limite_del_dia_respeta_la_zona_horaria_de_panama(): void
    {
        $designer = $this->userId('designer@atlantic.test');
        $this->log($designer, 'crm.project.created', '2026-09-10 23:30:00'); // = 2026-09-11 04:30 UTC, sigue siendo día 10 en Panamá
        $this->log($designer, 'crm.project.created', '2026-09-11 00:30:00'); // día 11 en Panamá

        $r = $this->withHeaders($this->superadmin())->getJson(self::URL.'?from=2026-09-10&to=2026-09-10')->assertStatus(200);

        $r->assertJsonPath('totals.interactions', 1);
        $this->assertSame([['date' => '2026-09-10', 'count' => 1]], $r->json('by_day'));
    }

    public function test_rango_vacio_devuelve_ceros_y_serie_continua(): void
    {
        $r = $this->withHeaders($this->superadmin())->getJson(self::URL.'?from=2026-01-01&to=2026-01-03')->assertStatus(200);

        $r->assertJsonPath('totals.interactions', 0)
            ->assertJsonPath('totals.data_changes_pct', 0)
            ->assertJsonPath('by_type.other', 0)
            ->assertJsonCount(3, 'by_day')
            ->assertJsonPath('by_user', [])
            ->assertJsonPath('by_module', []);
    }

    /** El total del dashboard debe coincidir con el visor de Logs para el mismo rango. */
    public function test_el_total_coincide_con_el_visor_de_logs(): void
    {
        $designer = $this->userId('designer@atlantic.test');
        $this->log($designer, 'crm.project.created', '2026-09-10 09:00:00');
        $this->log($designer, 'crm.project.updated', '2026-09-10 23:59:00');
        $this->log(null, 'ai.usage.requested', '2026-09-11 00:01:00');
        $h = $this->superadmin();

        $summary = $this->withHeaders($h)->getJson(self::URL.'?from=2026-09-10&to=2026-09-11')->json('totals.interactions');
        $logs    = $this->withHeaders($h)->getJson('/api/admin/audit/logs?from=2026-09-10&to=2026-09-11')->json('total');

        $this->assertSame(3, $summary);
        $this->assertSame($logs, $summary);
    }
}
