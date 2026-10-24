<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations\Lafayette;

use App\Modules\Auth\Models\AuditLog;
use App\Modules\Bodega\Models\OrderItem;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Shared\Services\AuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Bodega\Concerns\BodegaOrderTestHelpers;
use Tests\Feature\FeatureTestCase;
use Tests\Feature\Integrations\Lafayette\Concerns\ArmaEscenarioLafayette;

/**
 * SCRUM-990 (ajuste por el dry-run real en TEST, decisiones de Luis) — los puros referenciados por documentos se PROMUEVEN (pierden la marca de
 * Lafayette, no se borran) y las filas de `order_items` contra Zona Libre se CONSERVAN con `--conservar-historial-zona-libre`. Sobre la BD real.
 */
final class LafayetteAislarCatalogoPromocionTest extends FeatureTestCase
{
    use ArmaEscenarioLafayette;
    use BodegaOrderTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant();
    }

    /** @return array{quote: int, order_item: int} puro1 usado por 3 líneas de cotización y 1 línea de pedido (como el #12900/#22454 de test). */
    private function puroUsadoPorDocumentos(): array
    {
        $ids = $this->escenario();
        $this->referenciarEnCotizacion($ids['puro1'], 3);
        $order = $this->makeOrder(['stage' => \App\Modules\Bodega\Models\Order::STAGE_ASIGNADO], ['catalog_product_id' => $ids['puro1'], 'qty_requested' => 2]);

        return ['quote' => DB::table('atlantic_ventas_diseno.quote_items')->where('catalog_product_id', $ids['puro1'])->count(), 'order_item' => (int) $order->items->firstOrFail()->id];
    }

    private function refs(int $productId): array
    {
        return [
            'quote_items' => DB::table('atlantic_ventas_diseno.quote_items')->where('catalog_product_id', $productId)->count(),
            'order_items' => DB::table('atlantic_bodega.order_items')->where('catalog_product_id', $productId)->count(),
        ];
    }

    public function test_puro_referenciado_por_cotizacion_y_pedido_se_promueve_y_el_puro_sin_referencias_se_borra(): void
    {
        $ids = $this->escenario();
        $this->referenciarEnCotizacion($ids['puro1'], 3);
        $this->makeOrder(['stage' => \App\Modules\Bodega\Models\Order::STAGE_ASIGNADO], ['catalog_product_id' => $ids['puro1'], 'qty_requested' => 2]);
        $refsAntes = $this->refs($ids['puro1']);
        $this->assertSame(['quote_items' => 3, 'order_items' => 1], $refsAntes);
        $valores = CatalogProduct::find($ids['puro1'])->only(['name', 'reference', 'factory_reference', 'provider_id', 'price_full', 'brand']);
        $totalAntes = CatalogProduct::query()->count();

        $this->limpiar(['--apply' => true, '--yes' => true])->assertExitCode(0);
        $this->tenant();

        $p = CatalogProduct::find($ids['puro1']);
        $this->assertNotNull($p, 'el puro referenciado NO se borra');
        $this->assertNull($p->external_source);
        $this->assertNull($p->external_synced_at);
        $this->assertSame($valores, $p->only(['name', 'reference', 'factory_reference', 'provider_id', 'price_full', 'brand']), 'conserva su dato actual');
        $this->assertSame($refsAntes, $this->refs($ids['puro1']), 'las referencias quedan intactas');
        $this->assertNull(CatalogProduct::find($ids['puro2']));
        $this->assertNull(CatalogProduct::find($ids['puro3']));
        $this->assertSame($totalAntes - 2, CatalogProduct::query()->count(), 'total - puros_borrados');
        $this->assertSame(0, CatalogProduct::query()->where('external_source', 'lafayette_lighting')->count());
        $this->assertSame(1, DB::table('atlantic_ventas_diseno.catalog_products_bak_scrum990')->where('id', $ids['puro1'])->count(), 'el respaldo incluye al promovido');

        $m = AuditLog::query()->where('action', AuditService::BODEGA_LAFAYETTE_CATALOG_ISOLATED)->firstOrFail()->metadata;
        $this->assertSame(1, $m['puros_promovidos']);
        $this->assertSame(2, $m['puros_borrados']);
        $this->assertSame([$ids['puro1']], $m['ids_puros_promovidos']);
    }

    public function test_el_dry_run_lista_los_promovidos_con_sus_usos_y_no_escribe_nada(): void
    {
        $ids = $this->escenario();
        $this->referenciarEnCotizacion($ids['puro1'], 3);
        $antes = $this->foto();
        $auditoria = AuditLog::query()->count();

        $this->limpiar()->expectsOutputToContain('quote_items.catalog_product_id: 3')->assertExitCode(0);
        $this->tenant();

        $this->assertSame($antes, $this->foto());
        $this->assertSame($auditoria, AuditLog::query()->count());
    }

    public function test_un_puro_referenciado_sin_evento_de_creacion_sigue_exigiendo_la_regla_de_evidencia(): void
    {
        $ids = $this->escenario();
        $this->referenciarEnCotizacion($ids['puro1'], 1);
        AuditLog::query()->where('entity_type', 'catalog_product')->where('entity_id', (string) $ids['puro1'])->delete();
        $antes = $this->foto();

        $this->limpiar(['--apply' => true, '--yes' => true])->expectsOutputToContain('NINGÚN evento de creación')->assertExitCode(1);
        $this->tenant();
        $this->assertSame($antes, $this->foto());

        $this->limpiar(['--apply' => true, '--yes' => true, '--permitir-puros-sin-evidencia' => true])->assertExitCode(0);
        $this->tenant();
        $this->assertNotNull(CatalogProduct::find($ids['puro1']));
    }

    public function test_si_la_verificacion_de_los_promovidos_falla_hay_rollback_completo(): void
    {
        $ids = $this->escenario();
        $this->referenciarEnCotizacion($ids['puro1'], 1);
        $antes = $this->foto();
        $cp = (new CatalogProduct())->getTable();

        // Simula un efecto colateral: al quitar la marca, algo cambia el nombre del promovido.
        DB::statement("CREATE OR REPLACE FUNCTION public.test_scrum990_muta_promovido() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN IF OLD.external_source IS NOT NULL AND NEW.external_source IS NULL AND OLD.reference LIKE 'PURO-%' THEN NEW.name := NEW.name || ' (mutado)'; END IF; RETURN NEW; END \$\$");
        DB::statement("CREATE TRIGGER test_scrum990_muta_promovido BEFORE UPDATE ON {$cp} FOR EACH ROW EXECUTE FUNCTION public.test_scrum990_muta_promovido()");

        try {
            $this->limpiar(['--apply' => true, '--yes' => true])->expectsOutputToContain('cambiaron de valor')->assertExitCode(1);
            $this->tenant();
        } finally {
            DB::statement("DROP TRIGGER IF EXISTS test_scrum990_muta_promovido ON {$cp}");
            DB::statement('DROP FUNCTION IF EXISTS public.test_scrum990_muta_promovido()');
        }

        $this->assertSame($antes, $this->foto());
        $this->assertFalse(Schema::hasTable('atlantic_ventas_diseno.catalog_products_bak_scrum990'));
    }

    public function test_es_idempotente_la_segunda_corrida_no_hace_nada(): void
    {
        $ids = $this->escenario();
        $this->referenciarEnCotizacion($ids['puro1'], 2);

        $this->limpiar(['--apply' => true, '--yes' => true])->assertExitCode(0);
        $this->tenant();
        $despues = DB::table((new CatalogProduct())->getTable())->orderBy('id')->get()->toJson();
        $auditorias = AuditLog::query()->where('action', AuditService::BODEGA_LAFAYETTE_CATALOG_ISOLATED)->count();

        // Los respaldos de la 1a corrida se conservan hasta la aceptación; la 2a no debe ni intentar nada.
        $this->limpiar(['--apply' => true, '--yes' => true])->expectsOutputToContain('Nada que hacer')->assertExitCode(0);
        $this->tenant();

        $this->assertSame($despues, DB::table((new CatalogProduct())->getTable())->orderBy('id')->get()->toJson());
        $this->assertSame($auditorias, AuditLog::query()->where('action', AuditService::BODEGA_LAFAYETTE_CATALOG_ISOLATED)->count());
    }

    // ── order_items contra Zona Libre: historial conservable ─────────────────────────────────────────────────

    private function lineaDePedidoContraZl(): int
    {
        $order = $this->makeOrder(['stage' => \App\Modules\Bodega\Models\Order::STAGE_ASIGNADO], ['qty_requested' => 1, 'warehouse_id' => $this->zl()]);

        return (int) $order->items->firstOrFail()->id;
    }

    public function test_order_items_contra_zona_libre_sin_la_bandera_bloquea_y_con_ella_se_conserva_sin_tocarla(): void
    {
        $this->escenario();
        $linea = $this->lineaDePedidoContraZl();
        $filaAntes = DB::table('atlantic_bodega.order_items')->where('id', $linea)->first();
        $antes = $this->foto();

        $this->limpiar(['--apply' => true, '--yes' => true])->expectsOutputToContain('--conservar-historial-zona-libre')->assertExitCode(1);
        $this->tenant();
        $this->assertSame($antes, $this->foto(), 'sin la bandera el comando sigue rechazando');

        $this->limpiar(['--apply' => true, '--yes' => true, '--conservar-historial-zona-libre' => true])->assertExitCode(0);
        $this->tenant();

        $this->assertEquals($filaAntes, DB::table('atlantic_bodega.order_items')->where('id', $linea)->first(), 'la línea queda idéntica');
        $this->assertSame($this->zl(), (int) OrderItem::query()->findOrFail($linea)->warehouse_id);
        $this->assertSame(0, \App\Modules\Bodega\Models\ProductWarehouseStock::query()->where('warehouse_id', $this->zl())->count());
        $this->assertSame(0, CatalogProduct::query()->where('external_source', 'lafayette_lighting')->count());
    }

    public function test_el_dry_run_con_la_bandera_lista_aparte_el_historial_que_se_conserva(): void
    {
        $this->escenario();
        $this->lineaDePedidoContraZl();

        $this->limpiar(['--conservar-historial-zona-libre' => true])->expectsOutputToContain('se CONSERVA tal cual')->assertExitCode(0);
    }

    public function test_una_tabla_desconocida_con_filas_contra_zona_libre_se_rechaza_incluso_con_la_bandera(): void
    {
        $this->escenario();
        $antes = $this->foto();
        DB::statement('CREATE TABLE atlantic_bodega.zz_tabla_desconocida (id bigserial PRIMARY KEY, warehouse_id bigint REFERENCES atlantic_bodega.warehouses(id))');

        try {
            DB::table('atlantic_bodega.zz_tabla_desconocida')->insert(['warehouse_id' => $this->zl()]);

            $this->limpiar(['--apply' => true, '--yes' => true, '--conservar-historial-zona-libre' => true])
                ->expectsOutputToContain('tabla desconocida')
                ->assertExitCode(1);
            $this->tenant();
        } finally {
            DB::statement('DROP TABLE IF EXISTS atlantic_bodega.zz_tabla_desconocida');
        }

        $this->assertSame($antes, $this->foto());
    }
}
