<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Services;

use App\Modules\Notifications\Models\NotificationSend;
use App\Modules\Notifications\Services\NotificationService;
use App\Modules\VentasDiseno\Models\PipelineCard;
use Illuminate\Support\Collection;

/**
 * Dashboard CRM (SCRUM-684→689, REQ-604→609, Batch C) — exclusivo de Gerencia (gate de rol
 * en la ruta, ver routes/ventas-diseno.php), siempre alcance "Equipo" completo, sin selector
 * Mío/Equipo (REQ-609 RN2/RN3: Gerencia no tiene tarjetas propias de vendedor). Lee
 * directamente de PipelineCard, la misma fuente que Pipeline — no es una copia de datos.
 */
final class DashboardService
{
    private const STAGES = ['lead', 'design', 'quote', 'proposal', 'approved', 'lost'];

    private const ACTIVE_STAGES = ['lead', 'design', 'quote', 'proposal'];

    private const TAGS = ['design', 'quote', 'both'];

    public function __construct(private readonly NotificationService $notifications) {}

    /** @return array<string, mixed> */
    public function summary(): array
    {
        /** @var Collection<int, PipelineCard> $cards */
        $cards = PipelineCard::with(['salesProject', 'masterClient'])->get();

        return [
            'alerts'       => $this->alerts($cards),
            'stage_counts' => $this->stageCounts($cards),
            'totals'       => $this->totals($cards),
            'by_tag'       => $this->byTag($cards),
        ];
    }

    /**
     * REQ-604 RN5/RN6: un recordatorio por responsable, con solo sus propios proyectos
     * vencidos — el sistema nunca le escribe al cliente directamente.
     *
     * Hallazgo de Pre-QA (2026-07-31, batch SCRUM-684→689): `source_ref` ya codificaba
     * fecha+responsable con la forma de una clave de idempotencia diaria, pero nada la hacía
     * cumplir — cada clic (doble clic, o simplemente volver a entrar al Dashboard más tarde el
     * mismo día y presionar el botón otra vez) creaba un NotificationSend nuevo y lo entregaba
     * de verdad (email + in-app duplicados al mismo responsable, mismo proyecto). Confirmado en
     * dev.atlanticerp.ai con dos llamadas reales ~20s aparte: 2 filas de email 'sent' idénticas para
     * el mismo owner/proyecto. Fix: no re-enviar si ya existe un send de hoy con el mismo
     * source_ref — un responsable recibe como máximo 1 recordatorio por día por este botón,
     * sin importar cuántas veces Gerencia lo presione.
     *
     * @return array{notified: list<array{owner_id: int, owner_name: string, projects_count: int}>, already_sent_today: list<int>}
     */
    public function remind(int $createdBy): array
    {
        /** @var Collection<int, PipelineCard> $overdue */
        $overdue = $this->overdueProposals(
            PipelineCard::with(['salesProject', 'owner'])->where('stage', 'proposal')->get()
        );

        $notified = [];
        $alreadySentToday = [];
        foreach ($overdue->groupBy('owner_id') as $ownerId => $ownerCards) {
            /** @var Collection<int, PipelineCard> $ownerCards */
            $owner = $ownerCards->firstOrFail()->owner;
            $sourceRef = 'crm_dashboard_proposal_reminder:'.now()->toDateString().':'.$ownerId;

            if (NotificationSend::where('source_ref', $sourceRef)->exists()) {
                $alreadySentToday[] = (int) $ownerId;
                continue;
            }

            $projectNames = $ownerCards->map(fn (PipelineCard $c) => $c->salesProject->name)->implode(', ');

            $this->notifications->send(
                audience: [(int) $ownerId],
                content: [
                    'subject' => 'Recordatorio: propuestas vencidas pendientes de seguimiento',
                    'body'    => "Tenés {$ownerCards->count()} proyecto(s) con propuesta vencida (más de 15 días "
                        ."sin movimiento): {$projectNames}. Da seguimiento con el cliente cuanto antes.",
                ],
                channels: [NotificationSend::CHANNEL_IN_APP, NotificationSend::CHANNEL_EMAIL],
                source: NotificationSend::SOURCE_CRM_DASHBOARD_PROPOSAL_REMINDER,
                sourceRef: $sourceRef,
                createdBy: $createdBy,
            );

            $notified[] = [
                'owner_id'       => (int) $ownerId,
                'owner_name'     => trim("{$owner->first_name} {$owner->last_name}"),
                'projects_count' => $ownerCards->count(),
            ];
        }

        return ['notified' => $notified, 'already_sent_today' => $alreadySentToday];
    }

