<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Models;

use App\Models\User;
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
 * @property Collection<int, PipelineCardContact>      $contacts
 * @property Collection<int, PipelineCardDeliveryDate> $deliveryDates
 * @property Collection<int, PipelineCardFile>          $files
 */
class PipelineCard extends Model
{
    use LogsAuditTrail;

    public const STAGNANT_THRESHOLD_DAYS = 15;

    public const NON_EDITABLE_STAGE = 'lost';

    public const RESTRICTED_EDIT_STAGE = 'approved';

    public const RESTRICTED_EDIT_PERMISSION = 'ventas_diseno.edit.approved';

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

    /** @var list<string> */
    protected $fillable = [
        'sales_project_id', 'stage', 'master_client_id', 'sub_client_id', 'owner_id',
        'amount', 'worked_area_m2', 'delivery_type', 'observations', 'stage_changed_at',
        'compartido_con',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'amount'           => 'float',
            'worked_area_m2'   => 'float',
            'stage_changed_at' => 'datetime',
            // REQ-506 (Batch 15) — array de user_id de responsables ADICIONALES al owner_id
            // original, sin UI de asignación en este batch, ver docblock de la migración.
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

    public function daysInStage(): int
    {
        return (int) $this->stage_changed_at->diffInDays(now());
    }

    public function isStagnant(): bool
    {
        return $this->daysInStage() > self::STAGNANT_THRESHOLD_DAYS;
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
            if (! $this->files->contains('type', 'signed_quote')) {
                return 'Falta el archivo de la cotización firmada antes de mover a Propuesta.';
            }
        }

        if ($this->stage === 'proposal' && $targetStage === 'approved') {
            if ($this->deliveryDates->isEmpty()) {
                return 'Falta al menos 1 fecha de entrega antes de aprobar.';
            }
            if (! $this->files->contains('type', 'approval_proof')) {
                return 'Falta el comprobante de aprobación antes de aprobar.';
            }
        }

        return null;
    }
}
