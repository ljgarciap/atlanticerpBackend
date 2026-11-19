<?php

declare(strict_types=1);

use App\Shared\Multitenancy\Tenant;
use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Support\Facades\DB;

/**
 * Fase 34a (docs/specs/fase34-vocabularios-por-empresa.md) — los motivos de faltante al entregar un pedido pasan de lista fija a catálogo
 * `shortage_reason`. Mismas claves y orden de siempre (cero cambio de datos); `sin_stock` y `otra` son de sistema (la lógica pregunta por
 * ellas). Además siembra, como entradas propias, cualquier otro valor ya guardado (sin el sufijo `:<texto>` de «otra»).
 * Aditiva. `down()` borra las entradas del dominio.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'auth';

    /** @var list<array{string, string, string, bool}> clave, etiqueta, inglés, de sistema */
    private const ENTRIES = [
        ['sin_stock', 'Sin stock', 'Out of stock', true],
        ['no_encontrado', 'No encontrado', 'Not found', false],
        ['danado', 'Encontrado con avería/dañado', 'Found damaged', false],
        ['reservado_otro_pedido', 'Reservado/tomado por otro pedido', 'Reserved/taken by another order', false],
        ['referencia_equivocada', 'Referencia equivocada', 'Wrong reference', false],
        ['otra', 'Otra', 'Other', true],
    ];

    public function up(): void
    {
        $table = $this->table('catalog_entries');
        $now = now();
        $orden = 0;
        $claves = [];
        foreach (self::ENTRIES as [$key, $label, $en, $system]) {
            $orden++;
            $claves[] = $key;
            if (DB::table($table)->where('domain', 'shortage_reason')->where('key', $key)->exists()) {
                continue;
            }
            DB::table($table)->insert([
                'domain' => 'shortage_reason', 'key' => $key, 'label' => $label, 'labels' => json_encode(['en' => $en]), 'sort_order' => $orden,
                'is_active' => true, 'is_system' => $system, 'props' => null, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        // Valores ya guardados que no estaban en la lista fija: quedan como entradas propias para que no queden huérfanos.
        $items = Tenant::current()?->slug.'_bodega.order_items'; // la tabla vive en el schema de Bodega
        $guardados = DB::table($items)->whereNotNull('shortage_reason')->distinct()->pluck('shortage_reason');
        foreach ($guardados as $valor) {
            $clave = explode(':', (string) $valor, 2)[0];
            if ($clave === '' || in_array($clave, $claves, true)) {
                continue;
            }
            $claves[] = $clave;
            $orden++;
            DB::table($table)->insert([
                'domain' => 'shortage_reason', 'key' => $clave, 'label' => ucfirst(str_replace('_', ' ', strtolower($clave))), 'sort_order' => $orden,
                'is_active' => true, 'is_system' => false, 'props' => null, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table($this->table('catalog_entries'))->where('domain', 'shortage_reason')->delete();
    }
};
