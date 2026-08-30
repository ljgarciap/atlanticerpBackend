<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Models;

use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Batch 3 de Facturación (SCRUM-519→523, REQ-442) — ver docblock de la migración
 * `create_admin_contab_customer_credit_balances_table` para el porqué de esta tabla existir sin
 * ningún escritor real todavía.
 *
 * @property int         $id
 * @property int          $master_client_id
 * @property float          $saldo
 */
class AdminContCustomerCreditBalance extends Model
{
    /** @var list<string> */
    protected $fillable = ['master_client_id', 'saldo'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['saldo' => 'float'];
    }

    public function getTable(): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return 'customer_credit_balances';
        }

        return "{$tenant->slug}_admin_contab.customer_credit_balances";
    }
}
