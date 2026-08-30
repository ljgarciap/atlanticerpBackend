<?php

declare(strict_types=1);

namespace App\Modules\CRM\Observers;

use App\Modules\CRM\Events\ModelChangeDetected;
use Illuminate\Database\Eloquent\Model;

/**
 * Se registra únicamente sobre los modelos de NotifiableModel::REGISTRY (ver
 * AppServiceProvider::boot()) — nunca globalmente. Traduce created/updated de Eloquent en
 * el evento interno que EvaluateNotificationRulesListener consume.
 *
 * Sin estado por constructor a propósito: Model::observe() siempre resuelve el observer por
 * class-string vía el container cuando el evento realmente dispara (incluso si se le pasa
 * una instancia a observe()), así que un constructor con un argumento obligatorio como
 * $modelName rompe con "Unresolvable dependency". class_basename($model) ya da exactamente
 * la clave de NotifiableModel::REGISTRY ('Project', 'ProjectActivity', 'ProjectDocument'),
 * así que una única instancia sin estado sirve para los 3 modelos.
 */
final class NotificationRuleObserver
{
    public function created(Model $model): void
    {
        event(new ModelChangeDetected(class_basename($model), 'created', $model));
    }

    public function updated(Model $model): void
    {
        event(new ModelChangeDetected(class_basename($model), 'updated', $model));
    }
}
