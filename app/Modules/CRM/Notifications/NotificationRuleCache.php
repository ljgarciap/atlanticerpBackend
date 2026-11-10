<?php

declare(strict_types=1);

namespace App\Modules\CRM\Notifications;

use App\Modules\CRM\Models\NotificationRule;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\Eloquent\Collection;

/**
 * Fase 32 — memo de las reglas de notificación activas por evento de modelo, acotada a la petición (se registra `scoped` en
 * AppServiceProvider: se descarta entre peticiones y entre jobs de la cola, así que un worker nunca ve reglas viejas). Con ocho
 * modelos notificables (31b), cada guardado de una orden, un pedido o un ticket consultaba las reglas; ahora una petición que cambia
 * muchos registros las lee UNA vez. La clave incluye el tenant activo y guardar/borrar una regla la invalida (`forget()`).
 */
final class NotificationRuleCache
{
    /** @var array<string, Collection<int, NotificationRule>> reglas activas de tipo «evento de modelo», por tenant */
    private array $memo = [];

    /** @return Collection<int, NotificationRule> */
    public function activeFor(string $modelName, string $event): Collection
    {
        $tenant = Tenant::current()->slug ?? '-';

        $this->memo[$tenant] ??= NotificationRule::query()
            ->where('is_active', true)
            ->where('trigger_type', NotificationRule::TRIGGER_MODEL_EVENT)
            ->get();

        return $this->memo[$tenant]
            ->filter(fn (NotificationRule $r): bool => $r->trigger_model === $modelName && $r->trigger_event === $event)
            ->values();
    }

    public function forget(): void
    {
        $this->memo = [];
    }
}
