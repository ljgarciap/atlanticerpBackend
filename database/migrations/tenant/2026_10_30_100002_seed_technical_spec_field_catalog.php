<?php

declare(strict_types=1);

use App\Shared\Multitenancy\Tenant;
use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Support\Facades\DB;

/**
 * Fase 9c (docs/specs/fase9-catalogo-productos-generico.md §2) — siembra el dominio `technical_spec_field` (campos de la ficha técnica estructurada).
 *
 *  - 5 campos GENÉRICOS de sistema (dimensiones, peso, material / acabado, garantía, certificaciones): valen para cualquier rubro.
 *  - Una entrada por cada clave que ya exista en el JSONB `technical_spec` de algún producto de esta empresa (etiqueta = clave humanizada,
 *    editable en Ajustes), así ningún dato existente queda sin etiqueta. El código no lleva ninguna lista de campos de un sector.
 *
 * Aditiva: no toca `catalog_products`. `down()` borra las entradas del dominio.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'auth';

    /** @var array<string, string> clave => etiqueta */
    private array $generic = [
        'dimensions' => 'Dimensiones',
        'weight' => 'Peso',
        'material_finish' => 'Material / acabado',
        'warranty' => 'Garantía',
        'certifications' => 'Certificaciones',
    ];

    public function up(): void
    {
        $slug = Tenant::current()?->slug;
        $now = now();
        $order = 0;

        foreach ($this->generic as $key => $label) {
            DB::table($this->table('catalog_entries'))->insert([
                'domain' => 'technical_spec_field', 'key' => $key, 'label' => $label, 'sort_order' => $order++, 'is_active' => true, 'is_system' => true,
                'props' => null, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        $enUso = DB::table("{$slug}_ventas_diseno.catalog_products")
            ->whereNotNull('technical_spec')
            ->selectRaw('DISTINCT jsonb_object_keys(technical_spec) AS k')
            ->orderBy('k')->pluck('k');

        foreach ($enUso as $key) {
            if (array_key_exists((string) $key, $this->generic)) {
                continue;
            }
            DB::table($this->table('catalog_entries'))->insert([
                'domain' => 'technical_spec_field', 'key' => $key, 'label' => ucfirst(str_replace('_', ' ', (string) $key)), 'sort_order' => $order++,
                'is_active' => true, 'is_system' => false, 'props' => null, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table($this->table('catalog_entries'))->where('domain', 'technical_spec_field')->delete();
    }
};
