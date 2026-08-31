<?php

declare(strict_types=1);

namespace App\Modules\Bodega\Support;

/**
 * SCRUM-329 Oleada A / Batch A2 — `role_key` reales del catálogo de roles (`roles.key`, ver
 * `BusinessRoleUserSeeder`) que participan del flujo de Pedidos. Centralizado acá en vez de
 * repetir el string en cada Service/Controller — mismo criterio que `OrderService::
 * BODEGA_LEAD_ROLE_KEY` (Batch A1), generalizado porque A2 necesita distinguir varios roles
 * (Jefe, Asistentes, Picker, Repartidor), no solo uno.
 *
 * No hay un modelo de "picker"/"repartidor" nuevo — REQ-313/326 ya hablan de personas reales
 * sembradas por `BusinessRoleUserSeeder` con estos `role_key` (Apolonio González =
 * `ayudante_general_bodega`, Transporte Test = `transporte`), confirmado contra Jira y el seeder
 * antes de escribir código nuevo (regla del proyecto).
 */
final class BodegaRoles
{
    public const LIDER_BODEGA = 'lider_bodega';

    public const ASISTENTE_BODEGA = 'asistente_bodega';

    /** REQ-313 — el "picker" es un usuario con este rol, no una entidad nueva. */
    public const PICKER = 'ayudante_general_bodega';

    /** REQ-326 — el "repartidor" es un usuario con este rol, no una entidad nueva. */
    public const COURIER = 'transporte';

    /** @var list<string> Roles que pueden asignar picker (REQ-313 RN3) y operar el flujo general de Bodega. */
    public const CAN_OPERATE = [self::LIDER_BODEGA, self::ASISTENTE_BODEGA];

    /**
     * Pre-QA 2026-07-26 (CRÍTICO) — REQ-314/315/316 dicen "Visible para todos los perfiles de
     * Bodega" en el PERMISOS del ticket (SCRUM-384/385/386), pero eso nunca se validó a nivel de
     * rol en `PickingService::startPicking()`/`updatePickingSheet()`/`completePicking()` — solo
     * `assignPicker()` (REQ-313 RN3, alcance distinto: "quién puede ASIGNAR" vs "quién ejecuta el
     * picking ya asignado") tenía gate de rol. Un actor con rol `COURIER` (Gary/transporte, cuya
     * función real es la entrega en Packing/Despacho, no el picking) podía operar picking de
     * cualquier pedido con solo tener `bodega.write` — que la migración de permisos ya le concede
     * también a `transporte`. `CAN_PICK` es deliberadamente `CAN_OPERATE` + `PICKER`, sin incluir
     * `COURIER` — si Luis decide más adelante que Courier sí debería poder operar picking, es un
     * cambio de alcance a discutir con él, no una ampliación implícita acá.
     *
     * @var list<string> Roles que pueden ejecutar el flujo de picking ya asignado (REQ-314/315/316).
     */
    public const CAN_PICK = [self::LIDER_BODEGA, self::ASISTENTE_BODEGA, self::PICKER];

    /**
     * SCRUM-329 Oleada A / Batch B1 (Home de Bodega, REQ-295/296) — los 4 `role_key` que son
     * "de negocio Bodega" propiamente. Deliberadamente NO se deriva esto de
     * `RoleModuleVisibility(module: bodega)`: esa tabla también incluye a Gerencia (visibilidad
     * transversal a los 7 módulos, ver migración de Fase A) — correcta para "quién puede ABRIR
     * Bodega", pero errónea para "quién ES del equipo de Bodega" (el selector de personas del
     * Home no debe ofrecer a Gerencia como si fuera un miembro operativo del equipo).
     */
    public const ALL = [self::LIDER_BODEGA, self::ASISTENTE_BODEGA, self::PICKER, self::COURIER];

    /**
     * SCRUM-366 (REQ-296) — hallazgo de QA 2026-07-24 (marly.rangel): el selector de personas del
     * alcance "Equipo" en Home de Bodega debe listar ÚNICAMENTE Asistentes de Bodega, no los 4
     * roles de `ALL`. El ticket describe el selector como "una [opción] por cada Asistente/
     * Operativo de Bodega (ej. Asistente Bodega Test 2, Asistente Bodega Test)" — ambos ejemplos son
     * `ASISTENTE_BODEGA`; QA confirmó en vivo que el Jefe (Esteban, `LIDER_BODEGA`), el Ayudante
     * General (Apolonio, `PICKER`) y Transporte (Gary, `COURIER`) NO deben aparecer como opciones
     * filtrables, pese a que los 3 sí participan del flujo operativo de Pedidos — "Asistente/
     * Operativo" en el ticket es un único rol de negocio, no una unión de roles distintos.
     *
     * Usado EXCLUSIVAMENTE por `BodegaHomeService::team()` — no toca el guard de `owner_id`
     * (`isBodegaTeamMember()`) ni la agregación de calendario (`teamEmails()`), que siguen
     * usando `ALL` a propósito (ver Pre-QA 2026-07-23, comentario en SCRUM-366).
     *
     * @var list<string>
     */
    public const TEAM_SELECTABLE = [self::ASISTENTE_BODEGA];

    /**
     * Fix de regresión cruzada (2026-07-28) — `GET /bodega/home/team` (SCRUM-365/366) era el
     * único endpoint que el frontend tenía para listar personas seleccionables de Bodega; al
     * restringirlo a `TEAM_SELECTABLE` (solo Asistentes, fix correcto para el selector del Home),
     * se rompió silenciosamente todo lo demás que lo reusaba: "Asignar Repartidor" (SCRUM-396) y
     * "Quién entregó" (SCRUM-399) en el tablero de Pedidos necesitan `COURIER`, "Asignar Picker"
     * (SCRUM-383, ya en producción) necesita `PICKER`.
     *
     * Mapa de los valores públicos que acepta `GET /bodega/team-members?role=` (ver
     * `BodegaTeamMembersController`), uno por cada selector real — deliberadamente 1 valor
     * público → 1 solo `role_key`, nunca una unión de roles (a diferencia de `ALL`/`CAN_PICK`, que
     * sí agregan varios roles para un gate de permiso, no para un listado de personas).
     *
     * @var array<string, string>
     */
    public const TEAM_MEMBERS_QUERY_MAP = [
        'asistente_bodega' => self::ASISTENTE_BODEGA,
        'courier' => self::COURIER,
        'picker' => self::PICKER,
    ];
}
