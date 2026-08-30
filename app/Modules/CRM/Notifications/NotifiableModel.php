<?php

declare(strict_types=1);

namespace App\Modules\CRM\Notifications;

use App\Modules\CRM\Models\Project;
use App\Modules\CRM\Models\ProjectActivity;
use App\Modules\CRM\Models\ProjectDocument;
use Illuminate\Database\Eloquent\Model;

/**
 * Whitelist explícita de modelos elegibles como origen de una notification_rule, con sus
 * campos permitidos para condiciones. Existe por dos razones (ver docs/specs/notifications.md
 * § "Salvaguarda de alcance"):
 *
 * 1. Seguridad — sin esta whitelist, una regla podría apuntar a users.security_level_id o
 *    tablas sensibles y filtrar información por email a una lista mal configurada.
 * 2. Performance — el Observer solo se registra sobre estos 3 modelos, no sobre cada
 *    Eloquent model de la app.
 *
 * Agregar un modelo nuevo (ej. Case cuando exista Fase 4 — Installations) es agregar una
 * entrada acá + su Observer — las reglas sobre modelos ya habilitados siguen siendo 100%
 * sin código.
 */
final class NotifiableModel
{
    /** @var array<string, array{class: class-string<Model>, fields: list<string>}> */
    public const REGISTRY = [
        'Project' => [
            'class'  => Project::class,
            'fields' => ['etapa', 'valor', 'fecha_entrega', 'area', 'despacho'],
        ],
        'ProjectActivity' => [
            'class'  => ProjectActivity::class,
            'fields' => ['tipo'],
        ],
        'ProjectDocument' => [
            'class'  => ProjectDocument::class,
            'fields' => ['categoria'],
        ],
    ];

    public static function isWhitelisted(string $modelName): bool
    {
        return isset(self::REGISTRY[$modelName]);
    }

    public static function isFieldAllowed(string $modelName, string $field): bool
    {
        return in_array($field, self::REGISTRY[$modelName]['fields'] ?? [], true);
    }

    /** @return class-string<Model>|null */
    public static function classFor(string $modelName): ?string
    {
        return self::REGISTRY[$modelName]['class'] ?? null;
    }
}
