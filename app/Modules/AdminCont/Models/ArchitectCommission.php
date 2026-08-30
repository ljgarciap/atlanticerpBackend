<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Models;

use App\Models\User;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Batch 16 de Admin&Cont (SCRUM-585→590, REQ-512/513) — overlay persistido de UN proyecto
 * comisionable de arquitecto (cuenta de cobro + fecha de pago manual). Ver docblock de la
 * migración — nunca se crea a mano, es efecto colateral de subir la cuenta de cobro.
 *
 * Batch 17 (SCRUM-591→596, REQ-514/516/517) agrega: comprobante de retención (mismo patrón que
 * cuenta de cobro), cuenta de pago (`bank_account_id`, FK real — mismo schema), y el % de comisión
 * por proyecto (aprobado + propuesta pendiente de Mark).
 *
 * @property int         $id
 * @property int         $architect_id
 * @property int         $pipeline_card_id
 * @property string|null $cuenta_cobro_storage_key
 * @property string|null $cuenta_cobro_nombre_archivo
 * @property int|null    $cuenta_cobro_size_bytes
 * @property string|null $cuenta_cobro_mime_type
 * @property \Carbon\Carbon|null $cuenta_cobro_uploaded_at
 * @property int|null    $cuenta_cobro_uploaded_by
 * @property \Carbon\Carbon|null $fecha_pago
 * @property string|null $comprobante_retencion_storage_key
 * @property string|null $comprobante_retencion_nombre_archivo
 * @property int|null    $comprobante_retencion_size_bytes
 * @property string|null $comprobante_retencion_mime_type
 * @property \Carbon\Carbon|null $comprobante_retencion_uploaded_at
 * @property int|null    $comprobante_retencion_uploaded_by
 * @property int|null    $bank_account_id
 * @property float|null  $porcentaje_aprobado
 * @property float|null  $porcentaje_pendiente
 * @property string|null $porcentaje_pendiente_motivo
 * @property int|null    $porcentaje_pendiente_propuesto_por
 * @property \Carbon\Carbon|null $porcentaje_pendiente_propuesto_at
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 * @property-read User|null $cuentaCobroUploader
 * @property-read User|null $comprobanteRetencionUploader
 * @property-read AdminContBankAccount|null $bankAccount
 */
class ArchitectCommission extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'architect_id', 'pipeline_card_id',
        'cuenta_cobro_storage_key', 'cuenta_cobro_nombre_archivo', 'cuenta_cobro_size_bytes',
        'cuenta_cobro_mime_type', 'cuenta_cobro_uploaded_at', 'cuenta_cobro_uploaded_by',
        'fecha_pago',
        'comprobante_retencion_storage_key', 'comprobante_retencion_nombre_archivo',
        'comprobante_retencion_size_bytes', 'comprobante_retencion_mime_type',
        'comprobante_retencion_uploaded_at', 'comprobante_retencion_uploaded_by',
        'bank_account_id',
        'porcentaje_aprobado', 'porcentaje_pendiente', 'porcentaje_pendiente_motivo',
        'porcentaje_pendiente_propuesto_por', 'porcentaje_pendiente_propuesto_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'cuenta_cobro_uploaded_at'          => 'datetime',
            'fecha_pago'                        => 'date',
            'comprobante_retencion_uploaded_at' => 'datetime',
            'porcentaje_aprobado'               => 'float',
            'porcentaje_pendiente'              => 'float',
            'porcentaje_pendiente_propuesto_at' => 'datetime',
        ];
    }

    public function getTable(): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return 'architect_commissions';
        }

        return "{$tenant->slug}_admin_contab.architect_commissions";
    }

    /** @return BelongsTo<User, $this> */
    public function cuentaCobroUploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cuenta_cobro_uploaded_by');
    }

    /** @return BelongsTo<User, $this> */
    public function comprobanteRetencionUploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'comprobante_retencion_uploaded_by');
    }

    /** @return BelongsTo<AdminContBankAccount, $this> */
    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(AdminContBankAccount::class, 'bank_account_id');
    }
}
