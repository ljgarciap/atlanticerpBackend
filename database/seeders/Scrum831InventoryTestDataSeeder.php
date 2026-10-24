<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\Seeder;

/**
 * SCRUM-831 — data de prueba de Inventario pedida por Gerencia Test para test.atlanticerp.ai
 * (2026-09-03). NO se registra en DatabaseSeeder::run() — es un ajuste puntual de stock sobre 15
 * referencias YA EXISTENTES del catálogo real (RN01/RN02 del ticket: prohibido crear referencias
 * nuevas). Se invoca a mano vía tinker en el entorno destino:
 *
 *   (new \Database\Seeders\Scrum831InventoryTestDataSeeder)->run();
 *
 * Nunca corre en 'production' ni en 'testing' (guard explícito, mismo criterio que
 * CatalogUatTestDataSeeder): el ticket pide expresamente TEST, y 'testing' es la suite de
 * PHPUnit — `catalog_products` es tabla compartida con Bodega/Compras
 * (memory/feedback_catalog_product_shared_table_pollution.md), tocar stock de referencias reales
 * ahí no tiene sentido (ninguna de las 15 referencias ICG existe en los fixtures sintéticos).
 *
 * Reglas (RN03-RN05 del ticket): reasigna `stock_quantity` de las 15 referencias — 14 con
 * cantidad aleatoria entre 50 y 500 (ambos límites incluidos), y exactamente 1 (elegida al azar
 * entre las 15) con exactamente 10 unidades. Seguro de re-correr: solo actualiza filas existentes,
 * no inserta nada — cada corrida vuelve a barajar y sigue cumpliendo las mismas reglas.
 */
final class Scrum831InventoryTestDataSeeder extends Seeder
{
    /** @var list<string> */
    private const REFERENCES = [
        '00-A368-W1-L1',
        '000.000.A10.L0',
        '000.000.A20.L0',
        '000.000.A30.L0',
        '000.361.000.A0',
        '000.365.000.L0',
        '000.367.000.L0',
        '000.368.000.L0',
        '000.375.000.L0',
        'ML-28012ABK',
        'GE-SOCKET1',
        'GE-SOCKET2',
        'ML-001A-BKBK',
        'ML-001A-WHBK',
        '0535-01',
    ];

    private const LOW_STOCK_QUANTITY = 10;

    private const MIN_QUANTITY = 50;

    private const MAX_QUANTITY = 500;

    public function run(): void
    {
        if (app()->environment(['production', 'testing'])) {
            $this->command?->error('Scrum831InventoryTestDataSeeder: solo corre en dev/qa — abortado en ' . app()->environment() . '.');
            return;
        }

        $tenant = Tenant::where('slug', 'atlantic')->first();

        if ($tenant === null) {
            $this->command?->warn('Tenant "atlantic" not found — Scrum831InventoryTestDataSeeder skipped.');
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
        $products = CatalogProduct::whereIn('reference', self::REFERENCES)->get()->keyBy('reference');

        $missing = array_values(array_diff(self::REFERENCES, $products->keys()->all()));

        if ($missing !== []) {
            $this->command?->error(
                'Scrum831InventoryTestDataSeeder: faltan referencias en catalog_products, abortado sin tocar nada: '
                . implode(', ', $missing)
            );
            return;
        }

        $lowStockReference = self::REFERENCES[array_rand(self::REFERENCES)];

        foreach (self::REFERENCES as $reference) {
            $quantity = $reference === $lowStockReference
                ? self::LOW_STOCK_QUANTITY
                : random_int(self::MIN_QUANTITY, self::MAX_QUANTITY);

            $products[$reference]->update(['stock_quantity' => $quantity]);
        }

        $this->command?->info(
            "Scrum831InventoryTestDataSeeder: 15 referencias actualizadas — {$lowStockReference} quedó con 10 unidades."
        );
    }
}
