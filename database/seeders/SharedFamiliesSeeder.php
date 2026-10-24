<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Modules\VentasDiseno\Models\CatalogProductFamily;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\Seeder;

/**
 * SCRUM-1010 — familias compartidas de Inventario → Familias, tomadas del Excel adjunto al ticket
 * ("Familias Referencias riel magnetico mylight flua.xlsx", una hoja por familia). Tipo A: corre en
 * TODOS los entornos (son datos reales del cliente, no demo), salvo `testing` (ver DatabaseSeeder —
 * `catalog_product_families` se cuenta/lista en tests que no scopean sus fixtures; el test propio
 * invoca esta clase directamente).
 *
 * Idempotente y aditivo: la familia se crea solo si no existe una ACTIVA con el mismo nombre
 * (comparación sin distinguir mayúsculas, mismo criterio que `InventoryController::storeFamily()`),
 * y los productos se enlazan por `reference` con `syncWithoutDetaching` — nunca renombra, desactiva
 * ni desenlaza nada existente. Las referencias que todavía no están en el catálogo se reportan por
 * consola y se enlazan en una corrida posterior (p. ej. tras la próxima sincronización de ICG).
 */
class SharedFamiliesSeeder extends Seeder
{
    /** @var array<string, list<string>> nombre de la familia => referencias del catálogo */
    public const FAMILIES = [
        'Flua Luna S' => [
            '001680',
            '565017A-PAB',
            '575512A-PAB',
            '575512A-PAW',
            '560312-PAB',
            '520714-PAB',
            '560412-PAB',
            '560212-PAB',
            'M-150W-24V',
            '004568-PAB',
            // El Excel dice '04569-PAB' (typo: falta un cero, la serie va 004568→004581); en el catálogo
            // la referencia real es '004569-PAB' (confirmado con Luis, 2026-10-01).
            '004569-PAB',
            '004570-PAB',
            '004570-PAW',
            '004571-PAB',
            '004571-PAW',
            '004572-PAB',
            '004574-PAB',
            '004575-PAB',
            '004575-PAW',
            '004576-PAB',
            '004576-PAW',
            '004577-PAB',
            '004577-PAW',
            '004578-PAB',
            '004579-PAB',
            '004580-PAB',
            '004581-PAB',
            '004581-PAW',
        ],
        'Mylight Riel Magnético Mini' => [
            'ML-27129A-1M',
            'ML-27129A-2M',
            'ML-27129A-RC',
            'ML-27129A-RL',
            'ML-27129A-RJ',
            'ML-27129B-1M',
            'ML-27129B-2M',
            'ML-27129B-SC',
            'ML-27129B-SL',
            'ML-27129B-SJ',
            'ML-27130A',
            'ML-27130B',
            'ML-27130C',
            'ML-27129-ACC1',
            'ML-27129-ACC2',
            'ML-27129-END',
            'ML-27133A-WW',
            'ML-27133B-WW',
            'ML-27133B-GD',
            'ML-27133B-WWB2',
            'ML-27141A-WW',
            'ML-27141B-WW',
            'ML-27141C-WW',
            'ML-27136A-WW',
            'ML-27136B-CW',
            'ML-27136C-WW',
            'ML-27136C-CW',
            'ML-27135A-WW',
            'ML-27135A-CW',
            'ML-27134A-WW',
            'ML-27134A-WWH',
            'ML-27134A-WW2',
            'ML-27134A-WW4K',
            'ML-27150A-2M',
            'ML-27150A-1M',
            'ML-27150-ACC1',
            'ML-27150-ACC3',
            'ML-27150-ACC',
            'ML-27150-ACC2',
        ],
        'Mylight Riel Magnético Normal' => [
            'ML-27126-1M',
            'ML-27126-2M',
            'ML-27126-ACC1',
            'ML-27126-ACC2',
            'ML-27126-ACC3',
            'ML-27126-CON',
            'ML-27127-1M',
            'ML-27127-2M',
            'ML-27127-ACC1',
            'ML-27127-ACC2',
            'ML-27127-ACC3',
            'ML-27127-SCON',
            'ML-27127-SKIT',
            'ML-27128A',
            'ML-27128B',
            'ML-27128C',
            'ML-27128D',
            'ML-27120A-WW',
            'ML-27120A-CW',
            'ML-27120B-WW',
            'ML-27120B-CW',
            'ML-27120B-CWH',
            'ML-27120C-WW',
            'ML-27120C-CW',
            'ML-27121A-WW',
            'ML-27121B-WW',
            'ML-27121B-WWH',
            'ML-27121C-WW',
            'ML-27121C2-WW',
            'ML-27122A-WW',
            'ML-27122B-WW',
            'ML-27123A-WW',
            'ML-27123A-BRA',
            'ML-27123A-WH',
            'ML-27123B-WW',
            'ML-27123B-CW',
            'ML-27123B-WWH',
            'ML-27123B-CWH',
            'ML-27123B-BRA',
            'ML-27123C-WW',
            'ML-27123C-A',
            'ML-27123C-BRA',
            'ML-27124-BK',
            'ML-27124B-BK',
            'ML-27125A-BK',
            'ML-27125B-BK',
            'ML-27125C-BK',
        ],
    ];

    public function run(): void
    {
        $tenant = Tenant::where('slug', 'atlantic')->first();

        if ($tenant === null) {
            $this->report('warn', 'Tenant "atlantic" not found — SharedFamiliesSeeder skipped.');
            return;
        }

        $tenant->makeCurrent();

        try {
            $this->seed();
        } finally {
            Tenant::forgetCurrent();
        }
    }

    private function seed(): void
    {
        foreach (self::FAMILIES as $name => $references) {
            $family = CatalogProductFamily::where('is_active', true)
                ->whereRaw('lower(name) = ?', [mb_strtolower($name)])
                ->first()
                ?? CatalogProductFamily::create(['name' => $name, 'is_active' => true]);

            $productIds = CatalogProduct::whereIn('reference', $references)->pluck('id', 'reference');
            $family->products()->syncWithoutDetaching($productIds->values()->all());

            $missing = array_values(array_diff($references, $productIds->keys()->all()));
            $this->report('info', sprintf(
                '%s: %d/%d referencias enlazadas%s',
                $family->name,
                $productIds->count(),
                count($references),
                $missing === [] ? '' : ' — faltan en el catálogo: '.implode(', ', $missing),
            ));
        }
    }

    /** `$command` solo existe cuando corre vía artisan; el test invoca la clase directamente. */
    private function report(string $level, string $message): void
    {
        if (isset($this->command)) { // @phpstan-ignore isset.property
            $this->command->{$level}($message);
        }
    }
}
