<?php

declare(strict_types=1);

namespace App\Modules\Servicios\Models;

use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\Eloquent\Model;

/**
 * REQ-249 RN1: contador atómico del folio PREFIJO-AÑO-NNNN, una fila por (prefijo, año) — a
 * diferencia de `QuoteFolioCounter` (solo por año), acá el consecutivo es por prefijo+año, PK
 * compuesta. Eloquent no soporta primary keys compuestas de forma nativa, así que este modelo
 * existe solo para introspección/lectura (`getTable()`, casts) — la asignación atómica del folio
 * vive en `TicketFolioService::next()`, que opera sobre esta tabla vía `DB::table()` en vez de
 * los métodos de instancia de Eloquent (`increment()`, `save()`, etc., que asumen una PK simple).
 *
 * @property string $prefijo
 * @property int    $year
 * @property int    $last_number
 */
class TicketFolioCounter extends Model
{
    public $incrementing = false;

    /** @var list<string> */
    protected $fillable = ['prefijo', 'year', 'last_number'];

    public function getTable(): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return 'ticket_folio_counters';
        }

        return "{$tenant->slug}_servicios.ticket_folio_counters";
    }
}
