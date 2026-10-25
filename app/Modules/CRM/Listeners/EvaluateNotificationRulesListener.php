<?php

declare(strict_types=1);

namespace App\Modules\CRM\Listeners;

use App\Models\User;
use App\Modules\CRM\Events\ModelChangeDetected;
use App\Modules\CRM\Models\NotificationRule;
use App\Modules\CRM\Models\Project;
use App\Modules\CRM\Models\ProjectActivity;
use App\Modules\CRM\Models\ProjectDocument;
use App\Modules\CRM\Notifications\ConditionEvaluator;
use App\Modules\Notifications\Models\NotificationSend;
use App\Modules\Notifications\Services\NotificationService;
use Illuminate\Database\Eloquent\Model;

/**
 * Consume ModelChangeDetected (despachado por NotificationRuleObserver), evalúa las
 * notification_rules activas para ese modelo+evento, resuelve la audiencia, y llama al
 * módulo genérico Notifications. Este listener es el único punto donde CRM "sabe" cómo
 * traducir su dominio (proyectos, roles, asignados) a la audiencia genérica que
 * NotificationService espera.
 */
final class EvaluateNotificationRulesListener
{
    public function __construct(private readonly NotificationService $notifications)
    {
    }

    public function handle(ModelChangeDetected $event): void
    {
        $rules = NotificationRule::query()
            ->where('is_active', true)
            ->where('trigger_type', NotificationRule::TRIGGER_MODEL_EVENT)
            ->where('trigger_model', $event->modelName)
            ->where('trigger_event', $event->event)
            ->get();

        if ($rules->isEmpty()) {
            return;
        }

        $actorId = $this->currentActorId();

        foreach ($rules as $rule) {
            if (! ConditionEvaluator::matches($event->model, $rule->field, $rule->operator, $rule->value)) {
                continue;
            }

            $audience = $this->resolveAudience($rule, $event->model, $actorId);

            if ($audience === []) {
                continue;
            }

            $this->notifications->send(
                audience: $audience,
                content: $this->buildContent($rule, $event->model),
                channels: $rule->channels,
                source: NotificationSend::SOURCE_CRM_RULE,
                sourceRef: "notification_rule:{$rule->id}",
            );
        }
    }

    /** @return list<int> */
    private function resolveAudience(NotificationRule $rule, Model $model, ?int $actorId): array
    {
        $recipientValue = is_array($rule->recipient_value) ? array_values($rule->recipient_value) : [];

        $ids = match ($rule->recipient_type) {
            NotificationRule::RECIPIENT_USER              => array_map('intval', $recipientValue),
            NotificationRule::RECIPIENT_ROLE               => $this->userIdsByRole($recipientValue),
            NotificationRule::RECIPIENT_PROJECT_ASSIGNEES => $this->projectAssigneeIds($model),
            default                                        => [],
        };

        return array_values(array_filter($ids, fn (int $id): bool => $id !== $actorId));
    }

    /**
     * @param list<mixed> $roles
     * @return list<int>
     */
    private function userIdsByRole(array $roles): array
    {
        if ($roles === []) {
            return [];
        }

        /** @var list<int> $ids */
        $ids = User::query()
            ->withRoleKey($roles)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();

        return $ids;
    }

    /** @return list<int> */
    private function projectAssigneeIds(Model $model): array
    {
        $project = $this->resolveProject($model);

        if ($project === null) {
            return [];
        }

        /** @var list<int> $ids */
        $ids = $project->assignees()->pluck('users.id')->map(fn ($id) => (int) $id)->values()->all();

        return $ids;
    }

    /**
     * $model solo puede ser una de las 3 clases de NotifiableModel::REGISTRY — instanceof
     * explícito en vez de method_exists()/acceso dinámico, que PHPStan no puede verificar
     * sobre el tipo base Model.
     */
    private function resolveProject(Model $model): ?Project
    {
        return match (true) {
            $model instanceof Project         => $model,
            $model instanceof ProjectActivity => $model->project,
            $model instanceof ProjectDocument => $model->project,
            default                           => null,
        };
    }

    /**
     * El contenido no pasa por trans(): una notificación se genera en el request del actor
     * que disparó el cambio, pero el destinatario puede tener otro idioma preferido — no hay
     * forma correcta de elegir el locale acá. El "body" es el nombre de la regla (que el
     * admin ya escribe en el idioma que quiera) + el nombre del proyecto, sin traducción.
     *
     * @return array{subject: string, body: string, cta_url?: string}
     */
    private function buildContent(NotificationRule $rule, Model $model): array
    {
        $project = $this->resolveProject($model);
        // SCRUM-1170 — antes: URL ABSOLUTA a `/crm/proyectos/{id}`, una ruta que no existe en el frontend (la real es `/crm/projects`, una lista sin
        // detalle por id; el proyecto de la regla es el modelo legacy de CRM, no la tarjeta del Pipeline que muestra esa lista). Ahora una ruta INTERNA
        // y válida, como el resto de las notificaciones: la campana la abre con navigate() y el correo la completa con la URL del frontend
        // (`NotificationMail::absoluteCtaUrl`). Sin reglas en prod hoy; si se retoma la función, el detalle por id es un trabajo propio.
        $ctaUrl = $project !== null ? '/crm/projects' : null;

        $body = $project !== null ? "{$rule->name} — {$project->nombre}" : $rule->name;

        return array_filter([
            'subject' => $rule->name,
            'body'    => $body,
            'cta_url' => $ctaUrl,
        ], fn ($v) => $v !== null);
    }

    private function currentActorId(): ?int
    {
        try {
            /** @var array{sub?: int}|null $payload */
            $payload = request()->attributes->get('jwt');

            return isset($payload['sub']) ? (int) $payload['sub'] : null;
        } catch (\Throwable) {
            return null; // fuera de un contexto HTTP (job, comando)
        }
    }
}
