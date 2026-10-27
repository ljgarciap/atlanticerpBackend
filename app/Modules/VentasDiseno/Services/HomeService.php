<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Services;

use App\Models\User;
use App\Modules\Auth\Models\RoleModuleVisibility;
use App\Modules\Integrations\Outlook\Services\OutlookCalendarService;
use App\Modules\VentasDiseno\Models\PipelineCard;
use App\Modules\VentasDiseno\Models\SalesGoal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Pantalla Inicio (REQ-056 a REQ-062), Grupo 3. Ver memory/
 * project_backlog_ventas_diseno_req.md — REQ-058 (calendario) tiene su propio
 * tratamiento acordado con Luis (2026-07-13). REQ-057 (Mi desempeño) quedó de shell
 * hasta que Cotización/Reportes (Grupo 4) existieran; con CommissionService y
 * SalesGoal ya construidos, este servicio los reusa en vez de shell (SCRUM-65,
 * 2026-07-09).
 */
final class HomeService
{

    /**
     * REQ-059: umbral propio, más corto que el de "estancado" (15 días, REQ-004) —
     * son señales distintas mostradas en tarjetas distintas de la misma pantalla, el
     * solape es intencional (una tarjeta puede aparecer en ambas). Sin entidad
     * PENDIENTE nueva ni creación manual — decisión de Luis 2026-07-13: solo señales
     * derivadas de datos ya existentes.
     */
    private const NO_CONTACT_THRESHOLD_DAYS = 7;

    private const NO_CONTACT_HIGH_PRIORITY_DAYS = 14;

    public function __construct(
        private readonly PipelineService $pipeline,
        private readonly SalesTotalsService $totals,
        private readonly OutlookCalendarService $outlook,
        private readonly \App\Modules\AdminCont\Services\CommissionOrderService $internalCommissions,
    ) {}

    /**
     * Selector "ver a un vendedor puntual" del Inicio (alcance Equipo). Mismo hallazgo/fix que
     * `vendorEmails()`/`OutlookCalendarController::teamEmails()` (2026-07-21, Pre-QA): el rol
     * legado `user_roles.role='designer'` no incluye a los vendedores/diseñadores reales — se
     * deriva de `RoleModuleVisibility` en su lugar. A diferencia del "equipo completo" del
     * calendario (que incluye a Gerencia, para que su propio calendario cuente), este selector
     * solo debe ofrecer personas para "ver A", no a Gerencia mirándose a sí misma — se filtra
     * `can_view_team=false` (el rol que NO supervisa, el que es supervisado).
     *
     * @return Collection<int, array{id: int, name: string}>
     */
    public function vendors(): Collection
    {
        $roleIds = RoleModuleVisibility::where('module', 'ventas_diseno')
            ->where('can_view', '>=', RoleModuleVisibility::VIEW_FULL)
            ->where('can_view_team', false)
            ->pluck('role_id');

        return User::whereIn('role_id', $roleIds)
            ->orderBy('first_name')
            ->get()
            ->map(fn (User $u) => ['id' => $u->id, 'name' => trim("{$u->first_name} {$u->last_name}")])
            ->values();
    }

    /**
     * @param array{scope?: string, owner_id?: int} $filters
     * @return array{final_stage: list<array<string, mixed>>, pendientes: list<array<string, mixed>>, events_today_count: int, performance: array{goal_amount: float|null, current_amount: float, progress_percent: float|null, commission_amount: float}}
     */
    public function summary(User $user, array $filters = []): array
    {
        $cards = $this->pipeline->list($user, $filters);
        $cards->load('contacts');

        $activeCards = $cards->whereIn('stage', PipelineStages::open());

        return [
            'final_stage'         => $this->finalStage($cards),
            'pendientes'          => $this->pendientes($activeCards),
            'events_today_count'  => $this->eventsTodayCount($user, $filters),
            'performance'         => $this->performance($user, $filters),
        ];
    }

