<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Models;

use App\Modules\VentasDiseno\Exceptions\DuplicateSubClientTaxIdException;
use App\Shared\Multitenancy\Tenant;
use App\Shared\Support\LogsAuditTrail;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int         $id
 * @property int         $master_client_id
 * @property string      $business_name
 * @property string      $tax_id
 * @property string|null $tax_id_dv  Dígito verificador (DV) del RUC — SCRUM-928, obligatorio al crear (no retroactivo).
 * @property string|null $receptor_tipo SCRUM-1003 — `01` contribuyente (NULL en filas anteriores, se trata como `01`) | `02` consumidor final.
 * @property string|null $tax_id_key  Fase 17a — identificación normalizada + DV; la calcula el modelo al guardar. Única por tenant (índice parcial).
 * @property string|null $delivery_address
 * @property string|null $category
 * @property bool        $itbms_exento              SCRUM-1166 — cliente exento de ITBMS; solo lo cambia `ItbmsExemptionService`, nunca un formulario
 * @property string|null $itbms_exencion_documento  resolución o ley que respalda la exención
 * @property \Carbon\Carbon|null $itbms_exento_at
 * @property int|null    $itbms_exento_by
 * @property MasterClient $masterClient
 * @property \Illuminate\Database\Eloquent\Collection<int, SubClientContact> $contacts
 */
class SubClient extends Model
{
    use LogsAuditTrail;

    /** @var list<string> */
    protected $fillable = ['master_client_id', 'business_name', 'tax_id', 'receptor_tipo', 'tax_id_dv', 'delivery_address', 'category'];

    protected static function booted(): void
    {
        // Fase 17a — una identificación fiscal por tenant. La clave se recalcula al guardar y, si cambió, se verifica contra el resto; el
        // índice único de la BD queda como respaldo ante una carrera.
        static::saving(function (self $subClient): void {
            $key = self::taxIdKey($subClient->tax_id, $subClient->tax_id_dv);
            $subClient->tax_id_key = $key;

            if ($key === null || ! $subClient->isDirty('tax_id_key')) {
                return;
            }

            $existing = self::query()->where('tax_id_key', $key)
                ->when($subClient->exists, fn ($q) => $q->where('id', '!=', $subClient->id))
                ->first();
            if ($existing !== null) {
                throw new DuplicateSubClientTaxIdException($existing);
            }
        });
    }

    /**
     * Fase 17a — identificación comparable: sin espacios, guiones ni puntos, en mayúsculas, más el DV. `null` si no hay identificación
     * (consumidor final), que no entra al índice único.
     */
    public static function taxIdKey(?string $taxId, ?string $dv = null): ?string
    {
        $limpio = strtoupper((string) preg_replace('/[^0-9A-Za-z]/', '', (string) $taxId));
        if ($limpio === '') {
            return null;
        }

        return $limpio.'|'.strtoupper((string) preg_replace('/[^0-9A-Za-z]/', '', (string) $dv));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['itbms_exento' => 'boolean', 'itbms_exento_at' => 'datetime'];
    }

    public function getTable(): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return 'sub_clients';
        }

        return "{$tenant->slug}_ventas_diseno.sub_clients";
    }

    /** @return BelongsTo<MasterClient, $this> */
    public function masterClient(): BelongsTo
    {
        return $this->belongsTo(MasterClient::class);
    }

    /** @return HasMany<SalesProject, $this> */
    public function salesProjects(): HasMany
    {
        return $this->hasMany(SalesProject::class);
    }

    /** @return HasMany<SubClientContact, $this> */
    public function contacts(): HasMany
    {
        return $this->hasMany(SubClientContact::class);
    }
}
