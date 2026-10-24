<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations\Lafayette;

use App\Console\Commands\LafayetteAislarCatalogoCommand;
use App\Modules\Auth\Models\AuditLog;
use App\Modules\Bodega\Models\Order;
use App\Modules\Bodega\Models\OrderItem;
use App\Modules\Bodega\Services\ZonaLibreCatalogoService;
use App\Modules\Compras\Models\PurchaseOrder;
use App\Modules\Compras\Models\PurchaseOrderPayment;
use App\Modules\Compras\Models\PurchaseOrderReceipt;
use App\Modules\Compras\Models\PurchaseOrderReceiptLine;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Shared\Services\AuditService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Bodega\Concerns\BodegaOrderTestHelpers;
use Tests\Feature\FeatureTestCase;
use Tests\Feature\Integrations\Lafayette\Concerns\ArmaEscenarioLafayette;

/**
 * SCRUM-990 (Senior ALTO-2/ALTO-3 + Cybersecurity C1-C7) — `lafayette:aislar-catalogo`: clasificación por evidencia de auditoría, restauración con el
 * valor más antiguo por clave y respeto de ediciones de usuario, precondiciones del historial (nota de crédito, recepciones, filas hijas en CASCADE),
 * `--yes` por lista blanca, rol de migración, TOCTOU y auditoría dentro de la transacción. Sobre la BD REAL de pruebas.
 */
