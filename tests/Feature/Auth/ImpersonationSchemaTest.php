<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Modules\Auth\Models\AuditLog;
use App\Modules\Auth\Models\ImpersonationSession;
use App\Modules\Auth\Models\GlobalSetting;
use App\Modules\Auth\Services\ImpersonationSettings;
use App\Shared\Multitenancy\Tenant;
use App\Shared\Services\AuditService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-1158 (T1) — esquema de `auth.impersonation_sessions`, parámetros en `system_settings` y condición C4 del threat model: el rol de
 * runtime (`atlanticerp_app`) no puede borrar sesiones ni reescribir su evidencia. La parte de C4 INTENTA las operaciones con ese rol contra
 * Postgres real (`SET ROLE`), no solo consulta privilegios.
 */
final class ImpersonationSchemaTest extends FeatureTestCase
{
    private const APP_ROLE = 'atlanticerp_app';
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    private function superadminToken(): string
    {
        return $this->loginAs('superadmin@atlantic.test', 'Password123!', 'superadmin');
    }

    private function bootstrapAppRole(): void
    {
        $role = self::APP_ROLE;
        DB::statement(<<<SQL
            DO \$\$ BEGIN
                IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = '{$role}') THEN
                    CREATE ROLE {$role} WITH LOGIN PASSWORD 'test-only' NOSUPERUSER;
                END IF;
            END \$\$;
        SQL);
        $this->artisan('tenant:grant-app-role')->assertExitCode(0);
    }

    private function insertSession(): string
    {
        $id = (string) Str::uuid();
        $actor  = (int) DB::table('atlantic_auth.users')->where('email', 'superadmin@atlantic.test')->value('id');
        $target = (int) DB::table('atlantic_auth.users')->where('email', 'designer@atlantic.test')->value('id');

        DB::table('atlantic_auth.impersonation_sessions')->insert([
            'id' => $id, 'actor_id' => $actor, 'target_id' => $target, 'reason' => 'SCRUM-1158 prueba de esquema',
            'started_at' => now(), 'expires_at' => now()->addMinutes(30),
        ]);

        return $id;
    }

    /** Ejecuta `$statement` como atlanticerp_app dentro de un savepoint; devuelve el mensaje del error o null si funcionó. */
    private function runAsAppRole(string $statement): ?string
    {
        DB::statement('SET ROLE ' . self::APP_ROLE);

        try {
            DB::transaction(static fn () => DB::statement($statement));

            return null;
        } catch (QueryException $e) {
            return $e->getMessage();
        } finally {
            DB::statement('RESET ROLE');
        }
    }

    // ── Esquema ───────────────────────────────────────────────────────────────────────────────────────────

    public function test_la_tabla_tiene_las_columnas_del_diseno(): void
    {
        $columns = DB::table('information_schema.columns')
            ->where('table_schema', 'atlantic_auth')->where('table_name', 'impersonation_sessions')
            ->pluck('data_type', 'column_name')->all();

        foreach (['id', 'actor_id', 'target_id', 'reason', 'started_at', 'expires_at', 'ended_at', 'ended_reason', 'ended_by', 'ip_address', 'user_agent', 'request_id'] as $col) {
            $this->assertArrayHasKey($col, $columns, "falta la columna {$col}");
        }
        $this->assertSame('uuid', $columns['id']);
        $this->assertSame('timestamp with time zone', $columns['expires_at']);
    }

    public function test_las_fk_a_users_no_tienen_cascada_de_borrado(): void
    {
        $rows = DB::select(<<<'SQL'
            SELECT conname, confdeltype FROM pg_constraint
            WHERE conrelid = 'atlantic_auth.impersonation_sessions'::regclass AND contype = 'f'
        SQL);

        $this->assertCount(3, $rows, 'actor_id, target_id y ended_by');
        foreach ($rows as $row) {
            $this->assertNotContains($row->confdeltype, ['c', 'n', 'd'], "{$row->conname} no debe borrar ni anular en cascada");
        }
    }

    public function test_existe_el_indice_parcial_de_sesiones_abiertas(): void
    {
        $def = (string) DB::table('pg_indexes')->where('schemaname', 'atlantic_auth')->where('indexname', 'idx_impersonation_sessions_open')->value('indexdef');

        $this->assertStringContainsString('ended_at IS NULL', $def);
    }

    public function test_el_modelo_calcula_el_estado_de_la_sesion(): void
    {
        $id = $this->insertSession();
        $session = ImpersonationSession::findOrFail($id);
        $this->assertSame(ImpersonationSession::STATUS_ACTIVE, $session->status());
        $this->assertTrue($session->isOpen());

        $session->expires_at = now()->subMinute();
        $this->assertSame(ImpersonationSession::STATUS_EXPIRED, $session->status());

        $session->ended_at = now();
        $this->assertSame(ImpersonationSession::STATUS_ENDED, $session->status());
        $this->assertFalse($session->isOpen());
    }

    // ── Parámetros (GlobalSetting, ajustes globales de superadmin) ────────────────────────────────────────

    private const SETTINGS_URL = '/api/admin/global-settings';

    /** @return array<string, int> */
    private function defaults(): array
    {
        return [
            'impersonation_max_minutes' => 30, 'impersonation_reason_min_length' => 10, 'impersonation_alert_sessions_per_day' => 5,
            'impersonation_alert_targets_per_hour' => 3, 'impersonation_alert_blocked_writes_per_session' => 20,
        ];
    }

    public function test_sin_filas_en_la_base_los_parametros_valen_sus_defaults(): void
    {
        $this->assertSame($this->defaults(), app(ImpersonationSettings::class)->all());
    }

    public function test_un_valor_fuera_de_rango_editado_a_mano_se_acota(): void
    {
        GlobalSetting::updateOrCreate(['key' => 'impersonation_max_minutes'], ['value' => '9999', 'updated_at' => now()]);
        GlobalSetting::updateOrCreate(['key' => 'impersonation_reason_min_length'], ['value' => '0', 'updated_at' => now()]);
        GlobalSetting::updateOrCreate(['key' => 'impersonation_alert_sessions_per_day'], ['value' => 'abc', 'updated_at' => now()]);

        $settings = app(ImpersonationSettings::class);
        $this->assertSame(120, $settings->maxMinutes());
        $this->assertSame(5, $settings->reasonMinLength());
        $this->assertSame(5, $settings->alertSessionsPerDay(), 'un valor no numérico cae al default');
    }

    public function test_el_listado_de_ajustes_globales_expone_los_parametros_con_grupo_y_label(): void
    {
        $rows = collect($this->withHeaders($this->authHeaders($this->superadminToken()))->getJson(self::SETTINGS_URL)->assertOk()->json('data'));

        foreach ($this->defaults() as $key => $default) {
            $row = $rows->firstWhere('key', $key);
            $this->assertNotNull($row, "{$key} debe listarse");
            $this->assertSame('impersonation', $row['group']);
            $this->assertSame('integer', $row['type']);
            $this->assertSame($default, $row['value']);
            $this->assertSame($default, $row['default']);
            $this->assertSame("globalSettings.globalTab.labels.{$key}", $row['label_key']);
            $this->assertTrue($row['requires_reauth'], 'cambiarlo exige la contraseña del operador');
        }
    }

    public function test_superadmin_actualiza_un_parametro_y_queda_auditado(): void
    {
        $this->withHeaders($this->authHeaders($this->superadminToken()))
            ->putJson(self::SETTINGS_URL . '/impersonation_max_minutes', ['value' => 45, 'operator_password' => 'Password123!'])
            ->assertOk()->assertJsonPath('value', 45)->assertJsonPath('group', 'impersonation');

        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $this->assertSame(45, app(ImpersonationSettings::class)->maxMinutes());
        $log = AuditLog::query()->where('action', AuditService::SETTINGS_UPDATED)->where('entity_id', 'impersonation_max_minutes')->firstOrFail();
        $this->assertSame(30, $log->old_values['value']);
        $this->assertSame(45, $log->new_values['value']);
    }

    // ── Contraseña del operador al cambiar los parámetros (aprobado por Luis) ─────────────────────────────

    /** @return array<string, array{0: string, 1: int}> */
    public static function clavesConContrasena(): array
    {
        return [
            'duración'              => ['impersonation_max_minutes', 60],
            'largo del motivo'      => ['impersonation_reason_min_length', 20],
            'sesiones por día'      => ['impersonation_alert_sessions_per_day', 8],
            'objetivos por hora'    => ['impersonation_alert_targets_per_hour', 6],
            'escrituras bloqueadas' => ['impersonation_alert_blocked_writes_per_session', 40],
        ];
    }

    #[DataProvider('clavesConContrasena')]
    public function test_sin_contrasena_del_operador_responde_422_y_no_cambia_nada(string $key, int $nuevo): void
    {
        $headers = $this->authHeaders($this->superadminToken());
        $antes   = app(ImpersonationSettings::class)->all()[$key];

        $this->withHeaders($headers)->putJson(self::SETTINGS_URL . "/{$key}", ['value' => $nuevo])
            ->assertStatus(422)->assertJsonPath('code', 'operator_password_required');
        $this->withHeaders($headers)->putJson(self::SETTINGS_URL . "/{$key}", ['value' => $nuevo, 'operator_password' => ''])
            ->assertStatus(422)->assertJsonPath('code', 'operator_password_required');

        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $this->assertSame($antes, app(ImpersonationSettings::class)->all()[$key]);
        $this->assertNull(GlobalSetting::find($key));
    }

    #[DataProvider('clavesConContrasena')]
    public function test_con_contrasena_incorrecta_responde_422_y_no_cambia_nada(string $key, int $nuevo): void
    {
        $this->withHeaders($this->authHeaders($this->superadminToken()))
            ->putJson(self::SETTINGS_URL . "/{$key}", ['value' => $nuevo, 'operator_password' => 'incorrecta'])
            ->assertStatus(422)->assertJsonPath('code', 'operator_reauth_failed');

        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $this->assertNull(GlobalSetting::find($key));
    }

    #[DataProvider('clavesConContrasena')]
    public function test_con_la_contrasena_correcta_guarda_y_audita(string $key, int $nuevo): void
    {
        $default = $this->defaults()[$key];

        $this->withHeaders($this->authHeaders($this->superadminToken()))
            ->putJson(self::SETTINGS_URL . "/{$key}", ['value' => $nuevo, 'operator_password' => 'Password123!'])
            ->assertOk()->assertJsonPath('value', $nuevo);

        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $this->assertSame($nuevo, app(ImpersonationSettings::class)->all()[$key]);
        $log = AuditLog::query()->where('action', AuditService::SETTINGS_UPDATED)->where('entity_id', $key)->firstOrFail();
        $this->assertSame($default, $log->old_values['value']);
        $this->assertSame($nuevo, $log->new_values['value']);
        $this->assertStringNotContainsString('Password123!', (string) json_encode($log->toArray()), 'la contraseña nunca se registra');
    }

    public function test_un_valor_invalido_se_rechaza_antes_de_pedir_la_contrasena(): void
    {
        $this->withHeaders($this->authHeaders($this->superadminToken()))
            ->putJson(self::SETTINGS_URL . '/impersonation_max_minutes', ['value' => 9999])
            ->assertStatus(422)->assertJsonValidationErrors(['value']);
    }

    public function test_los_intentos_incorrectos_se_limitan_con_el_cupo_de_ver_como_usuario(): void
    {
        $headers = $this->authHeaders($this->superadminToken());

        for ($i = 0; $i < 5; $i++) {
            $this->withHeaders($headers)->putJson(self::SETTINGS_URL . '/impersonation_max_minutes', ['value' => 60, 'operator_password' => 'incorrecta'])->assertStatus(422);
        }
        $this->withHeaders($headers)->putJson(self::SETTINGS_URL . '/impersonation_max_minutes', ['value' => 60, 'operator_password' => 'Password123!'])
            ->assertStatus(429)->assertJsonPath('code', 'too_many_attempts');

        // Es el mismo cupo que el inicio de «Ver como usuario» (mismo ámbito)…
        $target = (int) DB::table('atlantic_auth.users')->where('email', 'designer@atlantic.test')->value('id');
        $this->withHeaders($headers)->postJson("/api/users/{$target}/impersonate", ['reason' => 'SCRUM-1158 prueba', 'operator_password' => 'Password123!'])->assertStatus(429);
        // …pero no toca el del reset de contraseñas ni el de otros ajustes que ya pedían contraseña.
        $this->withHeaders($headers)->putJson(self::SETTINGS_URL . '/password_reset_free_per_hour', ['value' => 7])->assertOk();
        $this->withHeaders($headers)->putJson(self::SETTINGS_URL . '/password_reset_expiration_hours', ['value' => 72, 'operator_password' => 'Password123!'])->assertOk();
    }

    public function test_agotar_el_cupo_del_reset_de_contrasenas_no_bloquea_los_parametros_de_impersonacion(): void
    {
        $headers = $this->authHeaders($this->superadminToken());

        for ($i = 0; $i < 5; $i++) {
            $this->withHeaders($headers)->putJson(self::SETTINGS_URL . '/password_reset_expiration_hours', ['value' => 72, 'operator_password' => 'incorrecta'])->assertStatus(422);
        }
        $this->withHeaders($headers)->putJson(self::SETTINGS_URL . '/password_reset_expiration_hours', ['value' => 72, 'operator_password' => 'Password123!'])->assertStatus(429);

        $this->withHeaders($headers)->putJson(self::SETTINGS_URL . '/impersonation_max_minutes', ['value' => 60, 'operator_password' => 'Password123!'])->assertOk();
    }

    /** Los demás ajustes globales siguen sin pedir contraseña (condición de Luis: ningún otro ajuste cambia). */
    public function test_los_ajustes_que_no_pedian_contrasena_siguen_sin_pedirla(): void
    {
        $headers = $this->authHeaders($this->superadminToken());

        foreach (['documents.max_size_mb' => 100, 'notifications.archive_read_after_days' => 10, 'audit_logs.retention_months' => 24, 'password_reset_free_per_hour' => 3] as $key => $value) {
            $this->withHeaders($headers)->putJson(self::SETTINGS_URL . "/{$key}", ['value' => $value])->assertOk();
        }

        $rows = collect($this->withHeaders($headers)->getJson(self::SETTINGS_URL)->json('data'))->keyBy('key');
        $this->assertEqualsCanonicalizing(
            ['password_reset_expiration_hours', 'password_reset_enabled', ...ImpersonationSettings::KEYS],
            $rows->filter(fn (array $r): bool => $r['requires_reauth'] === true)->keys()->all(),
            'solo los 2 de reset de contraseñas y los 5 de impersonación exigen contraseña',
        );
    }

    /** @return array<string, array{0: string, 1: list<mixed>, 2: list<int>}> */
    public static function rangos(): array
    {
        return [
            'duración'          => ['impersonation_max_minutes', [4, 121, 0, -1, 'abc', 5.5], [5, 120]],
            'largo del motivo'  => ['impersonation_reason_min_length', [4, 201, 0], [5, 200]],
            'sesiones por día'  => ['impersonation_alert_sessions_per_day', [0, 51], [1, 50]],
            'objetivos por hora' => ['impersonation_alert_targets_per_hour', [0, 21], [1, 20]],
            'escrituras bloqueadas' => ['impersonation_alert_blocked_writes_per_session', [0, 501], [1, 500]],
        ];
    }

    /**
     * @param list<mixed> $malos
     * @param list<int> $buenos
     */
    #[DataProvider('rangos')]
    public function test_los_parametros_validan_su_rango(string $key, array $malos, array $buenos): void
    {
        $headers = $this->authHeaders($this->superadminToken());

        foreach ($malos as $malo) {
            $this->withHeaders($headers)->putJson(self::SETTINGS_URL . "/{$key}", ['value' => $malo])->assertStatus(422)->assertJsonValidationErrors(['value']);
        }
        foreach ($buenos as $bueno) {
            $this->withHeaders($headers)->putJson(self::SETTINGS_URL . "/{$key}", ['value' => $bueno, 'operator_password' => 'Password123!'])->assertOk();
        }
    }

    public function test_quien_no_es_superadmin_no_puede_ver_ni_cambiar_los_parametros(): void
    {
        DB::table('atlantic_auth.users')->where('email', 'designer@atlantic.test')->update(['extra_permissions' => json_encode(['settings.global'])]);
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))->getJson(self::SETTINGS_URL)->assertStatus(403);
        $this->withHeaders($this->authHeaders($token))->putJson(self::SETTINGS_URL . '/impersonation_max_minutes', ['value' => 120, 'operator_password' => 'Password123!'])->assertStatus(403);

        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $this->assertSame(30, app(ImpersonationSettings::class)->maxMinutes());
    }

    // ── C4: inmutabilidad frente al rol de runtime ────────────────────────────────────────────────────────

    public function test_atlanticerp_app_no_tiene_delete_ni_update_de_tabla_pero_si_update_de_las_columnas_de_cierre(): void
    {
        $this->bootstrapAppRole();
        $table = 'atlantic_auth.impersonation_sessions';

        $priv = static fn (string $p): bool => (bool) DB::selectOne('SELECT has_table_privilege(?, ?, ?) AS ok', [self::APP_ROLE, $table, $p])->ok;
        $col  = static fn (string $c): bool => (bool) DB::selectOne('SELECT has_column_privilege(?, ?, ?, ?) AS ok', [self::APP_ROLE, $table, $c, 'UPDATE'])->ok;

        $this->assertTrue($priv('SELECT'));
        $this->assertTrue($priv('INSERT'));
        $this->assertFalse($priv('UPDATE'));
        $this->assertFalse($priv('DELETE'));
        $this->assertFalse($priv('TRUNCATE'));

        foreach (['ended_at', 'ended_reason', 'ended_by'] as $allowed) {
            $this->assertTrue($col($allowed), "{$allowed} debe poder actualizarse");
        }
        foreach (['reason', 'actor_id', 'target_id', 'started_at', 'expires_at', 'ip_address'] as $frozen) {
            $this->assertFalse($col($frozen), "{$frozen} no debe poder actualizarse");
        }
    }

    public function test_intentar_borrar_o_reescribir_con_el_rol_de_la_app_falla_en_postgres(): void
    {
        $this->bootstrapAppRole();
        $id = $this->insertSession();
        $table = 'atlantic_auth.impersonation_sessions';

        $delete = $this->runAsAppRole("DELETE FROM {$table} WHERE id = '{$id}'");
        $this->assertNotNull($delete);
        $this->assertStringContainsString('permission denied', $delete);

        $rewrite = $this->runAsAppRole("UPDATE {$table} SET reason = 'borrado' WHERE id = '{$id}'");
        $this->assertNotNull($rewrite);
        $this->assertStringContainsString('permission denied', $rewrite);

        $retime = $this->runAsAppRole("UPDATE {$table} SET expires_at = now() + interval '1 day' WHERE id = '{$id}'");
        $this->assertNotNull($retime);
        $this->assertStringContainsString('permission denied', $retime);

        $truncate = $this->runAsAppRole("TRUNCATE {$table}");
        $this->assertNotNull($truncate);
        $this->assertStringContainsString('permission denied', $truncate);

        $this->assertSame('SCRUM-1158 prueba de esquema', DB::table($table)->where('id', $id)->value('reason'), 'la fila sigue intacta');
    }

    public function test_el_rol_de_la_app_si_puede_cerrar_la_sesion(): void
    {
        $this->bootstrapAppRole();
        $id = $this->insertSession();
        $table = 'atlantic_auth.impersonation_sessions';

        $this->assertNull($this->runAsAppRole("UPDATE {$table} SET ended_at = now(), ended_reason = 'manual' WHERE id = '{$id}'"));
        $this->assertSame('manual', DB::table($table)->where('id', $id)->value('ended_reason'));
    }

    public function test_la_restriccion_es_idempotente_tras_otro_grant(): void
    {
        $this->bootstrapAppRole();
        $this->artisan('tenant:grant-app-role')->assertExitCode(0);

        $id = $this->insertSession();
        $delete = $this->runAsAppRole("DELETE FROM atlantic_auth.impersonation_sessions WHERE id = '{$id}'");
        $this->assertNotNull($delete);
        $this->assertStringContainsString('permission denied', (string) $delete);
    }
}
