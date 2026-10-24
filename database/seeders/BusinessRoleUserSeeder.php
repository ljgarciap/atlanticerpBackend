<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Auth\Support\NoLoginAccounts;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Roster genérico del catálogo de 13 roles de negocio (scaffolding — ver Atlantic/CLAUDE.md,
 * sesión 2026-08-30: este archivo traía 25 personas reales de un cliente anterior, genericizado a
 * propósito, historial completo en docs/changelog.md). Corre en TODOS los entornos.
 *
 * Mismo patrón que `CoreUserSeeder` (create-only-if-not-exists, password default = email, nunca
 * pisa un usuario ya existente) pero para el catálogo de roles de negocio en vez del enum legado
 * `user_roles` — asigna `role_id` + `security_level_id` (el default de cada rol, ver
 * `roles.default_security_level_id`), no filas de `user_roles`.
 *
 * Una identidad de "Gerencia" (management) queda con `security_level_id` override a un nivel más
 * restringido que el default del rol — ejemplo vivo del mecanismo de override individual (ver
 * `RESTRICTED_LEVEL_OVERRIDE_EMAIL`/`RESTRICTED_LEVEL_OVERRIDE` abajo), no una excepción real hoy.
 */
class BusinessRoleUserSeeder extends Seeder
{
    /**
     * @var list<array{first_name: string, last_name: string, email: string, role_key: string,
     *      manage_users?: bool}>
     */
    private const USERS = [
        // Gerencia — una identidad tiene override de nivel individual, manejado aparte más abajo.
        ['first_name' => 'Gerencia', 'last_name' => 'Test 5', 'email' => 'gerencia5@test.com', 'role_key' => 'management'],

        ['first_name' => 'Contabilidad', 'last_name' => 'Test', 'email' => 'contabilidad@test.com', 'role_key' => 'lider_admin_contab', 'manage_users' => true],
        ['first_name' => 'Asistente Administrativa', 'last_name' => 'Test', 'email' => 'asistenteadministrativa@test.com', 'role_key' => 'asistente_administrativa'],
        ['first_name' => 'Lider Compras', 'last_name' => 'Test', 'email' => 'lidercompras@test.com', 'role_key' => 'lider_compras'],
        ['first_name' => 'Lider Bodega', 'last_name' => 'Test', 'email' => 'liderbodega@test.com', 'role_key' => 'lider_bodega'],
        ['first_name' => 'Asistente Bodega', 'last_name' => 'Test', 'email' => 'asistentebodega@test.com', 'role_key' => 'asistente_bodega'],
        ['first_name' => 'Asistente Bodega', 'last_name' => 'Test 2', 'email' => 'asistentebodega2@test.com', 'role_key' => 'asistente_bodega'],
        ['first_name' => 'Ayudante General Bodega', 'last_name' => 'Test', 'email' => 'ayudantegeneralbodega@test.com', 'role_key' => 'ayudante_general_bodega'],
        ['first_name' => 'Transporte', 'last_name' => 'Test', 'email' => 'transporte@test.com', 'role_key' => 'transporte'],
        ['first_name' => 'Lider Servicios', 'last_name' => 'Test', 'email' => 'liderservicios@test.com', 'role_key' => 'lider_servicios'],
        ['first_name' => 'Tecnico Servicios', 'last_name' => 'Test', 'email' => 'tecnicoservicios@test.com', 'role_key' => 'tecnico_servicios'],
        ['first_name' => 'Tecnico Servicios', 'last_name' => 'Test 2', 'email' => 'tecnicoservicios2@test.com', 'role_key' => 'tecnico_servicios'],
        ['first_name' => 'Tecnico Servicios', 'last_name' => 'Test 3', 'email' => 'tecnicoservicios3@test.com', 'role_key' => 'tecnico_servicios'],
        ['first_name' => 'Garantias Servicios', 'last_name' => 'Test', 'email' => 'garantiasservicios@test.com', 'role_key' => 'garantias_servicios'],
        ['first_name' => 'Vendedor Disenador', 'last_name' => 'Test', 'email' => 'vendedordisenador@test.com', 'role_key' => 'vendedor_disenador'],
        ['first_name' => 'Vendedor Disenador', 'last_name' => 'Test 2', 'email' => 'vendedordisenador2@test.com', 'role_key' => 'vendedor_disenador'],
        ['first_name' => 'Vendedor Disenador', 'last_name' => 'Test 3', 'email' => 'vendedordisenador3@test.com', 'role_key' => 'vendedor_disenador'],
        ['first_name' => 'Vendedor Disenador', 'last_name' => 'Test 4', 'email' => 'vendedordisenador4@test.com', 'role_key' => 'vendedor_disenador'],
        ['first_name' => 'Vendedor Disenador', 'last_name' => 'Test 5', 'email' => 'vendedordisenador5@test.com', 'role_key' => 'vendedor_disenador'],
        ['first_name' => 'Vendedor Disenador', 'last_name' => 'Test 6', 'email' => 'vendedordisenador6@test.com', 'role_key' => 'vendedor_disenador'],
        ['first_name' => 'Vendedor Disenador', 'last_name' => 'Test 7', 'email' => 'vendedordisenador7@test.com', 'role_key' => 'vendedor_disenador'],
        ['first_name' => 'Vendedor Disenador', 'last_name' => 'Test 8', 'email' => 'vendedordisenador8@test.com', 'role_key' => 'vendedor_disenador'],
        ['first_name' => 'Vendedor Disenador', 'last_name' => 'Test 9', 'email' => 'vendedordisenador9@test.com', 'role_key' => 'vendedor_disenador'],
        // 10mo Vendedor/Diseñador — SCRUM-144 documentaba 9, SCRUM-148 sumó a Idmar con cargo
        // explícito, se incluye igual (no es de los 3 sin cargo).
        ['first_name' => 'Vendedor Disenador', 'last_name' => 'Test 10', 'email' => 'vendedordisenador10@test.com', 'role_key' => 'vendedor_disenador'],
        ['first_name' => 'Lider Operaciones', 'last_name' => 'Test', 'email' => 'lideroperaciones@test.com', 'role_key' => 'lider_operaciones'],
        // SCRUM-1142 — picker, repartidor y la opción «Personal de Bodega» de los dropdowns de Pedidos (genéricos).
        ['first_name' => 'Picker Bodega', 'last_name' => 'Test', 'email' => 'pickerbodega@test.com', 'role_key' => 'ayudante_general_bodega'],
        ['first_name' => 'Repartidor', 'last_name' => 'Test', 'email' => 'repartidor@test.com', 'role_key' => 'transporte'],
        ['first_name' => 'Personal de', 'last_name' => 'Bodega', 'email' => 'personalbodega@test.com', 'role_key' => 'transporte'],
    ];

