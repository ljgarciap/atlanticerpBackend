<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Models;

use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Fila única (id=1) de Datos de la Empresa (SCRUM-638/639/642). Mismo patrón `::current()` que
 * `AdminContFiscalSettings`. `razon_social`/`nombre_comercial` no son propiedad de este modelo —
 * ver docblock de la migración.
 *
 * @property int         $id
 * @property string|null $logo_key
 * @property string|null $logo_public_url
 * @property string      $descripcion_corta
 * @property string|null $sitio_web
 * @property array<int, array{plataforma: string, url: string}>|null $redes_sociales
 * @property string      $moneda
 * @property string      $anio_fiscal
 * @property string      $zona_horaria
 */
class AdminContCompanyProfile extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'descripcion_corta', 'sitio_web', 'redes_sociales', 'zona_horaria',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'redes_sociales' => 'array',
        ];
    }

    public function getTable(): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return 'company_profile';
        }

        return "{$tenant->slug}_admin_contab.company_profile";
    }

    /** La fila única de configuración — sembrada por la migración, siempre existe. */
    public static function current(): self
    {
        /** @var self $profile */
        $profile = self::findOrFail(1);

        return $profile;
    }
}
