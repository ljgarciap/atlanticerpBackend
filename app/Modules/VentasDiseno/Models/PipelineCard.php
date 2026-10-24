<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Models;

use App\Models\User;
use App\Modules\Bodega\Models\Order;
use App\Shared\Multitenancy\Tenant;
use App\Shared\Support\LogsAuditTrail;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Umbral de "estancado" (REQ-004): más de 15 días sin movimiento de etapa.
 * Restricciones de edición por etapa (REQ-008): "lost" nunca es editable (solo
 * reactivar, Grupo 1c); "approved" requiere el permiso
 * `ventas_diseno.edit.approved` — ver PipelineCard::isEditableBy().
 *
 * @property int                                    $id
 * @property int                                     $sales_project_id
 * @property string                                  $stage
 * @property int|null                                $master_client_id
 * @property int|null                                $sub_client_id
 * @property int                                      $owner_id
 * @property int|null                                 $created_by
 * @property int|null                                 $order_approved_by
 * @property \Carbon\Carbon|null                      $order_approved_at
 * @property list<int>|null                           $compartido_con
 * @property float|null                               $amount
 * @property float|null                               $worked_area_m2
 * @property string|null                              $delivery_type
 * @property string|null                              $observations
 * @property \Carbon\Carbon                          $stage_changed_at
 * @property SalesProject                            $salesProject
 * @property MasterClient|null                       $masterClient
 * @property SubClient|null                          $subClient
 * @property User                                     $owner
 * @property User|null                                $creator
 * @property Collection<int, PipelineCardContact>      $contacts
 * @property Collection<int, PipelineCardDeliveryDate> $deliveryDates
 * @property Collection<int, PipelineCardFile>          $files
 * @property Collection<int, PipelineCardProductDelivery> $productDeliveries
 */
class PipelineCard extends Model
{
    use LogsAuditTrail;


    public const NON_EDITABLE_STAGE = 'lost';

    public const RESTRICTED_EDIT_STAGE = 'approved';

    public const RESTRICTED_EDIT_PERMISSION = 'ventas_diseno.edit.approved';

    /** Permiso de ruta de las escrituras del Pipeline (routes/ventas-diseno.php). */
    public const EDIT_PERMISSION = 'ventas_diseno.edit';

    /**
     * SCRUM-1139 — permiso angosto de escritura SOLO del Pipeline (hermano de `ventas_diseno.pipeline.read_all`, SCRUM-1128).
     * Las rutas y controladores de /pipeline lo aceptan como alternativa a `ventas_diseno.edit` y le dan alcance de equipo,
     * pero ninguna otra ruta de Ventas & Diseño (cotizaciones, clientes, pedidos, catálogo) lo consulta.
     */
    public const PIPELINE_EDIT_PERMISSION = 'ventas_diseno.pipeline.edit';

    public static function userHasPipelineEdit(User $user): bool
    {
        return in_array(self::PIPELINE_EDIT_PERMISSION, $user->effectivePermissionsV2(), true);
    }

    /**
     * Archivos que NO se pueden quitar de un proyecto Aprobado, sin excepción ni permiso que lo salte (decisión de Luis
     * 2026-10-06): los de diseño y propuesta son de solo lectura una vez aprobado (REQ-013/015, igual que la subida) y
     * la cotización firmada y el comprobante de aprobación son la evidencia exigida para aprobar (ver los gates de
     * transición): borrarlos dejaría un proyecto aprobado sin su respaldo. Las fotos sí se pueden quitar.
     * Clave = tipo, valor = cómo se nombra en el mensaje al usuario.
     */
    public const APPROVED_LOCKED_FILE_TYPES = [
        'design'         => 'de diseño',
        'proposal'       => 'de propuesta',
        'signed_quote'   => 'de la cotización firmada',
        'approval_proof' => 'del comprobante de aprobación',
    ];

    /** @var list<string> Orden lineal del flujo — usado para bloquear saltos de etapa. */
    private const STAGE_ORDER = ['lead', 'design', 'quote', 'proposal', 'approved'];

    /**
     * Accesor público de solo lectura de STAGE_ORDER — lo usa
     * QuoteService::confirm() (Cotización-D, REQ-048/SCRUM-723) para saber si mover
     * la tarjeta hacia adelante sin duplicar el orden de etapas.
     *
     * @return list<string>
     */
    public static function stageOrder(): array
    {
        return self::STAGE_ORDER;
    }

    /**
     * SCRUM-1143 — ¿`$user` es Responsable de esta tarjeta/proyecto? Dueño (`owner_id`) o responsable adicional
     * (`compartido_con`, REQ-506/SCRUM-849.4). UNA sola definición: la usan el acceso a la tarjeta del Pipeline
     * (`PipelineController::canAccess`, que decide quién puede ver/mover) y la lectura de las versiones de cotización del
     * proyecto (`QuoteController::canView`), para que «puede ver» y «puede mover» no vuelvan a divergir.
     * `projectIdsOwnedBy()` aplica el mismo criterio en SQL.
     */
    public function isResponsible(User $user): bool
    {
        return $this->owner_id === $user->id || in_array($user->id, $this->compartido_con ?? [], true);
    }

