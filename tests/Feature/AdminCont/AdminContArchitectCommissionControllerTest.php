<?php

declare(strict_types=1);

namespace Tests\Feature\AdminCont;

use App\Modules\AdminCont\Jobs\SendArchitectReminderEmailJob;
use App\Modules\AdminCont\Models\AdminContBankAccount;
use App\Modules\AdminCont\Models\ArchitectCommission;
use App\Modules\AdminCont\Models\ArchitectFiscalProfile;
use App\Modules\AdminCont\Models\AdminContInvoice;
use App\Modules\AdminCont\Services\ArchitectCommissionService;
use App\Modules\Bodega\Models\Order;
use App\Modules\VentasDiseno\Models\Architect;
use App\Modules\VentasDiseno\Models\MasterClient;
use App\Modules\VentasDiseno\Models\PipelineCard;
use App\Modules\VentasDiseno\Models\Quote;
use App\Modules\VentasDiseno\Models\SalesProject;
use App\Modules\VentasDiseno\Models\SubClient;
use App\Shared\Multitenancy\Tenant;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\FeatureTestCase;

/**
 * Batch 16 de Admin&Cont (SCRUM-585→590, REQ-508→513) — Comisiones Externas. Ver
 * docs/adr/ADR-SCRUM585-590-batch16-comisiones-externas.md.
 * Batch 17 (SCRUM-591→596, REQ-514→519) al final del archivo. Ver
 * docs/adr/ADR-SCRUM591-596-batch17-comisiones-externas.md.
 */
