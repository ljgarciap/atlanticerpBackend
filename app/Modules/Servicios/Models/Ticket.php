<?php

declare(strict_types=1);

namespace App\Modules\Servicios\Models;

use App\Models\User;
use App\Modules\VentasDiseno\Models\SalesProject;
use App\Shared\Multitenancy\Tenant;
use App\Shared\Support\LogsAuditTrail;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Fase 4 — Servicios (docs/architecture/servicios-fase4-diseno.md, sección 4). Batch 1
 * (REQ-216→221, REQ-249) — ticket de visita técnica (instalación/garantía/reclamo/retrofit).
 *
 * `quote_status`/`inspection_report_status` son denormalizados a propósito — ver docblock de la
 * migración `2026_08_02_100001_create_servicios_tickets_table.php`: Cotización (Batch 11-12) e
 * Informe de Inspección (Batch 8-9) todavía no existen como módulos reales. Se derivan al crear
 * (`TicketService::deriveInitialStatuses()`, REQ-249 RN2) y las actualizará el módulo real
 * correspondiente vía evento de dominio cuando exista.
 *
 * @property int         $id
 * @property string      $numero
 * @property string      $tipo
 * @property string|null $subtipo
 * @property string      $tipo_instalacion
 * @property string      $descripcion
 * @property int|null    $sales_project_id
 * @property string|null $cliente
 * @property string|null $contacto
 * @property string|null $telefono
 * @property string|null $email
 * @property string|null $direccion
 * @property array{catalog?: list<string>, otros?: list<string>}|null $requerimientos_especiales
 * @property string|null $observaciones
 * @property string      $estado
 * @property string|null $cancellation_reason
 * @property string      $quote_status
 * @property string      $inspection_report_status
 * @property int|null    $internal_technician_id
 * @property \Carbon\Carbon|null $scheduled_at
 * @property \Carbon\Carbon|null $scheduled_ends_at
 * @property int          $visit_count
 * @property \Carbon\Carbon|null $closed_at
 * @property int|null    $created_by
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 * @property-read SalesProject|null $salesProject
 * @property-read User|null $creator
 * @property-read User|null $internalTechnician
 * @property-read InspectionReport|null $inspectionReport
 */
class Ticket extends Model
{
    use LogsAuditTrail;

    public const TIPO_INSTALLATION = 'installation';

    public const TIPO_WARRANTY = 'warranty';

    public const TIPO_CLAIM = 'claim';

    public const TIPO_RETROFIT = 'retrofit';

    public const SUBTIPO_INSTALLATION = 'installation';

    public const SUBTIPO_INSPECTION = 'inspection';

    public const SUBTIPO_WARRANTY_GENERIC = 'warranty_generic';

    public const SUBTIPO_REPLACEMENT_INSPECTION = 'replacement_inspection';

    public const TIPO_INSTALACION_INTERNAL = 'internal';

    public const TIPO_INSTALACION_SUBCONTRACTED = 'subcontracted';

    /** @var list<string> */
    public const TIPOS_INSTALACION = [self::TIPO_INSTALACION_INTERNAL, self::TIPO_INSTALACION_SUBCONTRACTED];

    public const ESTADO_REPORTED = 'reported';

    public const ESTADO_SCHEDULED = 'scheduled';

    public const ESTADO_ON_SITE = 'on_site';

    public const ESTADO_RESOLVED = 'resolved';

    public const ESTADO_CLOSED = 'closed';

    public const ESTADO_CANCELLED = 'cancelled';

    /** @var list<string> */
    public const ESTADOS = [
        self::ESTADO_REPORTED, self::ESTADO_SCHEDULED, self::ESTADO_ON_SITE,
        self::ESTADO_RESOLVED, self::ESTADO_CLOSED, self::ESTADO_CANCELLED,
    ];

    /** REQ-218 RN1/RN6 — solo estos 2 destinos disparan el gate de cotización/informe. */
    public const ESTADOS_REQUIRING_CLOSE_GATE = [self::ESTADO_RESOLVED, self::ESTADO_CLOSED];

    public const QUOTE_STATUS_NOT_APPLICABLE = 'not_applicable';

    public const QUOTE_STATUS_PENDING = 'pending';

    public const QUOTE_STATUS_DRAFT = 'draft';

    public const QUOTE_STATUS_SENT = 'sent';

    public const QUOTE_STATUS_APPROVED = 'approved';

    public const QUOTE_STATUS_REJECTED = 'rejected';

    /** @var list<string> */
    public const QUOTE_STATUSES = [
        self::QUOTE_STATUS_NOT_APPLICABLE, self::QUOTE_STATUS_PENDING, self::QUOTE_STATUS_DRAFT,
        self::QUOTE_STATUS_SENT, self::QUOTE_STATUS_APPROVED, self::QUOTE_STATUS_REJECTED,
    ];

    /** REQ-218 RN1(b) — el ticket puede cerrar con la cotización en uno de estos 2 estados. */
    public const QUOTE_STATUSES_ALLOWING_CLOSE = [self::QUOTE_STATUS_APPROVED, self::QUOTE_STATUS_NOT_APPLICABLE];

    public const INSPECTION_STATUS_NOT_APPLICABLE = 'not_applicable';

    public const INSPECTION_STATUS_PENDING = 'pending';

    public const INSPECTION_STATUS_COMPLETED = 'completed';

