<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\Seeder;

/**
 * Grants de `extra_permissions` angostos, concebidos para una persona puntual (no un rol
 * completo), que quedaban sin ningún mecanismo que los asignara de verdad — Pre-QA los encontró
 * dos veces (SCRUM-126/REQ-034 "Precio Socio" y SCRUM-138/REQ-046 "Condiciones") con el código
 * correcto pero NADIE con el permiso concedido en ningún entorno real, obligando a un grant manual
 * por SQL solo para poder probar el criterio 2 de cada ticket. Corre en TODOS los entornos (bloque
 * "Tipo A" de DatabaseSeeder) — son personas y permisos reales, no datos demo.
 *
 * Idempotente por fusión, no por reemplazo: cada corrida UNE estos permisos a lo que la persona ya
 * tenga en `extra_permissions` (nunca lo pisa) — así un `db:seed --force` en cada deploy no
 * deshace un grant adicional que un superadmin haya asignado a mano después vía la pantalla de
 * administración.
 *
 * Si se agrega un permiso angosto nuevo de este tipo (asignado a una persona puntual, no a un
 * security_level ni a un rol), agregarlo acá — no dejar que Pre-QA lo vuelva a descubrir vacío.
 */
class SpecialPermissionSeeder extends Seeder
{
    /** @var array<string, list<string>> */
    private const GRANTS = [
        // Mark — REQ-034 (Precio Socio) + REQ-046 (Condiciones, junto con Felix) + REQ-008
        // (editar proyectos Aprobado, SCRUM-77 — único usuario de negocio que el criterio nombra)
        // + SCRUM-725 (excepción de margen mínimo, junto con David)
        'mbekhar@illuminations.com.pa' => [
            'ventas_diseno.select_partner_price',
            'ventas_diseno.edit.conditions',
            'ventas_diseno.edit.approved',
            'ventas_diseno.override_min_margin',
            'servicios.quotes.view_cost_breakdown',
        ],
        // Felix — REQ-046 (Condiciones)
        'conta@illuminations.com.pa' => ['ventas_diseno.edit.conditions'],
        // David — SCRUM-725 (excepción de margen mínimo, junto con Mark; el ticket los nombra a
        // los dos por email explícitamente, no es un grant de rol) + SCRUM-295 (desglose de costo
        // del ítem Subcontratado, junto con Aaron/Mark/Whil)
        'david@grupolafayette.com' => ['ventas_diseno.override_min_margin', 'servicios.quotes.view_cost_breakdown'],
        // Aaron (lider_servicios) — SCRUM-295: aunque lidera el módulo, ver el desglose de
        // costo/margen del ítem Subcontratado es un grant angosto por persona (el ticket lo nombra
        // explícitamente junto con Mark/David/Whil), no algo que su rol/nivel otorgue por defecto.
        'servicio@illuminations.com.pa' => ['servicios.quotes.view_cost_breakdown'],
        // Whil (Whileyner Contreras, management con nivel restringido) — SCRUM-295, mismo grant.
        'whil@illuminations.com.pa' => ['servicios.quotes.view_cost_breakdown'],
        // Yirena Teng (lider_compras) — compras.settings.configure (umbral configurable de
        // "calificación baja" de proveedores, ver compras_settings), aprobado por Luis 2026-07-16
        // junto con el fix de su security_level default (Nivel 1 -> Nivel 4, ver migración
        // 2026_07_20_100000 en `dev`); compras.zona-libre.approve (SCRUM-440, Bloque B3 Zona Libre
        // de Colón, 2026-07-24) — es quien aprueba/rechaza las solicitudes que genera Bodega.
        // bodega.read (SCRUM-440, hallazgo de QA 2026-07-25/26): el backend ya la dejaba
        // aprobar/rechazar (200 real, confirmado por Pre-QA), pero su rol `lider_compras` no
        // tiene ninguna fila en `RoleModuleVisibility` para `bodega` — sin este grant, `Require
        // Permission permission="bodega.read"` (routes/App.tsx, TODAS las rutas de Bodega
        // comparten ese único gate, no hay uno más angosto solo para Zona Libre) la redirigía al
        // dashboard antes de poder llegar a /bodega/ordenes-zona-libre. Efecto secundario
        // aceptado (no un bug): al tener bodega.read, el Sidebar también le muestra el resto del
        // módulo Bodega (Home, Pedidos, Inventario, etc.) — no existe hoy un permiso más angosto
        // que exponga solo Zona Libre, y Luis confirmó que darle lectura completa de Bodega es
        // aceptable para este caso.
        'gerencia2@illuminations.com.pa' => ['compras.settings.configure', 'compras.zona-libre.approve', 'bodega.read'],
        // Mariam Diaz (lider_operaciones) — SCRUM-771: acceso COMPLETO a Comparación de
        // Referencias (Sustitutos) dentro de Compras, sin heredar compras.write/.edit (que
        // desbloquearía escritura en el resto del módulo). La visibilidad de solo-lectura del
        // resto de Compras (Ver Órdenes/Logística/Inventario/Ingresos) viene de la migración
        // 2026_08_18_100001 (role_module_visibility del rol, no de extra_permissions) — acá solo
        // el permiso angosto de Sustitutos, que sí es por persona.
        // SCRUM-771 (corrección 2026-08-18) — compras.limited.view sumado tras el hallazgo de
        // validación visual: VIEW_READONLY (migración 2026_08_18_100003) por sí solo deja el
        // módulo navegable pero sin `compras.read`, así que este permiso angosto es lo único
        // que de verdad habilita Ver Órdenes/Logística/Inventario/Ver Registros de Ingreso —
        // ver routes/compras.php (OR en esas rutas puntuales) e InventoryController::resolveAccess().
        'operaciones@illuminations.com.pa' => ['compras.sustitutos.manage', 'compras.limited.view'],
        // Los 10 vendedores/diseñadores reales (rol vendedor_disenador, ver
        // BusinessRoleUserSeeder) — Batch 14 de Admin&Cont (SCRUM-575→579, REQ-575/577): cada
        // uno consulta la tabla de tramos de comisión y su propio historial en Comisiones
        // Internas, nunca el resto de Admin&Cont. Mismo patrón que compras.limited.view arriba —
        // VIEW_READONLY (migración 2026_08_25_100002) deja el módulo navegable pero sin
        // admin_contab.read; este permiso angosto es lo único que de verdad habilita la pantalla.
        'milena.e@grupolafayette.com'      => ['admin_contab.commissions_internal.view'],
        'neil.quiel@illuminations.com.pa'  => ['admin_contab.commissions_internal.view'],
        'fefi@illuminations.com.pa'        => ['admin_contab.commissions_internal.view'],
        'vanessa@illuminations.com.pa'     => ['admin_contab.commissions_internal.view'],
        'paola@illuminations.com.pa'       => ['admin_contab.commissions_internal.view'],
        'annie@illuminations.com.pa'       => ['admin_contab.commissions_internal.view'],
        'maribel@illuminations.com.pa'     => ['admin_contab.commissions_internal.view'],
        'juanmanuel@illuminations.com.pa'  => ['admin_contab.commissions_internal.view'],
        'bernardo@illuminations.com.pa'    => ['admin_contab.commissions_internal.view'],
        'idmar@illuminations.com.pa'       => ['admin_contab.commissions_internal.view'],
    ];

    public function run(): void
    {
        $tenant = Tenant::where('slug', 'illuminations')->first();

        if ($tenant === null) {
            $this->command->warn('Tenant "illuminations" not found — SpecialPermissionSeeder skipped.');
            return;
        }

        $tenant->makeCurrent();

        try {
            foreach (self::GRANTS as $email => $permissions) {
                $user = User::where('email', $email)->first();
                if ($user === null) {
                    $this->command->warn("Usuario \"{$email}\" no encontrado — grant especial saltado.");
                    continue;
                }

                $merged = array_values(array_unique(array_merge($user->extra_permissions ?? [], $permissions)));
                sort($merged);
                $current = $user->extra_permissions ?? [];
                sort($current);

                if ($merged !== $current) {
                    $user->update(['extra_permissions' => $merged]);
                }
            }
        } finally {
            Tenant::forgetCurrent();
        }
    }
}