class AdminContArchitectCommissionControllerTest extends FeatureTestCase
{
    private const MARK_EMAIL  = 'mbekhar@atlantic.com.pa';
    private const FELIX_EMAIL = 'conta@atlantic.com.pa';

    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        Storage::fake('s3-private');
    }

    private function loginAsMark(): string
    {
        return $this->loginAs(self::MARK_EMAIL, self::MARK_EMAIL, 'management');
    }

    private function loginAsFelix(): string
    {
        return $this->loginAs(self::FELIX_EMAIL, self::FELIX_EMAIL, 'lider_admin_contab');
    }

    private function userIdByEmail(string $email): int
    {
        return (int) \App\Models\User::where('email', $email)->value('id');
    }

    /** loginAs()/una request HTTP real limpia el search_path que setUp() dejó activo — mismo
     *  gotcha ya documentado en ToolsBatch13Part1Test/TicketBatch4Test. */
    private function reactivateTenant(): void
    {
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    private function makeArchitect(): Architect
    {
        return Architect::create(['name' => 'Arq. '.uniqid(), 'email' => uniqid().'@arq.test']);
    }

    /**
     * Proyecto aprobado referido por un arquitecto: pipeline_card + Quote (con architect_id) +
     * Order + AdminContInvoice, cobro configurable. Mismo esqueleto que
     * AdminContInternalCommissionControllerTest::makeCommissionableOrder(), con el Quote agregado
     * (única forma hoy de vincular arquitecto↔proyecto, ver docblock de ArchitectCommissionService).
     */
    private function makeArchitectProject(Architect $architect, float $amount, float $totalCobrado, ?Carbon $fechaPedido = null): PipelineCard
    {
        $master  = MasterClient::create(['name' => 'Cliente Externa '.uniqid()]);
        $sub     = SubClient::create(['master_client_id' => $master->id, 'business_name' => 'Subcliente Externa '.uniqid(), 'tax_id' => '155-0000-2-'.random_int(1000, 9999)]);
        $project = SalesProject::create(['sub_client_id' => $sub->id, 'name' => 'Proyecto Externa '.uniqid()]);
        $card    = PipelineCard::create([
            'sales_project_id' => $project->id, 'stage' => 'approved', 'master_client_id' => $master->id,
            'sub_client_id' => $sub->id, 'owner_id' => $this->userIdByEmail(self::FELIX_EMAIL), 'amount' => $amount,
            'stage_changed_at' => $fechaPedido ?? Carbon::now(),
        ]);

        Quote::create([
            'owner_id' => $this->userIdByEmail(self::FELIX_EMAIL), 'sales_project_id' => $project->id,
            'architect_id' => $architect->id, 'status' => 'approved',
        ]);

        $order = Order::create([
            'order_number' => 'PED-ARQ-'.random_int(1000, 999999), 'pipeline_card_id' => $card->id,
            'order_type' => Order::ORDER_TYPE_PEDIDO, 'stage' => Order::STAGE_POR_DESPACHAR,
            'customer_name' => $sub->business_name,
        ]);

        AdminContInvoice::create([
            'numero' => 'F-ARQ-'.random_int(1000, 999999), 'order_id' => $order->id, 'cliente' => $sub->business_name,
            'master_client_id' => $master->id, 'monto' => $amount, 'saldo_aplicado' => $totalCobrado,
        ]);

        return $card;
    }

    /** REQ-515 (marcar pagada) es Batch 17, sin endpoint todavía — fixture directo para poder
     *  probar el desglose Generada/Pagada/Pendiente (REQ-508/509) contra un proyecto ya "Pagada". */
    private function markCommissionPaid(PipelineCard $card): void
    {
        ArchitectCommission::updateOrCreate(
            ['pipeline_card_id' => $card->id],
            ['architect_id' => $this->architectIdForCard($card), 'fecha_pago' => now()],
        );
    }

    private function architectIdForCard(PipelineCard $card): int
    {
        return (int) app(ArchitectCommissionService::class)->allProjects()
            ->firstWhere('pipeline_card_id', $card->id)['architect_id'];
    }

    // ---- ArchitectCommissionService, sin HTTP ----

    public function test_estado_for_todo_o_nada(): void
    {
        $service = app(ArchitectCommissionService::class);

        $this->assertSame(ArchitectCommissionService::ESTADO_AUN_NO_GENERADA, $service->estadoFor(1000.0, 600.0, null));
        $this->assertSame(ArchitectCommissionService::ESTADO_PENDIENTE_FACTURA, $service->estadoFor(1000.0, 1000.0, null));
        $this->assertSame(ArchitectCommissionService::ESTADO_PAGADA, $service->estadoFor(1000.0, 1000.0, \Carbon\CarbonImmutable::now()));
    }

    /** Escenarios 1/2/3 de REQ-511. */
    public function test_monto_for_segun_regimen_fiscal(): void
    {
        $service = app(ArchitectCommissionService::class);

        $conItbms = $service->montoFor(34000.0, ArchitectFiscalProfile::REGIMEN_CON_ITBMS);
        $this->assertSame(3400.0, $conItbms['comision']);
        $this->assertSame(238.0, $conItbms['impuesto']);
        $this->assertSame(3638.0, $conItbms['total']);

        $retencion = $service->montoFor(9200.0, ArchitectFiscalProfile::REGIMEN_RETENCION_50);
        $this->assertSame(920.0, $retencion['comision']);
        $this->assertSame(32.2, $retencion['impuesto']);
        $this->assertSame(952.2, $retencion['total']);

        $sinRegimen = $service->montoFor(9200.0, null);
        $this->assertNull($sinRegimen['impuesto']);
        $this->assertNull($sinRegimen['total']);

        $exento = $service->montoFor(1000.0, ArchitectFiscalProfile::REGIMEN_EXENTO);
        $this->assertSame(0.0, $exento['impuesto']);
        $this->assertSame(100.0, $exento['total']);
    }

    // ---- End-to-end vía HTTP ----

    public function test_summary_agrupa_por_arquitecto_y_respeta_datos_fiscales(): void
    {
        $architect = $this->makeArchitect();
        ArchitectFiscalProfile::create(['architect_id' => $architect->id, 'empresa' => 'Estudio X', 'ruc' => '1-1-1', 'regimen_fiscal' => ArchitectFiscalProfile::REGIMEN_CON_ITBMS]);

        $this->makeArchitectProject($architect, 34000.0, 34000.0); // pendiente_factura_arquitecto

        $token = $this->loginAsMark();
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/commissions/external')
            ->assertStatus(200);

        $response->assertJsonPath('pendiente_factura', 3400);
        $response->assertJsonPath('comision_generada', 3400);
        $response->assertJsonCount(1, 'arquitectos');
        $response->assertJsonPath('arquitectos.0.architect_id', $architect->id);
        $response->assertJsonPath('arquitectos.0.datos_fiscales_completos', true);
        $response->assertJsonPath('arquitectos.0.proyectos.0.impuesto', 238);
        $response->assertJsonPath('arquitectos.0.proyectos.0.total', 3638);
    }

    /** Visual Review — mockup del ticket (4F__Admin_Contabilidad_ComisionesExt.html) pide 3
     *  columnas de monto por fila de arquitecto (Generada/Pagada/Pendiente), no un solo total. */
    public function test_fila_de_arquitecto_desglosa_generada_pagada_pendiente(): void
    {
        $architect = $this->makeArchitect();
        ArchitectFiscalProfile::create(['architect_id' => $architect->id, 'empresa' => 'Estudio X', 'ruc' => '1-1-1', 'regimen_fiscal' => ArchitectFiscalProfile::REGIMEN_EXENTO]);

        $card1 = $this->makeArchitectProject($architect, 10000.0, 10000.0); // pendiente_factura_arquitecto ($1000)
        $this->markCommissionPaid($card1);
        $this->makeArchitectProject($architect, 5000.0, 5000.0); // pendiente_factura_arquitecto ($500)

        $token = $this->loginAsMark();
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/commissions/external')
            ->assertStatus(200);

        $response->assertJsonPath('arquitectos.0.generada', 1500);
        $response->assertJsonPath('arquitectos.0.pagada', 1000);
        $response->assertJsonPath('arquitectos.0.pendiente', 500);
    }

    public function test_arquitecto_sin_datos_fiscales_no_suma_pero_aparece_con_aviso(): void
    {
        $architect = $this->makeArchitect();
        $this->makeArchitectProject($architect, 10000.0, 10000.0);

        $token = $this->loginAsMark();
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/commissions/external')
            ->assertStatus(200);

        $response->assertJsonPath('pendiente_factura', 0);
        $response->assertJsonPath('comision_generada', 0);
        $response->assertJsonPath('arquitectos.0.datos_fiscales_completos', false);
        $response->assertJsonPath('arquitectos.0.proyectos.0.total', null);
    }

    public function test_cobro_parcial_nunca_genera_comision(): void
    {
        $architect = $this->makeArchitect();
        ArchitectFiscalProfile::create(['architect_id' => $architect->id, 'empresa' => 'Estudio X', 'ruc' => '1-1-1', 'regimen_fiscal' => ArchitectFiscalProfile::REGIMEN_EXENTO]);
        $this->makeArchitectProject($architect, 5000.0, 2500.0);

        $token = $this->loginAsMark();
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/commissions/external')
            ->assertStatus(200);

        $response->assertJsonPath('aun_no_generada', 500);
        $response->assertJsonPath('pendiente_factura', 0);
        $response->assertJsonPath('arquitectos.0.proyectos.0.estado', ArchitectCommissionService::ESTADO_AUN_NO_GENERADA);
    }

    public function test_filtro_por_estado_oculta_arquitecto_sin_ningun_proyecto_que_matchee(): void
    {
        $architect = $this->makeArchitect();
        ArchitectFiscalProfile::create(['architect_id' => $architect->id, 'empresa' => 'Estudio X', 'ruc' => '1-1-1', 'regimen_fiscal' => ArchitectFiscalProfile::REGIMEN_EXENTO]);
        $this->makeArchitectProject($architect, 5000.0, 2500.0); // aun_no_generada

        $token = $this->loginAsMark();
        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/commissions/external?estado='.ArchitectCommissionService::ESTADO_PAGADA)
            ->assertStatus(200)
            ->assertJsonCount(0, 'arquitectos');
    }

    public function test_felix_actualiza_datos_fiscales(): void
    {
        $architect = $this->makeArchitect();

        $token = $this->loginAsFelix();
        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/admin-contab/commissions/external/architects/{$architect->id}/fiscal-profile", [
                'empresa' => 'Estudio Nuevo', 'ruc' => '2-2-2', 'regimen_fiscal' => 'con_itbms',
            ])
            ->assertStatus(200)
            ->assertJsonPath('empresa', 'Estudio Nuevo');

        $this->reactivateTenant();
        $this->assertDatabaseHas((new ArchitectFiscalProfile())->getTable(), [
            'architect_id' => $architect->id, 'empresa' => 'Estudio Nuevo', 'regimen_fiscal' => 'con_itbms',
        ]);
    }

    /** RN1 REQ-510 — los 3 campos son obligatorios en conjunto. */
    public function test_datos_fiscales_incompletos_rechazados_422(): void
    {
        $architect = $this->makeArchitect();

        $token = $this->loginAsFelix();
        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/admin-contab/commissions/external/architects/{$architect->id}/fiscal-profile", [
                'empresa' => 'Estudio Nuevo', 'ruc' => '2-2-2',
            ])
            ->assertStatus(422);
    }

    public function test_felix_sube_cuenta_de_cobro_y_reemplazo_borra_el_archivo_viejo(): void
    {
        $architect = $this->makeArchitect();
        ArchitectFiscalProfile::create(['architect_id' => $architect->id, 'empresa' => 'Estudio X', 'ruc' => '1-1-1', 'regimen_fiscal' => ArchitectFiscalProfile::REGIMEN_EXENTO]);
        $card = $this->makeArchitectProject($architect, 10000.0, 10000.0);

        $token = $this->loginAsFelix();
        $file1 = UploadedFile::fake()->create('cuenta1.pdf', 100, 'application/pdf');

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/admin-contab/commissions/external/projects/{$card->id}/cuenta-cobro", ['file' => $file1])
            ->assertStatus(201)
            ->assertJsonPath('nombre_archivo', 'cuenta1.pdf');

        $this->reactivateTenant();
        $commission = ArchitectCommission::where('pipeline_card_id', $card->id)->first();
        $this->assertNotNull($commission);
        $oldKey = $commission->cuenta_cobro_storage_key;
        Storage::disk('s3-private')->assertExists($oldKey);

        $file2 = UploadedFile::fake()->create('cuenta2.pdf', 100, 'application/pdf');
        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/admin-contab/commissions/external/projects/{$card->id}/cuenta-cobro", ['file' => $file2])
            ->assertStatus(201)
            ->assertJsonPath('nombre_archivo', 'cuenta2.pdf');

        Storage::disk('s3-private')->assertMissing($oldKey);
        $this->reactivateTenant();
        $this->assertSame(1, ArchitectCommission::where('pipeline_card_id', $card->id)->count());
    }

    public function test_upload_rechaza_tipo_de_archivo_no_permitido(): void
    {
        $architect = $this->makeArchitect();
        $card = $this->makeArchitectProject($architect, 10000.0, 10000.0);

        $token = $this->loginAsFelix();
        $file = UploadedFile::fake()->create('cuenta.docx', 100, 'application/msword');

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/admin-contab/commissions/external/projects/{$card->id}/cuenta-cobro", ['file' => $file])
            ->assertStatus(422);
    }

    public function test_mark_no_puede_subir_cuenta_de_cobro(): void
    {
        $architect = $this->makeArchitect();
        $card = $this->makeArchitectProject($architect, 10000.0, 10000.0);

        $token = $this->loginAsMark();
        $file = UploadedFile::fake()->create('cuenta.pdf', 100, 'application/pdf');

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/admin-contab/commissions/external/projects/{$card->id}/cuenta-cobro", ['file' => $file])
            ->assertStatus(403);
    }

    /** Hallazgo de Senior Review — GET .../cuenta-cobro (ver el archivo, REQ-513) no tenía
     *  ningún test todavía. Mark SÍ puede verla aunque no pueda subirla (gate de lectura general,
     *  `permission:admin_contab.read`, distinto del grupo de escritura angosto de arriba). */
    public function test_ver_cuenta_de_cobro_ya_subida_devuelve_url_firmada(): void
    {
        $architect = $this->makeArchitect();
        $card = $this->makeArchitectProject($architect, 10000.0, 10000.0);

        $felixToken = $this->loginAsFelix();
        $file = UploadedFile::fake()->create('cuenta.pdf', 100, 'application/pdf');
        $this->withHeaders($this->authHeaders($felixToken))
            ->postJson("/api/admin-contab/commissions/external/projects/{$card->id}/cuenta-cobro", ['file' => $file])
            ->assertStatus(201);

        $markToken = $this->loginAsMark();
        $response = $this->withHeaders($this->authHeaders($markToken))
            ->getJson("/api/admin-contab/commissions/external/projects/{$card->id}/cuenta-cobro")
            ->assertStatus(200);

        $this->assertNotNull($response->json('url'));
    }

    public function test_ver_cuenta_de_cobro_sin_subir_devuelve_404(): void
    {
        $architect = $this->makeArchitect();
        $card = $this->makeArchitectProject($architect, 10000.0, 10000.0);

        $token = $this->loginAsMark();
        $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/admin-contab/commissions/external/projects/{$card->id}/cuenta-cobro")
            ->assertStatus(404);
    }

    // ==================== Batch 17 (SCRUM-591→596, REQ-514→519) ====================

    private function makeBankAccount(): AdminContBankAccount
    {
        return AdminContBankAccount::create([
            'banco' => 'Banco General', 'tipo_cuenta' => AdminContBankAccount::TIPO_CORRIENTE,
            'ultimos_4_digitos' => (string) random_int(1000, 9999), 'moneda' => 'USD', 'activa' => true,
        ]);
    }

    /** REQ-514 RN1 — solo régimen retención_50 puede subir comprobante. */
    public function test_upload_comprobante_retencion_rechazado_fuera_de_regimen_retencion(): void
    {
        $architect = $this->makeArchitect();
        ArchitectFiscalProfile::create(['architect_id' => $architect->id, 'empresa' => 'Estudio X', 'ruc' => '1-1-1', 'regimen_fiscal' => ArchitectFiscalProfile::REGIMEN_CON_ITBMS]);
        $card = $this->makeArchitectProject($architect, 10000.0, 10000.0);

        $token = $this->loginAsFelix();
        $file  = UploadedFile::fake()->create('retencion.pdf', 100, 'application/pdf');

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/admin-contab/commissions/external/projects/{$card->id}/comprobante-retencion", ['file' => $file])
            ->assertStatus(422);
    }

    public function test_upload_comprobante_retencion_exitoso_y_visible(): void
    {
        $architect = $this->makeArchitect();
        ArchitectFiscalProfile::create(['architect_id' => $architect->id, 'empresa' => 'Estudio X', 'ruc' => '1-1-1', 'regimen_fiscal' => ArchitectFiscalProfile::REGIMEN_RETENCION_50]);
        $card = $this->makeArchitectProject($architect, 10000.0, 10000.0);

        $felixToken = $this->loginAsFelix();
        $file = UploadedFile::fake()->create('retencion.pdf', 100, 'application/pdf');
        $this->withHeaders($this->authHeaders($felixToken))
            ->postJson("/api/admin-contab/commissions/external/projects/{$card->id}/comprobante-retencion", ['file' => $file])
            ->assertStatus(201)
            ->assertJsonPath('nombre_archivo', 'retencion.pdf');

        $markToken = $this->loginAsMark();
        $response = $this->withHeaders($this->authHeaders($markToken))
            ->getJson("/api/admin-contab/commissions/external/projects/{$card->id}/comprobante-retencion")
            ->assertStatus(200);
        $this->assertNotNull($response->json('url'));
    }

    public function test_bank_account_options_lista_solo_cuentas_activas(): void
    {
        $activa = $this->makeBankAccount();
        $inactiva = $this->makeBankAccount();
        $this->reactivateTenant();
        $inactiva->update(['activa' => false]);

        $token = $this->loginAsMark();
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/commissions/external/bank-accounts')
            ->assertStatus(200);

        $ids = collect($response->json())->pluck('id');
        $this->assertTrue($ids->contains($activa->id));
        $this->assertFalse($ids->contains($inactiva->id));
    }

    /** REQ-517 — Felix asigna una cuenta de pago específica al proyecto. */
    public function test_felix_actualiza_cuenta_de_pago(): void
    {
        $architect = $this->makeArchitect();
        $card = $this->makeArchitectProject($architect, 10000.0, 10000.0);
        $account = $this->makeBankAccount();

        $token = $this->loginAsFelix();
        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/admin-contab/commissions/external/projects/{$card->id}/cuenta-pago", ['bank_account_id' => $account->id])
            ->assertStatus(200)
            ->assertJsonPath('bank_account_id', $account->id);
    }

    public function test_cuenta_de_pago_no_editable_tras_pago(): void
    {
        $architect = $this->makeArchitect();
        $card = $this->makeArchitectProject($architect, 10000.0, 10000.0);
        $this->markCommissionPaid($card);
        $account = $this->makeBankAccount();

        $token = $this->loginAsFelix();
        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/admin-contab/commissions/external/projects/{$card->id}/cuenta-pago", ['bank_account_id' => $account->id])
            ->assertStatus(422);
    }

    /** REQ-516 RN3 — Felix propone un % distinto del default, queda pendiente de Mark. */
    public function test_felix_propone_porcentaje(): void
    {
        $architect = $this->makeArchitect();
        $card = $this->makeArchitectProject($architect, 10000.0, 10000.0);

        $token = $this->loginAsFelix();
        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/admin-contab/commissions/external/projects/{$card->id}/percent/propose", [
                'porcentaje' => 12.5, 'motivo' => 'Proyecto con alcance ampliado',
            ])
            ->assertStatus(200)
            ->assertJsonPath('porcentaje_pendiente', 12.5);
    }

    public function test_no_se_puede_proponer_porcentaje_dos_veces_sin_decidir(): void
    {
        $architect = $this->makeArchitect();
        $card = $this->makeArchitectProject($architect, 10000.0, 10000.0);

        $token = $this->loginAsFelix();
        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/admin-contab/commissions/external/projects/{$card->id}/percent/propose", ['porcentaje' => 12.5, 'motivo' => 'x'])
            ->assertStatus(200);

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/admin-contab/commissions/external/projects/{$card->id}/percent/propose", ['porcentaje' => 15.0, 'motivo' => 'y'])
            ->assertStatus(422);
    }

    /** REQ-516 RN4 — solo Mark puede decidir. */
    public function test_felix_no_puede_decidir_porcentaje(): void
    {
        $architect = $this->makeArchitect();
        $card = $this->makeArchitectProject($architect, 10000.0, 10000.0);

        $felixToken = $this->loginAsFelix();
        $this->withHeaders($this->authHeaders($felixToken))
            ->postJson("/api/admin-contab/commissions/external/projects/{$card->id}/percent/propose", ['porcentaje' => 12.5, 'motivo' => 'x'])
            ->assertStatus(200);

        $this->withHeaders($this->authHeaders($felixToken))
            ->postJson("/api/admin-contab/commissions/external/projects/{$card->id}/percent/decide", ['approve' => true])
            ->assertStatus(403);
    }

    public function test_mark_aprueba_porcentaje_propuesto(): void
    {
        $architect = $this->makeArchitect();
        $card = $this->makeArchitectProject($architect, 10000.0, 10000.0);

        $felixToken = $this->loginAsFelix();
        $this->withHeaders($this->authHeaders($felixToken))
            ->postJson("/api/admin-contab/commissions/external/projects/{$card->id}/percent/propose", ['porcentaje' => 12.5, 'motivo' => 'x'])
            ->assertStatus(200);

        $markToken = $this->loginAsMark();
        $this->withHeaders($this->authHeaders($markToken))
            ->postJson("/api/admin-contab/commissions/external/projects/{$card->id}/percent/decide", ['approve' => true])
            ->assertStatus(200)
            ->assertJsonPath('porcentaje_aprobado', 12.5);

        $this->reactivateTenant();
        $commission = ArchitectCommission::where('pipeline_card_id', $card->id)->first();
        $this->assertNotNull($commission);
        $this->assertNull($commission->porcentaje_pendiente);
    }

    public function test_mark_rechaza_porcentaje_propuesto_exige_motivo(): void
    {
        $architect = $this->makeArchitect();
        $card = $this->makeArchitectProject($architect, 10000.0, 10000.0);

        $felixToken = $this->loginAsFelix();
        $this->withHeaders($this->authHeaders($felixToken))
            ->postJson("/api/admin-contab/commissions/external/projects/{$card->id}/percent/propose", ['porcentaje' => 12.5, 'motivo' => 'x'])
            ->assertStatus(200);

        $markToken = $this->loginAsMark();
        $this->withHeaders($this->authHeaders($markToken))
            ->postJson("/api/admin-contab/commissions/external/projects/{$card->id}/percent/decide", ['approve' => false])
            ->assertStatus(422);

        $this->withHeaders($this->authHeaders($markToken))
            ->postJson("/api/admin-contab/commissions/external/projects/{$card->id}/percent/decide", ['approve' => false, 'motivo_rechazo' => 'No aplica'])
            ->assertStatus(200);

        $this->reactivateTenant();
        $commission = ArchitectCommission::where('pipeline_card_id', $card->id)->first();
        $this->assertNotNull($commission);
        $this->assertNull($commission->porcentaje_pendiente);
        $this->assertNull($commission->porcentaje_aprobado);
    }

    /** REQ-515 RN2 — cadena de validación de "Marcar como pagado", en el mismo orden del mockup. */
    public function test_marcar_pagado_bloqueado_por_porcentaje_pendiente(): void
    {
        $architect = $this->makeArchitect();
        ArchitectFiscalProfile::create(['architect_id' => $architect->id, 'empresa' => 'Estudio X', 'ruc' => '1-1-1', 'regimen_fiscal' => ArchitectFiscalProfile::REGIMEN_EXENTO]);
        $card = $this->makeArchitectProject($architect, 10000.0, 10000.0);

        $token = $this->loginAsFelix();
        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/admin-contab/commissions/external/projects/{$card->id}/percent/propose", ['porcentaje' => 12.5, 'motivo' => 'x'])
            ->assertStatus(200);

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/admin-contab/commissions/external/projects/{$card->id}/mark-paid")
            ->assertStatus(422);
    }

    public function test_marcar_pagado_bloqueado_por_datos_fiscales_incompletos(): void
    {
        $architect = $this->makeArchitect();
        $card = $this->makeArchitectProject($architect, 10000.0, 10000.0);

        $token = $this->loginAsFelix();
        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/admin-contab/commissions/external/projects/{$card->id}/mark-paid")
            ->assertStatus(422);
    }

    public function test_marcar_pagado_bloqueado_sin_cuenta_de_cobro(): void
    {
        $architect = $this->makeArchitect();
        ArchitectFiscalProfile::create(['architect_id' => $architect->id, 'empresa' => 'Estudio X', 'ruc' => '1-1-1', 'regimen_fiscal' => ArchitectFiscalProfile::REGIMEN_EXENTO]);
        $card = $this->makeArchitectProject($architect, 10000.0, 10000.0);

        $token = $this->loginAsFelix();
        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/admin-contab/commissions/external/projects/{$card->id}/mark-paid")
            ->assertStatus(422);
    }

    public function test_marcar_pagado_bloqueado_sin_comprobante_retencion_en_regimen_retencion(): void
    {
        $architect = $this->makeArchitect();
        ArchitectFiscalProfile::create(['architect_id' => $architect->id, 'empresa' => 'Estudio X', 'ruc' => '1-1-1', 'regimen_fiscal' => ArchitectFiscalProfile::REGIMEN_RETENCION_50]);
        $card = $this->makeArchitectProject($architect, 10000.0, 10000.0);

        $token = $this->loginAsFelix();
        $file = UploadedFile::fake()->create('cuenta.pdf', 100, 'application/pdf');
        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/admin-contab/commissions/external/projects/{$card->id}/cuenta-cobro", ['file' => $file])
            ->assertStatus(201);

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/admin-contab/commissions/external/projects/{$card->id}/mark-paid")
            ->assertStatus(422);
    }

    public function test_marcar_pagado_exitoso_con_todos_los_requisitos(): void
    {
        $architect = $this->makeArchitect();
        ArchitectFiscalProfile::create(['architect_id' => $architect->id, 'empresa' => 'Estudio X', 'ruc' => '1-1-1', 'regimen_fiscal' => ArchitectFiscalProfile::REGIMEN_EXENTO]);
        $card = $this->makeArchitectProject($architect, 10000.0, 10000.0);

        $token = $this->loginAsFelix();
        $file = UploadedFile::fake()->create('cuenta.pdf', 100, 'application/pdf');
        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/admin-contab/commissions/external/projects/{$card->id}/cuenta-cobro", ['file' => $file])
            ->assertStatus(201);

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/admin-contab/commissions/external/projects/{$card->id}/mark-paid")
            ->assertStatus(200)
            ->assertJsonPath('fecha_pago', now()->toDateString());
    }

    /**
     * Batch 18 (SCRUM-597→601) — ver ADR-SCRUM597-601-batch18-arqueo-caja.md, reconciliación
     * punto 5: marcar pagado con una cuenta de pago ya configurada debe alimentar el ledger real
     * de `bank_movements` (única fuente de "comisiones pagadas" en la Vista real del flujo de caja).
     */
    public function test_marcar_pagado_con_cuenta_de_pago_registra_bank_movement(): void
    {
        $architect = $this->makeArchitect();
        ArchitectFiscalProfile::create(['architect_id' => $architect->id, 'empresa' => 'Estudio X', 'ruc' => '1-1-1', 'regimen_fiscal' => ArchitectFiscalProfile::REGIMEN_EXENTO]);
        $card    = $this->makeArchitectProject($architect, 10000.0, 10000.0);
        $account = $this->makeBankAccount();

        $token = $this->loginAsFelix();
        $file  = UploadedFile::fake()->create('cuenta.pdf', 100, 'application/pdf');
        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/admin-contab/commissions/external/projects/{$card->id}/cuenta-cobro", ['file' => $file])
            ->assertStatus(201);
        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/admin-contab/commissions/external/projects/{$card->id}/cuenta-pago", ['bank_account_id' => $account->id])
            ->assertStatus(200);

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/admin-contab/commissions/external/projects/{$card->id}/mark-paid")
            ->assertStatus(200);

        $slug     = (string) Tenant::where('slug', 'atlantic')->value('slug');
        $movement = \Illuminate\Support\Facades\DB::table("{$slug}_admin_contab.bank_movements")
            ->where('source_type', 'architect_commission')->first();

        $this->assertNotNull($movement, 'debe registrar un bank_movement al marcar pagada una comisión con cuenta de pago');
        $this->assertSame('comision', $movement->tipo);
        $this->assertSame($account->id, $movement->bank_account_id);
        $this->assertEquals(1000.0, (float) $movement->monto); // 10% default de comision_externa_default_percent, régimen exento sin impuesto
    }

    /** REQ-518 — recordatorio se despacha async, nunca síncrono. */
    public function test_enviar_recordatorio_encola_job(): void
    {
        Queue::fake();

        $architect = $this->makeArchitect();
        ArchitectFiscalProfile::create(['architect_id' => $architect->id, 'empresa' => 'Estudio X', 'ruc' => '1-1-1', 'regimen_fiscal' => ArchitectFiscalProfile::REGIMEN_EXENTO]);
        $card = $this->makeArchitectProject($architect, 10000.0, 10000.0);

        $token = $this->loginAsFelix();
        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/admin-contab/commissions/external/projects/{$card->id}/remind")
            ->assertStatus(200);

        Queue::assertPushed(SendArchitectReminderEmailJob::class);
    }

    /** REQ-516 RN4 — el frontend gatea Aprobar/Rechazar en el modal de detalle con este flag. */
    public function test_summary_expone_puede_decidir_porcentaje_segun_actor(): void
    {
        $markToken = $this->loginAsMark();
        $this->withHeaders($this->authHeaders($markToken))
            ->getJson('/api/admin-contab/commissions/external')
            ->assertStatus(200)
            ->assertJsonPath('puede_decidir_porcentaje', true);

        $felixToken = $this->loginAsFelix();
        $this->withHeaders($this->authHeaders($felixToken))
            ->getJson('/api/admin-contab/commissions/external')
            ->assertStatus(200)
            ->assertJsonPath('puede_decidir_porcentaje', false);
    }

    public function test_enviar_recordatorio_bloqueado_si_no_esta_pendiente_de_factura(): void
    {
        $architect = $this->makeArchitect();
        $card = $this->makeArchitectProject($architect, 5000.0, 2500.0); // aun_no_generada

        $token = $this->loginAsFelix();
        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/admin-contab/commissions/external/projects/{$card->id}/remind")
            ->assertStatus(422);
    }
}
