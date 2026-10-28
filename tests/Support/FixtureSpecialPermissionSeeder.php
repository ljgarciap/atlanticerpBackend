<?php

declare(strict_types=1);

namespace Tests\Support;

use Database\Seeders\SpecialPermissionSeeder;

/**
 * Solo para tests. SpecialPermissionSeeder::GRANTS queda vacío en Atlantic (scaffold sin personas de un
 * cliente), pero muchos tests de binax ejercitan features gateadas por un permiso angosto concedido a una
 * persona (receptor fiscal, exención de ITBMS, pipeline, etc.). Este seeder reproduce esas concesiones sobre
 * las identidades genéricas (gerencia3@test.com = aprobador primario, etc.) para conservar la cobertura sin
 * meter personas en el seeder real. Se aplica desde FeatureTestCase::afterRefreshingDatabase().
 * Generado de binax origin/test (2026-10-22) con scripts/upstream-sync/transform.py.
 */
final class FixtureSpecialPermissionSeeder extends SpecialPermissionSeeder
{
    protected const GRANTS = [
        // Gerencia — REQ-034 (Precio Socio) + REQ-046 (Condiciones, junto con Contabilidad) + REQ-008
        // (editar proyectos Aprobado, SCRUM-77 — único usuario de negocio que el criterio nombra)
        // + SCRUM-725 (excepción de margen mínimo, junto con Gerencia) + SCRUM-925 (LUMO, junto con
        // Gerencia/Felix)
        'gerencia3@test.com' => [
            'ventas_diseno.select_partner_price',
            'ventas_diseno.edit.conditions',
            'ventas_diseno.edit.approved',
            'ventas_diseno.override_min_margin',
            'servicios.quotes.view_cost_breakdown',
            'ventas_diseno.edit.client_tax_data',
            'admin_contab.edit.receptor_fiscal',
            'admin_contab.edit.receptor_consumidor_final',
            // SCRUM-1166 — marcar a un cliente fiscal como exento de ITBMS (con documento), junto con Contabilidad y Asistente Administrativa.
            'admin_contab.edit.client_itbms_exemption',
        ],
        // Contabilidad — REQ-046 (Condiciones) + SCRUM-925 (LUMO, junto con Gerencia/David) + SCRUM-1002 (editar
        // razón social/RUC/DV de un SubCliente, junto con Gerencia; Ventas & Diseño lo tiene por `ventas_diseno.edit`)
        // SCRUM-1128 — Contabilidad pasa proyectos aprobados a Pedido y ve el Pipeline en SOLO LECTURA
        // (`ventas_diseno.pipeline.read_all` no desbloquea ninguna ruta de escritura).
        // SCRUM-1139 — Contabilidad edita el Pipeline (`ventas_diseno.pipeline.edit`, SOLO rutas de /pipeline) y los proyectos Aprobados
        // (`edit.approved`).
        // SCRUM-1150 (2026-10-15, decisión de Luis) — Contabilidad replica a Gerencia en Ventas & Diseño: suma select_partner_price y
        // override_min_margin (reemplaza la exclusión de SCRUM-1139); ver/editar/aprobar vienen del override en user_module_visibility.
        'contabilidad@test.com' => ['ventas_diseno.edit.conditions', 'ventas_diseno.edit.client_tax_data', 'admin_contab.edit.receptor_fiscal', 'admin_contab.edit.receptor_consumidor_final', 'ventas_diseno.pass_to_order', 'ventas_diseno.pipeline.read_all', 'ventas_diseno.pipeline.edit', 'ventas_diseno.edit.approved', 'ventas_diseno.select_partner_price', 'ventas_diseno.override_min_margin', 'ventas_diseno.gerencia_scope', 'admin_contab.edit.client_itbms_exemption'],
        // Guarda de RUC al facturar (docs/specs/guarda-validacion-ruc-al-facturar.md): `admin_contab.edit.receptor_fiscal`
        // permite corregir RUC/DV/razón social desde la vista previa de "Crear factura". Gerencia y Contabilidad son quienes facturan;
        // otras personas de Admin&Cont las asigna un superadmin desde la pantalla de usuarios.
        // SCRUM-1140 — `admin_contab.edit.receptor_consumidor_final` (facturar a Consumidor Final por decisión de quien factura)
        // va a quien factura: hoy Gerencia y Contabilidad; las personas nuevas que facturen lo reciben de un superadmin junto con el permiso
        // de facturar y `receptor_fiscal` (ver docs/runbooks/alta-de-facturador.md).
        // Gerencia — SCRUM-725 (excepción de margen mínimo, junto con Gerencia; el ticket los nombra a
        // los dos por email explícitamente, no es un grant de rol) + SCRUM-295 (desglose de costo
        // del ítem Subcontratado, junto con Lider Servicios/Mark/Whil) + SCRUM-925 (LUMO, junto con Gerencia/Felix)
        'gerencia2@test.com' => ['ventas_diseno.override_min_margin', 'servicios.quotes.view_cost_breakdown'],
        // Lider Servicios (lider_servicios) — SCRUM-295: aunque lidera el módulo, ver el desglose de
        // costo/margen del ítem Subcontratado es un grant angosto por persona (el ticket lo nombra
        // explícitamente junto con Gerencia/David/Whil), no algo que su rol/nivel otorgue por defecto.
        'liderservicios@test.com' => ['servicios.quotes.view_cost_breakdown'],
        // Gerencia (Gerencia Test 5, management con nivel restringido) — SCRUM-295, mismo grant.
        'gerencia5@test.com' => ['servicios.quotes.view_cost_breakdown'],
        // Lider Compras Test (lider_compras) — compras.settings.configure (umbral configurable de
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
        'lidercompras@test.com' => ['compras.settings.configure', 'compras.zona-libre.approve', 'bodega.read'],
        // Lider Operaciones Test (lider_operaciones) — SCRUM-771: acceso COMPLETO a Comparación de
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
        'lideroperaciones@test.com' => ['compras.sustitutos.manage', 'compras.limited.view', 'admin_contab.edit.receptor_fiscal'],
        // SCRUM-1139 — la asistente administrativa (Asistente Administrativa) replica `receptor_fiscal` de Contabilidad (corregir RUC/DV/razón social del receptor).
        // Sus rutas de Admin&Cont las da el ROL (`role:`); este es el único permiso angosto que le faltaba.
        'asistenteadministrativa@test.com' => ['admin_contab.edit.receptor_fiscal', 'admin_contab.edit.client_itbms_exemption'],
        // Los 10 vendedores/diseñadores reales (rol vendedor, ver
        // BusinessRoleUserSeeder) — Batch 14 de Admin&Cont (SCRUM-575→579, REQ-575/577): cada
        // uno consulta la tabla de tramos de comisión y su propio historial en Comisiones
        // Internas, nunca el resto de Admin&Cont. Mismo patrón que compras.limited.view arriba —
        // VIEW_READONLY (migración 2026_08_25_100002) deja el módulo navegable pero sin
        // admin_contab.read; este permiso angosto es lo único que de verdad habilita la pantalla.
        // SCRUM-1013 (rebote 2026-10-02) — los mismos 10 reciben `compras.logistica.view` (solo
        // Órdenes → Logística y Envío, en consulta) y, SCRUM-1021, `bodega.warehouses.view` (solo la
        // pantalla Bodegas, en consulta). NO `compras.limited.view` (el de Operaciones): desenmascara
        // costo y margen en Inventario, que un vendedor nunca ve — la migración
        // 2026_10_02_120100 lo quita de estos 10 y este seeder no lo vuelve a poner.
        'vendedor@test.com'      => ['admin_contab.commissions_internal.view', 'compras.logistica.view', 'bodega.warehouses.view'],
        'vendedor2@test.com'  => ['admin_contab.commissions_internal.view', 'compras.logistica.view', 'bodega.warehouses.view'],
        'vendedor3@test.com'        => ['admin_contab.commissions_internal.view', 'compras.logistica.view', 'bodega.warehouses.view'],
        'vendedor4@test.com'     => ['admin_contab.commissions_internal.view', 'compras.logistica.view', 'bodega.warehouses.view'],
        'vendedor5@test.com'       => ['admin_contab.commissions_internal.view', 'compras.logistica.view', 'bodega.warehouses.view'],
        'vendedor6@test.com'       => ['admin_contab.commissions_internal.view', 'compras.logistica.view', 'bodega.warehouses.view'],
        'vendedor7@test.com'     => ['admin_contab.commissions_internal.view', 'compras.logistica.view', 'bodega.warehouses.view'],
        'vendedor8@test.com'  => ['admin_contab.commissions_internal.view', 'compras.logistica.view', 'bodega.warehouses.view'],
        'vendedor9@test.com'    => ['admin_contab.commissions_internal.view', 'compras.logistica.view', 'bodega.warehouses.view'],
        'vendedor10@test.com'       => ['admin_contab.commissions_internal.view', 'compras.logistica.view', 'bodega.warehouses.view'],
    ];
}
