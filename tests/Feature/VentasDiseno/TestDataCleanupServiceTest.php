<?php

declare(strict_types=1);

namespace Tests\Feature\VentasDiseno;

use App\Models\User;
use App\Modules\Compras\Models\Provider;
use App\Modules\Compras\Models\PurchaseOrder;
use App\Modules\Compras\Models\PurchaseOrderLine;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Modules\VentasDiseno\Models\MasterClient;
use App\Modules\VentasDiseno\Models\PipelineCard;
use App\Modules\VentasDiseno\Models\Quote;
use App\Modules\VentasDiseno\Models\QuoteItem;
use App\Modules\VentasDiseno\Models\QuotePart;
use App\Modules\VentasDiseno\Models\SalesProject;
use App\Modules\VentasDiseno\Models\SubClient;
use App\Modules\VentasDiseno\Services\TestDataCleanupService;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Http\Request;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-735. Gerencia (gerencia@test.com, seedeada por CoreUserSeeder en TODOS
 * los entornos, incl. `testing`) y Vendedor Disenador (vendedordisenador2@test.com, seedeado por
 * BusinessRoleUserSeeder, rol vendedor real) son usuarios reales — no hace falta
 * ningún fixture de usuario propio.
 */
class TestDataCleanupServiceTest extends FeatureTestCase
{
    private User $gerencia;

    private User $neil;

    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();