    /** @var list<string> */
    public const INSPECTION_STATUSES = [
        self::INSPECTION_STATUS_NOT_APPLICABLE, self::INSPECTION_STATUS_PENDING, self::INSPECTION_STATUS_COMPLETED,
    ];

    /** REQ-218 RN1(a) — el ticket puede cerrar con el informe en uno de estos 2 estados. */
    public const INSPECTION_STATUSES_ALLOWING_CLOSE = [self::INSPECTION_STATUS_COMPLETED, self::INSPECTION_STATUS_NOT_APPLICABLE];

    /**
     * REQ-247 RN1 — checklist fijo de 18 requerimientos especiales (seguridad personal, trabajo
     * en altura, acceso y energía, equipo y logística), mismo catálogo usado en toda la
     * aplicación. Extraído del mockup real (`REQ_LABELS` en `5A__Servicios_Tickets.html`) — no
     * inventar claves nuevas, esta es la fuente de verdad tanto para validar
     * `requerimientos_especiales.catalog.*` (StoreTicketRequest/UpdateTicketRequest) como para
     * traducir las claves a español en el PDF (TicketPdfService, el backend no tiene
     * infraestructura de i18n de dominio). El frontend mantiene su propia copia de las etiquetas
     * en `i18n/locales/{es,en}/servicios.json` — mismo criterio ya usado para TIPO_LABELS/
     * SUBTIPO_LABELS.
     *
     * @var array<string, string>
     */
    public const REQUIREMENT_LABELS = [
        'casco'               => 'Casco de seguridad',
        'botas'                => 'Botas de seguridad',
        'guantes'              => 'Guantes de seguridad',
        'gafas'                => 'Gafas de protección',
        'chaleco'              => 'Chaleco reflectivo',
        'arnes'                => 'Arnés de seguridad',
        'escaleraEstandar'     => 'Escalera estándar',
        'escaleraAltura'       => 'Escalera doble altura',
        'andamio'              => 'Andamio',
        'plataforma'           => 'Plataforma elevadora',
        'permisoAltura'        => 'Permiso de trabajo en altura',
        'corteEnergia'         => 'Corte de energía previo (LOTO)',
        'accesoEdificio'       => 'Autorización de acceso al edificio',
        'estacionamiento'      => 'Permiso de estacionamiento',
        'horarioRestringido'   => 'Horario restringido',
        'herramientaEsp'       => 'Herramienta especializada',
        'dosTecnicos'          => 'Requiere 2 técnicos',
        'induccionSeguridad'   => 'Inducción de seguridad del cliente',
    ];

    /** @var list<string> */
    protected $fillable = [
        'numero', 'tipo', 'subtipo', 'tipo_instalacion', 'descripcion',
        'sales_project_id', 'cliente', 'contacto', 'telefono', 'email', 'direccion',
        'requerimientos_especiales', 'observaciones', 'estado', 'cancellation_reason', 'quote_status', 'inspection_report_status',
        'internal_technician_id', 'scheduled_at', 'scheduled_ends_at', 'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'scheduled_at'               => 'datetime',
            'scheduled_ends_at'          => 'datetime',
            'closed_at'                  => 'datetime',
            'requerimientos_especiales'  => 'array',
        ];
    }

    public function getTable(): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return 'tickets';
        }

        return "{$tenant->slug}_servicios.tickets";
    }

    /** @return BelongsTo<SalesProject, $this> */
    public function salesProject(): BelongsTo
    {
        return $this->belongsTo(SalesProject::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<TicketProduct, $this> */
    public function ticketProducts(): HasMany
    {
        return $this->hasMany(TicketProduct::class);
    }

    /** @return HasMany<TicketAttachment, $this> */
    public function attachments(): HasMany
    {
        return $this->hasMany(TicketAttachment::class);
    }

    /**
     * SCRUM-804 — historial de reagendamientos, más reciente primero.
     *
     * @return HasMany<TicketRescheduleHistory, $this>
     */
    public function rescheduleHistory(): HasMany
    {
        return $this->hasMany(TicketRescheduleHistory::class)->orderByDesc('created_at');
    }

    /**
     * Batch 2 — corrige un gap preexistente de Batch 1: el frontend (`Ticket.internal_technician`,
     * `types/servicios.ts`) ya esperaba este objeto anidado desde SCRUM-279→284, pero el backend
     * nunca lo servía (solo el id crudo) — el nombre del técnico nunca se mostró en tabla/tablero.
     * Nombre de método en camelCase a propósito: Eloquent serializa relaciones eager-loaded con la
     * clave snake_case correspondiente (`internal_technician`), el contrato exacto que el frontend
     * ya consume.
     *
     * @return BelongsTo<User, $this>
     */
    public function internalTechnician(): BelongsTo
    {
        return $this->belongsTo(User::class, 'internal_technician_id')->select(['id', 'first_name', 'last_name']);
    }

    /**
     * Batch 8 (REQ-238) — un ticket tiene como máximo un Informe de Inspección.
     *
     * @return HasOne<InspectionReport, $this>
     */
    public function inspectionReport(): HasOne
    {
        return $this->hasOne(InspectionReport::class);
    }

    /**
     * Batch 9 (REQ-278) — contraparte de `inspectionReport()` para tickets `tipo = claim`.
     *
     * @return HasOne<ClaimSheet, $this>
     */
    public function claimSheet(): HasOne
    {
        return $this->hasOne(ClaimSheet::class);
    }
}
