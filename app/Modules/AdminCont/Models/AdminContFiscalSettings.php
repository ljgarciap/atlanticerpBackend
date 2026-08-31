<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Models;

use App\Shared\Multitenancy\Tenant;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * Fila única (id=1) de Configuración Fiscal (SCRUM-632/633/634/637). Mismo patrón que
 * App\Modules\Compras\Models\ComprasSettings::current().
 *
 * @property int $id
 * @property string $razon_social
 * @property string $nombre_comercial
 * @property string $ruc
 * @property string $dv
 * @property string $direccion_fiscal
 * @property string $regimen_tributario
 * @property string $pac_provider
 * @property string $pac_connection_status
 * @property string $pac_ambiente
 * @property Carbon|null $pac_last_sync_at
 * @property bool $pac_doc_factura_habilitado
 * @property bool $pac_doc_nota_credito_habilitado
 * @property bool $retencion_proveedores_activa
 * @property int $dias_credito_factura
 * @property int|null $primary_approver_user_id
 * @property int|null $responsable_administracion_user_id
 * @property float $primary_approval_threshold
 * @property float $comision_externa_default_percent
 * @property float $petty_cash_balance
 * @property int $petty_cash_max_intentos_rechazo
 * @property Carbon|null $arqueo_tracking_start_date
 */
class AdminContFiscalSettings extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'razon_social', 'nombre_comercial', 'ruc', 'dv', 'direccion_fiscal', 'regimen_tributario',
        'pac_ambiente', 'pac_doc_factura_habilitado', 'pac_doc_nota_credito_habilitado',
        'retencion_proveedores_activa', 'dias_credito_factura', 'primary_approver_user_id',
        'responsable_administracion_user_id', 'primary_approval_threshold', 'comision_externa_default_percent',
        'petty_cash_balance', 'petty_cash_max_intentos_rechazo', 'arqueo_tracking_start_date',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'pac_last_sync_at' => 'datetime',
            'pac_doc_factura_habilitado' => 'boolean',
            'pac_doc_nota_credito_habilitado' => 'boolean',
            'retencion_proveedores_activa' => 'boolean',
            'dias_credito_factura' => 'integer',
            'primary_approver_user_id' => 'integer',
            'responsable_administracion_user_id' => 'integer',
            'primary_approval_threshold' => 'float',
            'comision_externa_default_percent' => 'float',
            'petty_cash_balance' => 'float',
            'petty_cash_max_intentos_rechazo' => 'integer',
            'arqueo_tracking_start_date' => 'date',
        ];
    }

    public function getTable(): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return 'fiscal_settings';
        }

        return "{$tenant->slug}_admin_contab.fiscal_settings";
    }

    /** La fila única de configuración — sembrada por la migración, siempre existe. */
    public static function current(): self
    {
        /** @var self $settings */
        $settings = self::findOrFail(1);

        return $settings;
    }
}