    private const RESTRICTED_LEVEL_OVERRIDE_EMAIL = 'gerencia5@test.com';

    private const RESTRICTED_LEVEL_OVERRIDE = 8; // "Gerencia Restringida", no el default del rol (9)

    public function run(): void
    {
        $tenant = Tenant::where('slug', 'atlantic')->first();

        if ($tenant === null) {
            $this->command?->warn('Tenant "atlantic" not found — BusinessRoleUserSeeder skipped.');

            return;
        }

        $tenant->makeCurrent();

        try {
            $rolesTable = "{$tenant->slug}_auth.roles";
            $levelsTable = "{$tenant->slug}_auth.security_levels";

            $roleDefaults = DB::table($rolesTable)->pluck('default_security_level_id', 'key');
            $roleIds = DB::table($rolesTable)->pluck('id', 'key');
            $overrideLevelId = DB::table($levelsTable)->where('level', self::RESTRICTED_LEVEL_OVERRIDE)->value('id');

            foreach (self::USERS as $data) {
                if (! isset($roleIds[$data['role_key']])) {
                    $this->command?->warn("Rol desconocido [{$data['role_key']}] — {$data['email']} omitido.");

                    continue;
                }

                /** @var User|null $existing */
                $existing = User::query()->where('email', $data['email'])->first();
                if ($existing !== null) {
                    continue; // create-only-if-not-exists, mismo criterio que CoreUserSeeder
                }

                $securityLevelId = $data['email'] === self::RESTRICTED_LEVEL_OVERRIDE_EMAIL
                    ? $overrideLevelId
                    : $roleDefaults[$data['role_key']];

                User::query()->create([
                    'first_name' => $data['first_name'],
                    'last_name' => $data['last_name'],
                    'email' => $data['email'],
                    // Cuentas sin acceso (`NoLoginAccounts`): la contraseña no es el correo sino un valor aleatorio.
                    'password_hash' => NoLoginAccounts::isNoLogin($data['email']) ? Str::random(40) : $data['email'],
                    'is_active' => true,
                    'role_id' => $roleIds[$data['role_key']],
                    'security_level_id' => $securityLevelId,
                    'approve_large_amounts' => false,
                    'manage_users' => $data['manage_users'] ?? false,
                    'extra_permissions' => [],
                ]);
            }
        } finally {
            Tenant::forgetCurrent();
        }
    }
}
