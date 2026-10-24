<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations\Lafayette\Concerns;

use App\Modules\Bodega\Models\ProductWarehouseStock;
use App\Modules\Bodega\Models\Warehouse;
use App\Modules\Compras\Models\Provider;
use App\Modules\Integrations\Lafayette\Data\MappedLafayetteProducto;
use App\Modules\Integrations\Lafayette\Models\LafayetteProducto;
use App\Modules\Integrations\Lafayette\Services\LafayetteProductoLoader;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Modules\VentasDiseno\Models\CatalogProductFamily;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-990 — escenario realista para `lafayette:aislar-catalogo`: lo que el sincronizador viejo dejó en dev/test/prod. Los productos ICG se
 * sobrescriben con `CatalogProduct::update()` para que los eventos de `audit_logs` se generen DE VERDAD (el comando restaura desde ahí).
 */
trait ArmaEscenarioLafayette
{
    /** @var array<string, int> */
    private array $ids = [];

    private function tenant(): void
    {
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    /** @param array<string, mixed> $options */
    private function limpiar(array $options = []): \Illuminate\Testing\PendingCommand
    {
        // --operador es obligatorio con --apply (N8): los tests lo aportan por defecto.
        $defecto = isset($options['--apply']) ? ['--operador' => 'tests'] : [];

        return $this->artisan('lafayette:aislar-catalogo', ['--tenant' => 'atlantic'] + $options + $defecto);
    }

    private function zl(): int
    {
        return (int) Warehouse::query()->where('name', 'Bodega Zona Libre')->value('id');
    }

    private function central(): int
    {
        return (int) Warehouse::query()->where('name', 'Bodega Central')->value('id');
    }

    private function stockZl(int $productId, int $qty): void
    {
        $wh = (new Warehouse())->getTable();
        $zl = $this->zl();
        // Reproduce el estado HISTÓRICO previo a SCRUM-990: se abre la bodega un instante para insertar (el trigger la cerraría).
        DB::table($wh)->where('id', $zl)->update(['solo_consulta' => false]);
        ProductWarehouseStock::olvidarBodegasSoloConsulta(); // el cambio por SQL directo no dispara eventos del modelo
        ProductWarehouseStock::create(['catalog_product_id' => $productId, 'warehouse_id' => $zl, 'quantity' => $qty]);
        DB::table($wh)->where('id', $zl)->update(['solo_consulta' => true]);
        ProductWarehouseStock::olvidarBodegasSoloConsulta();
    }

    private function stockCentral(int $productId, int $qty): void
    {
        ProductWarehouseStock::create(['catalog_product_id' => $productId, 'warehouse_id' => $this->central(), 'quantity' => $qty]);
    }

    private function proveedorLafayette(): Provider
    {
        return Provider::query()->where('name', 'Lafayette Lighting')->first()
            ?? Provider::query()->create(['name' => 'Lafayette Lighting', 'category' => Provider::CATEGORY_LOCALES, 'origin' => Provider::ORIGIN_LOCAL, 'currency' => 'USD', 'is_active' => true]);
    }

    private function usuarioId(): int
    {
        return (int) DB::table('atlantic_auth.users')->orderBy('id')->value('id');
    }

    /** PURO: lo creó el sincronizador (evento `created` con external_source Lafayette), solo tiene su fila de stock en Zona Libre. */
    private function crearPuro(string $clave, int $stockZl = 100): int
    {
        $p = CatalogProduct::create([
            'reference' => "PURO-{$clave}", 'factory_reference' => "LNP-PURO-{$clave}", 'name' => "Producto puro {$clave}", 'brand' => 'FLUA', 'category' => 'LAMPARAS',
            'price_full' => 5.5, 'provider_id' => $this->proveedorLafayette()->id, 'is_active' => true, 'external_source' => 'lafayette_lighting', 'external_synced_at' => now(),
        ]);
        $this->stockZl((int) $p->id, $stockZl);

        return (int) $p->id;
    }

    /**
     * Lo que hacía el loader viejo con un producto que YA existía: lo vinculaba (1er evento: external_source + proveedor + datos de Lafayette) y lo
     * volvía a pisar en corridas posteriores. `$cambiaMarca = false` => el enlace NO cambia la marca (queda fuera del old_values del primer evento).
     */
    private function sobrescribirComoLafayette(CatalogProduct $p, string $clave, bool $cambiaMarca = true): void
    {
        $cambios = [
            'external_source' => 'lafayette_lighting', 'external_synced_at' => now(), 'provider_id' => $this->proveedorLafayette()->id,
            'factory_reference' => "LNP-ICG-{$clave}", 'name' => "Nombre Lafayette {$clave}", 'photo_url' => "https://lafayette.example/{$clave}.jpg",
            'category' => 'LAMPARAS', 'price_full' => 99.0,
        ];
        if ($cambiaMarca) {
            $cambios['brand'] = 'FLUA';
        }
        $p->update($cambios);
        $p->update(['name' => "Nombre Lafayette v2 {$clave}"]); // 2a corrida del sincronizador viejo
    }

    /**
     * ICG: ya existía (import de ICG, enlazado a `legacy.productos`) con sus datos ORIGINALES y el sincronizador viejo lo sobrescribió.
     *
     * @param array<string, mixed> $originales
     */
    private function crearIcg(string $clave, array $originales, bool $cambiaMarca = true): int
    {
        // `fresh()`: el sincronizador viejo trabajaba sobre productos LEÍDOS de la BD (todas las columnas, nulos incluidos), así que su old_values trae las claves
        // con valor null (p. ej. provider_id). Un modelo recién creado solo conoce los atributos que se le pasaron.
        $p = CatalogProduct::create(['reference' => "ICG-{$clave}", 'provider_id' => null, 'is_active' => true] + $originales)->fresh();
        DB::table('atlantic_legacy.productos')->insert([
            'icg_codarticulo' => 9000 + (int) crc32($clave) % 90000, 'descripcion' => (string) ($originales['name'] ?? $clave), 'catalog_product_id' => $p->id, 'descatalogado' => false,
        ]);
        $this->sobrescribirComoLafayette($p, $clave, $cambiaMarca);

        return (int) $p->id;
    }

    /** Producto de AtlanticERP creado a mano (NO está en legacy.productos) que el loader viejo "vinculó" por referencia. */
    private function crearPreexistenteNoIcg(string $clave): int
    {
        $p = CatalogProduct::create([
            'reference' => "MANUAL-{$clave}", 'factory_reference' => "FACT-MANUAL-{$clave}", 'name' => "Producto manual de AtlanticERP {$clave}", 'brand' => "MarcaManual{$clave}",
            'photo_url' => null, 'category' => 'Cat Manual', 'price_full' => 12.0, 'provider_id' => null, 'is_active' => true,
        ])->fresh();
        $this->sobrescribirComoLafayette($p, $clave);

        return (int) $p->id;
    }

    private function referenciarEnFamilia(int $productId, string $nombre = 'Familia de prueba'): void
    {
        $familia = CatalogProductFamily::create(['name' => $nombre, 'is_active' => true]);
        DB::table('atlantic_ventas_diseno.catalog_product_family_items')->insert(['family_id' => $familia->id, 'catalog_product_id' => $productId]);
    }

    private function repoblarEspejo(int $n = 6): void
    {
        app(LafayetteProductoLoader::class)->load(array_map(
            static fn (int $i): MappedLafayetteProducto => new MappedLafayetteProducto(sprintf('ESP%04d', $i), "REF-{$i}", "Espejo {$i}", 'FLUA', 'LAMPARAS', null, 10, 1.0, 1.5, null),
            range(1, $n),
        ));
    }

    /**
     * @return array<string, int>
     */
    private function escenario(): array
    {
        $this->ids['provider'] = (int) $this->proveedorLafayette()->id;

        // 3 PUROS: los creó el sincronizador; solo tienen su fila de stock en Zona Libre.
        foreach ([1, 2, 3] as $i) {
            $this->ids["puro{$i}"] = $this->crearPuro((string) $i, 100 * $i);
        }

        // 3 ICG con sus datos ORIGINALES (ICG3: Lafayette NO cambió la marca => old_values NO trae `brand`).
        $originales = [
            1 => ['factory_reference' => 'FACT-ICG-1', 'name' => 'Candelabro ICG 1', 'brand' => 'MarcaICG1', 'photo_url' => null, 'category' => 'Cat ICG', 'price_full' => 10.5],
            2 => ['factory_reference' => 'FACT-ICG-2', 'name' => 'Candelabro ICG 2', 'brand' => 'MarcaICG2', 'photo_url' => 'public/legacy-photos/i2.jpg', 'category' => 'Cat ICG', 'price_full' => 20.0],
            3 => ['factory_reference' => 'FACT-ICG-3', 'name' => 'Candelabro ICG 3', 'brand' => 'MarcaICG3', 'photo_url' => null, 'category' => 'Cat ICG', 'price_full' => 30.0],
        ];
        foreach ($originales as $i => $orig) {
            $this->ids["icg{$i}"] = $this->crearIcg((string) $i, $orig, cambiaMarca: $i !== 3);
        }
        $this->stockCentral($this->ids['icg2'], 8);

        // Un ICG referenciado por un documento (ítem de familia), y un producto de AtlanticERP sin nada que ver con Lafayette.
        $this->referenciarEnFamilia($this->ids['icg1']);
        $n = CatalogProduct::create(['reference' => 'ATLANTICERP-1', 'name' => 'Producto de AtlanticERP', 'price_full' => 7.0, 'is_active' => true]);
        $this->ids['atlanticerp1'] = (int) $n->id;
        $this->stockCentral((int) $n->id, 5);

        // Historial de prueba contra Zona Libre (kardex).
        DB::table('atlantic_bodega.inventory_movements')->insert([
            'catalog_product_id' => $n->id, 'warehouse_id' => $this->zl(), 'tipo' => 'entrada', 'cantidad' => 3, 'referencia_texto' => 'Prueba', 'saldo_inicial' => 0, 'saldo_resultante' => 3,
        ]);

        // El espejo ya repoblado desde la fuente (requisito previo de la limpieza).
        $this->repoblarEspejo(6);

        return $this->ids;
    }

    /** @return array<string, mixed> */
    private function foto(): array
    {
        return [
            'cp'      => DB::table((new CatalogProduct())->getTable())->count(),
            'cp_json' => DB::table((new CatalogProduct())->getTable())->orderBy('id')->get()->toJson(),
            'stock'   => DB::table((new ProductWarehouseStock())->getTable())->orderBy('id')->get()->toJson(),
            'mov'     => DB::table('atlantic_bodega.inventory_movements')->count(),
            'laf'     => LafayetteProducto::query()->count(),
            'bak'     => Schema::hasTable('atlantic_ventas_diseno.catalog_products_bak_scrum990'),
        ];
    }

    /** Cotización real con `$lineas` líneas (quote_items) que referencian el producto del catálogo. */
    private function referenciarEnCotizacion(int $productId, int $lineas = 1): int
    {
        $master = \App\Modules\VentasDiseno\Models\MasterClient::create(['name' => 'Cliente ' . uniqid(), 'default_price_type' => 'project']);
        $sub = \App\Modules\VentasDiseno\Models\SubClient::create([
            'master_client_id' => $master->id, 'business_name' => 'Sub ' . uniqid(), 'tax_id' => sprintf('155-%04d-1-2026', random_int(1000, 9999)), 'category' => 'b_architect_designer',
        ]);
        $project = \App\Modules\VentasDiseno\Models\SalesProject::create(['sub_client_id' => $sub->id, 'name' => 'Proyecto ' . uniqid(), 'tag' => 'both']);
        $card = \App\Modules\VentasDiseno\Models\PipelineCard::create([
            'sales_project_id' => $project->id, 'stage' => 'lead', 'master_client_id' => $master->id, 'sub_client_id' => $sub->id, 'owner_id' => $this->usuarioId(), 'stage_changed_at' => now(),
        ]);
        $quote = \App\Modules\VentasDiseno\Models\Quote::create([
            'master_client_id' => $master->id, 'sub_client_id' => $sub->id, 'sales_project_id' => $project->id, 'description' => 'Cotización de prueba', 'owner_id' => $this->usuarioId(),
        ]);
        $part = \App\Modules\VentasDiseno\Models\QuotePart::create(['quote_id' => $quote->id, 'name' => 'Partida', 'position' => 1]);
        for ($i = 0; $i < $lineas; $i++) {
            \App\Modules\VentasDiseno\Models\QuoteItem::create([
                'quote_part_id' => $part->id, 'catalog_product_id' => $productId, 'is_custom' => false, 'reference' => 'REF-Q', 'description' => 'Línea', 'quantity' => 1, 'unit_price' => 10.0,
            ]);
        }

        return (int) $quote->id;
    }
}
