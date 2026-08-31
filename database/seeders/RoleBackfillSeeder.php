<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * ADR-006 — mapeo rol→nivel. Backfill de `role_id` + los 2 flags transversales para el roster
 * fijo de `CoreUserSeeder` (3 superadmins + 4 identidades de Gerencia, ver el propio seeder).
 *
 * Por qué es un SEEDER y no una migración (bug real encontrado en CI): las migraciones de tenant
 * corren dentro de `tenant:create` (invocado por `TenantSeeder`), que es el PRIMER seeder de
 * `DatabaseSeeder::run()` — es decir, ANTES de que `CoreUserSeeder` cree a estas personas. Una
 * migración que matchea por email contra `users` siempre encuentra 0 filas en un tenant recién
 * creado (CI, o un tenant nuevo real el día de mañana) — silenciosamente, sin error. Como seeder,
 * corre después de `CoreUserSeeder` en la misma cadena de `DatabaseSeeder`, así que siempre
 * encuentra las filas.
 *
 * Corre en TODOS los entornos (bloque "Tipo A" de DatabaseSeeder) — este roster fijo son las
 * cuentas base de todo entorno, no datos demo.
 *
 * Idempotente a propósito: a diferencia de `CoreUserSeeder` (que nunca pisa a un usuario ya
 * creado, para proteger su password), `role_id`/los 2 flags de este roster fijo no son un dato que
 * el usuario cambie por su cuenta vía UI — son valores canónicos que solo cambian editando este
 * archivo. Reasignarlos en cada `db:seed --force` (cada deploy) es seguro y esperado.
 *
 * NO toca `security_level_id` — los "arquetipos de capacidad" de ADR-006 (Gerencia Total/
 * Restringida, Editor sin aprobar, Solo Ver) NO son niveles nuevos — reusan la escala existente
 * 1-10 (10=todo, 1=solo lectura, intermedios según se necesite). Gerencia Total = Nivel 9
 * ("Admin"), que es exactamente donde estas identidades ya están desde `CoreUserSeeder` — no hace
 * falta reasignarlos. Ver migración `2026_07_18_100002_reuse_existing_levels_for_role_defaults.php`.
 */
class RoleBackfillSeeder extends Seeder
{
    /** @var list<string> */
    private const GERENCIA_EMAILS = [
        'gerencia3@test.com',
        'gerencia2@test.com',
        'gerencia@test.com',
    ];

    /** @var list<string> */
    private const SUPERADMIN_EMAILS = [
        'superadmin@test.com',
        'superadmin2@test.com',
        'superadmin3@test.com',
        'gerencia4@test.com', // identidad seedeada como "Gerencia" pero con rol técnico superadmin
    ];

    public function run(): void
    {
        $tenant = Tenant::where('slug', 'atlantic')->first();

        if ($tenant === null) {
            $this->command?->warn('Tenant "atlantic" not found — RoleBackfillSeeder skipped.');

            return;
        }

        $tenant->makeCurrent();

        try {
            $rolesTable = "{$tenant->slug}_auth.roles";
            $roleIds = DB::table($rolesTable)->whereIn('key', ['management', 'superadmin'])->pluck('id', 'key');

            if (! isset($roleIds['management'], $roleIds['superadmin'])) {
                $this->command?->warn('Roles "management"/"superadmin" no encontrados — RoleBackfillSeeder skipped.');

                return;
            }

            User::query()
                ->whereIn('email', self::GERENCIA_EMAILS)
                ->update([
                    'role_id' => $roleIds['management'],
                    'approve_large_amounts' => true,
                    'manage_users' => true,
                ]);

            User::query()
                ->whereIn('email', self::SUPERADMIN_EMAILS)
                ->update([
                    'role_id' => $roleIds['superadmin'],
                    // approve_large_amounts/manage_users quedan en su default (false) — superadmin
                    // bypassa por `is_superadmin`, no depende de estos 2 flags transversales.
                ]);
        } finally {
            Tenant::forgetCurrent();
        }
    }
}
