<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Modules\VentasDiseno\Models\CatalogProductFamily;
use App\Shared\Multitenancy\Tenant;
use App\Shared\Catalogs\CatalogEntry;
use Illuminate\Database\Seeder;

/**
 * SCRUM-745 (Highest, épica SCRUM-327 UAT) — data de prueba de Catálogo
 * (Productos y Familias) pedida EXPLÍCITAMENTE en Producción para que el
 * equipo de UAT pueda validar búsquedas/filtros/detalle/familias antes de
 * empezar las pruebas. Distinto de CatalogProductSeeder/CatalogProductFamilySeeder
 * (Tipo B — esos son datos demo de dev.atlanticerp.ai, nunca corren en producción):
 * este seeder es Tipo A, corre en TODOS los entornos (ver DatabaseSeeder).
 *
 * Identificable a propósito (criterio de aceptación del ticket, decisión de
 * Luis 2026-08-11): `reference` con prefijo `UAT-`, `description` con prefijo
 * `[PRUEBA UAT] `, familias con `name` prefijado igual — mismo criterio de
 * "texto marcador" que `[DEMO]` en CRM o `*@atlantic.test` en Auth, sin
 * columna nueva. Idempotente: si ya existe algún producto `UAT-%`, no repite
 * el seed (evita duplicar en cada deploy). Limpieza real vía
 * CatalogUatTestDataCleanupController (POST /api/admin/catalog/uat-test-data/cleanup,
 * superadmin.all).
 *
 * Fotos (5 de 30, RN del ticket): `photo_url` apunta al endpoint público
 * CatalogUatPlaceholderPhotoController, que genera un placeholder con GD al
 * vuelo (fondo de color + "PRODUCTO DE PRUEBA UAT" + reference) — decisión
 * 2026-08-11 tras descartar subir a S3 (`s3-public`): el bucket `atlanticerp-dev`
 * tiene ACLs deshabilitadas y el disco fuerza `public-read` en cada put(),
 * así que CUALQUIER upload a esa zona pública falla ahora mismo con
 * `AccessControlListNotSupported` (hallazgo reportado aparte — parece
 * afectar también a DocumentService::upload() en la zona pública real de
 * Share Links, fuera de alcance de este ticket). Generar la imagen on-demand
 * evita depender de esa infraestructura rota para data que de entrada es
 * sintética, y no necesita limpieza de storage en el cleanup controller.
 *
 * Se salta 'testing' (a diferencia del resto de seeders Tipo A): `catalog_products`
 * es una tabla compartida con Bodega/Compras (mismo gotcha ya documentado en
 * memory/feedback_catalog_product_shared_table_pollution.md) — 30 productos UAT con
 * rotación/estado/stock variados ensucian conteos, facetas y dropdowns de tests que
 * consultan el catálogo completo sin scopear sus propios fixtures (confirmado
 * 2026-08-11: 16 tests de BodegaInventoryControllerTest/InventoryControllerTest/
 * CatalogControllerTest/PurchaseOrderControllerTest rotos al correr la suite completa
 * con este seeder sin el guard).
 */
class CatalogUatTestDataSeeder extends Seeder
{
    private const REFERENCE_PREFIX   = 'UAT-';
    private const DESCRIPTION_PREFIX = '[PRUEBA UAT] ';
    public const FAMILY_PREFIX      = '[PRUEBA UAT] ';

    /** Distribución por categoría — cubre las categorías demo con al menos 1 producto c/u (Fase 9a: `general` viene del núcleo, el resto se siembra acá). */
    private const CATEGORY_COUNTS = [
        'general'     => 8,
        'accesorios'  => 8,
        'repuestos'   => 7,
        'consumibles' => 7,
    ];

    private const CATEGORY_LABELS = [
        'general'     => 'Producto General',
        'accesorios'  => 'Accesorio',
        'repuestos'   => 'Repuesto',
        'consumibles' => 'Consumible',
    ];

    /** Entradas del dominio `product_category` que el demo agrega además de `general` (de sistema, sembrada por migración). */
    private const DEMO_CATEGORY_ENTRIES = [
        'accesorios'  => 'Accesorios',
        'repuestos'   => 'Repuestos',
        'consumibles' => 'Consumibles',
    ];

