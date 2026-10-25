<?php

namespace Database\Seeders;

use App\Models\User;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class AuthUserSeeder extends Seeder
{
    private const PASSWORD = 'Password123!';

    /**
     * level: security level number to assign. Superadmin/management = 10 so they can
     * manage all levels in tests. Other roles get appropriate mid-range levels.
     *
     * role_key: ADR-006 — rol nuevo (SCRUM-144) equivalente al rol legado, para que Task 4
     * (canViewTeamV2() en vez de hasRole(MANAGEMENT)) no rompa los tests existentes de
     * VentasDiseno que dependen de estos fixtures para "ve equipo"/"ve solo lo propio". null
     * donde no hay correspondencia real (supervisor/electrician son roles legados de
     * Installations/CRM, fuera del catálogo de 13 roles de negocio).
     *
     * @var list<array{first_name: string, last_name: string, email: string, level: int, role_key: string|null}>
     */
    private const USERS = [
        ['first_name' => 'Management', 'last_name' => 'Demo', 'email' => 'management@atlantic.test',  'level' => 10, 'role_key' => 'management'],
        ['first_name' => 'Gerencia',   'last_name' => 'Demo', 'email' => 'gerencia@atlantic.test',    'level' => 10, 'role_key' => 'management'],
        // level 4 -- SCRUM-83: debe coincidir con roles.default_security_level_id real de
        // vendedor (migración 2026_07_18_100002), que es el único nivel con fila de
        // capacidad ventas_diseno.can_edit=true. Antes estaba en nivel 3 (sin fila en
        // security_level_module_permissions -- ver ADR-006 corrección 2026-07-10), por lo que
        // esta cuenta demo podía ver pero nunca crear proyectos/cotizaciones -- el bug que Gerencia
        // reportó. Los tests de VentasDiseno no lo detectaron porque cada uno otorga
        // extra_permissions manualmente vía grantVentasDisenoEdit(), sin pasar por el bridge
        // rol+nivel real.
        ['first_name' => 'Designer',   'last_name' => 'Demo', 'email' => 'designer@atlantic.test',    'level' => 4, 'role_key' => 'vendedor'],
        ['first_name' => 'Supervisor', 'last_name' => 'Demo', 'email' => 'supervisor@atlantic.test',  'level' => 5, 'role_key' => null],
        ['first_name' => 'Electrician','last_name' => 'Demo', 'email' => 'electrician@atlantic.test','roles' => ['electrician'],                'level' => 2, 'role_key' => null],
        ['first_name' => 'Multi',      'last_name' => 'Role', 'email' => 'multi@atlantic.test',       'level' => 8, 'role_key' => 'management'],
        ['first_name' => 'Readonly',   'last_name' => 'Demo', 'email' => 'readonly@atlantic.test',    'level' => 1, 'role_key' => null],
    ];

    /** @var array{first_name: string, last_name: string, email: string, level: int, role_key: string|null} */
    private const SUPERADMIN = [
        'first_name' => 'Super',
        'last_name'  => 'Admin',
        'email'      => 'superadmin@atlantic.test',
                'level'      => 10,
        'role_key'   => 'superadmin',
    ];

    public function run(): void
    {
        $tenant = Tenant::where('slug', 'atlantic')->first();

        if ($tenant === null) {
            $this->command->warn('Tenant "atlantic" not found — AuthUserSeeder skipped.');
            return;
        }

        $tenant->makeCurrent();

        try {
            $securityLevels = "{$tenant->slug}_auth.security_levels";
            $rolesTable     = "{$tenant->slug}_auth.roles";
            $fallbackLevelId = (int) (DB::table($securityLevels)->where('level', 1)->value('id') ?? 1);

            foreach ([self::SUPERADMIN, ...self::USERS] as $data) {
                $levelId = (int) (DB::table($securityLevels)->where('level', $data['level'])->value('id') ?? $fallbackLevelId);
                $roleId  = $data['role_key'] !== null
                    ? DB::table($rolesTable)->where('key', $data['role_key'])->value('id')
                    : null;

                /** @var User|null $existing */
                $existing = User::query()->where('email', $data['email'])->first();

                if ($existing === null) {
                    /** @var User $user */
                    $user = User::query()->create([
                        'email'             => $data['email'],
                        'first_name'        => $data['first_name'],
                        'last_name'         => $data['last_name'],
                        'password_hash'     => self::PASSWORD,
                        'is_active'         => true,
                        'security_level_id' => $levelId,
                        'role_id'           => $roleId,
                    ]);
                } else {
                    // Preserve password changes made via UI but sync name, status and security level.
                    $existing->update([
                        'first_name'        => $data['first_name'],
                        'last_name'         => $data['last_name'],
                        'is_active'         => true,
                        'security_level_id' => $levelId,
                        'role_id'           => $roleId,
                    ]);
                    /** @var User $user */
                    $user = $existing;
                }
            }
        } finally {
            Tenant::forgetCurrent();
        }
    }
}