    /**
     * SCRUM-796 (secc. 3/4) — la lista de cada alerta pasa de `list<string>` a
     * `list<array{...}>` con `card_id` para que el frontend pueda navegar a
     * `/ventas-diseno/pipeline?card=<id>` (mecanismo ya existente, ver
     * PipelinePage.tsx REQ-022/065). Clave `items` en ambas alertas (antes
     * `projects`/`clients`) — mismo shape para las dos, consumido por el mismo
     * componente de modal en el frontend (DashboardAlertListModal). Ordenados
     * por `days` descendente — el más urgente primero — para que el frontend
     * pueda mostrar "los 3 principales" con un simple `slice(0, 3)`, sin lógica
     * de orden propia.
     *
     * @param Collection<int, PipelineCard> $cards
     * @return array{
     *   overdue_proposals: array{count: int, items: list<array{card_id: int, project_name: string, client_name: string|null, days: int}>}|null,
     *   cold_clients: array{count: int, items: list<array{card_id: int, project_name: string, client_name: string|null, days: int}>}|null,
     * }
     */
    private function alerts(Collection $cards): array
    {
        $overdue = $this->overdueProposals($cards);
        $overdueAlert = $overdue->isEmpty() ? null : [
            'count' => $overdue->count(),
            'items' => $this->sortedByDaysDesc($overdue),
        ];

        // RN2: el CONTEO sigue siendo por Cliente Master, una sola vez aunque tenga
        // varias tarjetas estancadas (sin cambios) — pero la LISTA (secc. 4,
        // decisión SCRUM-796) ya no colapsa por cliente: cada tarjeta estancada es
        // su propia fila con su propio card_id, el frontend agrupa visualmente por
        // cliente si quiere. Tarjetas sin Cliente Master (ej. Lead sin vincular) se
        // excluyen — no se pueden atribuir a ningún cliente.
        $staleCards = $cards
            ->whereIn('stage', self::ACTIVE_STAGES)
            ->filter(fn (PipelineCard $c): bool => $c->isStagnant() && $c->masterClient !== null)
            ->values();
        $clientCount = $staleCards->map(fn (PipelineCard $c) => $c->masterClient?->name)->unique()->count();
        $coldAlert = $staleCards->isEmpty() ? null : [
            'count' => $clientCount,
            'items' => $this->sortedByDaysDesc($staleCards),
        ];

        return ['overdue_proposals' => $overdueAlert, 'cold_clients' => $coldAlert];
    }

    /**
     * @param Collection<int, PipelineCard> $cards
     * @return list<array{card_id: int, project_name: string, client_name: string|null, days: int}>
     */
    private function sortedByDaysDesc(Collection $cards): array
    {
        return array_values($cards
            ->map(fn (PipelineCard $c): array => [
                'card_id'      => $c->id,
                'project_name' => $c->salesProject->name,
                'client_name'  => $c->masterClient?->name,
                'days'         => $c->daysInStage(),
            ])
            ->sortByDesc('days')
            ->all());
    }

    /**
     * @param Collection<int, PipelineCard> $cards
     * @return Collection<int, PipelineCard>
     */
    private function overdueProposals(Collection $cards): Collection
    {
        return $cards->where('stage', 'proposal')->filter(fn (PipelineCard $c): bool => $c->isStagnant())->values();
    }

    /**
     * @param Collection<int, PipelineCard> $cards
     * @return array<string, int>
     */
    private function stageCounts(Collection $cards): array
    {
        $counts = [];
        foreach (self::STAGES as $stage) {
            $counts[$stage] = $cards->where('stage', $stage)->count();
        }

        return $counts;
    }

    /**
     * @param Collection<int, PipelineCard> $cards
     * @return array{active_pipeline: float, closed_won: float}
     */
    private function totals(Collection $cards): array
    {
        return [
            'active_pipeline' => (float) $cards->whereIn('stage', self::ACTIVE_STAGES)->sum('amount'),
            'closed_won'      => (float) $cards->where('stage', 'approved')->sum('amount'),
        ];
    }

    /**
     * @param Collection<int, PipelineCard> $cards
     * @return array{tagged: array<string, int>, untagged_count: int}
     */
    private function byTag(Collection $cards): array
    {
        $counts = array_fill_keys(self::TAGS, 0);

        foreach ($cards as $card) {
            $tag = $card->salesProject->tag;
            if (in_array($tag, self::TAGS, true)) {
                $counts[$tag]++;
            }
        }

        $untagged = $cards->count() - array_sum($counts);

        return ['tagged' => $counts, 'untagged_count' => $untagged];
    }
}
