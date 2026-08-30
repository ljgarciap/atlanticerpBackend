<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * ADR-006 — roster real de SCRUM-148 ("Usuarios - Correos"), 25 de las 26 personas de negocio de
 * SCRUM-144 que todavía no tenían cuenta (Mark ya existe vía `CoreUserSeeder`). Corre en TODOS
 * los entornos — son empleados reales de Atlantic, no datos demo.
 *
 * Mismo patrón que `CoreUserSeeder` (create-only-if-not-exists, password default = email, nunca
 * pisa un usuario ya existente) pero para el catálogo de 13 roles de negocio en vez del enum
 * legado `user_roles` — asigna `role_id` + `security_level_id` (el default de cada rol, ver
 * `roles.default_security_level_id`), no filas de `user_roles`.
 *
 * Decisiones de Luis (2026-07-09, hilo de SCRUM-148 + chat):
 * - Felix tiene 2 correos en el ticket — se usa "Correo 1" (`conta@atlantic.com.pa`), per
 *   instrucción explícita de Daniela Amaya (reportera del ticket) en el comentario del 2026-07-09.
 * - Apolonio no tiene correo real en el ticket ("no usan el sistema hoy en día") — se crea con
 *   correo genérico `nombre.apellido@atlantic.com.pa`, patrón confirmado por Luis.
 * - Carlos Gonzalez / Joel Somarria / Dan Rosales aparecen en el ticket solo con nombre y cédula,
 *   sin cargo — no se crean todavía, no hay rol al que mapearlos sin inventar uno.
 * - Ninguna de estas 25 personas recibe el WelcomeMail todavía ("a ninguno le vamos a enviar
 *   correo en primera instancia") — a diferencia de `UserService::create()`, este seeder NO
 *   dispara mail.
 *
 * Whil (Whileyner Contreras) es la excepción de Decisión 5/7 del ADR: mismo rol Gerencia (key
 * `management` desde el rename de Fase C, ver 2026_07_18_100001) que Mark/David/Daniela, pero
 * `security_level_id` override a "Gerencia Restringida" (Nivel 8, "Senior Manager" -- corrección
 * 2026-07-10: reusa la escala existente 1-10, no un nivel nuevo) en vez del default del rol
 * ("Gerencia Total", Nivel 9 "Admin") — ver tabla de flags en el ADR y
 * 2026_07_18_100002_reuse_existing_levels_for_role_defaults.php.
 */
class BusinessRoleUserSeeder extends Seeder
{
    /**
     * @var list<array{first_name: string, last_name: string, email: string, role_key: string,
     *      manage_users?: bool}>
     */
    private const USERS = [
        // Gerencia — Whil tiene override de nivel individual, manejado aparte más abajo.
        ['first_name' => 'Whileyner', 'last_name' => 'Contreras', 'email' => 'whil@atlantic.com.pa', 'role_key' => 'management'],

        ['first_name' => 'Felix', 'last_name' => 'Campos', 'email' => 'conta@atlantic.com.pa', 'role_key' => 'lider_admin_contab', 'manage_users' => true],
        ['first_name' => 'Yaneth', 'last_name' => 'Pineda', 'email' => 'asistente@atlantic.com.pa', 'role_key' => 'asistente_administrativa'],
        ['first_name' => 'Yirena', 'last_name' => 'Teng', 'email' => 'gerencia2@atlantic.com.pa', 'role_key' => 'lider_compras'],
        ['first_name' => 'Esteban', 'last_name' => 'Cardenas', 'email' => 'almacen@atlantic.com.pa', 'role_key' => 'lider_bodega'],
        ['first_name' => 'Osvaldo', 'last_name' => 'Santos', 'email' => 'logistica@atlantic.com.pa', 'role_key' => 'asistente_bodega'],
        ['first_name' => 'Mariano', 'last_name' => 'Sandoval', 'email' => 'almacen2@atlantic.com.pa', 'role_key' => 'asistente_bodega'],
        // Sin correo real en SCRUM-148 — genérico nombre.apellido@atlantic.com.pa (Luis, 2026-07-09).
        ['first_name' => 'Apolonio', 'last_name' => 'Gonzalez', 'email' => 'apolonio.gonzalez@atlantic.com.pa', 'role_key' => 'ayudante_general_bodega'],
        ['first_name' => 'Gary', 'last_name' => 'Arrocha', 'email' => 'arrochagary@gmail.com', 'role_key' => 'transporte'],
        // SCRUM-805 (2026-08-28) — Aaron Leis ya no pertenece a la compañía, reemplazado por Aldo
        // en el rol Líder de Servicios (mismo email genérico por rol). Apellido real de Aldo aún
        // sin confirmar por Luis — placeholder explícito "Aldo Aldo" a pedido suyo, pendiente de
        // corrección cuando lo confirme. El seeder es create-only-if-not-exists (mismo criterio
        // que CoreUserSeeder) — este cambio NO actualiza el usuario ya provisionado en dev/test/
        // prod, requiere un update de datos aparte (ver docs/changelog.md).
        ['first_name' => 'Aldo', 'last_name' => 'Aldo', 'email' => 'servicio@atlantic.com.pa', 'role_key' => 'lider_servicios'],
        ['first_name' => 'Carlos', 'last_name' => 'Vergara', 'email' => 'carlos@atlantic.com.pa', 'role_key' => 'tecnico_servicios'],
        ['first_name' => 'Pedro', 'last_name' => 'Santos', 'email' => 'santopedro181994@gmail.com', 'role_key' => 'tecnico_servicios'],
        ['first_name' => 'Agustin', 'last_name' => 'Rodriguez', 'email' => 'agustinrodriguez141985@gmail.com', 'role_key' => 'tecnico_servicios'],
        ['first_name' => 'Miguel', 'last_name' => 'Castillo', 'email' => 'garantias@atlantic.com.pa', 'role_key' => 'garantias_servicios'],
        ['first_name' => 'Kayra Milena', 'last_name' => 'Estrada', 'email' => 'milena.e@grupolafayette.com', 'role_key' => 'vendedor_disenador'],
        ['first_name' => 'Neil', 'last_name' => 'Quiel', 'email' => 'neil.quiel@atlantic.com.pa', 'role_key' => 'vendedor_disenador'],
        ['first_name' => 'Maria F.', 'last_name' => 'Bonvini', 'email' => 'fefi@atlantic.com.pa', 'role_key' => 'vendedor_disenador'],
        ['first_name' => 'Vanessa', 'last_name' => 'Villareal', 'email' => 'vanessa@atlantic.com.pa', 'role_key' => 'vendedor_disenador'],
        ['first_name' => 'Paola', 'last_name' => 'Gutierrez', 'email' => 'paola@atlantic.com.pa', 'role_key' => 'vendedor_disenador'],
        ['first_name' => 'Anelhys', 'last_name' => 'Nuez', 'email' => 'annie@atlantic.com.pa', 'role_key' => 'vendedor_disenador'],
        ['first_name' => 'Maribel', 'last_name' => 'Gauthier', 'email' => 'maribel@atlantic.com.pa', 'role_key' => 'vendedor_disenador'],
        ['first_name' => 'Juan Manuel', 'last_name' => 'Brustein', 'email' => 'juanmanuel@atlantic.com.pa', 'role_key' => 'vendedor_disenador'],
        ['first_name' => 'Bernardo', 'last_name' => 'Gomez', 'email' => 'bernardo@atlantic.com.pa', 'role_key' => 'vendedor_disenador'],
        // 10mo Vendedor/Diseñador — SCRUM-144 documentaba 9, SCRUM-148 sumó a Idmar con cargo
        // explícito, se incluye igual (no es de los 3 sin cargo).
        ['first_name' => 'Idmar', 'last_name' => 'Hernandez', 'email' => 'idmar@atlantic.com.pa', 'role_key' => 'vendedor_disenador'],
        ['first_name' => 'Mariam', 'last_name' => 'Diaz', 'email' => 'operaciones@atlantic.com.pa', 'role_key' => 'lider_operaciones'],
    ];

    private const WHIL_EMAIL = 'whil@atlantic.com.pa';
    private const WHIL_OVERRIDE_LEVEL = 8; // "Gerencia Restringida", no el default del rol (9)

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
            $whilLevelId = DB::table($levelsTable)->where('level', self::WHIL_OVERRIDE_LEVEL)->value('id');

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

                $securityLevelId = $data['email'] === self::WHIL_EMAIL
                    ? $whilLevelId
                    : $roleDefaults[$data['role_key']];

                User::query()->create([
                    'first_name'            => $data['first_name'],
                    'last_name'             => $data['last_name'],
                    'email'                 => $data['email'],
                    'password_hash'         => $data['email'],
                    'is_active'             => true,
                    'role_id'               => $roleIds[$data['role_key']],
                    'security_level_id'     => $securityLevelId,
                    'approve_large_amounts' => false,
                    'manage_users'          => $data['manage_users'] ?? false,
                    'extra_permissions'     => [],
                ]);
            }
        } finally {
            Tenant::forgetCurrent();
        }
    }
}