    /**
     * SCRUM-818 — extraído de `ReplacementRequestController::ownedSalesProjectIds()` para
     * reusarlo también en `QuoteListService::searchApprovedProjects()` (picker de "Proyecto de
     * venta" filtrado por producto, mismo criterio "responsable del proyecto" en los dos lugares
     * — no duplicar la query). `owner_id` o presente en `compartido_con` (JSON) cuenta como
     * responsable.
     *
     * @return list<int>
     */
    public static function projectIdsOwnedBy(User $user): array
    {
        return self::projectIdsOwnedByUserIds([$user->id]);
    }

    /**
     * SCRUM-1195 — mismo criterio que `projectIdsOwnedBy()` para varias personas a la vez (filtro Vendedor/Diseñador del listado de
     * cotizaciones de Gerencia: «los proyectos de Paola» incluye los que le asignaron, no solo lo que ella generó).
     *
     * @param list<int> $userIds
     * @return list<int>
     */
    public static function projectIdsOwnedByUserIds(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $ids = self::query()
            ->where(function ($q) use ($userIds): void {
                $q->whereIn('owner_id', $userIds);
                foreach ($userIds as $id) {
                    $q->orWhereJsonContains('compartido_con', $id);
                }
            })
            ->whereNotNull('sales_project_id')
            ->pluck('sales_project_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return array_values($ids);
    }

    /** @var list<string> */
    protected $fillable = [
        'sales_project_id', 'stage', 'master_client_id', 'sub_client_id', 'owner_id',
        'created_by', 'order_approved_by', 'order_approved_at', 'amount', 'worked_area_m2', 'delivery_type', 'observations',
        'stage_changed_at', 'compartido_con',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'amount'           => 'float',
            'worked_area_m2'   => 'float',
            'stage_changed_at' => 'datetime',
            'order_approved_at' => 'datetime',
            // REQ-506 (Batch 15) — array de user_id de responsables ADICIONALES al owner_id
            // original. SCRUM-849.4 agregó la UI de asignación real (Gerencia, ver
            // PipelineController::update()/create()) y el split de comisión N-way
            // (SalesTotalsService::monthlyApprovedTotal()) que faltaban en el batch original.
            'compartido_con'   => 'array',
        ];
    }

