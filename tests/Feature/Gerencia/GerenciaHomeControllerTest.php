<?php

declare(strict_types=1);

namespace Tests\Feature\Gerencia;

use App\Models\User;
use App\Modules\AdminCont\Models\AdminContFiscalSettings;
use App\Modules\AdminCont\Models\AdminContInvoice;
use App\Modules\Bodega\Models\RelocationRequest;
use App\Modules\Bodega\Models\Warehouse;
use App\Modules\Gerencia\Services\GerenciaHomeService;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Modules\VentasDiseno\Models\MasterClient;
use App\Modules\VentasDiseno\Models\PipelineCard;
use App\Modules\VentasDiseno\Models\SalesProject;
use App\Modules\VentasDiseno\Models\SubClient;
use App\Shared\Multitenancy\Tenant;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-160 — Gerencia Test, comentario 2026-08-26: cuando el rango filtrado del panel
 * "Proyectos" solo tenía datos reales en un mes, `chart_mensual` traía un único ítem — el
 * `BarChart` del frontend (flex-1 con un solo hijo) lo estiraba al 100% del ancho, y se veía
 * como un bloque grueso en vez de una barra dentro de un gráfico de barras. Ver
 * GerenciaHomeService::zeroFillMeses().
 */
class GerenciaHomeControllerTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    // La UserFactory del proyecto está rota (referencia columnas legadas `rol`/`nombre`/`activo`
    // que ya no existen en `atlantic_auth.users`, ver ADR-004/006) — se inserta el owner
    // directo por query builder, reusando el security_level_id de un usuario real ya sembrado,
    // en vez de depender de esa factory (fuera de alcance de este ticket arreglarla).
    private function createOwner(): int
    {
        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');

        $securityLevelId = DB::table("{$slug}_auth.users")
            ->where('email', 'superadmin@atlantic.test')
            ->value('security_level_id');

        return DB::table("{$slug}_auth.users")->insertGetId([
            'first_name' => 'Owner',
            'last_name' => 'SCRUM-160',
            'email' => 'owner-scrum-160@atlantic.test',
            'password_hash' => bcrypt('irrelevant'),
            'security_level_id' => $securityLevelId,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);
    }

    public function test_proyectos_chart_mensual_rellena_con_cero_los_meses_sin_datos(): void
    {
        $ownerId = $this->createOwner();

        $master = MasterClient::create(['name' => 'Cliente SCRUM-160']);
        $sub = SubClient::create([
            'master_client_id' => $master->id,
            'business_name' => 'Subcliente SCRUM-160',
            'tax_id' => '155-0000-9-2026',
        ]);
        $project = SalesProject::create(['sub_client_id' => $sub->id, 'name' => 'Proyecto SCRUM-160']);

        $card = PipelineCard::create([
            'sales_project_id' => $project->id,
            'stage' => 'lead',
            'master_client_id' => $master->id,
            'sub_client_id' => $sub->id,
            'owner_id' => $ownerId,
            'amount' => 51000,
            'stage_changed_at' => Carbon::parse('2026-08-15'),
        ]);
        $card->forceFill(['created_at' => Carbon::parse('2026-08-15')])->save();

        // loginAs() hace un round-trip HTTP real que olvida el tenant "actual" al terminar
        // (mismo gotcha que AdminContInvoiceControllerTest) — fixtures cross-schema antes de loguear.
        $token = $this->loginAs('superadmin@atlantic.test', 'Password123!');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/gerencia/home?desde=2026-06-01&hasta=2026-08-31&vendedor_id={$ownerId}")
            ->assertStatus(200);

        $chart = $response->json('proyectos.chart_mensual');

        $this->assertSame(['2026-06', '2026-07', '2026-08'], array_column($chart, 'mes'));
        $this->assertEquals(0.0, $chart[0]['monto']);
        $this->assertEquals(0.0, $chart[1]['monto']);
        $this->assertEquals(51000.0, $chart[2]['monto']);
    }

    /**
     * SCRUM-862 — "Exportar reporte" nunca debe generar un archivo vacío sin explicación. Un
     * vendedor recién creado (sin facturas ni proyectos cerrados asociados) filtrado por
     * vendedor_id garantiza facturas_count=0 y cerrados_mes_count=0 sin depender de un rango de
     * fechas libre de datos sembrados por otros tests/seeders.
     */
    public function test_export_sin_datos_retorna_empty_true_sin_generar_archivo(): void
    {
        $ownerId = $this->createOwner();
        $token = $this->loginAs('superadmin@atlantic.test', 'Password123!');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/gerencia/home/export?vendedor_id={$ownerId}&format=pdf")
            ->assertStatus(200);

        $response->assertJsonPath('empty', true);
    }

    /**
     * SCRUM-862 — con datos reales en el rango filtrado, el export debe devolver el binario del
     * PDF (no la respuesta {empty:true}), con el content-type correcto.
     */
    public function test_export_pdf_con_datos_genera_archivo_descargable(): void
    {
        $ownerId = $this->createOwner();

        $master = MasterClient::create(['name' => 'Cliente SCRUM-862']);
        $sub = SubClient::create([
            'master_client_id' => $master->id,
            'business_name' => 'Subcliente SCRUM-862',
            'tax_id' => '155-0000-9-2027',
        ]);
        $project = SalesProject::create(['sub_client_id' => $sub->id, 'name' => 'Proyecto SCRUM-862']);

        // stage=approved + updated_at "ahora" (default de Eloquent) cae dentro del rango default
        // de home()/export() (inicio del mes actual → hoy) sin necesidad de forzar fechas.
        PipelineCard::create([
            'sales_project_id' => $project->id,
            'stage' => 'approved',
            'master_client_id' => $master->id,
            'sub_client_id' => $sub->id,
            'owner_id' => $ownerId,
            'amount' => 8000,
        ]);

        $token = $this->loginAs('superadmin@atlantic.test', 'Password123!');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/gerencia/home/export?vendedor_id={$ownerId}&format=pdf")
            ->assertStatus(200);

        $response->assertHeader('Content-Type', 'application/pdf');
        $this->assertNotEmpty($response->streamedContent());
    }

    // SCRUM-1154 — la solicitud de reubicación que Gerencia ve en «Aprobaciones pendientes» lleva a Bodega → Bodegas
    // (bandeja de solicitudes) con el id de ESA solicitud, no a Inventario.
    public function test_aprobacion_pendiente_de_reubicacion_apunta_a_la_bandeja_de_bodegas(): void
    {
        $product = CatalogProduct::create([
            'reference' => 'REF-1154', 'name' => 'Reflector XL', 'description' => 'Reflector XL',
            'price_full' => 80, 'cost' => 40, 'stock_quantity' => 10, 'is_active' => true,
        ]);
        $solicitante = User::where('email', 'vendedor@atlantic.test')->firstOrFail();
        $relocation = RelocationRequest::create([
            'catalog_product_id' => $product->id,
            'origin_warehouse_id' => Warehouse::where('name', 'Bodega Central')->firstOrFail()->id,
            'destination_warehouse_id' => Warehouse::where('name', 'Merma')->firstOrFail()->id,
            'cantidad' => 2, 'motivo' => 'Prueba SCRUM-1154', 'solicitado_por' => $solicitante->id,
            'estado' => RelocationRequest::ESTADO_PENDIENTE,
        ]);
        $approver = User::where('email', 'gerencia3@test.com')->firstOrFail();

        $items = collect(app(GerenciaHomeService::class)->pendingApprovalsForUser($approver->id)['aprobaciones']);
        $item = $items->first(fn (array $i) => $i['type'] === 'relocation' && $i['id'] === $relocation->id);

        $this->assertNotNull($item, 'Gerencia ve la reubicación pendiente en sus aprobaciones.');
        $this->assertSame("/bodega/bodegas?relocation={$relocation->id}", $item['action_url']);
    }

    /**
     * SCRUM-944/945 — CxC vencidas/Cuentas al día usaban `subDays(30)` hardcodeado en vez del
     * plazo de crédito real (`AdminContFiscalSettings::current()->dias_credito_factura`), a
     * diferencia del resto del sistema (Antigüedad de Cartera, Estado de Cuenta). Se prueba con un
     * plazo de crédito DISTINTO de 30 (10 días) para que el hallazgo real quede cubierto: con el
     * código viejo, una factura de hace 15 días NUNCA hubiera contado como vencida (15 < 30).
     */
    public function test_cxc_vencidas_usa_el_plazo_de_credito_configurado_no_30_hardcodeado(): void
    {
        AdminContFiscalSettings::current()->update(['dias_credito_factura' => 10]);

        $invoice = AdminContInvoice::create([
            'numero' => 'F-GER-944', 'order_id' => 944000, 'cliente' => 'Cliente SCRUM-944',
            'monto' => 500.0, 'saldo_aplicado' => 0,
        ]);
        $invoice->forceFill(['created_at' => now()->subDays(15)])->save();

        $token = $this->loginAs('superadmin@atlantic.test', 'Password123!');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/gerencia/home')
            ->assertStatus(200);

        $this->assertGreaterThanOrEqual(500.0, $response->json('kpis.cxc_al_dia.vencidas'));
        // "cuentas al día" = total menos lo vencido — con la factura de arriba contando como
        // vencida (15 días > plazo de 10), tiene que quedar al menos 500 por debajo del total,
        // sin asumir un delta exacto (no controlamos si hay otras facturas seed en la BD).
        $ac = $response->json('salud_modulos.admin_contab');
        $this->assertLessThanOrEqual($ac['cxc_total'] - 500.0 + 0.01, $ac['cuentas_al_dia']);
    }

    /**
     * SCRUM-944/945 (2do pase, decisión de Luis 2026-09-23): además de leer el plazo de crédito
     * real, la fórmula pasó a reusar `InvoiceService::cobrablesConVencimientoSigned()` — cambia
     * de sumar el monto BRUTO de la factura a sumar el SALDO neto (monto - saldo_aplicado). Una
     * factura con un abono parcial ya no debe contar su monto completo como CxC pendiente.
     */
    public function test_cxc_total_usa_saldo_neto_no_monto_bruto(): void
    {
        $token = $this->loginAs('superadmin@atlantic.test', 'Password123!');

        $antes = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/gerencia/home')->assertStatus(200)
            ->json('salud_modulos.admin_contab.cxc_total');

        // loginAs() hace un round-trip HTTP real que olvida el tenant "actual" al terminar (mismo
        // gotcha ya documentado en setUp() de este archivo) — se re-afirma antes de crear el fixture.
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        AdminContInvoice::create([
            'numero' => 'F-GER-944B', 'order_id' => 944001, 'cliente' => 'Cliente SCRUM-944 Abono',
            'monto' => 1000.0, 'saldo_aplicado' => 400.0,
        ]);

        $despues = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/gerencia/home')->assertStatus(200)
            ->json('salud_modulos.admin_contab.cxc_total');

        // El saldo pendiente real es 600 (1000 - 400) — el total tiene que subir exactamente eso,
        // nunca los 1000 del monto bruto de la factura.
        $this->assertEqualsWithDelta($antes + 600.0, $despues, 0.01);
    }

    /** SCRUM-944/945 — una factura anulada nunca debe contar en CxC (la query vieja no la excluía). */
    public function test_cxc_excluye_facturas_anuladas(): void
    {
        $invoice = AdminContInvoice::create([
            'numero' => 'F-GER-944C', 'order_id' => 944002, 'cliente' => 'Cliente SCRUM-944 Anulada',
            'monto' => 999999.0, 'saldo_aplicado' => 0,
        ]);
        $invoice->forceFill(['anulada_at' => now()])->save();

        $token = $this->loginAs('superadmin@atlantic.test', 'Password123!');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/gerencia/home')
            ->assertStatus(200);

        $ac = $response->json('salud_modulos.admin_contab');
        $this->assertLessThan(999999.0, $ac['cxc_total'], 'una factura anulada de $999,999 no debe entrar en el total de CxC');
    }
}
