<?php

declare(strict_types=1);

namespace App\Modules\CRM\Notifications;

use App\Modules\Bodega\Models\CustomerReturn;
use App\Modules\Bodega\Models\Order;
use App\Modules\Compras\Models\PurchaseOrder;
use App\Modules\Compras\Models\ReplacementRequest;
use App\Modules\CRM\Models\Project;
use App\Modules\CRM\Models\ProjectActivity;
use App\Modules\CRM\Models\ProjectDocument;
use App\Modules\Servicios\Models\InspectionReport;
use App\Modules\Servicios\Models\Ticket;
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
    /** @var array<string, array{class: class-string<Model>, fields: list<string>, modulo: string, events: list<string>}> */
    public const REGISTRY = [
        'Project' => [
            'class'  => Project::class,
            'modulo' => 'crm',
            'events' => ['created', 'updated'],
            'fields' => ['etapa', 'valor', 'fecha_entrega', 'area', 'despacho'],
        ],
        'ProjectActivity' => [
            'class'  => ProjectActivity::class,
            'modulo' => 'crm',
            'events' => ['created', 'updated'],
            'fields' => ['tipo'],
        ],
        'ProjectDocument' => [
            'class'  => ProjectDocument::class,
            'modulo' => 'crm',
            'events' => ['created', 'updated'],
            'fields' => ['categoria'],
        ],
        // Fase 31b — Compras, Bodega y Servicios: tres eventos por módulo (lista corta, a propósito).
        'PurchaseOrder' => [
            'class'  => PurchaseOrder::class,
            'modulo' => 'compras',
            'events' => ['created', 'updated'],
            'fields' => ['status'],
        ],
        'ReplacementRequest' => [
            'class'  => ReplacementRequest::class,
            'modulo' => 'compras',
            'events' => ['created'],
            'fields' => ['status'],
        ],
        'Order' => [
            'class'  => Order::class,
            'modulo' => 'bodega',
            'events' => ['created', 'updated'],
            'fields' => ['stage'],
        ],
        'CustomerReturn' => [
            'class'  => CustomerReturn::class,
            'modulo' => 'bodega',
            'events' => ['created'],
            'fields' => ['status'],
        ],
        'Ticket' => [
            'class'  => Ticket::class,
            'modulo' => 'servicios',
            'events' => ['created', 'updated'],
            'fields' => ['estado', 'tipo'],
        ],
        'InspectionReport' => [
            'class'  => InspectionReport::class,
            'modulo' => 'servicios',
            'events' => ['updated'],
            'fields' => ['estado'],
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

    public static function isEventAllowed(string $modelName, string $event): bool
    {
        return in_array($event, self::REGISTRY[$modelName]['events'] ?? [], true);
    }

    /** Solo los modelos de CRM tienen proyecto del que sacar «asignados». */
    public static function isCrm(string $modelName): bool
    {
        return (self::REGISTRY[$modelName]['modulo'] ?? null) === 'crm';
    }

    /** @return class-string<Model>|null */
    public static function classFor(string $modelName): ?string
    {
        return self::REGISTRY[$modelName]['class'] ?? null;
    }
}