final class LafayetteAislarCatalogoSeguridadTest extends FeatureTestCase
{
    use ArmaEscenarioLafayette;
    use BodegaOrderTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant();
        LafayetteAislarCatalogoCommand::$despuesDeConfirmar = null;
    }

    protected function tearDown(): void
    {
        LafayetteAislarCatalogoCommand::$despuesDeConfirmar = null;
        parent::tearDown();
    }

    // ── C1 / ALTO-3: clasificación por evidencia ─────────────────────────────────────────────────────────────

    public function test_un_producto_de_atlanticerp_vinculado_por_el_loader_viejo_se_restaura_y_nunca_se_borra(): void
    {
        $this->escenario();
        $manual = $this->crearPreexistenteNoIcg('M1'); // NO está en legacy.productos; no tiene referencias: antes se clasificaba "puro" y se BORRABA

        $this->limpiar()->expectsOutputToContain('PREEXISTENTES')->assertExitCode(0);
        $this->tenant();
        $this->limpiar(['--apply' => true, '--yes' => true])->assertExitCode(0);
        $this->tenant();

        $p = CatalogProduct::find($manual);
        $this->assertNotNull($p, 'un producto de AtlanticERP creado a mano jamás se borra');
        $this->assertSame('Producto manual de AtlanticERP M1', $p->name);
        $this->assertSame('FACT-MANUAL-M1', $p->factory_reference);
        $this->assertSame('MarcaManualM1', $p->brand);
        $this->assertNull($p->provider_id);
        $this->assertNull($p->external_source);
        $this->assertNotNull(DB::table('atlantic_ventas_diseno.catalog_products_bak_scrum990')->where('id', $manual)->first(), 'queda en el respaldo');
        $evento = AuditLog::query()->where('action', AuditService::BODEGA_LAFAYETTE_CATALOG_ISOLATED)->firstOrFail();
        $this->assertSame(1, $evento->metadata['clasificacion']['preexistentes']);
        $this->assertSame(3, $evento->metadata['clasificacion']['puros']);
    }

    public function test_un_puro_sin_ningun_evento_de_creacion_bloquea_salvo_opcion_explicita(): void
    {
        $this->escenario();
        // Sin ningún evento de auditoría (p. ej. podados o creados por SQL): no hay evidencia de que lo creó el sincronizador.
        $sinEvidencia = CatalogProduct::withoutEvents(fn () => CatalogProduct::create([
            'reference' => 'RARO-1', 'name' => 'Producto raro', 'price_full' => 1.0, 'is_active' => true,
            'provider_id' => $this->proveedorLafayette()->id, 'external_source' => 'lafayette_lighting',
        ]));
        $antes = $this->foto();

        $this->limpiar(['--apply' => true, '--yes' => true])->expectsOutputToContain('NINGÚN evento de creación')->assertExitCode(1);
        $this->tenant();
        $this->assertSame($antes, $this->foto());

        $this->limpiar(['--apply' => true, '--yes' => true, '--permitir-puros-sin-evidencia' => true])->assertExitCode(0);
        $this->tenant();
        $this->assertNull(CatalogProduct::find($sinEvidencia->id));
    }

    // ── C3: restauración desde auditoría ─────────────────────────────────────────────────────────────────────

    /** El enlace "no cambió" el proveedor (ya era Lafayette): su old_values no trae provider_id => no es un evento válido. */
    private function crearIcgConEventoSinProveedor(string $clave, bool $conDocumento): int
    {
        $p = CatalogProduct::create([
            'reference' => "ICG-{$clave}", 'name' => "Original {$clave}", 'factory_reference' => "FACT-{$clave}", 'price_full' => 3.0, 'is_active' => true,
            'provider_id' => $this->proveedorLafayette()->id,
        ]);
        DB::table('atlantic_legacy.productos')->insert(['icg_codarticulo' => 80000 + (int) crc32($clave) % 9000, 'descripcion' => "Original {$clave}", 'catalog_product_id' => $p->id, 'descatalogado' => false]);
        $p->update(['external_source' => 'lafayette_lighting', 'external_synced_at' => now(), 'name' => "Nombre Lafayette {$clave}"]);
        if ($conDocumento) {
            $this->referenciarEnFamilia((int) $p->id, "Familia {$clave}");
        }

        return (int) $p->id;
    }

    public function test_un_evento_sin_provider_id_en_old_values_no_es_valido_y_bloquea(): void
    {
        $this->escenario();
        $this->crearIcgConEventoSinProveedor('SP1', conDocumento: false);
        $antes = $this->foto();

        $this->limpiar(['--apply' => true, '--yes' => true])->expectsOutputToContain('--permitir-icg-sin-auditoria')->assertExitCode(1);
        $this->tenant();
        $this->assertSame($antes, $this->foto());

        // Con la opción y SIN documentos: nunca "en silencio" (la opción es explícita) y solo se quita la marca y el proveedor de Lafayette.
        $this->limpiar(['--apply' => true, '--yes' => true, '--permitir-icg-sin-auditoria' => true])->assertExitCode(0);
        $this->tenant();
        $p = CatalogProduct::where('reference', 'ICG-SP1')->firstOrFail();
        $this->assertNull($p->external_source);
        $this->assertNull($p->provider_id);
    }

    public function test_un_producto_con_documentos_y_sin_evento_valido_bloquea_incluso_con_la_opcion(): void
    {
        $this->escenario();
        $this->crearIcgConEventoSinProveedor('SP2', conDocumento: true);
        $antes = $this->foto();

        $this->limpiar(['--apply' => true, '--yes' => true, '--permitir-icg-sin-auditoria' => true])
            ->expectsOutputToContain('SIN evento de auditoría válido')
            ->assertExitCode(1);
        $this->tenant();

        $this->assertSame($antes, $this->foto());
    }

    public function test_el_valor_original_es_el_mas_antiguo_por_clave_aunque_lo_cambie_un_evento_posterior_del_loader(): void
    {
        $this->escenario();
        // El primer enlace NO cambia la marca; una corrida posterior del loader SÍ (MarcaICG4 -> FLUA): el original está en el old_values del 2º evento.
        $p = CatalogProduct::create(['reference' => 'ICG-4', 'factory_reference' => 'FACT-ICG-4', 'name' => 'Candelabro ICG 4', 'brand' => 'MarcaICG4', 'category' => 'Cat ICG', 'price_full' => 40.0, 'is_active' => true])->fresh();
        DB::table('atlantic_legacy.productos')->insert(['icg_codarticulo' => 70004, 'descripcion' => 'Candelabro ICG 4', 'catalog_product_id' => $p->id, 'descatalogado' => false]);
        $this->sobrescribirComoLafayette($p, '4', cambiaMarca: false);
        $p->update(['brand' => 'FLUA']);

        $this->limpiar(['--apply' => true, '--yes' => true])->assertExitCode(0);
        $this->tenant();

        $this->assertSame('MarcaICG4', CatalogProduct::findOrFail($p->id)->brand, 'con solo el primer evento quedaba la marca de Lafayette');
        $this->assertSame('Candelabro ICG 4', CatalogProduct::findOrFail($p->id)->name);
    }

    public function test_los_campos_editados_por_un_usuario_despues_del_enlace_no_se_pisan_y_se_reportan(): void
    {
        $ids = $this->escenario();
        $icg = CatalogProduct::findOrFail($ids['icg2']);
        // Un USUARIO corrigió el nombre en AtlanticERP después de que Lafayette lo pisó (evento con user_id).
        DB::table((new AuditLog())->getTable())->insert([
            'user_id' => $this->usuarioId(), 'action' => 'ventas_diseno.catalog_product.updated', 'entity_type' => 'catalog_product', 'entity_id' => (string) $icg->id,
            'old_values' => json_encode(['name' => 'Nombre Lafayette v2 2']), 'new_values' => json_encode(['name' => 'Nombre corregido por un usuario']),
            'metadata' => '{}', 'created_at' => now(),
        ]);
        DB::table((new CatalogProduct())->getTable())->where('id', $icg->id)->update(['name' => 'Nombre corregido por un usuario']);

        $this->limpiar()->expectsOutputToContain('editados POR USUARIOS')->assertExitCode(0);
        $this->tenant();
        $this->limpiar(['--apply' => true, '--yes' => true])->assertExitCode(0);
        $this->tenant();

        $p = CatalogProduct::findOrFail($ids['icg2']);
        $this->assertSame('Nombre corregido por un usuario', $p->name, 'la edición del usuario se conserva');
        $this->assertSame('public/legacy-photos/i2.jpg', $p->photo_url, 'el resto de los campos sí se restaura');
        $this->assertSame('FACT-ICG-2', $p->factory_reference);
        $this->assertSame(1, AuditLog::query()->where('action', AuditService::BODEGA_LAFAYETTE_CATALOG_ISOLATED)->firstOrFail()->metadata['campos_de_usuario_conservados']);
    }

    public function test_la_verificacion_independiente_detecta_un_factory_reference_que_es_un_id_de_lafayette(): void
    {
        $this->escenario();
        // Original "legítimo" cuyo valor coincide con un id del espejo de Lafayette: la restauración copiaría un id de Lafayette => se detecta SIN usar old_values.
        $p = CatalogProduct::create(['reference' => 'ICG-X', 'factory_reference' => 'ESP0001', 'name' => 'ICG X', 'price_full' => 1.0, 'is_active' => true])->fresh();
        DB::table('atlantic_legacy.productos')->insert(['icg_codarticulo' => 70099, 'descripcion' => 'ICG X', 'catalog_product_id' => $p->id, 'descatalogado' => false]);
        $this->sobrescribirComoLafayette($p, 'X');
        $antes = $this->foto();

        $this->limpiar(['--apply' => true, '--yes' => true])->expectsOutputToContain('factory_reference un id de Lafayette')->assertExitCode(1);
        $this->tenant();

        $this->assertSame($antes, $this->foto(), 'ROLLBACK completo');
    }

    // ── C2 / ALTO-2: historial ───────────────────────────────────────────────────────────────────────────────

    private function ordenConItem(): OrderItem
    {
        $order = $this->makeOrder(['stage' => Order::STAGE_ASIGNADO], ['qty_requested' => 2]);

        return $order->items->firstOrFail();
    }

    private function devolucionContraZl(?int $notaCredito = null, int $lineas = 1): int
    {
        $item = $this->ordenConItem();
        $id = (int) DB::table('atlantic_bodega.customer_returns')->insertGetId([
            'return_number' => 'DEV-' . uniqid(), 'order_id' => $item->order_id, 'status' => 'pendiente', 'contact_name' => 'Contacto', 'contact_phone' => '6000-0000',
            'destination_warehouse_id' => $this->zl(), 'credit_note_id' => $notaCredito, 'created_by' => $this->usuarioId(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        for ($i = 0; $i < $lineas; $i++) {
            DB::table('atlantic_bodega.customer_return_lines')->insert([
                'customer_return_id' => $id, 'order_item_id' => $item->id, 'qty_requested' => 1, 'reason' => 'prueba', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $id;
    }

    private function conteoContraZl(int $productId): int
    {
        $id = (int) DB::table('atlantic_bodega.general_count_requests')->insertGetId([
            'warehouse_id' => $this->zl(), 'realizado_por' => $this->usuarioId(), 'estado' => 'pendiente_evaluacion', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('atlantic_bodega.general_count_request_lines')->insert([
            'general_count_request_id' => $id, 'catalog_product_id' => $productId, 'cantidad_sistema' => 5, 'cantidad_contada' => 4, 'diferencia' => -1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    /** @return array{0: PurchaseOrder, 1: PurchaseOrderReceiptLine} */
    private function recepcionContraZl(int $productId, string $estadoOc = 'ordenado', string $pago = 'pendiente', bool $confirmada = false): array
    {
        $po = PurchaseOrder::create(['provider_id' => $this->proveedorLafayette()->id, 'currency' => 'USD', 'status' => $estadoOc, 'status_changed_at' => now()]);
        if ($pago !== 'pendiente') {
            // El estado de pago se deriva de los pagos registrados: cualquier pago deja la orden "parcial" o "completo".
            PurchaseOrderPayment::create(['purchase_order_id' => $po->id, 'amount' => 50, 'payment_proof_storage_key' => 'prueba', 'registered_by' => $this->usuarioId()]);
        }
        $receipt = PurchaseOrderReceipt::create(['purchase_order_id' => $po->id]);
        $line = PurchaseOrderReceiptLine::create([
            'purchase_order_receipt_id' => $receipt->id, 'catalog_product_id' => $productId, 'quantity' => 2, 'unit_cost' => 10, 'warehouse_id' => $this->zl(),
            'confirmed_at' => $confirmada ? now() : null,
        ]);

        return [$po, $line];
    }

    public function test_una_devolucion_con_nota_de_credito_emitida_bloquea_la_anulacion(): void
    {
        $this->escenario();
        $this->devolucionContraZl(notaCredito: 777);
        $antes = $this->foto();

        $this->limpiar(['--apply' => true, '--yes' => true])->expectsOutputToContain('Nota de Crédito')->assertExitCode(1);
        $this->tenant();

        $this->assertSame($antes, $this->foto());
        $this->assertSame(1, DB::table('atlantic_bodega.customer_returns')->count());
    }

    public function test_recepciones_confirmadas_o_de_ordenes_recibidas_o_pagadas_bloquean_y_las_demas_se_anulan(): void
    {
        $ids = $this->escenario();

        foreach ([['recibido', 'pendiente', false, 'ya recibida o con pagos'], ['ordenado', 'parcial', false, 'ya recibida o con pagos'], ['ordenado', 'pendiente', true, 'CONFIRMADA']] as [$estado, $pago, $conf, $texto]) {
            [, $linea] = $this->recepcionContraZl($ids['atlanticerp1'], $estado, $pago, $conf);
            $antes = $this->foto();

            $this->limpiar(['--apply' => true, '--yes' => true])->expectsOutputToContain($texto)->assertExitCode(1);
            $this->tenant();
            $this->assertSame($antes, $this->foto(), "{$estado}/{$pago}/confirmada={$conf}");

            DB::table('atlantic_compras.purchase_order_receipt_lines')->where('id', $linea->id)->delete();
        }

        // Orden pendiente, sin pago, línea sin confirmar: se anula, y el dry-run lista el estado de la OC que quedaría desfasado.
        $this->recepcionContraZl($ids['atlanticerp1'], 'ordenado', 'pendiente', false);
        $this->limpiar()->expectsOutputToContain('DESFASADOS')->assertExitCode(0);
        $this->tenant();
        $this->limpiar(['--apply' => true, '--yes' => true])->assertExitCode(0);
        $this->tenant();
        $this->assertSame(0, DB::table('atlantic_compras.purchase_order_receipt_lines')->where('warehouse_id', $this->zl())->count());
        $this->assertSame(1, DB::table('atlantic_compras.purchase_order_receipt_lines_bak_scrum990')->count());
    }

    public function test_las_filas_hijas_en_cascade_se_respaldan_y_no_provocan_un_falso_fallo_de_verificacion(): void
    {
        $ids = $this->escenario();
        // Regresión de ALTO-2: general_count_request_lines referencia catalog_products y cae en CASCADE al anular el conteo; antes verificar() la contaba como cambio.
        $conteo = $this->conteoContraZl($ids['atlanticerp1']);
        $devolucion = $this->devolucionContraZl(lineas: 2);

        $this->limpiar(['--apply' => true, '--yes' => true])->assertExitCode(0);
        $this->tenant();

        $this->assertSame(0, DB::table('atlantic_bodega.general_count_requests')->count());
        $this->assertSame(0, DB::table('atlantic_bodega.general_count_request_lines')->count());
        $this->assertSame(0, DB::table('atlantic_bodega.customer_returns')->count());
        $this->assertSame(1, DB::table('atlantic_bodega.general_count_request_lines_bak_scrum990')->count(), 'la fila hija también se respalda');
        $this->assertSame(2, DB::table('atlantic_bodega.customer_return_lines_bak_scrum990')->count());
        $this->assertSame(1, DB::table('atlantic_bodega.general_count_requests_bak_scrum990')->where('id', $conteo)->count());
        $this->assertSame(1, DB::table('atlantic_bodega.customer_returns_bak_scrum990')->where('id', $devolucion)->count());
    }

    public function test_la_condicion_de_solo_zona_libre_excluye_filas_que_tocan_ademas_otra_bodega(): void
    {
        $zl = 7;
        DB::statement('CREATE TEMP TABLE t_mix (id int, a int, b int)');
        DB::table('t_mix')->insert([
            ['id' => 1, 'a' => 7, 'b' => null], // solo Zona Libre
            ['id' => 2, 'a' => 7, 'b' => 7],    // solo Zona Libre
            ['id' => 3, 'a' => 7, 'b' => 8],    // Zona Libre + otra bodega operativa: NO se anula
            ['id' => 4, 'a' => 8, 'b' => 7],    // idem
            ['id' => 5, 'a' => 8, 'b' => null], // no toca Zona Libre
            ['id' => 6, 'a' => null, 'b' => 7], // solo Zona Libre
        ]);

        $solo = array_map(fn ($r) => $r->id, DB::select('SELECT id FROM t_mix WHERE ' . LafayetteAislarCatalogoCommand::whereSoloBodega(['a', 'b'], $zl) . ' ORDER BY id'));
        $toca = array_map(fn ($r) => $r->id, DB::select('SELECT id FROM t_mix WHERE ' . LafayetteAislarCatalogoCommand::whereTocaBodega(['a', 'b'], $zl) . ' ORDER BY id'));

        $this->assertSame([1, 2, 6], $solo);
        $this->assertSame([1, 2, 3, 4, 6], $toca);
        DB::statement('DROP TABLE t_mix');
    }

    public function test_el_dry_run_lista_ids_y_estados_desfasados_sin_escribir(): void
    {
        $ids = $this->escenario();
        $this->recepcionContraZl($ids['atlanticerp1'], 'ordenado', 'pendiente', false);
        $antes = $this->foto();

        $this->limpiar()
            ->expectsOutputToContain('Estados que quedarían DESFASADOS')
            ->expectsOutputToContain('stock_quantity')
            ->expectsOutputToContain('Huella del plan')
            ->assertExitCode(0);
        $this->tenant();

        $this->assertSame($antes, $this->foto());
    }

    // ── C5: rol de migración ─────────────────────────────────────────────────────────────────────────────────

    private function rolActual(): string
    {
        return (string) DB::selectOne('SELECT current_user AS u')->u;
    }

    public function test_fuera_de_local_y_testing_exige_el_rol_de_migracion(): void
    {
        $this->escenario();
        $antes = $this->foto();
        config(['app.env' => 'dev', 'lafayette.migrate_username' => null, 'lafayette.migrate_password_definida' => false]);

        $this->limpiar(['--apply' => true])->expectsOutputToContain('DB_MIGRATE_USERNAME y DB_MIGRATE_PASSWORD no están definidos')->assertExitCode(1);
        $this->tenant();

        config(['lafayette.migrate_username' => 'otro_rol', 'lafayette.migrate_password_definida' => true]);
        $this->limpiar(['--apply' => true])->expectsOutputToContain('distinto de DB_MIGRATE_USERNAME')->assertExitCode(1);
        $this->tenant();

        config(['lafayette.migrate_username' => $this->rolActual(), 'lafayette.app_role' => $this->rolActual()]);
        $this->limpiar(['--apply' => true])->expectsOutputToContain('rol de la aplicación')->assertExitCode(1);
        $this->tenant();

        $this->assertSame($antes, $this->foto());
    }

    public function test_con_el_rol_de_migracion_correcto_pide_la_confirmacion_escrita_del_entorno_y_aplica(): void
    {
        $this->escenario();
        config(['app.env' => 'dev', 'lafayette.migrate_username' => $this->rolActual(), 'lafayette.migrate_password_definida' => true, 'lafayette.app_role' => 'atlanticerp_app']);

        $this->limpiar(['--apply' => true])
            ->expectsQuestion('Para continuar escribe exactamente: APLICAR dev atlantic', 'APLICAR dev atlantic')
            ->assertExitCode(0);
        $this->tenant();

        $this->assertSame(0, CatalogProduct::query()->where('external_source', 'lafayette_lighting')->count());
    }

    // ── C6: TOCTOU ───────────────────────────────────────────────────────────────────────────────────────────

    public function test_si_los_datos_cambian_despues_de_confirmar_el_plan_aborta_sin_aplicar_nada(): void
    {
        $this->escenario();
        $antes = $this->foto();
        // Entre la confirmación y el bloqueo aparece un producto "puro" nuevo (p. ej. una corrida del sincronizador viejo en otro entorno).
        LafayetteAislarCatalogoCommand::$despuesDeConfirmar = function (): void {
            $this->tenant();
            $this->crearPuro('NUEVO', 7);
        };

        $this->limpiar(['--apply' => true])
            ->expectsQuestion('Para continuar escribe exactamente: APLICAR testing atlantic', 'APLICAR testing atlantic')
            ->expectsOutputToContain('huella distinta')
            ->assertExitCode(1);
        $this->tenant();

        $despues = $this->foto();
        $this->assertSame($antes['cp'] + 1, $despues['cp'], 'solo existe el producto nuevo que apareció en la ventana');
        $this->assertFalse($despues['bak'], 'no se creó ningún respaldo ni se tocó nada');
        $this->assertSame(0, AuditLog::query()->where('action', AuditService::BODEGA_LAFAYETTE_CATALOG_ISOLATED)->count());
    }

    // ── C7: auditoría dentro de la transacción ───────────────────────────────────────────────────────────────

    public function test_el_evento_de_auditoria_incluye_operador_host_entorno_conteos_e_ids(): void
    {
        $ids = $this->escenario();
        $this->recepcionContraZl($ids['atlanticerp1'], 'ordenado', 'pendiente', false);

        $this->limpiar(['--apply' => true, '--yes' => true, '--operador' => 'luis.garcia'])->assertExitCode(0);
        $this->tenant();

        $m = AuditLog::query()->where('action', AuditService::BODEGA_LAFAYETTE_CATALOG_ISOLATED)->firstOrFail()->metadata;
        $this->assertSame('luis.garcia', $m['operador']);
        $this->assertSame('testing', $m['entorno']);
        $this->assertSame('atlantic', $m['tenant']);
        $this->assertNotSame('', $m['host']);
        $this->assertNotSame('', $m['usuario_so']);
        $this->assertSame(3, $m['puros_borrados']);
        $this->assertSame(3, $m['restaurados']);
        $this->assertSame([$ids['icg1']], $m['ids_documentales']);
        $this->assertSame(64, strlen($m['huella_plan']), 'SHA-256 del plan');
        $this->assertArrayHasKey('puros', $m['huellas_md5']);
        $this->assertNotEmpty($m['desfases']);
    }

    public function test_si_no_se_puede_escribir_la_auditoria_la_limpieza_entera_hace_rollback(): void
    {
        $this->escenario();
        $antes = $this->foto();
        $audit = (new AuditLog())->getTable();

        DB::statement("CREATE OR REPLACE FUNCTION public.test_scrum990_bloquea_audit() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN RAISE EXCEPTION 'auditoria no disponible (simulado)'; END \$\$");
        DB::statement("CREATE TRIGGER test_scrum990_bloquea_audit BEFORE INSERT ON {$audit} FOR EACH ROW WHEN (NEW.action = 'bodega.lafayette_catalog.isolated') EXECUTE FUNCTION public.test_scrum990_bloquea_audit()");

        try {
            $this->limpiar(['--apply' => true, '--yes' => true])->expectsOutputToContain('ROLLBACK')->assertExitCode(1);
            $this->tenant();
        } finally {
            DB::statement("DROP TRIGGER IF EXISTS test_scrum990_bloquea_audit ON {$audit}");
            DB::statement('DROP FUNCTION IF EXISTS public.test_scrum990_bloquea_audit()');
        }

        $this->assertSame($antes, $this->foto(), 'una limpieza destructiva sin registro de auditoría no puede quedar commiteada');
    }

    public function test_tras_aplicar_se_invalida_el_cache_del_resumen_de_zona_libre(): void
    {
        $this->escenario();
        Cache::flush();
        $this->repoblarEspejo(6);
        $this->assertSame(6, app(ZonaLibreCatalogoService::class)->resumen()['total_catalogo']);
        $this->assertTrue(Cache::has(ZonaLibreCatalogoService::cacheKey()));

        $this->limpiar(['--apply' => true, '--yes' => true])->assertExitCode(0);
        $this->tenant();

        $this->assertFalse(Cache::has(ZonaLibreCatalogoService::cacheKey()), 'el resumen cacheado se descarta tras la limpieza');
    }

    public function test_los_mensajes_de_error_no_incluyen_sql_ni_nombres_de_tabla_con_bindings(): void
    {
        $this->escenario();
        $cp = (new CatalogProduct())->getTable();
        DB::statement("CREATE OR REPLACE FUNCTION public.test_scrum990_bloquea_delete() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN RAISE EXCEPTION 'fallo simulado'; END \$\$");
        DB::statement("CREATE TRIGGER test_scrum990_bloquea BEFORE DELETE ON {$cp} FOR EACH ROW EXECUTE FUNCTION public.test_scrum990_bloquea_delete()");

        try {
            $this->limpiar(['--apply' => true, '--yes' => true])
                ->expectsOutputToContain('ROLLBACK')
                ->doesntExpectOutputToContain('DELETE FROM')
                ->doesntExpectOutputToContain('(SQL:')
                ->assertExitCode(1);
            $this->tenant();
        } finally {
            DB::statement("DROP TRIGGER IF EXISTS test_scrum990_bloquea ON {$cp}");
            DB::statement('DROP FUNCTION IF EXISTS public.test_scrum990_bloquea_delete()');
        }

        $this->assertFalse(Schema::hasTable('atlantic_ventas_diseno.catalog_products_bak_scrum990'));
    }

    // ── C8: bodega ───────────────────────────────────────────────────────────────────────────────────────────

    public function test_aborta_si_la_bodega_zona_libre_no_esta_marcada_solo_consulta(): void
    {
        $this->escenario();
        DB::table('atlantic_bodega.warehouses')->where('id', $this->zl())->update(['solo_consulta' => false]);
        \App\Modules\Bodega\Models\ProductWarehouseStock::olvidarBodegasSoloConsulta();
        $antes = $this->foto();

        $this->limpiar(['--apply' => true, '--yes' => true])->expectsOutputToContain('no está marcada solo_consulta')->assertExitCode(1);
        $this->tenant();

        $this->assertSame($antes, $this->foto());
    }

    // ── N1 / N8: tiempos de espera y operador obligatorio ────────────────────────────────────────────────────

    public function test_con_apply_el_operador_es_obligatorio(): void
    {
        $this->escenario();
        $antes = $this->foto();

        $this->artisan('lafayette:aislar-catalogo', ['--tenant' => 'atlantic', '--apply' => true, '--yes' => true])
            ->expectsOutputToContain('--operador es obligatorio')
            ->assertExitCode(1);
        $this->tenant();

        $this->assertSame($antes, $this->foto());
    }

    public function test_si_otra_transaccion_retiene_un_bloqueo_la_limpieza_expira_con_mensaje_claro_y_rollback(): void
    {
        $this->escenario();
        $antes = $this->foto();
        $cfg = config('database.connections.pgsql');
        $otra = new \PDO(sprintf('pgsql:host=%s;port=%s;dbname=%s', $cfg['host'], $cfg['port'], $cfg['database']), (string) $cfg['username'], (string) $cfg['password']);
        $otra->beginTransaction();
        // ROW EXCLUSIVE no choca con las escrituras de este proceso, pero SÍ con el SHARE ROW EXCLUSIVE del comando.
        $otra->exec('LOCK TABLE ' . (new CatalogProduct())->getTable() . ' IN ROW EXCLUSIVE MODE');

        try {
            $inicio = microtime(true);
            $this->limpiar(['--apply' => true, '--yes' => true, '--lock-timeout' => 1])
                ->expectsOutputToContain('ROLLBACK: la limpieza NO se aplicó. Se agotó el tiempo de espera')
                ->assertExitCode(1);
            $this->assertLessThan(10, microtime(true) - $inicio, 'no debe esperar indefinidamente');
        } finally {
            $otra->rollBack();
        }
        $this->tenant();

        $this->assertSame($antes, $this->foto(), 'tras expirar el bloqueo no queda nada aplicado');
    }

    public function test_los_tiempos_de_espera_deben_ser_enteros_positivos(): void
    {
        $this->escenario();

        $this->limpiar(['--apply' => true, '--yes' => true, '--lock-timeout' => 0])->expectsOutputToContain('--lock-timeout debe ser un entero')->assertExitCode(1);
        $this->limpiar(['--apply' => true, '--yes' => true, '--statement-timeout' => 'abc'])->expectsOutputToContain('--statement-timeout debe ser un entero')->assertExitCode(1);
    }
}
