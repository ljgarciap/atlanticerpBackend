<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Models;

use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * SCRUM-607→609 (REQ-530→532). RN4 de REQ-530: nunca se persiste el número completo de cuenta,
 * solo `ultimos_4_digitos`.
 *
 * @property int    $id
 * @property string $banco
 * @property string $tipo_cuenta
 * @property string $ultimos_4_digitos
 * @property string $moneda
 * @property bool   $activa
 */
class AdminContBankAccount extends Model
{
    public const TIPO_CORRIENTE       = 'corriente';
    public const TIPO_AHORRO          = 'ahorro';
    public const TIPO_TARJETA_CREDITO = 'tarjeta_credito';

    /**
     * `activa` fillable a propósito: `StoreBankAccountRequest` nunca la incluye en sus reglas (el
     * controller la asigna explícito, ver `store()`), así que el único llamador real de `update()`
     * con esa key son `deactivate()`/`reactivate()` — mismo criterio que `AdminContLocation`.
     *
     * @var list<string>
     */
    protected $fillable = ['banco', 'tipo_cuenta', 'ultimos_4_digitos', 'moneda', 'activa'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['activa' => 'boolean'];
    }

    public function getTable(): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return 'bank_accounts';
        }

        return "{$tenant->slug}_admin_contab.bank_accounts";
    }

    /** @return HasMany<AdminContBankMovement, $this> */
    public function movements(): HasMany
    {
        return $this->hasMany(AdminContBankMovement::class, 'bank_account_id');
    }

    public function label(): string
    {
        return "{$this->banco} — {$this->tipo_cuenta} ****{$this->ultimos_4_digitos}";
    }
}
