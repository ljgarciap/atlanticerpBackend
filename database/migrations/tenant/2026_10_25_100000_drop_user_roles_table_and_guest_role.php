<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 2, paso 2.2 del aislamiento del núcleo (docs/specs/fase2-retiro-user-roles.md, §9) — CAMBIO DESTRUCTIVO confirmado por Luis (2026-10-25).
 *
 * Retira el enum legado `user_roles` (Fase E del ADR-006) y el flujo de registro pendiente:
 *   1. ABORTA si alguien tiene un rol legado con equivalente (`superadmin`/`management`) y NO tiene `role_id`: perdería el acceso. No debería
 *      ocurrir tras el backfill del paso 2.1 (migración 500000); si pasa, se resuelve a mano antes de borrar nada.
 *   2. Quien tenga el rol de sistema `guest` (registro pendiente) queda con `role_id = NULL` y sigue inactivo: no se borra a nadie; un administrador
 *      puede asignarle un rol real y activarlo desde la edición normal de usuarios.
 *   3. Elimina el rol `guest` y la tabla `user_roles` (su trigger de auditoría cae con ella).
 *
 * `down()` recrea la tabla (PK compuesta, restricción de valores, trigger de auditoría) y el rol `guest`, y repuebla `user_roles` desde `role_id`
 * para `superadmin`/`management`. NO puede restaurar quién estaba pendiente (ese estado se pierde en el `up()`) ni los roles legados sin equivalente
 * (`designer`/`supervisor`/`electrician`, solo cuentas demo).
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'auth';

    public function up(): void
    {
        $users     = $this->table('users');
        $roles     = $this->table('roles');
        $userRoles = $this->table('user_roles');

        if (Schema::hasTable($userRoles)) {
            $sinRoleId = DB::table($userRoles.' as ur')
                ->join($users.' as u', 'u.id', '=', 'ur.user_id')
                ->whereIn('ur.role', ['superadmin', 'management'])
                ->whereNull('u.role_id')
                ->count();
            if ($sinRoleId > 0) {
                throw new RuntimeException("{$sinRoleId} usuario(s) tienen un rol legado superadmin/management en user_roles y NO tienen role_id: perderían el acceso. Asignarles su rol (role_id) antes de borrar la tabla.");
            }
        }

        $guestId = DB::table($roles)->where('key', 'guest')->value('id');
        if ($guestId !== null) {
            DB::table($users)->where('role_id', $guestId)->update(['role_id' => null]);
            DB::table($roles)->where('id', $guestId)->delete();
        }

        Schema::dropIfExists($userRoles);
    }

    public function down(): void
    {
        $users     = $this->table('users');
        $roles     = $this->table('roles');
        $userRoles = $this->table('user_roles');

        if (! Schema::hasTable($userRoles)) {
            Schema::create($userRoles, function (Blueprint $table) use ($users): void {
                $table->unsignedBigInteger('user_id');
                $table->string('role', 30);
                $table->primary(['user_id', 'role']);
                $table->foreign('user_id')->references('id')->on($users)->cascadeOnDelete();
            });

            DB::statement("ALTER TABLE {$userRoles} ADD CONSTRAINT user_roles_role_check CHECK (role IN ('superadmin','management','designer','supervisor','electrician','guest'))");

            // Mismo trigger de auditoría que le puso la migración 2026_09_14_100001 (la función `fn_audit_row_change` sigue existiendo).
            DB::statement(
                "CREATE TRIGGER trg_audit_user_roles AFTER INSERT OR UPDATE OR DELETE ON {$userRoles} "
                ."FOR EACH ROW EXECUTE FUNCTION \"{$this->schema()}\".fn_audit_row_change()"
            );
        }

        DB::table($roles)->insertOrIgnore([
            'key' => 'guest', 'name' => 'Invitado (registro pendiente)',
            'is_superadmin' => false, 'is_system' => true, 'is_active' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        foreach (['superadmin', 'management'] as $key) {
            DB::statement(
                "INSERT INTO {$userRoles} (user_id, role) SELECT u.id, '{$key}' FROM {$users} u JOIN {$roles} r ON r.id = u.role_id WHERE r.key = '{$key}' ON CONFLICT DO NOTHING"
            );
        }
    }
};
