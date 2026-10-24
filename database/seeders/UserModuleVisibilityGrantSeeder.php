<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Auth\Models\RoleModuleVisibility;
use App\Modules\Auth\Models\UserModuleMenuVisibility;
use App\Modules\Auth\Models\UserModuleVisibility;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\Seeder;

/**
 * SCRUM-1139 — overrides de visibilidad de módulo concedidos a una persona puntual (no a un rol) vía
 * `user_module_visibility` (SCRUM-724). Mismo criterio que `SpecialPermissionSeeder`: corre en TODOS los
 * entornos y reemplaza el "grant manual" que un reseed no restauraría.
 *
 * Hoy: el líder de operaciones (Mariam Díaz, rol `lider_operaciones`) ve Administración & Contabilidad igual que
 * Félix (ver y alcance de equipo). El rol NO se toca: sus rutas de Admin&Cont las abre `role:` en
 * `routes/admin-contab.php` y su edición no depende del nivel de seguridad.
 *
 * Idempotente y no destructivo: solo CREA la fila cuando no existe ninguna para ese usuario y módulo. Si ya hay una
 * (por ejemplo, un superadmin la ajustó desde la pantalla de usuarios), no la toca. Salta con aviso si el usuario no existe.
 */
class UserModuleVisibilityGrantSeeder extends Seeder
{
    /** @var array<string, list<array{module: string, can_view: int, can_view_team: bool, can_edit?: bool, can_approve?: bool}>> */
    private const GRANTS = [
        'lideroperaciones@test.com' => [
            ['module' => 'admin_contab', 'can_view' => RoleModuleVisibility::VIEW_FULL, 'can_view_team' => true],
        ],
        // SCRUM-1150 — Félix replica a Mark en Ventas & Diseño (ver, equipo, editar y aprobar) sin subir su nivel de seguridad.
        'contabilidad@test.com' => [
            ['module' => 'ventas_diseno', 'can_view' => RoleModuleVisibility::VIEW_FULL, 'can_view_team' => true, 'can_edit' => true, 'can_approve' => true],
        ],
    ];

    /**
     * SCRUM-1157 — ítems de menú OCULTOS por persona (`user_module_menu_visibility`, solo filas `visible=false`). Mariam ve Compras
     * únicamente en modo consulta (Órdenes, Logística, Inventario, Ingresos, Sustitutos): el resto de Compras no es suyo. SCRUM-771 lo dejó
     * "por configuración del panel admin" para aplicar a mano en cada entorno; dev y prod lo tenían y test no, y en test aparecían enlaces
     * a pantallas que la redirigían a inicio. Solo siembra sobre una configuración vacía del módulo: nunca pisa ni borra lo que alguien ajustó.
     *
     * @var array<string, array<string, list<string>>> email => módulo => llaves de `menuItemCatalog.ts`
     */
    private const HIDDEN_MENU_ITEMS = [
        'lideroperaciones@test.com' => [
            'compras' => ['inicio', 'proveedores', 'nueva_orden', 'agencias', 'pagos', 'reclamos', 'reportes'],
        ],
    ];

    public function run(): void
    {
        $tenant = Tenant::where('slug', 'atlantic')->first();

        if ($tenant === null) {
            $this->command->warn('Tenant "atlantic" not found — UserModuleVisibilityGrantSeeder skipped.');
            return;
        }

        $tenant->makeCurrent();

        try {
            foreach (self::GRANTS as $email => $grants) {
                $user = User::where('email', $email)->first();
                if ($user === null) {
                    $this->command->warn("Usuario \"{$email}\" no encontrado — grant de visibilidad saltado.");
                    continue;
                }

                foreach ($grants as $grant) {
                    $exists = UserModuleVisibility::where('user_id', $user->id)->where('module', $grant['module'])->exists();
                    if (! $exists) {
                        UserModuleVisibility::create([
                            'user_id'       => $user->id,
                            'module'        => $grant['module'],
                            'can_view'      => $grant['can_view'],
                            'can_view_team' => $grant['can_view_team'],
                            'can_edit'      => $grant['can_edit'] ?? null,
                            'can_approve'   => $grant['can_approve'] ?? null,
                        ]);
                    }
                }
            }

            foreach (self::HIDDEN_MENU_ITEMS as $email => $modules) {
                $user = User::where('email', $email)->first();
                if ($user === null) {
                    $this->command->warn("Usuario \"{$email}\" no encontrado — ítems de menú ocultos saltados.");
                    continue;
                }

                foreach ($modules as $module => $itemKeys) {
                    // Solo existen filas `visible=false` (borrar una = mostrar el ítem), así que cualquier fila del módulo significa que
                    // alguien ya configuró el menú de esa persona (dev y prod): no se toca. Se siembra solo sobre una configuración vacía.
                    if (UserModuleMenuVisibility::where('user_id', $user->id)->where('module', $module)->exists()) {
                        continue;
                    }
                    foreach ($itemKeys as $itemKey) {
                        UserModuleMenuVisibility::create([
                            'user_id' => $user->id, 'module' => $module, 'menu_item_key' => $itemKey, 'visible' => false,
                        ]);
                    }
                }
            }
        } finally {
            Tenant::forgetCurrent();
        }
    }
}
