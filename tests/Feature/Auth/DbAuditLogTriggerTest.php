<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Shared\Http\Middleware\AttributeDbChangesToUser;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-916 — auditoría a nivel de base de datos (backstop de LogsAuditTrail, que solo ve cambios
 * vía Eloquent). Verifica el trigger `fn_audit_row_change()` y la atribución de usuario vía
 * `app.current_user_id` que setea AttributeDbChangesToUser.
 */
final class DbAuditLogTriggerTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    private function latestAuditRow(string $table): ?object
    {
        return DB::table('atlantic_auth.db_audit_log')
            ->where('table_name', $table)
            ->orderByDesc('id')
            ->first();
    }

    public function test_un_update_crudo_por_fuera_de_eloquent_queda_registrado_sin_app_user_id(): void
    {
        $user = User::query()->first();
        $this->assertNotNull($user, 'AuthUserSeeder debería haber creado al menos un usuario');

        // DB::table()->update() bypasea los eventos de Eloquent (LogsAuditTrail nunca se entera)
        // — exactamente el caso que este ticket cubre.
        DB::table('atlantic_auth.users')->where('id', $user->id)->update(['notes' => 'cambio directo sin ORM']);

        $row = $this->latestAuditRow('users');
        $this->assertNotNull($row);
        $this->assertSame('UPDATE', $row->operation);
        $this->assertNull($row->app_user_id);
        $this->assertSame('atlanticerp', $row->db_role); // el rol de conexión de test, nunca null
        $this->assertStringContainsString('cambio directo sin ORM', (string) $row->new_data);
    }

    public function test_un_cambio_con_la_variable_de_sesion_seteada_queda_atribuido_al_usuario_correcto(): void
    {
        $actor  = User::query()->first();
        $target = User::query()->skip(1)->first() ?? $actor;
        $this->assertNotNull($actor);

        // Postgres no acepta parámetros bindeados en SET SESSION — mismo bug real corregido en
        // AttributeDbChangesToUser, $actor->id es un entero de BD, seguro de interpolar directo.
        DB::statement("SET SESSION app.current_user_id = {$actor->id}");
        try {
            DB::table('atlantic_auth.users')->where('id', $target->id)->update(['notes' => 'cambio atribuido']);
        } finally {
            DB::statement('RESET app.current_user_id');
        }

        $row = $this->latestAuditRow('users');
        $this->assertNotNull($row);
        $this->assertSame($actor->id, $row->app_user_id);
    }

    /**
     * Reproduce en vivo el riesgo que motivó la decisión de diseño (ver docblock de
     * AttributeDbChangesToUser): sin el RESET, un app_user_id de un "request" anterior queda
     * pegado a la conexión y contamina el siguiente. Con el patrón SET SESSION + RESET en
     * finally, dos requests simulados en secuencia sobre la MISMA conexión de test nunca se
     * cruzan.
     */
    public function test_dos_requests_simulados_en_secuencia_no_se_contaminan_entre_si(): void
    {
        $userA = User::query()->first();
        $userB = User::query()->skip(1)->first();
        $this->assertNotNull($userA);
        $this->assertNotNull($userB, 'Se necesitan al menos 2 usuarios de fixture para este test');

        $this->simulateRequestAs($userA->id, fn () => DB::table('atlantic_auth.users')
            ->where('id', $userA->id)->update(['notes' => 'request A']));

        $rowA = $this->latestAuditRow('users');
        $this->assertSame($userA->id, $rowA->app_user_id);

        $this->simulateRequestAs($userB->id, fn () => DB::table('atlantic_auth.users')
            ->where('id', $userB->id)->update(['notes' => 'request B']));

        $rowB = $this->latestAuditRow('users');
        $this->assertSame($userB->id, $rowB->app_user_id, 'El app_user_id del request anterior no debería sobrevivir al siguiente');

        // Un tercer "request" sin usuario autenticado (ej. una ruta pública) no debe heredar
        // el app_user_id del request B — confirma que el RESET realmente limpia el estado.
        DB::table('atlantic_auth.users')->where('id', $userA->id)->update(['notes' => 'sin request/anonimo']);
        $rowAnon = $this->latestAuditRow('users');
        $this->assertNull($rowAnon->app_user_id);
    }

    /**
     * Pre-QA 2026-09-14 — CRÍTICO real encontrado: `fn_audit_row_change()` volcaba la fila
     * COMPLETA vía `to_jsonb(OLD/NEW)`, sin excluir `password_hash` — exactamente lo que
     * `User::auditableAttributes()` (SCRUM-915) excluye a nivel de ORM, pero el trigger de BD
     * (que audita también los cambios que bypasean Eloquent) lo volvía a exponer sin filtro.
     * Corregido en 2026_09_14_100002_fix_db_audit_log_exclude_password_hash (CREATE OR REPLACE
     * FUNCTION restando password_hash/remember_token del jsonb antes de insertar).
     */
    public function test_password_hash_nunca_aparece_en_db_audit_log(): void
    {
        $user = User::query()->first();
        $this->assertNotNull($user);

        DB::table('atlantic_auth.users')->where('id', $user->id)->update(['password_hash' => 'hash-nuevo-de-prueba']);

        $row = $this->latestAuditRow('users');
        $this->assertNotNull($row);

        $newData = json_decode((string) $row->new_data, true);
        $oldData = json_decode((string) $row->old_data, true);

        $this->assertArrayNotHasKey('password_hash', $newData, 'password_hash NO debería viajar en new_data de db_audit_log');
        $this->assertArrayNotHasKey('password_hash', $oldData, 'password_hash NO debería viajar en old_data de db_audit_log');
    }

    /**
     * Pre-QA 2026-09-14 — el docblock de AttributeDbChangesToUser afirma que el RESET en `finally`
     * corre "incluso si $next tira" pero eso nunca se ejercitó con una excepción real (solo el
     * camino feliz). Fuerza un 500 real dentro del closure y confirma que app.current_user_id
     * queda reseteado igual, para que no contamine la próxima query en la misma conexión.
     */
    public function test_reset_corre_incluso_si_next_tira_una_excepcion_real(): void
    {
        $actor = User::query()->first();
        $this->assertNotNull($actor);

        $request = Request::create('/api/test');
        $request->attributes->set('jwt', ['sub' => $actor->id]);

        $middleware = new AttributeDbChangesToUser();

        try {
            $middleware->handle($request, function (): never {
                throw new \RuntimeException('boom - endpoint real que tira 500');
            });
            $this->fail('Se esperaba que la excepción se propagara');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom - endpoint real que tira 500', $e->getMessage());
        }

        $current = DB::selectOne("SELECT current_setting('app.current_user_id', true) as v");
        $this->assertTrue(
            $current->v === '' || $current->v === null,
            'app.current_user_id debería estar reseteado tras la excepción, valor: '.var_export($current->v, true),
        );
    }

    /**
     * Pre-QA 2026-09-14 — `user_roles` es la única de las 11 tablas auditadas con PK compuesta
     * (user_id, role), sin columna `id`. Confirma que la resolución de PK vía catálogo
     * (pg_index/pg_attribute) arma `row_pk` con ambas columnas, tanto en INSERT como en DELETE.
     */
    public function test_user_roles_pk_compuesta_se_resuelve_bien_en_row_pk(): void
    {
        $user = User::query()->first();
        $this->assertNotNull($user);

        DB::table('atlantic_auth.user_roles')->insert(['user_id' => $user->id, 'role' => 'guest']);

        $row = DB::table('atlantic_auth.db_audit_log')
            ->where('table_name', 'user_roles')->orderByDesc('id')->first();
        $this->assertNotNull($row);
        $this->assertSame('INSERT', $row->operation);
        $this->assertStringContainsString("user_id={$user->id}", $row->row_pk);
        $this->assertStringContainsString('role=guest', $row->row_pk);

        DB::table('atlantic_auth.user_roles')->where('user_id', $user->id)->where('role', 'guest')->delete();
        $rowDelete = DB::table('atlantic_auth.db_audit_log')
            ->where('table_name', 'user_roles')->orderByDesc('id')->first();
        $this->assertSame('DELETE', $rowDelete->operation);
        $this->assertStringContainsString("user_id={$user->id}", $rowDelete->row_pk);
        $this->assertStringContainsString('role=guest', $rowDelete->row_pk);
    }

    /** Ejercita el middleware real, no solo el mecanismo de SET/RESET a mano. */
    private function simulateRequestAs(int $userId, callable $duringRequest): void
    {
        $request = Request::create('/api/test');
        $request->attributes->set('jwt', ['sub' => $userId]);

        $middleware = new AttributeDbChangesToUser();
        $middleware->handle($request, function () use ($duringRequest) {
            $duringRequest();

            return response()->json([]);
        });
    }
}
