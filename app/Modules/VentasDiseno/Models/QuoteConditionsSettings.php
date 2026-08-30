<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Models;

use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\Eloquent\Model;

/**
 * SCRUM-138 — fila única (id=1) del texto global de Condiciones que se copia a toda
 * cotización nueva (`QuoteService::create()`). Editable vía `ventas_diseno.edit.conditions`
 * (mismo permiso que antes gateaba la edición por-cotización). Ver
 * QuoteConditionsSettings::current().
 *
 * @property int    $id
 * @property string $text
 */
class QuoteConditionsSettings extends Model
{
    /** @var list<string> */
    protected $fillable = ['text'];

    public function getTable(): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return 'quote_conditions_settings';
        }

        return "{$tenant->slug}_ventas_diseno.quote_conditions_settings";
    }

    /** La fila única de configuración — sembrada por la migración, siempre existe. */
    public static function current(): self
    {
        /** @var self $settings */
        $settings = self::findOrFail(1);
        return $settings;
    }
}
