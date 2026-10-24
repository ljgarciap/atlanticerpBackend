<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Models;

use App\Shared\Multitenancy\Tenant;
use App\Shared\Support\LogsAuditTrail;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * SCRUM-607→609 (REQ-530→532). RN4 de REQ-530: nunca se persiste el número completo de cuenta,
 * solo `ultimos_4_digitos` — SIGUE VIGENTE para todo propósito interno (listados, selectores de
 * Cobros/Comisiones). SCRUM-857 (REQ-458) agrega `numero_cuenta_completo`, opcional, como la ÚNICA
 * excepción: el Estado de Cuenta (y por extensión las Facturas, mismo `InvoiceService::
 * paymentBlock()`) es un documento que el CLIENTE necesita para pagar por transferencia, no un
 * listado interno.
 *
 * @property int         $id
 * @property string      $banco
 * @property string      $tipo_cuenta
 * @property string      $ultimos_4_digitos
 * @property string|null $numero_cuenta_completo
 * @property string      $moneda
 * @property bool        $activa
 */
class AdminContBankAccount extends Model
{
    use LogsAuditTrail;

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
    protected $fillable = ['banco', 'tipo_cuenta', 'ultimos_4_digitos', 'numero_cuenta_completo', 'moneda', 'activa'];

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

    /**
     * SCRUM-936 — el Recibo de pago (PDF que recibe el cliente) nunca muestra el número
     * enmascarado, a diferencia de `label()`: número completo cuando está cargado, "-" cuando
     * no. `label()` se mantiene intacto para los selectores internos (Cobros, Comisiones,
     * Cuentas Bancarias) donde el enmascarado sigue siendo deseable — mismo criterio D-6 de
     * SCRUM-928, ya aplicado en `InvoiceService::paymentBlock()` para Factura/Estado de Cuenta.
     */
    public function receiptLabel(): string
    {
        $numero = trim((string) $this->numero_cuenta_completo) !== ''
            ? trim((string) $this->numero_cuenta_completo)
            : '-';

        return "{$this->banco} — {$this->tipo_cuenta} {$numero}";
    }
}
