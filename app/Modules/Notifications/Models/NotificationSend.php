<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * "Algo para entregar" — contenido + audiencia + canal(es) + programación opcional.
 * Disparado por el motor de reglas de CRM (Fase 2), un reporte programado (Fase 3),
 * o un admin componiendo una circular manual (Fase 3). Este módulo no distingue el
 * origen más allá de trazabilidad (`source`/`source_ref`).
 *
 * @property int                     $id
 * @property string                   $source
 * @property string|null               $source_ref
 * @property array<string, mixed>       $content
 * @property list<int>                   $audience
 * @property list<string>                 $channels
 * @property \Carbon\Carbon|null            $scheduled_at
 * @property \Carbon\Carbon|null             $sent_at
 * @property int|null                         $created_by
 * @property \Carbon\Carbon                    $created_at
 * @property \Carbon\Carbon                     $updated_at
 */
class NotificationSend extends Model
{
    public const SOURCE_CRM_RULE            = 'crm_rule';
    public const SOURCE_CRM_SCHEDULED_REPORT = 'crm_scheduled_report';
    public const SOURCE_MANUAL_CIRCULAR      = 'manual_circular';
    // SCRUM-329 Batch A1 — alerta de triage manual cuando un Order se crea sin assistant_id
    // (WarehouseAssistantMapping faltante para el vendedor). Ver OrderService::createFromApprovedQuote().
    public const SOURCE_BODEGA_ORDER_UNASSIGNED = 'bodega_order_unassigned';

    /**
     * SCRUM-329 Oleada A / Batch A2 (REQ-318 RN2/RN3, REQ-325) — un pedido queda "sin stock" tras
     * una Revisión de Inventario (ningún hallazgo, o mixto) o una entrega parcial en Packing.
     * Notifica a Compras (para reordenar) y a Ventas & Diseño (para informar al cliente).
     */
    public const SOURCE_BODEGA_ORDER_SHORTAGE = 'bodega_order_shortage';

    /** SCRUM-329 Oleada A / Batch A2 (REQ-325) — Packing guardó una entrega, Administración factura. */
    public const SOURCE_BODEGA_ORDER_READY_TO_INVOICE = 'bodega_order_ready_to_invoice';

    /**
     * SCRUM-406 RN2 (ADR docs/adr/ADR-SCRUM406-compras-bodega-stock-bridge.md) — el contrario de
     * `SOURCE_BODEGA_ORDER_SHORTAGE`: el stock que faltaba ya llegó y el pedido vuelve al flujo
     * normal de picking. Avisa al asistente de Bodega asignado (retomar el pedido) y, si el
     * pedido nació de una tarjeta de Pipeline, a su dueño en Ventas & Diseño (informar al cliente).
     */
    public const SOURCE_BODEGA_ORDER_STOCK_RELEASED = 'bodega_order_stock_released';

    /**
     * SCRUM-445 (REQ-375, Bloque B3 Zona Libre de Colón) — Bodega presiona "Recordar" sobre una
     * solicitud `pendiente`, avisa al rol `lider_compras` (Yirena) para que la resuelva.
     */
    public const SOURCE_BODEGA_ZONA_LIBRE_REMINDER = 'bodega_zona_libre_reminder';

    /**
     * SCRUM-684→689 Batch C (REQ-604 RN5/RN6) — Dashboard CRM, botón "Enviar recordatorios"
     * sobre el aviso de propuestas vencidas. Un send por responsable, con solo sus propios
     * proyectos vencidos — nunca contacta al cliente directamente.
     */
    public const SOURCE_CRM_DASHBOARD_PROPOSAL_REMINDER = 'crm_dashboard_proposal_due';

    public const CHANNEL_IN_APP = 'in_app';
    public const CHANNEL_EMAIL  = 'email';

    /** @var list<string> */
    protected $fillable = [
        'source',
        'source_ref',
        'content',
        'audience',
        'channels',
        'scheduled_at',
        'sent_at',
        'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'content'      => 'array',
            'audience'     => 'array',
            'channels'     => 'array',
            'scheduled_at' => 'datetime',
            'sent_at'      => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<Notification, $this> */
    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class);
    }

    /** @return HasMany<NotificationDelivery, $this> */
    public function deliveries(): HasMany
    {
        return $this->hasMany(NotificationDelivery::class);
    }

    public function isPending(): bool
    {
        return $this->sent_at === null;
    }

    public function isDue(): bool
    {
        return $this->isPending() && ($this->scheduled_at === null || $this->scheduled_at->isPast());
    }
}