    /**
     * REQ-057/SCRUM-64: "Mi desempeño" sigue el mismo alcance Mío/Equipo que el
     * resto de la pantalla — si Gerencia elige un vendedor específico en el
     * selector, la meta/comisión mostrada es la de ese vendedor, no la propia
     * (mismo mecanismo de resolución de owner_id que ya usan pipeline->list() y
     * eventsTodayCount(), reutilizado acá en vez de duplicarlo). "current_amount"
     * sigue siendo el total Aprobado del mes en curso (SalesTotalsService, para la
     * barra de progreso contra la meta) — pero "commission_amount" ("Comisión
     * generada") ya NO se recalcula acá: antes aplicaba la tabla escalonada solo
     * sobre el mes en curso (ignorando arrastre de meses previos sin cobrar y
     * notas de crédito), quedando desalineado de Comisiones Internas (SCRUM-910,
     * casos reales de UAT). Ahora reusa la misma fuente de verdad que Comisiones
     * Internas: Ya pagada + Por pagar + Pendiente de cobro
     * (CommissionOrderService::summaryForVendor(), App\Modules\AdminCont).
     *
     * @param array{scope?: string, owner_id?: int} $filters
     * @return array{goal_amount: float|null, current_amount: float, progress_percent: float|null, commission_amount: float}
     */
    private function performance(User $user, array $filters): array
    {
        $target = $this->resolveTargetUser($user, $filters);

        $monthStart = CarbonImmutable::now()->startOfMonth();
        $currentAmount = $this->totals->monthlyApprovedTotal($target, $monthStart);

        $commissionSummary = $this->internalCommissions->summaryForVendor($target, $monthStart);
        $commissionAmount  = round(
            $commissionSummary['pagada'] + $commissionSummary['por_pagar'] + $commissionSummary['pendiente_cobro'],
            2
        );

        $goal = SalesGoal::where('user_id', $target->id)->first();
        $goalAmount = $goal?->goal_amount;

        return [
            'goal_amount'        => $goalAmount !== null ? round($goalAmount, 2) : null,
            'current_amount'     => round($currentAmount, 2),
            'progress_percent'   => $goalAmount !== null && $goalAmount > 0
                ? round(min(100.0, $currentAmount / $goalAmount * 100), 1)
                : null,
            'commission_amount'  => $commissionAmount,
        ];
    }

    /**
     * REQ-060: "próximo paso" no existe como campo en el modelo de datos actual — se
     * omite (solo se muestran las notas/observations ya existentes), simplificación
     * documentada, no bloqueante para el resto del ticket.
     *
     * @param Collection<int, PipelineCard> $cards
     * @return list<array<string, mixed>>
     */
    private function finalStage(Collection $cards): array
    {
        return array_values($cards->whereIn('stage', PipelineStages::openAtOrAfter(PipelineStages::PROPOSAL))->map(fn (PipelineCard $c) => [
            'id'             => $c->id,
            'project_name'   => $c->salesProject->name,
            'client_name'    => $c->subClient->business_name ?? $c->masterClient->name ?? null,
            'notes'          => $c->observations,
            // SCRUM-68 — el modal de detalle necesita etapa/días/valor, no solo Subcliente y
            // Observaciones (todos los items de esta lista ya están en "proposal" por el filtro
            // de arriba, así que la etapa es fija).
            'days_in_stage'  => $c->daysInStage(),
            'amount'         => $c->amount,
        ])->all());
    }

