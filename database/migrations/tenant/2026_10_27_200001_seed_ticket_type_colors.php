<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Support\Facades\DB;

/**
 * Fase 7b — color de cada tipo de ticket como propiedad del catálogo (antes duplicado en `ReportsService::TYPE_COLORS` y en el
 * `TicketBoard` del frontend). Se siembran los mismos colores que ya se mostraban, así que el gráfico de Reportes no cambia.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'auth';

    /** @var array<string, string> */
    private array $colors = [
        'installation' => '#5BA5A0',
        'warranty' => '#9fc54d',
        'claim' => '#ef4444',
        'retrofit' => '#8b5cf6',
    ];

    public function up(): void
    {
        foreach ($this->colors as $key => $color) {
            $row = DB::table($this->table('catalog_entries'))->where('domain', 'ticket_type')->where('key', $key)->first();
            if ($row === null) {
                continue;
            }

            $props = (array) json_decode((string) $row->props, true);
            $props['color'] = $color;
            DB::table($this->table('catalog_entries'))->where('id', $row->id)->update(['props' => json_encode($props)]);
        }
    }

    public function down(): void
    {
        foreach (array_keys($this->colors) as $key) {
            $row = DB::table($this->table('catalog_entries'))->where('domain', 'ticket_type')->where('key', $key)->first();
            if ($row === null) {
                continue;
            }

            $props = (array) json_decode((string) $row->props, true);
            unset($props['color']);
            DB::table($this->table('catalog_entries'))->where('id', $row->id)->update(['props' => json_encode($props)]);
        }
    }
};
