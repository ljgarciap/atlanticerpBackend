<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Support\Facades\DB;

/**
 * Fase 2 del aislamiento del núcleo (docs/specs/fase2-retiro-user-roles.md, paso 2.1) — Fase E del ADR-006: `role_id` pasa a ser el ÚNICO
 * mecanismo de rol base. Esta migración pasa a `role_id` lo que solo vivía en el enum legado `user_roles`:
 *
 *   1. Crea el rol de sistema `guest` (registro pendiente de aprobación): sin permisos ni módulos, `is_active = false` para que NO aparezca
 *      en los selectores de la UI ni se pueda asignar como rol de un usuario real.
 *   2. Backfill de quien no tiene `role_id`: `guest` → rol guest, `superadmin` → rol superadmin, `management` → rol management (si hay varios
 *      legados gana el de más privilegio). `designer`/`supervisor`/`electrician` no tienen equivalente en el catálogo (solo cuentas demo):
 *      su `role_id` queda NULL, sin acceso, igual que hoy.
 *
 * La tabla `user_roles` NO se toca en `up()` (se borra en el paso 2.2, aparte). `down()` restaura la fila legada `guest` de quien se registró después,
 * deja `role_id = NULL` a quien tenga el rol guest y elimina ese rol.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'auth';

    private const GUEST = 'guest';

    /** Prioridad de los roles legados con equivalente: el primero que tenga la persona es el que se usa. */
    private const LEGACY_TO_ROLE_KEY = ['superadmin' => 'superadmin', 'management' => 'management', 'guest' => self::GUEST];

    public function up(): void
    {
        $roles = $this->table('roles');

        DB::table($roles)->insertOrIgnore([
            'key' => self::GUEST, 'name' => 'Invitado (registro pendiente)',
            'is_superadmin' => false, 'is_system' => true, 'is_active' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        /** @var array<string, int> $roleIds */
        $roleIds = DB::table($roles)->whereIn('key', array_values(self::LEGACY_TO_ROLE_KEY))->pluck('id', 'key')->map(fn (mixed $id): int => (int) $id)->all();

        $pendientes = DB::table($this->table('users'))->whereNull('role_id')->pluck('id');
        foreach ($pendientes as $userId) {
            $legacy = DB::table($this->table('user_roles'))->where('user_id', $userId)->pluck('role')->all();

            foreach (self::LEGACY_TO_ROLE_KEY as $legacyKey => $roleKey) {
                if (in_array($legacyKey, $legacy, true) && isset($roleIds[$roleKey])) {
                    DB::table($this->table('users'))->where('id', $userId)->update(['role_id' => $roleIds[$roleKey]]);
                    break;
                }
            }
        }
    }

    public function down(): void
    {
        $roles = $this->table('roles');
        $guestId = DB::table($roles)->where('key', self::GUEST)->value('id');
        if ($guestId === null) {
            return;
        }

        // Quien se registró DESPUÉS de esta migración solo tiene `role_id = guest` (sin fila legada): se le restaura la fila para no perder su estado pendiente.
        foreach (DB::table($this->table('users'))->where('role_id', $guestId)->pluck('id') as $userId) {
            if (! DB::table($this->table('user_roles'))->where('user_id', $userId)->where('role', self::GUEST)->exists()) {
                DB::table($this->table('user_roles'))->insert(['user_id' => $userId, 'role' => self::GUEST]);
            }
        }

        DB::table($this->table('users'))->where('role_id', $guestId)->update(['role_id' => null]);
        DB::table($roles)->where('id', $guestId)->delete();
    }
};
