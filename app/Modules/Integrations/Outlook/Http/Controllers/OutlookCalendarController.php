<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Outlook\Http\Controllers;

use App\Models\User;
use App\Modules\Auth\Models\RoleModuleVisibility;
use App\Modules\Integrations\Outlook\Services\OutlookCalendarService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;

/**
 * SCRUM-66/177 — endpoint de solo lectura compartido entre la tarjeta "Mi calendario" de Ventas &
 * Diseño (Inicio) y el panel de Compras. El módulo que gatea el alcance Mío/Equipo viene del
 * `defaults('module', ...)` de cada ruta (mismo mecanismo que CalendarEventController, que este
 * endpoint reemplaza como fuente de datos — ver rutas ventas-diseno.php/compras.php).
 */
final class OutlookCalendarController extends Controller
{
    /**
     * Módulos donde este endpoint soporta "Calendario del equipo" (`scope=team` / `owner_id`).
     * Allowlist explícita — no basta con `canViewTeamV2($module)`, porque ese flag existe para
     * OTRAS pantallas del módulo (ej. `servicios.view_team` habilita "Rutas del día", que sí es
     * team-wide por diseño, ver REQ-208) y no necesariamente autoriza el calendario personal de
     * ese mismo módulo.
     *
     * Hallazgo CRÍTICO de Pre-QA (2026-08-14, SCRUM-272): con `servicios` ausente de esta lista
     * (estado previo, sin allowlist — el gate era solo `canViewTeamV2($module)`), forjar
     * `scope=team` directo contra `GET /api/servicios/calendar` (sin pasar por el frontend, que
     * a propósito nunca manda ese param — ver `ServiciosMyCalendarPanel.tsx`) devolvía eventos
     * reales de Outlook de OTROS usuarios para cualquier actor con `servicios.view_team=true`
     * (ej. Lider Servicios/`lider_servicios` veía el calendario de Tecnico Garantias Test y Gerencia Test,
     * confirmado en vivo contra el stack local). Viola PERMISOS de SCRUM-272 ("nadie puede ver
     * el calendario personal de otro usuario desde aquí") — regla deliberadamente MÁS estricta
     * que Ventas & Diseño/Compras/Bodega, que sí exponen team-view como funcionalidad aprobada.
     * La restricción del frontend (nunca enviar `scope`/`owner_id`) es necesaria pero no
     * suficiente por sí sola — el endpoint es de acceso directo (curl/Postman/JWT robado), así
     * que el bloqueo real tiene que vivir acá. `servicios` queda deliberadamente FUERA de esta
     * lista.
     */
    private const MODULES_WITH_TEAM_CALENDAR = ['ventas_diseno', 'compras', 'bodega'];

    public function __construct(private readonly OutlookCalendarService $service) {}

