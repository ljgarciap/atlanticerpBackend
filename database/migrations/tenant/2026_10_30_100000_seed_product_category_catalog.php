<?php

declare(strict_types=1);

use App\Shared\Multitenancy\Tenant;
use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Support\Facades\DB;

/**
 * Fase 9a (docs/specs/fase9-catalogo-productos-generico.md §2) — siembra el dominio `product_category`.
 *
 *  - `general`: entrada de SISTEMA. El campo `category` es obligatorio al crear un producto, así que un tenant nuevo necesita al menos una opción.
 *  - Una entrada por cada categoría DISTINTA que ya tengan los productos de esta empresa (etiqueta = clave humanizada, editable en Ajustes), así
 *    ningún dato existente queda huérfano. El código no lleva ninguna lista de categorías: se deriva de los datos.
 *
 * Aditiva: no toca `catalog_products.category`. `down()` borra las entradas del dominio.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'auth';

    public function up(): void
    {
        $slug = Tenant::current()?->slug;
        $now = now();

        DB::table($this->table('catalog_entries'))->insert([
            'domain' => 'product_category', 'key' => 'general', 'label' => 'General', 'sort_order' => 0, 'is_active' => true, 'is_system' => true,
            'props' => null, 'created_at' => $now, 'updated_at' => $now,
        ]);

        $enUso = DB::table("{$slug}_ventas_diseno.catalog_products")
            ->whereNotNull('category')->where('category', '!=', '')->where('category', '!=', 'general')
            ->distinct()->orderBy('category')->pluck('category');

        foreach ($enUso as $i => $key) {
            DB::table($this->table('catalog_entries'))->insert([
                'domain' => 'product_category', 'key' => $key, 'label' => ucfirst(str_replace('_', ' ', (string) $key)), 'sort_order' => $i + 1,
                'is_active' => true, 'is_system' => false, 'props' => null, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table($this->table('catalog_entries'))->where('domain', 'product_category')->delete();
    }
};
