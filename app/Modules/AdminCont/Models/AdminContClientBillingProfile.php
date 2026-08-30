<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Models;

use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Batch 8 — Estado de Cuenta (SCRUM-529→533, REQ-455) — ver docblock de la migración de creación.
 *
 * @property int         $id
 * @property int         $master_client_id
 * @property string      $regimen_fiscal
 * @property string      $terminos_pago
 */
class AdminContClientBillingProfile extends Model
{
    public const REGIMEN_CON_ITBMS = 'con_itbms';
    public const REGIMEN_SIN_ITBMS = 'sin_itbms';

    /** @var list<string> */
    protected $fillable = ['master_client_id', 'regimen_fiscal', 'terminos_pago'];

    public function getTable(): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return 'client_billing_profiles';
        }

        return "{$tenant->slug}_admin_contab.client_billing_profiles";
    }

    /**
     * REQ-455 RN2 — "siempre los vigentes actualmente para ese cliente". Devuelve la fila real si
     * existe, o un default razonable (Con ITBMS / Neto 30) SIN persistirlo — así un cliente nuevo,
     * sin fila propia todavía, siempre tiene un valor sensato sin depender de un seeder de backfill
     * que corra después de cada alta.
     */
    public static function forMasterClient(int $masterClientId): self
    {
        return self::query()->where('master_client_id', $masterClientId)->first()
            ?? new self([
                'master_client_id' => $masterClientId,
                'regimen_fiscal'   => self::REGIMEN_CON_ITBMS,
                'terminos_pago'    => 'Neto 30',
            ]);
    }
}