    public function getTable(): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return 'pipeline_cards';
        }

        return "{$tenant->slug}_ventas_diseno.pipeline_cards";
    }

    /** @return BelongsTo<SalesProject, $this> */
    public function salesProject(): BelongsTo
    {
        return $this->belongsTo(SalesProject::class);
    }

    /** @return BelongsTo<MasterClient, $this> */
    public function masterClient(): BelongsTo
    {
        return $this->belongsTo(MasterClient::class);
    }

    /** @return BelongsTo<SubClient, $this> */
    public function subClient(): BelongsTo
    {
        return $this->belongsTo(SubClient::class);
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * SCRUM-884 — quien creó el proyecto originalmente, inmutable (nunca se toca en
     * PipelineService::update(), a diferencia de owner_id/compartido_con). Nullable solo para
     * tarjetas creadas antes de este campo y aún no backfilleadas
     * (ventas_diseno:backfill-pipeline-card-created-by).
     *
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * SCRUM-1128 — quien confirmó "Pasar a Pedido". Null en tarjetas aprobadas antes de este flujo.
     *
     * @return BelongsTo<User, $this>
     */
    public function orderApprover(): BelongsTo
    {
        return $this->belongsTo(User::class, 'order_approved_by');
    }

    /**
     * SCRUM-1128 — pedidos de Bodega generados desde esta tarjeta (1, o una familia si la entrega
     * es parcial). Su existencia es el estado REAL de "pasó a pedido", no el stage del Pipeline.
     *
     * @return HasMany<Order, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'pipeline_card_id');
    }

    /** @return HasMany<PipelineCardContact, $this> */
    public function contacts(): HasMany
    {
        return $this->hasMany(PipelineCardContact::class);
    }

    /** @return HasMany<PipelineCardDeliveryDate, $this> */
    public function deliveryDates(): HasMany
    {
        return $this->hasMany(PipelineCardDeliveryDate::class);
    }

    /** @return HasMany<PipelineCardFile, $this> */
    public function files(): HasMany
    {
        return $this->hasMany(PipelineCardFile::class);
    }

    /** @return HasMany<PipelineCardProductDelivery, $this> SCRUM-909 */
    public function productDeliveries(): HasMany
    {
        return $this->hasMany(PipelineCardProductDelivery::class);
    }

    public function daysInStage(): int
    {
        return (int) $this->stage_changed_at->diffInDays(now());
    }

    public function isStagnant(): bool
    {
        return $this->daysInStage() > app(\App\Modules\CRM\Services\CrmSettingsService::class)->pipelineEstancadoDias();
    }

    public function hasArchitectContact(): bool
    {
        return $this->contacts->contains('role', 'architect');
    }

    public function isEditableBy(User $user): bool
    {
        if ($this->stage === self::NON_EDITABLE_STAGE) {
            return false;
        }

        // SCRUM-1128 — mismo permiso que exigen las rutas de escritura del Pipeline
        // (`permission:ventas_diseno.edit`, evaluado sobre los permisos del JWT = V2). Sin esto
        // `can_edit` llegaba en true para Félix (solo lectura vía `pipeline.read_all`) y la UI le
        // ofrecía acciones que el backend rechazaba con 403.
        $permissions = $user->effectivePermissionsV2();
        if (
            ! in_array('superadmin.all', $permissions, true)
            && ! in_array(self::EDIT_PERMISSION, $permissions, true)
            && ! in_array(self::PIPELINE_EDIT_PERMISSION, $permissions, true)
        ) {
            return false;
        }

        if ($this->stage === self::RESTRICTED_EDIT_STAGE) {
            return $user->hasPermission(self::RESTRICTED_EDIT_PERMISSION);
        }

        return true;
    }

    /**
     * Gates entre etapas (REQ-011, 014, 016, 017, 018). Devuelve null si la transición
     * es válida, o un mensaje describiendo qué falta si no lo es.
     */
    public function validationErrorForTransitionTo(string $targetStage): ?string
    {
        if ($this->stage === self::NON_EDITABLE_STAGE) {
            return $targetStage === 'lead'
                ? null
                : 'No se puede mover una tarjeta desde Perdido, salvo reactivarla a Lead.';
        }

        if ($targetStage === self::NON_EDITABLE_STAGE) {
            // REQ-603 RN2 / REQ-597 RN3 (Epic CRM Batch B) — Aprobado es una etapa terminal, sin
            // ningún botón de movimiento, ni siquiera "Marcar como Perdido". Sin este check, un
            // usuario con RESTRICTED_EDIT_PERMISSION (ej. Mark) podía mover una tarjeta Aprobada
            // a Perdido, ya que isEditableBy() la trata como editable para ese permiso.
            if ($this->stage === self::RESTRICTED_EDIT_STAGE) {
                return 'Una tarjeta en Aprobado no puede marcarse como Perdido — es una etapa final.';
            }

            return null; // cualquier otra etapa activa puede marcarse Perdida, sin gate.
        }

        if ($targetStage === $this->stage) {
            return null; // no-op, no es una transición real.
        }

        // El flujo Lead→Diseño→Cotización→Propuesta→Aprobado es lineal — no se pueden
        // saltear etapas (ej. Lead directo a Aprobado) aunque los datos del gate
        // específico de una etapa intermedia ya estén completos.
        $currentIndex = array_search($this->stage, self::STAGE_ORDER, true);
        $targetIndex  = array_search($targetStage, self::STAGE_ORDER, true);
        if ($currentIndex === false || $targetIndex !== $currentIndex + 1) {
            return 'No se puede saltear etapas — solo se puede avanzar a la siguiente etapa del flujo.';
        }

        if ($this->stage === 'lead' && $targetStage !== 'lead') {
            if ($this->contacts->isEmpty()) {
                return 'Falta al menos 1 contacto antes de mover esta tarjeta desde Lead.';
            }
            if ($this->master_client_id === null) {
                return 'Falta vincular un Cliente Master antes de mover esta tarjeta desde Lead.';
            }
            if ($this->sub_client_id === null) {
                return 'Falta vincular un Subcliente antes de mover esta tarjeta desde Lead.';
            }
        }

        if ($this->stage === 'design' && $targetStage === 'quote') {
            // RN5 (SCRUM-677/680) — la única vía de Diseño a Cotización es generar una
            // cotización real ("Crear cotización"), que avanza la tarjeta a través de
            // QuoteService::advanceCardToQuoteStage() y a propósito NO pasa por este método
            // (ver docblock de QuoteService::confirm()). Este endpoint genérico de cambio de
            // etapa (PATCH .../stage) nunca debe poder saltar directo a "quote" — antes solo se
            // exigía superficie+archivo, lo mismo que "Crear cotización" pedía, permitiendo
            // llegar a Cotización sin ninguna Quote real detrás.
            return 'La transición a Cotización solo ocurre generando una cotización real desde "Crear cotización".';
        }

        if ($this->stage === 'quote' && $targetStage === 'proposal') {
            if ($this->worked_area_m2 === null) {
                return 'Falta la superficie trabajada (m²) antes de mover a Propuesta.';
            }
            // SCRUM-849.3 — el check de cotización firmada se movió a Propuesta→Aprobado (ver
            // abajo): se percibía mal ubicado acá, la firma real solo se obtiene una vez que el
            // cliente recibió la propuesta, no al momento de generarla.
        }

        if ($this->stage === 'proposal' && $targetStage === 'approved') {
            if ($this->deliveryDates->isEmpty()) {
                return 'Falta al menos 1 fecha de entrega antes de aprobar.';
            }
            if (! $this->files->contains('type', 'signed_quote')) {
                return 'Falta el archivo de la cotización firmada antes de aprobar.';
            }
            if (! $this->files->contains('type', 'approval_proof')) {
                return 'Falta el comprobante de aprobación antes de aprobar.';
            }
        }

        return null;
    }
}
