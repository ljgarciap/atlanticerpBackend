<?php

declare(strict_types=1);

namespace App\Modules\CRM\Events;

use Illuminate\Database\Eloquent\Model;

/**
 * Evento interno (no implementa DomainEvent — no es un evento de dominio público, es la
 * señal que alimenta al motor de reglas de notificaciones). Despachado por
 * NotificationRuleObserver, registrado solo sobre los modelos de
 * App\Modules\CRM\Notifications\NotifiableModel::REGISTRY.
 */
final class ModelChangeDetected
{
    public function __construct(
        public readonly string $modelName, // clave de NotifiableModel::REGISTRY, ej. 'Project'
        public readonly string $event,     // 'created' | 'updated'
        public readonly Model  $model,
    ) {
    }
}
