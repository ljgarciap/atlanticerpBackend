<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Models;

use App\Shared\Multitenancy\Tenant;
use App\Shared\Support\LogsAuditTrail;
use Illuminate\Database\Eloquent\Model;

/**
 * Batch 16 de Admin&Cont (SCRUM-585→590, REQ-510) — datos fiscales del arquitecto externo,
 * únicos por arquitecto (RN2), nunca por proyecto. Ver docblock de la migración para el porqué de
 * no tener un default tipo `AdminContClientBillingProfile::forMasterClient()`.
 *
 * @property int    $id
 * @property int    $architect_id
 * @property string $empresa
 * @property string $ruc
 * @property string $regimen_fiscal
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class ArchitectFiscalProfile extends Model
{
    use LogsAuditTrail;

    /** REQ-511 RN3/RN4/RN5 — único impuesto, tratado distinto según régimen. */
    public const REGIMEN_EXENTO         = 'exento';

    public const REGIMEN_CON_ITBMS      = 'con_itbms';

    public const REGIMEN_RETENCION_50   = 'retencion_50';

    /** @var list<string> */
    public const REGIMENES = [self::REGIMEN_EXENTO, self::REGIMEN_CON_ITBMS, self::REGIMEN_RETENCION_50];

    /** @var list<string> */
    protected $fillable = ['architect_id', 'empresa', 'ruc', 'regimen_fiscal'];

    public function getTable(): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return 'architect_fiscal_profiles';
        }

        return "{$tenant->slug}_admin_contab.architect_fiscal_profiles";
    }
}