        $this->daniela = User::where('email', 'gerencia@test.com')->firstOrFail();
        $this->neil = User::where('email', 'vendedordisenador2@test.com')->firstOrFail();
    }

    /**
     * App\Shared\Support\LogsAuditTrail atribuye el "created" al usuario del JWT de la
     * request (AuditService::resolveUserId()) — fuera de un endpoint HTTP real eso no
     * existe (ver comentario de AuditLogControllerTest::test_filtro_por_usuario). En vez
     * de orquestar cada uno de los 10 endpoints reales (con sus uploads/validaciones/
     * flujos propios) solo para poblar audit_logs, se fuerza el mismo contexto que la
     * request real tendría: un Request con el atributo `jwt` que AuthenticateJwt setea.
     */
    private function actingForAudit(User $user): void
    {
        $request = Request::create('/');
        $request->attributes->set('jwt', ['sub' => $user->id]);
        $this->app->instance('request', $request);
    }

    /**
     * Crea, atribuidos a $owner, un MasterClient → SubClient → SalesProject (sin
     * LogsAuditTrail, se infiere por relación) → PipelineCard → Quote → QuotePart →
     * QuoteItem — la cadena completa que el ticket pide poder identificar/borrar.
     *
     * @return array{master: MasterClient, sub: SubClient, project: SalesProject, card: PipelineCard, quote: Quote, part: QuotePart, item: QuoteItem}
     */
    private function seedFullPipeline(User $owner, string $prefix): array
    {
        $this->actingForAudit($owner);
        $master = MasterClient::create(['name' => "{$prefix} Cliente", 'default_price_type' => 'project']);

        $this->actingForAudit($owner);
        $sub = SubClient::create([
            'master_client_id' => $master->id,
            'business_name' => "{$prefix} Subcliente S.A.",
            'tax_id' => sprintf('155-%04d-1-2026', random_int(1000, 9999)),
            'category' => 'b_architect_designer',
        ]);

        // SalesProject NO usa LogsAuditTrail (ver docblock de TestDataCleanupService) —
        // se infiere por sub_client_id + ventana de created_at, no necesita actingForAudit.
        $project = SalesProject::create(['sub_client_id' => $sub->id, 'name' => "{$prefix} Proyecto", 'tag' => 'both']);

        $this->actingForAudit($owner);
        $card = PipelineCard::create([
            'sales_project_id' => $project->id,
            'stage' => 'lead',
            'master_client_id' => $master->id,
            'sub_client_id' => $sub->id,
            'owner_id' => $owner->id,
            'stage_changed_at' => now(),
        ]);

        $this->actingForAudit($owner);
        $quote = Quote::create([
            'master_client_id' => $master->id,
            'sub_client_id' => $sub->id,
            'sales_project_id' => $project->id,
            'description' => "{$prefix} Cotización",
            'owner_id' => $owner->id,
        ]);

        $this->actingForAudit($owner);
        $part = QuotePart::create(['quote_id' => $quote->id, 'name' => "{$prefix} Partida", 'position' => 1]);

        $this->actingForAudit($owner);
        $item = QuoteItem::create([
            'quote_part_id' => $part->id,
            'is_custom' => true,
            'reference' => 'REF-1',
            'description' => "{$prefix} Item",
            'quantity' => 1,
            'unit_price' => 10.0,
        ]);

        return compact('master', 'sub', 'project', 'card', 'quote', 'part', 'item');
    }

    public function test_dry_run_identifica_toda_la_data_de_gerencia_y_excluye_lo_de_otro_usuario(): void
    {
        $gerencia = $this->seedFullPipeline($this->daniela, '[Gerencia QA]');
        $neil = $this->seedFullPipeline($this->neil, '[Vendedor Disenador Real]');

        $report = app(TestDataCleanupService::class)->buildReport('gerencia@test.com');

        $this->assertContains($gerencia['master']->id, $report['candidates']['master_client']);
        $this->assertContains($gerencia['sub']->id, $report['candidates']['sub_client']);
        $this->assertContains($gerencia['project']->id, $report['candidates']['sales_project']);
        $this->assertContains($gerencia['card']->id, $report['candidates']['pipeline_card']);
        $this->assertContains($gerencia['quote']->id, $report['candidates']['quote']);
        $this->assertContains($gerencia['part']->id, $report['candidates']['quote_part']);
        $this->assertContains($gerencia['item']->id, $report['candidates']['quote_item']);

        $this->assertNotContains($neil['master']->id, $report['candidates']['master_client']);
        $this->assertNotContains($neil['sub']->id, $report['candidates']['sub_client']);
        $this->assertNotContains($neil['project']->id, $report['candidates']['sales_project']);
        $this->assertNotContains($neil['card']->id, $report['candidates']['pipeline_card']);
        $this->assertNotContains($neil['quote']->id, $report['candidates']['quote']);
        $this->assertNotContains($neil['part']->id, $report['candidates']['quote_part']);
        $this->assertNotContains($neil['item']->id, $report['candidates']['quote_item']);
    }

    public function test_marcador_demo_711_protege_incluso_si_lo_creo_gerencia(): void
    {
        $this->actingForAudit($this->daniela);
        $master = MasterClient::create(['name' => '[DEMO-711] Cliente de Gerencia', 'default_price_type' => 'project']);

        $report = app(TestDataCleanupService::class)->buildReport('gerencia@test.com');

        $this->assertNotContains($master->id, $report['candidates']['master_client']);
        $excludedIds = collect($report['excluded'])->where('entity_type', 'master_client')->pluck('id')->all();
        $this->assertContains($master->id, $excludedIds);
    }

    public function test_marcador_qa_vd_protege_hijo_via_cadena_de_fks(): void
    {
        // El marcador está en el Quote (padre), no en el QuoteItem (hijo) — el hijo debe
        // quedar protegido igual, vía labelsFor() caminando quote_part -> quote.
        $this->actingForAudit($this->daniela);
        $master = MasterClient::create(['name' => 'Cliente sin marcador']);
        $sub = SubClient::create(['master_client_id' => $master->id, 'business_name' => 'Sub sin marcador', 'tax_id' => '155-0001-1-2026']);
        $quote = Quote::create(['master_client_id' => $master->id, 'sub_client_id' => $sub->id, 'name' => '[QA-VD] Cotización protegida', 'description' => '[QA-VD] Cotización protegida', 'owner_id' => $this->daniela->id]);
        $part = QuotePart::create(['quote_id' => $quote->id, 'name' => 'Partida', 'position' => 1]);
        $item = QuoteItem::create(['quote_part_id' => $part->id, 'is_custom' => true, 'reference' => 'REF-X', 'name' => 'Item sin marcador propio', 'description' => 'Item sin marcador propio', 'quantity' => 1, 'unit_price' => 5.0]);

        $report = app(TestDataCleanupService::class)->buildReport('gerencia@test.com');

        $this->assertNotContains($quote->id, $report['candidates']['quote']);
        $this->assertNotContains($part->id, $report['candidates']['quote_part']);
        $this->assertNotContains($item->id, $report['candidates']['quote_item']);
    }

    public function test_actualizar_un_registro_de_otro_usuario_no_lo_marca_como_candidato(): void
    {
        $this->actingForAudit($this->neil);
        $master = MasterClient::create(['name' => 'Cliente real de Vendedor Disenador']);

        // Gerencia solo EDITA (action=updated) — nunca lo creó. No debe quedar candidato.
        $this->actingForAudit($this->daniela);
        $master->update(['default_price_type' => 'special']);

        $report = app(TestDataCleanupService::class)->buildReport('gerencia@test.com');

        $this->assertNotContains($master->id, $report['candidates']['master_client']);
    }

    public function test_sales_project_inferido_por_relacion_y_ventana_de_created_at(): void
    {
        $gerencia = $this->seedFullPipeline($this->daniela, '[Gerencia Inferido]');

        $report = app(TestDataCleanupService::class)->buildReport('gerencia@test.com');

        // SalesProject nunca tiene su propia fila en audit_logs (no usa LogsAuditTrail) —
        // si aparece como candidato, es porque la inferencia por sub_client_id + ventana
        // de created_at funcionó.
        $this->assertContains($gerencia['project']->id, $report['candidates']['sales_project']);
    }

    public function test_active_reference_protege_master_client_con_subcliente_vivo_de_otro_usuario(): void
    {
        $this->actingForAudit($this->daniela);
        $master = MasterClient::create(['name' => '[Gerencia] Cliente compartido']);
        $subDaniela = SubClient::create(['master_client_id' => $master->id, 'business_name' => '[Gerencia] Sub propio', 'tax_id' => '155-1111-1-2026']);

        $this->actingForAudit($this->neil);
        $subNeil = SubClient::create(['master_client_id' => $master->id, 'business_name' => 'Sub real de Vendedor Disenador', 'tax_id' => '155-2222-1-2026']);

        $report = app(TestDataCleanupService::class)->buildReport('gerencia@test.com');

        // El subcliente de Gerencia sigue siendo candidato en sí mismo...
        $this->assertContains($subDaniela->id, $report['candidates']['sub_client']);
        $this->assertNotContains($subNeil->id, $report['candidates']['sub_client']);
        // ...pero el MasterClient compartido NO se borra: sub_clients.master_client_id es
        // CASCADE, y borrarlo se llevaría puesto el subcliente real de Vendedor Disenador.
        $this->assertNotContains($master->id, $report['candidates']['master_client']);
        $excludedIds = collect($report['excluded'])->where('entity_type', 'master_client')->pluck('id')->all();
        $this->assertContains($master->id, $excludedIds);
        $reason = collect($report['excluded'])->firstWhere('id', $master->id)['reason'] ?? null;
        $this->assertSame('active_reference', $reason);
    }

    /**
     * SCRUM-735 — gap encontrado en Senior Review del commit c1e1a99: CatalogProduct es
     * referenciado desde FUERA de Ventas & Diseño (Compras/Bodega/Servicios reutilizan el
     * Catálogo). `compras.purchase_order_lines.catalog_product_id` es nullOnDelete — sin
     * guardCatalogProducts(), borrar un CatalogProduct de prueba de Gerencia referenciado
     * por una línea de orden de compra REAL le nulificaría la trazabilidad en silencio.
     */
    public function test_active_reference_protege_catalog_product_referenciado_por_purchase_order_line_real(): void
    {
        $this->actingForAudit($this->daniela);
        $product = CatalogProduct::create([
            'reference' => '[Gerencia] REF-TEST',
            'name' => '[Gerencia] Producto de prueba', 'description' => '[Gerencia] Producto de prueba',
            'price_full' => 100.0,
            'is_active' => true,
        ]);

        // Línea de compra REAL — no participa del algoritmo de candidatos (Compras no es
        // Ventas & Diseño), así que bloquea el borrado del producto sin importar quién la creó.
        $provider = Provider::create([
            'name' => 'Proveedor real', 'category' => Provider::CATEGORY_LOCALES,
            'origin' => Provider::ORIGIN_LOCAL, 'currency' => 'USD', 'is_active' => true,
            'created_by' => $this->neil->id,
        ]);
        $order = PurchaseOrder::create([
            'provider_id' => $provider->id, 'status' => PurchaseOrder::STATUS_POR_APROBAR,
            'shipping_type' => PurchaseOrder::SHIPPING_TERRESTRE, 'who_pays_shipping' => PurchaseOrder::PAYS_PROVIDER,
            'modality' => PurchaseOrder::MODALITY_DIRECTO, 'requires_primary_approval' => false,
            'currency' => 'USD', 'created_by' => $this->neil->id,
        ]);
        $line = PurchaseOrderLine::create([
            'purchase_order_id' => $order->id, 'catalog_product_id' => $product->id,
            'quantity' => 5, 'unit_cost' => 60.0, 'subtotal' => 300.0,
        ]);

        $report = app(TestDataCleanupService::class)->buildReport('gerencia@test.com');

        $this->assertNotContains($product->id, $report['candidates']['catalog_product']);
        $excludedIds = collect($report['excluded'])->where('entity_type', 'catalog_product')->pluck('id')->all();
        $this->assertContains($product->id, $excludedIds);
        $reason = collect($report['excluded'])->firstWhere('id', $product->id)['reason'] ?? null;
        $this->assertSame('active_reference', $reason);

        // El borrado real, si se ejecutara, tampoco debe tocarlo — refuerza que execute()
        // respeta el reporte y no borra lo que buildReport() excluyó.
        app(TestDataCleanupService::class)->execute($report);
        $this->assertNotNull(CatalogProduct::find($product->id));
        $this->assertNotNull(PurchaseOrderLine::find($line->id));
    }

    /**
     * Un CatalogProduct de prueba de Gerencia SIN ninguna referencia real (ni dentro de
     * ventas_diseno ni en Compras/Bodega/Servicios) sí debe poder borrarse — guardCatalogProducts()
     * no debe sobre-bloquear cuando no hay ninguna fila real involucrada.
     */
    public function test_catalog_product_sin_referencias_reales_se_borra_con_force(): void
    {
        $this->actingForAudit($this->daniela);
        $product = CatalogProduct::create([
            'reference' => '[Gerencia] REF-LIBRE',
            'name' => '[Gerencia] Producto sin referencias', 'description' => '[Gerencia] Producto sin referencias',
            'price_full' => 50.0,
            'is_active' => true,
        ]);

        $service = app(TestDataCleanupService::class);
        $report = $service->buildReport('gerencia@test.com');

        $this->assertContains($product->id, $report['candidates']['catalog_product']);

        $service->execute($report);

        $this->assertNull(CatalogProduct::find($product->id));
    }

    public function test_force_borra_los_candidatos_y_preserva_lo_protegido(): void
    {
        $gerencia = $this->seedFullPipeline($this->daniela, '[Gerencia Force]');

        $this->actingForAudit($this->daniela);
        $protectedMaster = MasterClient::create(['name' => '[QA-VD] Cliente protegido']);

        $neil = $this->seedFullPipeline($this->neil, '[Vendedor Disenador Force]');

        $service = app(TestDataCleanupService::class);
        $report = $service->buildReport('gerencia@test.com');
        $service->execute($report);

        $this->assertNull(MasterClient::find($gerencia['master']->id));
        $this->assertNull(SubClient::find($gerencia['sub']->id));
        $this->assertNull(SalesProject::find($gerencia['project']->id));
        $this->assertNull(PipelineCard::find($gerencia['card']->id));
        $this->assertNull(Quote::find($gerencia['quote']->id));
        $this->assertNull(QuotePart::find($gerencia['part']->id));
        $this->assertNull(QuoteItem::find($gerencia['item']->id));

        $this->assertNotNull(MasterClient::find($protectedMaster->id));

        $this->assertNotNull(MasterClient::find($neil['master']->id));
        $this->assertNotNull(SubClient::find($neil['sub']->id));
        $this->assertNotNull(SalesProject::find($neil['project']->id));
        $this->assertNotNull(PipelineCard::find($neil['card']->id));
        $this->assertNotNull(Quote::find($neil['quote']->id));
        $this->assertNotNull(QuotePart::find($neil['part']->id));
        $this->assertNotNull(QuoteItem::find($neil['item']->id));
    }

    /**
     * SCRUM-735 — hallazgo real de Pre-QA (2026-08-07): sales_projects.sub_client_id es
     * CASCADE, y sub_client se borra ANTES que sales_project en DELETE_ORDER (sales_project
     * siempre va al final). Cuando ambos son candidatos del mismo owner (el caso típico —
     * seedFullPipeline() lo reproduce siempre), borrar el sub_client se lleva puesto el
     * sales_project por cascade ANTES de que execute() llegue a su propio paso explícito —
     * el `delete()->rowCount()` de ese paso da 0 aunque la fila sí desapareció. Antes del fix,
     * execute() reportaba `deletedCounts['sales_project'] === 0` pese a haber borrado 1 fila
     * real (contradiciendo tanto la BD como el propio Log::info() de la línea de abajo, que sí
     * listaba el id). Este test fija que el conteo reportado refleje lo que de verdad ocurrió.
     */
    public function test_deleted_count_no_subreporta_filas_borradas_por_cascade(): void
    {
        $gerencia = $this->seedFullPipeline($this->daniela, '[Gerencia Cascade Count]');

        $service = app(TestDataCleanupService::class);
        $report = $service->buildReport('gerencia@test.com');
        $this->assertContains($gerencia['sub']->id, $report['candidates']['sub_client']);
        $this->assertContains($gerencia['project']->id, $report['candidates']['sales_project']);

        $deleted = $service->execute($report);

        $this->assertNull(SalesProject::find($gerencia['project']->id));
        $this->assertSame(
            1,
            $deleted['sales_project'],
            'sales_project fue borrado (por cascade de sub_client), el conteo reportado debe reflejarlo — no puede quedar en 0.'
        );
        $this->assertSame(1, $deleted['sub_client']);
    }

    public function test_artisan_command_dry_run_por_default_no_borra_nada(): void
    {
        $gerencia = $this->seedFullPipeline($this->daniela, '[Gerencia CLI]');

        $this->artisan('ventas-diseno:cleanup-test-data', ['--env-override' => true])
            ->assertExitCode(0);

        // El command hace Tenant::forgetCurrent() en su finally al terminar — hay que
        // volver a activar el tenant antes de poder resolver el schema correcto.
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();

        $this->assertNotNull(MasterClient::find($gerencia['master']->id));
        $this->assertNotNull(Quote::find($gerencia['quote']->id));
    }

    public function test_artisan_command_con_force_borra_de_verdad(): void
    {
        $gerencia = $this->seedFullPipeline($this->daniela, '[Gerencia CLI Force]');

        $this->artisan('ventas-diseno:cleanup-test-data', ['--env-override' => true, '--force' => true])
            ->assertExitCode(0);

        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();

        $this->assertNull(MasterClient::find($gerencia['master']->id));
        $this->assertNull(Quote::find($gerencia['quote']->id));
    }

    public function test_artisan_command_aborta_fuera_de_qa_sin_env_override(): void
    {
        // phpunit corre con APP_ENV=testing (ver .env.testing) — sin --env-override el
        // guard de entorno debe abortar, igual que abortaría contra dev/local/production.
        $this->artisan('ventas-diseno:cleanup-test-data')->assertExitCode(1);
    }

    public function test_usuario_inexistente_falla_con_mensaje_claro(): void
    {
        $this->artisan('ventas-diseno:cleanup-test-data', [
            '--env-override' => true,
            '--user' => 'no-existe@atlantic.com.pa',
        ])->assertExitCode(1);
    }
}