    public function index(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $module = (string) $request->route('module', 'ventas_diseno');
        $canSeeTeam = in_array($module, self::MODULES_WITH_TEAM_CALENDAR, true) && $user->canViewTeamV2($module);

        $ownerId = $request->query('owner_id');
        $scope = $request->query('scope');

        // Mismo mecanismo de 3 vías que CalendarEventController (que este endpoint reemplaza como
        // fuente de datos): equipo completo (sin owner_id) / un vendedor puntual / propio. La
        // versión anterior de este método solo cubría los últimos dos — sin `scope`, "todo el
        // equipo" (Gerencia con el selector en blanco) caía silenciosamente al calendario propio
        // del que mira, no al de todos (bug real, hallazgo de Senior Review 2026-07-21).
        $team = $this->teamEmails($module);

        if ($canSeeTeam && $scope === 'team' && ! is_numeric($ownerId)) {
            $emails = $team;
        } elseif ($canSeeTeam && is_numeric($ownerId)) {
            // Hallazgo CRÍTICO de Pre-QA (2026-07-21, confirmado en vivo contra dev.atlanticerp.ai):
            // `canSeeTeam` solo dice "este actor puede ver EQUIPOS en este módulo" — no dice que
            // el `owner_id` pedido sea parte de ese equipo. Sin este chequeo, Gerencia (team-view
            // en ventas_diseno/compras) podía pasar el owner_id de CUALQUIER empleado del tenant
            // (probado con el superadmin, que no es diseñador ni de Compras) y AtlanticERP se lo servía
            // igual — Graph no lo bloquea (permiso de aplicación, lee cualquier mailbox), así que
            // el control tiene que vivir acá. Igual que "sin team-view", un owner_id fuera del
            // equipo real cae al calendario propio del actor, nunca al del objetivo.
            $target = User::find((int) $ownerId);
            $emails = ($target !== null && $target->email !== null && in_array($target->email, $team, true))
                ? [$target->email]
                : ($user->email !== null ? [$user->email] : []);
        } else {
            $emails = $user->email !== null ? [$user->email] : [];
        }

        // Hallazgo CRÍTICO de Pre-QA (2026-07-21): el frontend manda `from`/`to` como fechas
        // puras ("2026-07-21"), sin hora — Carbon::parse() las deja en medianoche. Sin
        // ->endOfDay() acá, un pill "Día" (from === to, mismo string) arma un intervalo
        // [medianoche, medianoche) de ancho CERO contra Graph, que nunca puede solapar ningún
        // evento real por más que existan — confirmado en vivo (0 eventos con from=to=hoy, 4
        // eventos reales con to=mañana). El mismo bug truncaba el último día de los rangos
        // Semana/Mes. `endOfDay()` se aplica siempre, no solo en el fallback por defecto.
        $from = $this->parseDate($request->query('from')) ?? Carbon::now()->startOfDay();
        $to = ($this->parseDate($request->query('to')) ?? $from->copy())->endOfDay();

        if ($emails === []) {
            return response()->json(['data' => [], 'source_unavailable' => false]);
        }

        try {
            $result = $this->service->eventsForUsers($emails, $from, $to);

            // Visual Review CRÍTICO (SCRUM-368, 2026-07-28): "Calendario del equipo" traía
            // `owner_email` pero ningún consumidor lo resolvía a un nombre — RN2/Escenario 2 exige
            // que cada evento combinado muestre a quién pertenece. Se resuelve acá, una sola vez
            // para los 3 módulos que comparten este endpoint, en vez de que cada frontend adivine
            // un nombre a partir del email.
            /** @var array<string, string> $namesByEmail */
            $namesByEmail = User::whereIn('email', $emails)
                ->get(['email', 'first_name', 'last_name'])
                ->mapWithKeys(fn (User $u) => [$u->email => trim("{$u->first_name} {$u->last_name}")])
                ->all();

            $events = array_map(
                fn (array $e) => $e + ['owner_name' => $namesByEmail[$e['owner_email']] ?? null],
                $result['events'],
            );

            return response()->json(['data' => $events, 'source_unavailable' => $result['degraded']]);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['data' => [], 'source_unavailable' => true]);
        }
    }

    /**
     * Hallazgo de Luis en validación (2026-07-21): "vista de equipo" mostraba cero eventos con
     * datos reales aunque hay 10+ vendedores/diseñadores reales con cuenta corporativa. Causa
     * raíz: la primera versión de este método (y `HomeService::vendorEmails()`, que la copié de
     * ahí) usaba el rol LEGADO `user_roles.role = 'designer'` — en dev.atlanticerp.ai ese valor solo lo
     * tienen 3 cuentas demo `*.test`, ninguna con buzón real. Los vendedores/diseñadores reales
     * (`annie@`, `vendedor9@`, etc.) están asignados por el sistema de roles NUEVO (`role_id` →
     * `Role` "Vendedor/Diseñador", ver ADR-006/SCRUM-144-145) — el legado nunca se actualizó para
     * ellos porque ya no es la fuente de verdad de permisos, pero seguía siendo la fuente de
     * verdad de ESTE query.
     *
     * Fix: derivar "equipo del módulo X" de `RoleModuleVisibility` (la misma tabla que ya usa
     * `canViewTeamV2()`/`modulesPayload()` para permisos), no de un rol legado hardcodeado —
     * cualquier rol con visibilidad del módulo cuenta como "equipo", sin importar su nombre. Esto
     * también resuelve genéricamente el módulo `compras` (antes devolvía `[]` siempre, hallazgo
     * MEDIO de Pre-QA) sin necesitar un caso especial por módulo.
     *
     * @return list<string>
     */
    private function teamEmails(string $module): array
    {
        $roleIds = RoleModuleVisibility::where('module', $module)
            ->where('can_view', '>=', RoleModuleVisibility::VIEW_FULL)
            ->pluck('role_id');

        /** @var list<string> $emails */
        $emails = User::whereIn('role_id', $roleIds)
            ->pluck('email')
            ->filter()
            ->values()
            ->all();

        return $emails;
    }

    private function parseDate(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function resolveUser(Request $request): ?User
    {
        /** @var array{sub?: int}|null $payload */
        $payload = $request->attributes->get('jwt');

        if ($payload === null || ! isset($payload['sub'])) {
            return null;
        }

        return User::find((int) $payload['sub']);
    }
}
