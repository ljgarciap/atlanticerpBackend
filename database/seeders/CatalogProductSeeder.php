<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\Seeder;

/**
 * REQ-036: productos demo del Catálogo — necesarios para poder probar el
 * buscador (REQ-036) y las fórmulas de Tipo de Precio sobre un producto real
 * (REQ-033/035/042) en dev.atlanticerp.ai. Mismo criterio que VentasDisenoDemoSeeder:
 * nunca en 'testing' (los tests de feature crean sus propios CatalogProduct
 * directo, sin depender de datos compartidos).
 *
 * SCRUM-715: ampliado de 10 a 46 productos cubriendo 10 familias (los 10
 * originales se mantienen sin cambios — Scrum711SalesForceDemoSeeder ya
 * referencia catálogo por índice genérico via CatalogProduct::pluck('id'),
 * nunca por `reference` puntual, así que sumar productos no rompe cotizaciones
 * ya sembradas por SCRUM-714/716).
 */
class CatalogProductSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('testing')) {
            return;
        }

        $tenant = Tenant::where('slug', 'atlantic')->first();

        if ($tenant === null) {
            $this->command->warn('Tenant "atlantic" not found — CatalogProductSeeder skipped.');
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
        if (CatalogProduct::exists()) {
            return;
        }

        $products = [
            ['reference' => 'LAMP-COL-001', 'description' => 'Lámpara colgante LED 40W, acabado dorado', 'brand' => 'Atlantic Home', 'price_full' => 189.99, 'cost' => 95.00, 'stock_quantity' => 24],
            ['reference' => 'LAMP-COL-002', 'description' => 'Lámpara colgante industrial, acabado negro mate', 'brand' => 'Atlantic Home', 'price_full' => 149.50, 'cost' => 78.00, 'stock_quantity' => 15],
            ['reference' => 'SPOT-EMP-010', 'description' => 'Spot LED empotrable 12W, luz cálida 3000K', 'brand' => 'LuxTech', 'price_full' => 24.99, 'cost' => 11.50, 'stock_quantity' => 300],
            ['reference' => 'SPOT-EMP-011', 'description' => 'Spot LED empotrable 12W, luz fría 5000K', 'brand' => 'LuxTech', 'price_full' => 24.99, 'cost' => 11.50, 'stock_quantity' => 210],
            ['reference' => 'RIEL-TRK-050', 'description' => 'Riel electrificado 2m, blanco, para spots direccionables', 'brand' => 'LuxTech', 'price_full' => 65.00, 'cost' => 32.00, 'stock_quantity' => 40],
            ['reference' => 'APLQ-PAR-020', 'description' => 'Aplique de pared LED 8W, difusor opalino', 'brand' => 'Atlantic Home', 'price_full' => 45.75, 'cost' => 21.00, 'stock_quantity' => 60],
            ['reference' => 'CAND-SAL-005', 'description' => 'Candelabro de sala 6 luces, cristal y bronce', 'brand' => 'Signature Lighting', 'price_full' => 890.00, 'cost' => 410.00, 'stock_quantity' => 3],
            ['reference' => 'CINT-LED-100', 'description' => 'Cinta LED RGB 5m, control remoto incluido', 'brand' => 'LuxTech', 'price_full' => 32.00, 'cost' => 14.00, 'stock_quantity' => 150],
            ['reference' => 'PISO-LAMP-030', 'description' => 'Lámpara de piso arco, base mármol', 'brand' => 'Signature Lighting', 'price_full' => 320.00, 'cost' => 165.00, 'stock_quantity' => 8],
            ['reference' => 'MESA-LAMP-015', 'description' => 'Lámpara de mesa táctil, regulable 3 niveles', 'brand' => 'Atlantic Home', 'price_full' => 79.90, 'cost' => 38.00, 'stock_quantity' => 45],

            // SCRUM-715 — Riel Direccionable (ampliación)
            ['reference' => 'RIEL-TRK-051', 'description' => 'Riel electrificado 2m, negro mate, para spots direccionables', 'brand' => 'LuxTech', 'price_full' => 68.00, 'cost' => 34.00, 'stock_quantity' => 35],
            ['reference' => 'RIEL-TRK-052', 'description' => 'Riel electrificado 3m, blanco, alta capacidad de carga', 'brand' => 'LuxTech', 'price_full' => 92.00, 'cost' => 46.00, 'stock_quantity' => 20],
            ['reference' => 'SPOT-TRK-012', 'description' => 'Spot para riel electrificado 15W, blanco, ángulo ajustable', 'brand' => 'LuxTech', 'price_full' => 28.50, 'cost' => 13.50, 'stock_quantity' => 180],
            ['reference' => 'SPOT-TRK-013', 'description' => 'Spot para riel electrificado 15W, negro, ángulo ajustable', 'brand' => 'LuxTech', 'price_full' => 28.50, 'cost' => 13.50, 'stock_quantity' => 160],
            ['reference' => 'CONE-TRK-060', 'description' => 'Conector en L para riel electrificado, blanco', 'brand' => 'LuxTech', 'price_full' => 9.90, 'cost' => 4.20, 'stock_quantity' => 90],

            // SCRUM-715 — Sala Elegante (ampliación)
            ['reference' => 'CAND-SAL-006', 'description' => 'Candelabro moderno esfera 8 luces, acabado cromo', 'brand' => 'Signature Lighting', 'price_full' => 650.00, 'cost' => 305.00, 'stock_quantity' => 5],
            ['reference' => 'PISO-LAMP-031', 'description' => 'Lámpara de piso trípode, base madera y pantalla de lino', 'brand' => 'Atlantic Home', 'price_full' => 145.00, 'cost' => 68.00, 'stock_quantity' => 18],
            ['reference' => 'MESA-LAMP-016', 'description' => 'Lámpara de mesa cerámica, pantalla textil crudo', 'brand' => 'Atlantic Home', 'price_full' => 95.50, 'cost' => 44.00, 'stock_quantity' => 22],

            // SCRUM-715 — Iluminación LED Básica (ampliación)
            ['reference' => 'PANEL-LED-070', 'description' => 'Panel LED 60x60cm, 40W, luz neutra 4000K', 'brand' => 'LuxTech', 'price_full' => 38.00, 'cost' => 17.50, 'stock_quantity' => 120],
            ['reference' => 'TIRA-LED-101', 'description' => 'Tira LED blanca cálida 10m, regulable con control', 'brand' => 'LuxTech', 'price_full' => 42.00, 'cost' => 19.00, 'stock_quantity' => 95],
            ['reference' => 'BOMB-LED-200', 'description' => 'Bombillo LED E27 9W, luz cálida 2700K', 'brand' => 'LuxTech', 'price_full' => 4.50, 'cost' => 1.80, 'stock_quantity' => 500],

            // SCRUM-715 — Iluminación Exterior (familia nueva)
            ['reference' => 'FARO-EXT-300', 'description' => 'Farol de pared exterior IP65, acabado negro', 'brand' => 'ProLine Comercial', 'price_full' => 58.00, 'cost' => 27.00, 'stock_quantity' => 40],
            ['reference' => 'REFL-EXT-301', 'description' => 'Reflector LED 50W exterior con sensor de movimiento', 'brand' => 'ProLine Comercial', 'price_full' => 34.90, 'cost' => 15.50, 'stock_quantity' => 70],
            ['reference' => 'POSTE-EXT-302', 'description' => 'Poste de jardín solar LED, 60cm, sensor crepuscular', 'brand' => 'ProLine Comercial', 'price_full' => 22.00, 'cost' => 9.50, 'stock_quantity' => 60],
            ['reference' => 'CINT-EXT-303', 'description' => 'Cinta LED exterior IP67, 5m, blanco frío', 'brand' => 'LuxTech', 'price_full' => 39.00, 'cost' => 17.00, 'stock_quantity' => 55],
            ['reference' => 'APLQ-EXT-304', 'description' => 'Aplique exterior cilíndrico, luz arriba/abajo, IP54', 'brand' => 'ProLine Comercial', 'price_full' => 46.50, 'cost' => 21.00, 'stock_quantity' => 38],

            // SCRUM-715 — Colgantes de Diseño (familia nueva)
            ['reference' => 'LAMP-COL-003', 'description' => 'Colgante nórdico, madera natural y metal negro', 'brand' => 'Nordic Glow', 'price_full' => 165.00, 'cost' => 78.00, 'stock_quantity' => 25],
            ['reference' => 'LAMP-COL-004', 'description' => 'Colgante esfera de cristal soplado, acabado ámbar', 'brand' => 'Nordic Glow', 'price_full' => 210.00, 'cost' => 98.00, 'stock_quantity' => 12],
            ['reference' => 'LAMP-COL-005', 'description' => 'Colgante industrial tipo jaula, acabado cobre', 'brand' => 'Nordic Glow', 'price_full' => 128.00, 'cost' => 60.00, 'stock_quantity' => 20],
            ['reference' => 'LAMP-COL-006', 'description' => 'Set de 3 colgantes escalonados, base madera', 'brand' => 'Nordic Glow', 'price_full' => 285.00, 'cost' => 132.00, 'stock_quantity' => 9],

            // SCRUM-715 — Empotrables & Downlights (familia nueva)
            ['reference' => 'DOWN-EMP-400', 'description' => 'Downlight LED 18W redondo, empotrar, 3000K', 'brand' => 'LuxTech', 'price_full' => 19.90, 'cost' => 8.90, 'stock_quantity' => 260],
            ['reference' => 'DOWN-EMP-401', 'description' => 'Downlight LED 18W cuadrado, empotrar, 4000K', 'brand' => 'LuxTech', 'price_full' => 21.50, 'cost' => 9.50, 'stock_quantity' => 220],
            ['reference' => 'DOWN-EMP-402', 'description' => 'Downlight LED regulable CCT (3000K-6000K), control remoto', 'brand' => 'LuxTech', 'price_full' => 32.00, 'cost' => 14.50, 'stock_quantity' => 90],
            ['reference' => 'OJOBUEY-EMP-403', 'description' => 'Ojo de buey empotrable exterior, IP65', 'brand' => 'ProLine Comercial', 'price_full' => 27.00, 'cost' => 12.00, 'stock_quantity' => 65],

            // SCRUM-715 — Baño & Espejo (familia nueva)
            ['reference' => 'ESP-BAN-500', 'description' => 'Luz LED para espejo de baño, IP44, sensor táctil', 'brand' => 'LuxTech', 'price_full' => 54.00, 'cost' => 24.50, 'stock_quantity' => 45],
            ['reference' => 'APLQ-BAN-501', 'description' => 'Aplique de baño doble, IP44, acabado cromo', 'brand' => 'Atlantic Home', 'price_full' => 62.00, 'cost' => 28.00, 'stock_quantity' => 30],
            ['reference' => 'TECHO-BAN-502', 'description' => 'Plafón de techo IP44 para baño, difusor opalino', 'brand' => 'Atlantic Home', 'price_full' => 36.50, 'cost' => 16.50, 'stock_quantity' => 55],

            // SCRUM-715 — Cocina & Bajo Alacena (familia nueva)
            ['reference' => 'BARRA-COC-600', 'description' => 'Barra LED bajo alacena, 30cm, sensor de movimiento', 'brand' => 'LuxTech', 'price_full' => 22.00, 'cost' => 9.80, 'stock_quantity' => 80],
            ['reference' => 'BARRA-COC-601', 'description' => 'Barra LED bajo alacena, 60cm, sensor de movimiento', 'brand' => 'LuxTech', 'price_full' => 32.00, 'cost' => 14.50, 'stock_quantity' => 60],
            ['reference' => 'COLG-COC-602', 'description' => 'Set de 3 colgantes para isla de cocina, acabado negro mate', 'brand' => 'Atlantic Home', 'price_full' => 175.00, 'cost' => 82.00, 'stock_quantity' => 15],

            // SCRUM-715 — Oficina & Comercial (familia nueva)
            ['reference' => 'PANEL-OFI-700', 'description' => 'Panel LED oficina 60x120cm, 48W, luz fría 5000K', 'brand' => 'ProLine Comercial', 'price_full' => 55.00, 'cost' => 25.00, 'stock_quantity' => 70],
            ['reference' => 'RIEL-OFI-701', 'description' => 'Riel comercial 3m, alta potencia, blanco', 'brand' => 'ProLine Comercial', 'price_full' => 110.00, 'cost' => 52.00, 'stock_quantity' => 25],
            ['reference' => 'SPOT-OFI-702', 'description' => 'Spot comercial orientable 25W, para riel de alta potencia', 'brand' => 'ProLine Comercial', 'price_full' => 48.00, 'cost' => 22.00, 'stock_quantity' => 40],

            // SCRUM-715 — Decorativo Vintage/Industrial (familia nueva)
            ['reference' => 'BOMB-VIN-800', 'description' => 'Bombillo filamento vintage E27, ámbar decorativo', 'brand' => 'Nordic Glow', 'price_full' => 6.90, 'cost' => 2.80, 'stock_quantity' => 200],
            ['reference' => 'LAMP-VIN-801', 'description' => 'Lámpara colgante vintage estilo Edison, portalámparas expuesto', 'brand' => 'Nordic Glow', 'price_full' => 58.00, 'cost' => 26.00, 'stock_quantity' => 35],
            ['reference' => 'APLQ-VIN-802', 'description' => 'Aplique de pared industrial tipo tubo, acabado negro', 'brand' => 'Nordic Glow', 'price_full' => 44.00, 'cost' => 20.00, 'stock_quantity' => 28],
        ];

        foreach ($products as $product) {
            // SCRUM-237/240 — `name` es ahora NOT NULL (ver migración 2026_08_15_100000_...);
            // el texto que estos 46 arrays ya traían en `description` siempre fue en realidad el
            // nombre visible del producto (título corto, ej. "Lámpara colgante LED 40W, acabado
            // dorado"), no una descripción larga aparte — se mapea a `name` en vez de duplicarlo
            // en las 2 columnas, `description` queda vacía como en cualquier alta real hoy.
            $product['name'] = $product['description'];
            unset($product['description']);
            CatalogProduct::create($product);
        }
    }
}