    public function run(): void
    {
        // SCRUM-1111: en producción deja de sembrarse. La data UAT de SCRUM-745 ya cumplió su propósito y, con el seeder
        // corriendo en cada deploy, recrearía los 30 productos y 3 familias `[PRUEBA UAT]` tras la limpieza previa al lanzamiento.
        if (app()->environment(['testing', 'production'])) {
            return;
        }

        $tenant = Tenant::where('slug', 'atlantic')->first();

        if ($tenant === null) {
            $this->command->warn('Tenant "atlantic" not found — CatalogUatTestDataSeeder skipped.');
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
        if (CatalogProduct::where('reference', 'like', self::REFERENCE_PREFIX . '%')->exists()) {
            return;
        }

        $this->seedCategoryEntries();

        $definitions = $this->buildDefinitions();
        $rotations   = CatalogProduct::ROTATIONS;
        // 5 de 30 con foto, repartidas entre categorías distintas para que el
        // criterio "distribuidos entre categorías" también aplique a las fotos.
        $photoIndexes = [0, 6, 12, 18, 24];

        $productsByCategory = [];

        foreach ($definitions as $i => $def) {
            $reference   = sprintf('%s%s-%03d', self::REFERENCE_PREFIX, strtoupper(substr($def['category'], 0, 4)), $i + 1);
            $description = self::DESCRIPTION_PREFIX . $def['name'];
            $price       = round(18 + $i * 6.35, 2);
            $cost        = round($price * 0.46, 2);
            // Algunos en 0 a propósito — cubre el estado "sin stock" del catálogo.
            $stock       = $i % 5 === 0 ? 0 : 8 + $i * 2;

            // Relativa a propósito, NO url(): APP_URL está mal configurado en los 3 entornos
            // (apunta a la IP pública del EC2 en vez de {dev,test,''}.atlanticerp.ai — hallazgo real
            // 2026-08-11, ver memoria project_hallazgo_app_url_misconfigurado.md), así que
            // url() genera un link que el navegador no puede cargar. El frontend siempre sirve
            // esta pantalla desde el mismo origin que la API (nginx proxea /api y /public al
            // mismo dominio), así que una ruta relativa resuelve bien sin depender de APP_URL.
            $photoUrl = in_array($i, $photoIndexes, true)
                ? "/public/catalog/uat-placeholder/{$reference}?category=" . urlencode($def['category'])
                : null;

            $product = CatalogProduct::create([
                'reference'      => $reference,
                // SCRUM-237/240 — `name` NOT NULL (ver migración 2026_08_15_100000_...); el
                // marcador `[PRUEBA UAT]` se conserva en el nombre visible, no en `description`
                // (que queda vacía, mismo criterio que el resto del backfill).
                'name'           => $description,
                'brand'          => 'Data de Prueba UAT',
                'photo_url'      => $photoUrl,
                'price_full'     => $price,
                'cost'           => $cost,
                'stock_quantity' => $stock,
                'is_active'      => $i % 7 !== 0,
                'rotation'       => $rotations[$i % count($rotations)],
                'category'       => $def['category'],
            ]);

            $productsByCategory[$def['category']][] = $product->id;
        }

        $this->seedFamilies($productsByCategory);
    }

    /** @return list<array{category: string, name: string}> */
    private function buildDefinitions(): array
    {
        $definitions = [];

        foreach (self::CATEGORY_COUNTS as $category => $count) {
            $label = self::CATEGORY_LABELS[$category];
            for ($n = 1; $n <= $count; $n++) {
                $definitions[] = ['category' => $category, 'name' => "{$label} #{$n}"];
            }
        }

        return $definitions;
    }

    private function seedCategoryEntries(): void
    {
        $order = (int) CatalogEntry::query()->where('domain', 'product_category')->max('sort_order');
        foreach (self::DEMO_CATEGORY_ENTRIES as $key => $label) {
            CatalogEntry::query()->firstOrCreate(
                ['domain' => 'product_category', 'key' => $key],
                ['label' => $label, 'sort_order' => ++$order, 'is_active' => true, 'is_system' => false, 'props' => null],
            );
        }
    }

    /** @param array<string, list<int>> $productsByCategory */
    private function seedFamilies(array $productsByCategory): void
    {
        $families = [
            self::FAMILY_PREFIX . 'Familia Sala' => array_merge(
                array_slice($productsByCategory['general'] ?? [], 0, 3),
                array_slice($productsByCategory['accesorios'] ?? [], 0, 2),
            ),
            self::FAMILY_PREFIX . 'Familia Exterior' => $productsByCategory['repuestos'] ?? [],
            self::FAMILY_PREFIX . 'Familia Empotrados y Artículos' => array_merge(
                array_slice($productsByCategory['consumibles'] ?? [], 0, 3),
                array_slice($productsByCategory['accesorios'] ?? [], 2, 2),
            ),
        ];

        foreach ($families as $name => $productIds) {
            if ($productIds === []) {
                continue;
            }

            $family = CatalogProductFamily::create(['name' => $name, 'is_active' => true]);
            $family->products()->attach($productIds);
        }
    }
}