    /**
     * @param Collection<int, PipelineCard> $activeCards
     * @return list<array<string, mixed>>
     */
    private function pendientes(Collection $activeCards): array
    {
        $items = collect();

        foreach ($activeCards as $card) {
            $days = $card->daysInStage();

            if ($days > self::NO_CONTACT_THRESHOLD_DAYS) {
                $items->push([
                    'type'       => 'no_contact',
                    'priority'   => $days > self::NO_CONTACT_HIGH_PRIORITY_DAYS ? 'high' : 'medium',
                    'card_id'    => $card->id,
                    'client'     => $card->subClient->business_name ?? $card->masterClient->name ?? null,
                    'project'    => $card->salesProject->name,
                    'assignee'   => trim("{$card->owner->first_name} {$card->owner->last_name}"),
                    'days'       => $days,
                    'suggestion' => 'Priorizar el contacto con el cliente cuanto antes.',
                ]);
            }

            if (! $card->hasArchitectContact()) {
                $items->push([
                    'type'       => 'missing_architect',
                    'priority'   => 'medium',
                    'card_id'    => $card->id,
                    'client'     => $card->subClient->business_name ?? $card->masterClient->name ?? null,
                    'project'    => $card->salesProject->name,
                    'assignee'   => trim("{$card->owner->first_name} {$card->owner->last_name}"),
                    'days'       => $days,
                    'suggestion' => 'Solicitar el contacto del arquitecto a cargo del proyecto.',
                ]);
            }
        }

        return array_values($items->sortByDesc(fn (array $i) => $i['priority'] === 'high' ? 1 : 0)->all());
    }

    /**
     * SCRUM-66 (2026-07-21) — desde que el calendario lee Outlook real, este conteo se mueve de
     * `calendar_events` (tabla local, sin uso desde este cambio) a OutlookCalendarService, mismo
     * mecanismo de resolución Mío/Equipo/vendedor puntual que el resto de la pantalla.
     *
     * @param array{scope?: string, owner_id?: int} $filters
     */
    private function eventsTodayCount(User $user, array $filters): int
    {
        // ADR-006, Fase C Task 4 — canViewTeamV2() reemplaza hasRole(MANAGEMENT/SUPERADMIN)
        $canSeeTeam = $user->canViewTeamV2('ventas_diseno');
        $scope      = $filters['scope'] ?? 'own';

        if ($scope !== 'team' || ! $canSeeTeam) {
            $emails = array_values(array_filter([$user->email]));
        } elseif (isset($filters['owner_id'])) {
            $target = User::find($filters['owner_id']);
            $emails = $target !== null && $target->email !== null ? [$target->email] : [];
        } else {
            $emails = $this->vendorEmails();
        }

        if ($emails === []) {
            return 0;
        }

        $today  = CarbonImmutable::now();
        $result = $this->outlook->eventsForUsers($emails, $today->startOfDay(), $today->endOfDay());

        return count($result['events']);
    }

    /**
     * Mismo fix que OutlookCalendarController::teamEmails() (2026-07-21, hallazgo de Luis en
     * validación): el rol legado `user_roles.role = 'designer'` solo lo tienen cuentas demo — los
     * vendedores/diseñadores reales están en el `role_id` nuevo ("Vendedor/Diseñador"). Se deriva
     * de `RoleModuleVisibility` (misma fuente que ya usa `canViewTeamV2()`) en vez del rol legado.
     *
     * @return list<string>
     */
    private function vendorEmails(): array
    {
        $roleIds = RoleModuleVisibility::where('module', 'ventas_diseno')
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

    /**
     * Mismo mecanismo de resolución que pipeline->list() (PipelineService::list) y
     * eventsTodayCount(): dentro del alcance "Equipo" con permiso, un owner_id
     * explícito acota a ese vendedor específico; en cualquier otro caso (alcance
     * "Mío", sin permiso de equipo, o "Equipo completo" sin vendedor elegido) el
     * objetivo es el propio usuario autenticado.
     *
     * @param array{scope?: string, owner_id?: int} $filters
     */
    private function resolveTargetUser(User $user, array $filters): User
    {
        $canSeeTeam = $user->canViewTeamV2('ventas_diseno');
        $scope      = $filters['scope'] ?? 'own';

        if ($scope === 'team' && $canSeeTeam && isset($filters['owner_id'])) {
            return User::find($filters['owner_id']) ?? $user;
        }

        return $user;
    }
}
