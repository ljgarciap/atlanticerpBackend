<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations\Lafayette;

use App\Modules\Auth\Models\AuditLog;
use App\Modules\Bodega\Models\ProductWarehouseStock;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Modules\Integrations\Lafayette\Models\LafayetteProducto;
use App\Shared\Services\AuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\FeatureTestCase;
use Tests\Feature\Integrations\Lafayette\Concerns\ArmaEscenarioLafayette;

/**
 * SCRUM-990 — `lafayette:aislar-catalogo`: limpieza destructiva de una sola vez. Se prueba sobre la BD REAL de pruebas con un
 * escenario que reproduce lo que pasó en dev/test/prod: productos PUROS de Lafayette (con stock en Zona Libre), productos ICG
 * sobrescritos por el sincronizador viejo (con sus eventos REALES en `audit_logs`, generados por `CatalogProduct::update()`),
 * una referencia de documento a un ICG, stock legítimo en Bodega Central e historial de prueba contra Zona Libre.
 */
final class LafayetteAislarCatalogoCommandTest extends FeatureTestCase
{
    use ArmaEscenarioLafayette;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant();
    }

    public function test_dry_run_es_el_modo_por_defecto_y_no_escribe_nada(): void
    {
        $this->escenario();
        $antes = $this->foto();
        $auditoriaAntes = AuditLog::query()->count();

        $this->limpiar()->expectsOutputToContain('Modo dry-run')->assertExitCode(0);
        $this->tenant();

        $this->assertSame($antes, $this->foto());
        $this->assertSame($auditoriaAntes, AuditLog::query()->count(), 'el dry-run no escribe ni siquiera auditoría');
        $this->assertFalse(Schema::hasTable('atlantic_bodega.product_warehouse_stock_bak_scrum990'));
    }

    public function test_apply_restaura_icg_borra_puros_y_stock_zona_libre_y_deja_respaldo_y_auditoria(): void
    {
        $ids = $this->escenario();
        $stockCentralAntes = ProductWarehouseStock::query()->where('warehouse_id', $this->central())->orderBy('id')->get(['catalog_product_id', 'quantity'])->toJson();
        $atlanticerpAntes = CatalogProduct::find($ids['atlanticerp1'])->toJson();

        $this->limpiar(['--apply' => true, '--yes' => true])->assertExitCode(0);
        $this->tenant();

        // Puros: borrados, junto con su stock en Zona Libre.
        foreach ([1, 2, 3] as $i) {
            $this->assertNull(CatalogProduct::find($ids["puro{$i}"]));
        }
        $this->assertSame(0, ProductWarehouseStock::query()->where('warehouse_id', $this->zl())->count());
        $this->assertSame(0, CatalogProduct::query()->where('external_source', 'lafayette_lighting')->count());

        // ICG restaurados a sus valores ORIGINALES (los del PRIMER evento), aunque hubo un segundo evento.
        $i1 = CatalogProduct::findOrFail($ids['icg1']);
        $this->assertSame('FACT-ICG-1', $i1->factory_reference);
        $this->assertSame('Candelabro ICG 1', $i1->name);
        $this->assertSame('MarcaICG1', $i1->brand);
        $this->assertNull($i1->photo_url, 'valor null en old_values: vuelve a null');
        $this->assertNull($i1->provider_id, 'el proveedor Lafayette se quita');
        $this->assertSame('Cat ICG', $i1->category);
        $this->assertSame(10.5, (float) $i1->price_full);
        $this->assertNull($i1->external_source);
        $this->assertNull($i1->external_synced_at);

        $i2 = CatalogProduct::findOrFail($ids['icg2']);
        $this->assertSame('public/legacy-photos/i2.jpg', $i2->photo_url, 'la foto original de ICG vuelve');
        $this->assertSame('Candelabro ICG 2', $i2->name);

        $i3 = CatalogProduct::findOrFail($ids['icg3']);
        $this->assertSame('MarcaICG3', $i3->brand, 'clave ausente en old_values (Lafayette no la cambió): se conserva');
        $this->assertSame('FACT-ICG-3', $i3->factory_reference);
        $this->assertNull($i3->provider_id);

        // Lo que NO debe cambiar.
        $this->assertSame($stockCentralAntes, ProductWarehouseStock::query()->where('warehouse_id', $this->central())->orderBy('id')->get(['catalog_product_id', 'quantity'])->toJson());
        $this->assertSame($atlanticerpAntes, CatalogProduct::find($ids['atlanticerp1'])->toJson());
        $this->assertSame(1, DB::table('atlantic_ventas_diseno.catalog_product_family_items')->where('catalog_product_id', $ids['icg1'])->count(), 'el documento que usa el ICG sigue intacto');
        $this->assertSame(3, DB::table('atlantic_legacy.productos')->whereNotNull('catalog_product_id')->count(), 'los enlaces con legacy siguen');
        $this->assertSame(6, LafayetteProducto::query()->count(), 'el espejo no se toca');

        // Historial de prueba contra Zona Libre: anulado, con respaldo.
        $this->assertSame(0, DB::table('atlantic_bodega.inventory_movements')->where('warehouse_id', $this->zl())->count());
        $this->assertSame(1, DB::table('atlantic_bodega.inventory_movements_bak_scrum990')->count());

        // Respaldos completos.
        $this->assertSame(6, DB::table('atlantic_ventas_diseno.catalog_products_bak_scrum990')->count(), '3 puros + 3 ICG');
        $this->assertSame(3, DB::table('atlantic_bodega.product_warehouse_stock_bak_scrum990')->count());

        // Un único evento de auditoría resumen.
        $evento = AuditLog::query()->where('action', AuditService::BODEGA_LAFAYETTE_CATALOG_ISOLATED)->get();
        $this->assertCount(1, $evento);
        $this->assertSame(3, $evento->first()->metadata['puros_borrados']);
        $this->assertSame(3, $evento->first()->metadata['restaurados']);
    }

    public function test_sin_confirmacion_correcta_no_aplica_nada_y_con_ella_si(): void
    {
        $this->escenario();
        $antes = $this->foto();

        $this->limpiar(['--apply' => true])
            ->expectsQuestion('Para continuar escribe exactamente: APLICAR testing atlantic', 'si')
            ->assertExitCode(1);
        $this->tenant();
        $this->assertSame($antes, $this->foto());

        $this->limpiar(['--apply' => true])
            ->expectsQuestion('Para continuar escribe exactamente: APLICAR testing atlantic', 'APLICAR testing atlantic')
            ->assertExitCode(0);
        $this->tenant();
        $this->assertSame(0, CatalogProduct::query()->where('external_source', 'lafayette_lighting')->count());
    }

    public function test_yes_solo_se_acepta_en_local_y_testing_por_lista_blanca_sobre_app_env(): void
    {
        $this->escenario();
        $antes = $this->foto();

        // Lista BLANCA: cualquier entorno distinto de local/testing lo rechaza (también `dev`, `prod`, `staging`, `Production`...).
        foreach (['production', 'qa', 'dev', 'prod', 'staging', 'Production'] as $entorno) {
            config(['app.env' => $entorno]);

            $this->limpiar(['--apply' => true, '--yes' => true])->expectsOutputToContain('--yes no se acepta')->assertExitCode(1);
            $this->tenant();
            $this->assertSame($antes, $this->foto(), "en {$entorno} --yes no puede aplicar nada");
        }
    }

    public function test_env_en_la_linea_de_comandos_no_permite_evadir_la_lista_blanca(): void
    {
        $this->escenario();
        $antes = $this->foto();
        config(['app.env' => 'production']);

        // `--env=local` cambia `app()->environment()` pero NO APP_ENV (`config('app.env')`), que es lo que lee el comando.
        $this->limpiar(['--apply' => true, '--yes' => true, '--env' => 'local'])->expectsOutputToContain('--yes no se acepta')->assertExitCode(1);
        $this->tenant();

        $this->assertSame($antes, $this->foto());
    }

    public function test_un_puro_referenciado_por_un_documento_se_promueve_y_no_se_borra(): void
    {
        $ids = $this->escenario();
        $this->referenciarEnFamilia($ids['puro2'], 'Otra familia');
        $antes = CatalogProduct::find($ids['puro2'])->only(['name', 'reference', 'provider_id', 'price_full']);

        $this->limpiar(['--apply' => true, '--yes' => true])->assertExitCode(0);
        $this->tenant();

        $p = CatalogProduct::find($ids['puro2']);
        $this->assertNotNull($p, 'un puro usado por un documento jamás se borra');
        $this->assertNull($p->external_source);
        $this->assertSame($antes, $p->only(['name', 'reference', 'provider_id', 'price_full']));
        $this->assertNull(CatalogProduct::find($ids['puro1']), 'los puros sin referencias siguen borrándose');
        $this->assertSame(1, DB::table('atlantic_ventas_diseno.catalog_product_family_items')->where('catalog_product_id', $ids['puro2'])->count());
    }

    public function test_un_puro_con_stock_en_otra_bodega_se_promueve(): void
    {
        $ids = $this->escenario();
        $this->stockCentral($ids['puro1'], 4);

        $this->limpiar(['--apply' => true, '--yes' => true])->assertExitCode(0);
        $this->tenant();

        $this->assertNull(CatalogProduct::find($ids['puro1'])?->external_source);
        $this->assertNotNull(CatalogProduct::find($ids['puro1']));
        $this->assertSame(4, (int) ProductWarehouseStock::query()->where('catalog_product_id', $ids['puro1'])->where('warehouse_id', $this->central())->value('quantity'));
        $this->assertSame(0, ProductWarehouseStock::query()->where('warehouse_id', $this->zl())->count());
    }

    public function test_bloquea_si_un_icg_con_documentos_no_tiene_evento_de_auditoria(): void
    {
        $ids = $this->escenario();
        AuditLog::query()->where('entity_type', 'catalog_product')->where('entity_id', (string) $ids['icg1'])->delete();
        $antes = $this->foto();

        $this->limpiar(['--apply' => true, '--yes' => true, '--permitir-icg-sin-auditoria' => true])
            ->expectsOutputToContain('referenciados por documentos SIN evento')
            ->assertExitCode(1);
        $this->tenant();

        $this->assertSame($antes, $this->foto(), 'ni con --permitir-icg-sin-auditoria: un ICG usado por documentos jamás se deja sin restaurar');
    }

    public function test_icg_sin_evento_y_sin_documentos_solo_se_permite_con_la_opcion_explicita(): void
    {
        $ids = $this->escenario();
        AuditLog::query()->where('entity_type', 'catalog_product')->where('entity_id', (string) $ids['icg3'])->delete();
        $antes = $this->foto();

        $this->limpiar(['--apply' => true, '--yes' => true])->expectsOutputToContain('--permitir-icg-sin-auditoria')->assertExitCode(1);
        $this->tenant();
        $this->assertSame($antes, $this->foto());

        $this->limpiar(['--apply' => true, '--yes' => true, '--permitir-icg-sin-auditoria' => true])->assertExitCode(0);
        $this->tenant();
        $i3 = CatalogProduct::findOrFail($ids['icg3']);
        $this->assertNull($i3->external_source);
        $this->assertNull($i3->provider_id, 'se le quita el proveedor Lafayette');
        $this->assertSame('Nombre Lafayette v2 3', $i3->name, 'sin evento no hay de dónde restaurar el resto: queda como estaba');
    }

    public function test_bloquea_si_el_espejo_aun_no_se_repoblo_desde_la_fuente(): void
    {
        $this->escenario();
        DB::table((new LafayetteProducto())->getTable())->delete();
        $antes = $this->foto();

        $this->limpiar(['--apply' => true, '--yes' => true])->expectsOutputToContain('repoblarla primero')->assertExitCode(1);
        $this->tenant();

        $this->assertSame($antes, $this->foto());
    }

    public function test_bloquea_si_la_bodega_zona_libre_tiene_referencias_que_no_son_historial(): void
    {
        $this->escenario();
        DB::table('atlantic_bodega.warehouse_locations')->insert(['warehouse_id' => $this->zl(), 'codigo' => 'ZL-A1', 'is_active' => true]);
        $antes = $this->foto();

        $this->limpiar(['--apply' => true, '--yes' => true])->expectsOutputToContain('NO es historial anulable')->assertExitCode(1);
        $this->tenant();

        $this->assertSame($antes, $this->foto());
    }

    public function test_conservar_historial_no_anula_los_movimientos(): void
    {
        $this->escenario();

        $this->limpiar(['--apply' => true, '--yes' => true, '--conservar-historial-zona-libre' => true])->assertExitCode(0);
        $this->tenant();

        $this->assertSame(1, DB::table('atlantic_bodega.inventory_movements')->where('warehouse_id', $this->zl())->count());
        $this->assertFalse(Schema::hasTable('atlantic_bodega.inventory_movements_bak_scrum990'));
        $this->assertSame(0, CatalogProduct::query()->where('external_source', 'lafayette_lighting')->count());
    }

    public function test_un_fallo_a_mitad_de_camino_hace_rollback_completo_incluidos_los_respaldos(): void
    {
        $this->escenario();
        $antes = $this->foto();

        DB::statement("CREATE OR REPLACE FUNCTION public.test_scrum990_bloquea_delete() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN RAISE EXCEPTION 'fallo simulado a mitad de la limpieza'; END \$\$");
        $cp = (new CatalogProduct())->getTable();
        DB::statement("CREATE TRIGGER test_scrum990_bloquea BEFORE DELETE ON {$cp} FOR EACH ROW EXECUTE FUNCTION public.test_scrum990_bloquea_delete()");

        try {
            $this->limpiar(['--apply' => true, '--yes' => true])->expectsOutputToContain('ROLLBACK')->assertExitCode(1);
            $this->tenant();
        } finally {
            DB::statement("DROP TRIGGER IF EXISTS test_scrum990_bloquea ON {$cp}");
            DB::statement('DROP FUNCTION IF EXISTS public.test_scrum990_bloquea_delete()');
        }

        // Nada cambió: ni los ICG restaurados (que se hicieron ANTES del borrado que falló), ni stock, ni historial, ni respaldos, ni auditoría.
        $this->assertSame($antes, $this->foto());
        $this->assertFalse(Schema::hasTable('atlantic_bodega.product_warehouse_stock_bak_scrum990'));
        $this->assertSame(0, AuditLog::query()->where('action', AuditService::BODEGA_LAFAYETTE_CATALOG_ISOLATED)->count());
        $this->assertNotNull(CatalogProduct::find($this->ids['icg1'])->external_source, 'la restauración también se deshizo');
    }

    public function test_segunda_corrida_es_idempotente_y_no_hace_nada(): void
    {
        $this->escenario();
        $this->limpiar(['--apply' => true, '--yes' => true])->assertExitCode(0);
        $this->tenant();
        $despues = $this->foto();
        $auditorias = AuditLog::query()->where('action', AuditService::BODEGA_LAFAYETTE_CATALOG_ISOLATED)->count();

        $this->limpiar(['--apply' => true, '--yes' => true])->expectsOutputToContain('Nada que hacer')->assertExitCode(0);
        $this->tenant();

        $this->assertSame($despues, $this->foto());
        $this->assertSame($auditorias, AuditLog::query()->where('action', AuditService::BODEGA_LAFAYETTE_CATALOG_ISOLATED)->count());
    }

    public function test_bloquea_si_ya_existen_tablas_de_respaldo_de_una_corrida_previa(): void
    {
        $this->escenario();
        DB::statement('CREATE TABLE atlantic_ventas_diseno.catalog_products_bak_scrum990 AS SELECT * FROM atlantic_ventas_diseno.catalog_products WHERE false');
        $antes = $this->foto();

        $this->limpiar(['--apply' => true, '--yes' => true])->expectsOutputToContain('Ya existen tablas de respaldo')->assertExitCode(1);
        $this->tenant();

        $this->assertSame($antes['cp_json'], $this->foto()['cp_json']);
    }

    public function test_tenant_inexistente_falla(): void
    {
        $this->artisan('lafayette:aislar-catalogo', ['--tenant' => 'no-existe'])->assertExitCode(1);
    }
}
