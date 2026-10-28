<?php

declare(strict_types=1);

namespace Tests\Feature\AdminCont\FacturacionElectronica;

use App\Modules\AdminCont\Events\InvoiceCreated;
use App\Modules\AdminCont\FacturacionElectronica\DTOs\FiscalDocumentResult;
use App\Modules\AdminCont\FacturacionElectronica\Services\FiscalCertificationService;
use App\Modules\AdminCont\Models\AdminContCreditNote;
use App\Modules\AdminCont\Models\AdminContFiscalSettings;
use App\Modules\AdminCont\Models\AdminContInvoice;
use App\Modules\AdminCont\Models\AdminContItbmsRate;
use App\Modules\Bodega\Models\Order;
use App\Modules\VentasDiseno\Models\MasterClient;
use App\Modules\VentasDiseno\Models\PipelineCard;
use App\Modules\VentasDiseno\Models\Quote;
use App\Modules\VentasDiseno\Models\SalesProject;
use App\Modules\VentasDiseno\Models\SubClient;
use App\Shared\Multitenancy\Tenant;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-943 — wiring de Notas de Crédito: certifica solo cuando la nota queda `aplicada` de
 * verdad (nunca mientras está `pendiente_aprobacion`, mismo gate que el correo de confirmación ya
 * existente), y la factura de REEMPLAZO de una "Corrección de datos" se certifica como factura
 * propia (reusa `InvoiceCreated`/`CertifyInvoiceOnCreated`, cero código de FE nuevo para ese caso).
 */
final class CertifyCreditNoteTest extends FeatureTestCase
{
    private const CONTABILIDAD_EMAIL = 'contabilidad@test.com';
    private const APPROVER_EMAIL = 'gerencia3@test.com';

    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        config(['services.msl_fe.sandbox' => ['base_url' => 'https://sandbox.msl.test', 'api_key' => 'sandbox-key']]);
        AdminContFiscalSettings::current()->forceFill(['pac_provider' => 'msl', 'pac_ambiente' => 'testing'])->save();
    }

    private function userIdByEmail(string $email): int
    {
        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');

        return (int) \Illuminate\Support\Facades\DB::table("{$slug}_auth.users")->where('email', $email)->value('id');
    }

    /** El ciclo de vida del request HTTP de test olvida el tenant activo al terminar — hay que
     *  re-establecerlo antes de cualquier consulta directa a Eloquent después de un ->post()/->put(). */
    private function reTenant(): void
    {
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    /** @return array{invoice: AdminContInvoice, masterClientId: int} */
    private function facturaCertificadaConCotizacion(int $ownerId, float $monto): array
    {
        $master  = MasterClient::create(['name' => 'Cliente NC FE '.uniqid()]);
        $sub     = SubClient::create(['master_client_id' => $master->id, 'business_name' => 'Subcliente '.uniqid(), 'tax_id' => '155-0000-1-'.random_int(1000, 9999)]);
        $project = SalesProject::create(['sub_client_id' => $sub->id, 'name' => 'Proyecto '.uniqid()]);
        $card    = PipelineCard::create([
            'sales_project_id' => $project->id, 'stage' => 'approved', 'master_client_id' => $master->id,
            'sub_client_id' => $sub->id, 'owner_id' => $ownerId, 'amount' => $monto, 'stage_changed_at' => Carbon::now(),
        ]);
        $order = Order::create([
            'order_number' => 'PED-2026-'.random_int(1000, 999999),
            'order_type' => Order::ORDER_TYPE_PEDIDO,
            'stage' => Order::STAGE_POR_DESPACHAR,
            'customer_name' => $sub->business_name,
            'pipeline_card_id' => $card->id,
        ]);
        $quote = Quote::create([
            'owner_id' => $ownerId, 'master_client_id' => $master->id, 'sub_client_id' => $sub->id,
            'sales_project_id' => $project->id, 'folio' => 'COT-2026-'.static::uniqueTestNumber(), 'generated_at' => Carbon::now(),
        ]);
        $generalRate = AdminContItbmsRate::where('nombre', '7% — General')->firstOrFail();

        $invoice = AdminContInvoice::create([
            'numero' => 'F-'.static::uniqueTestNumber(), 'order_id' => $order->id, 'quote_id' => $quote->id,
            'itbms_rate_id' => $generalRate->id, 'sales_project_id' => $project->id, 'master_client_id' => $master->id,
            'cliente' => $sub->business_name, 'monto' => $monto, 'saldo_aplicado' => 0.0,
        ]);
        $invoice->forceFill(['serie' => 'ILU', 'cufe' => 'FE0120000...origen-'.$invoice->id, 'fe_estado' => 'certificado'])->save();

        return ['invoice' => $invoice->fresh(), 'masterClientId' => $master->id];
    }

    /** @return array<string, mixed> */
    private function registerPayload(AdminContInvoice $invoice, float $monto): array
    {
        Storage::fake('s3-private');

        return [
            'master_client_id' => $invoice->master_client_id,
            'cliente' => $invoice->cliente,
            'tipo' => AdminContCreditNote::TIPO_DESCUENTO_COMERCIAL,
            'factura_origen_id' => $invoice->id,
            'monto' => $monto,
            'motivo' => 'Descuento comercial acordado con el cliente',
            'comprobante' => UploadedFile::fake()->create('soporte.pdf', 100, 'application/pdf'),
        ];
    }

    private function loginAsContabilidad(): string
    {
        return $this->loginAs(self::CONTABILIDAD_EMAIL, self::CONTABILIDAD_EMAIL, 'lider_admin_contab');
    }

    private function loginAsMark(): string
    {
        return $this->loginAs(self::APPROVER_EMAIL, self::APPROVER_EMAIL, 'management');
    }

    public function test_nota_aplicada_de_inmediato_se_certifica(): void
    {
        Http::fake(['sandbox.msl.test/*' => Http::response(['ok' => true, 'estado' => 'CERTIFICADO', 'cufe' => 'FE04...nc'], 200)]);
        $ownerId = $this->userIdByEmail(self::CONTABILIDAD_EMAIL);
        ['invoice' => $invoice] = $this->facturaCertificadaConCotizacion($ownerId, 500);
        $token = $this->loginAsContabilidad();

        // 100 < umbral (5000) y < saldo pendiente (500) -> resultado aplicado_saldo, estado aplicada.
        $response = $this->withHeaders($this->authHeaders($token))
            ->post('/api/admin-contab/notas-credito', $this->registerPayload($invoice, 100))
            ->assertStatus(201);
        $this->reTenant();

        $note = AdminContCreditNote::findOrFail($response->json('id'));
        $this->assertSame(AdminContCreditNote::ESTADO_APLICADA, $note->estado);
        $this->assertSame('certificado', $note->fe_estado);
        $this->assertSame('FE04...nc', $note->cufe);
    }

    public function test_nota_pendiente_de_aprobacion_no_se_certifica_todavia(): void
    {
        Http::fake();
        $ownerId = $this->userIdByEmail(self::CONTABILIDAD_EMAIL);
        ['invoice' => $invoice] = $this->facturaCertificadaConCotizacion($ownerId, 10000);
        $token = $this->loginAsContabilidad();

        // 6000 > umbral (5000) -> queda pendiente_aprobacion, nunca debe llamar a MSL.
        $response = $this->withHeaders($this->authHeaders($token))
            ->post('/api/admin-contab/notas-credito', $this->registerPayload($invoice, 6000))
            ->assertStatus(201);
        $this->reTenant();

        Http::assertNothingSent();
        $note = AdminContCreditNote::findOrFail($response->json('id'));
        $this->assertSame(AdminContCreditNote::ESTADO_PENDIENTE_APROBACION, $note->estado);
        $this->assertNull($note->fe_estado);
    }

    public function test_decide_aprobar_certifica_la_nota_pendiente(): void
    {
        Http::fake(['sandbox.msl.test/*' => Http::response(['ok' => true, 'estado' => 'CERTIFICADO', 'cufe' => 'FE04...aprobada'], 200)]);
        $ownerId = $this->userIdByEmail(self::CONTABILIDAD_EMAIL);
        ['invoice' => $invoice] = $this->facturaCertificadaConCotizacion($ownerId, 10000);
        $contabilidad = $this->loginAsContabilidad();
        $store = $this->withHeaders($this->authHeaders($contabilidad))
            ->post('/api/admin-contab/notas-credito', $this->registerPayload($invoice, 6000))
            ->assertStatus(201);
        $this->reTenant();
        Http::assertNothingSent(); // sigue pendiente, no debió certificar al registrar

        $approver = $this->loginAsMark();
        $this->withHeaders($this->authHeaders($approver))
            ->putJson("/api/admin-contab/notas-credito/{$store->json('id')}/decision", ['approve' => true])
            ->assertStatus(200);
        $this->reTenant();

        $note = AdminContCreditNote::findOrFail($store->json('id'));
        $this->assertSame(AdminContCreditNote::ESTADO_APLICADA, $note->estado);
        $this->assertSame('certificado', $note->fe_estado);
        $this->assertSame('FE04...aprobada', $note->cufe);
    }

    public function test_decide_rechazar_nunca_certifica(): void
    {
        Http::fake();
        $ownerId = $this->userIdByEmail(self::CONTABILIDAD_EMAIL);
        ['invoice' => $invoice] = $this->facturaCertificadaConCotizacion($ownerId, 10000);
        $contabilidad = $this->loginAsContabilidad();
        $store = $this->withHeaders($this->authHeaders($contabilidad))
            ->post('/api/admin-contab/notas-credito', $this->registerPayload($invoice, 6000))
            ->assertStatus(201);
        $this->reTenant();

        $approver = $this->loginAsMark();
        $this->withHeaders($this->authHeaders($approver))
            ->putJson("/api/admin-contab/notas-credito/{$store->json('id')}/decision", ['approve' => false, 'motivo_rechazo' => 'No procede'])
            ->assertStatus(200);
        $this->reTenant();

        Http::assertNothingSent();
        $note = AdminContCreditNote::findOrFail($store->json('id'));
        $this->assertSame(AdminContCreditNote::ESTADO_RECHAZADA, $note->estado);
        $this->assertNull($note->fe_estado);
    }

    /** pac_provider que no existe en el registro — el registro de la nota nunca debe fallar por esto. */
    public function test_un_fallo_de_certificacion_nunca_bloquea_el_registro_de_la_nota(): void
    {
        AdminContFiscalSettings::current()->forceFill(['pac_provider' => 'proveedor-inexistente'])->save();
        Http::fake();
        $ownerId = $this->userIdByEmail(self::CONTABILIDAD_EMAIL);
        ['invoice' => $invoice] = $this->facturaCertificadaConCotizacion($ownerId, 500);
        $token = $this->loginAsContabilidad();

        $response = $this->withHeaders($this->authHeaders($token))
            ->post('/api/admin-contab/notas-credito', $this->registerPayload($invoice, 100))
            ->assertStatus(201);
        $this->reTenant();

        $note = AdminContCreditNote::findOrFail($response->json('id'));
        $this->assertSame('error_configuracion', $note->fe_estado);
    }

    /** La factura de REEMPLAZO de una Corrección de datos se certifica como factura propia. */
    public function test_correccion_de_datos_certifica_la_nota_y_la_factura_de_reemplazo(): void
    {
        Http::fake(['sandbox.msl.test/*' => Http::response(['ok' => true, 'estado' => 'CERTIFICADO', 'cufe' => 'FE01...reemplazo'], 200)]);
        $ownerId = $this->userIdByEmail(self::CONTABILIDAD_EMAIL);
        ['invoice' => $invoice] = $this->facturaCertificadaConCotizacion($ownerId, 100);
        $token = $this->loginAsContabilidad();
        Storage::fake('s3-private');

        $response = $this->withHeaders($this->authHeaders($token))
            ->post('/api/admin-contab/notas-credito/correccion', [
                'master_client_id' => $invoice->master_client_id,
                'factura_origen_id' => $invoice->id,
                'motivo_correccion' => AdminContCreditNote::MOTIVO_CORRECCION_FECHA,
                'nueva_fecha' => now()->toDateString(),
                'motivo' => 'Fecha de facturación incorrecta',
                'comprobante' => UploadedFile::fake()->create('correccion.pdf', 100, 'application/pdf'),
            ])
            ->assertStatus(201);
        $this->reTenant();

        $note = AdminContCreditNote::findOrFail($response->json('id'));
        $this->assertSame(AdminContCreditNote::ESTADO_APLICADA, $note->estado);
        $this->assertNotNull($note->factura_nueva_id);

        // La nota Y la factura de reemplazo se certifican de forma independiente (2 llamadas a MSL).
        $facturaNueva = AdminContInvoice::findOrFail($note->factura_nueva_id);
        $this->assertSame('certificado', $note->fe_estado);
        $this->assertSame('certificado', $facturaNueva->fe_estado);
        $this->assertSame('FE01...reemplazo', $facturaNueva->cufe);
        Http::assertSentCount(2);
    }

    /**
     * SCRUM-1008 — el caso más común: corregir una factura recién emitida (el MISMO día). Antes la NC
     * de anulación salía con `fechaEmision` a las 00:00 y la DGI la rechazaba por "fecha referenciada"
     * (Pre-QA de SCRUM-958), y el reemplazo se certificaba igual. Recorre el flujo real:
     * registerCorreccion() -> certificación -> request a MSL.
     */
    public function test_correccion_de_datos_el_mismo_dia_manda_la_nc_con_emision_posterior_a_la_factura(): void
    {
        Http::fake(['sandbox.msl.test/*' => Http::response(['ok' => true, 'estado' => 'CERTIFICADO', 'cufe' => 'FE01...mismo-dia'], 200)]);
        Carbon::setTestNow(Carbon::parse('2026-09-30 10:00:00', 'America/Panama'));
        $ownerId = $this->userIdByEmail(self::CONTABILIDAD_EMAIL);
        ['invoice' => $invoice] = $this->facturaCertificadaConCotizacion($ownerId, 100);
        $token = $this->loginAsContabilidad();
        Storage::fake('s3-private');

        Carbon::setTestNow(Carbon::parse('2026-09-30 10:05:00', 'America/Panama'));
        $this->withHeaders($this->authHeaders($token))
            ->post('/api/admin-contab/notas-credito/correccion', [
                'master_client_id' => $invoice->master_client_id,
                'factura_origen_id' => $invoice->id,
                'motivo_correccion' => AdminContCreditNote::MOTIVO_CORRECCION_FECHA,
                'nueva_fecha' => now()->toDateString(),
                'motivo' => 'Fecha de facturación incorrecta',
                'comprobante' => UploadedFile::fake()->create('correccion.pdf', 100, 'application/pdf'),
            ])
            ->assertStatus(201);

        Http::assertSent(function ($request): bool {
            if (! str_ends_with($request->url(), '/v1/notas-credito')) {
                return false;
            }
            $emision = strtotime((string) $request['fechaEmision']);
            $referencia = strtotime((string) $request['referencia']['fecha']);

            return $emision > $referencia
                && $request['fechaEmision'] === '2026-09-30T10:05:00-05:00'
                && ! str_contains((string) $request['fechaEmision'], 'T00:00:00');
        });
        Carbon::setTestNow();
    }

    // ─── SCRUM-1009 — "Corrección de datos": el reemplazo espera a que la NC de anulación certifique ───

    private const RECHAZO_MSL = ['ok' => false, 'estado' => 'RECHAZADO', 'detalles' => '9026 - Falló la validación', 'error' => 'VALIDATE_B07a'];

    private function postCorreccionFecha(string $token, AdminContInvoice $invoice): \Illuminate\Testing\TestResponse
    {
        Storage::fake('s3-private');

        return $this->withHeaders($this->authHeaders($token))->post('/api/admin-contab/notas-credito/correccion', [
            'master_client_id' => $invoice->master_client_id,
            'factura_origen_id' => $invoice->id,
            'motivo_correccion' => AdminContCreditNote::MOTIVO_CORRECCION_FECHA,
            'nueva_fecha' => now()->toDateString(),
            'motivo' => 'Fecha de facturación incorrecta',
            'comprobante' => UploadedFile::fake()->create('correccion.pdf', 100, 'application/pdf'),
        ]);
    }

    /** @return array{note: AdminContCreditNote, nueva: AdminContInvoice, invoice: AdminContInvoice} */
    private function correccionConNcRechazada(int $certificacionesPosteriores = 0): array
    {
        // 1.ª respuesta: la DGI rechaza la NC de anulación; las siguientes (si se piden) certifican.
        $respuestas = Http::sequence()->push(self::RECHAZO_MSL, 422);
        for ($i = 0; $i < $certificacionesPosteriores; $i++) {
            $respuestas->push(['ok' => true, 'estado' => 'CERTIFICADO', 'cufe' => 'FE04...posterior-'.$i], 200);
        }
        Http::fake(['sandbox.msl.test/*' => $respuestas]);
        ['invoice' => $invoice] = $this->facturaCertificadaConCotizacion($this->userIdByEmail(self::CONTABILIDAD_EMAIL), 100);

        $response = $this->postCorreccionFecha($this->loginAsContabilidad(), $invoice)->assertStatus(201);
        $this->reTenant();

        $note = AdminContCreditNote::findOrFail($response->json('id'));

        return ['note' => $note, 'nueva' => AdminContInvoice::findOrFail($note->factura_nueva_id), 'invoice' => $invoice];
    }

    public function test_correccion_con_nc_rechazada_retiene_el_reemplazo_y_no_lo_envia_a_la_dgi(): void
    {
        ['note' => $note, 'nueva' => $nueva] = $this->correccionConNcRechazada();

        $this->assertSame('rechazado', $note->fe_estado);
        $this->assertSame(FiscalCertificationService::ESTADO_EN_ESPERA_NC, $nueva->fe_estado);
        $this->assertNull($nueva->cufe);
        Http::assertSentCount(1); // solo la NC: nunca dos facturas vigentes ante la DGI por el mismo hecho
    }

    public function test_al_certificarse_la_nc_por_reintento_manual_se_libera_y_certifica_el_reemplazo_una_sola_vez(): void
    {
        ['note' => $note, 'nueva' => $nueva] = $this->correccionConNcRechazada(certificacionesPosteriores: 2);

        app(FiscalCertificationService::class)->recertificar($note->fresh(), 'nota_credito');

        $this->assertSame('certificado', $note->fresh()->fe_estado);
        $this->assertSame('certificado', $nueva->fresh()->fe_estado);
        // 1 (NC rechazada inicial) + 1 (reintento de la NC) + 1 (el reemplazo liberado) = 3, nunca 4.
        Http::assertSentCount(3);
    }

    public function test_la_liberacion_del_reemplazo_es_idempotente_ante_dos_caminos_que_certifican_la_nc(): void
    {
        ['note' => $note, 'nueva' => $nueva] = $this->correccionConNcRechazada();
        Event::fake([InvoiceCreated::class]);
        $certificada = new FiscalDocumentResult(estado: 'certificado', pacProvider: 'msl', cufe: 'FE04...nc-doble');
        $servicio = app(FiscalCertificationService::class);

        // Polling y reintento manual llegando a la vez: el compare-and-set deja pasar solo al primero.
        $servicio->persistirResultado($note->fresh(), 'nota_credito', $certificada);
        $servicio->persistirResultado($note->fresh(), 'nota_credito', $certificada);

        Event::assertDispatchedTimes(InvoiceCreated::class, 1);
        $this->assertNotSame(FiscalCertificationService::ESTADO_EN_ESPERA_NC, $nueva->fresh()->fe_estado);
    }

    public function test_correccion_con_fe_apagada_deja_el_reemplazo_no_aplica_sin_retenerlo(): void
    {
        AdminContFiscalSettings::current()->forceFill(['pac_ambiente' => 'apagado'])->save();
        Http::fake();
        ['invoice' => $invoice] = $this->facturaCertificadaConCotizacion($this->userIdByEmail(self::CONTABILIDAD_EMAIL), 100);

        $response = $this->postCorreccionFecha($this->loginAsContabilidad(), $invoice)->assertStatus(201);
        $this->reTenant();

        $note = AdminContCreditNote::findOrFail($response->json('id'));
        $this->assertSame('no_aplica', $note->fe_estado);
        $this->assertSame('no_aplica', AdminContInvoice::findOrFail($note->factura_nueva_id)->fe_estado);
        Http::assertNothingSent();
    }

    public function test_aprobar_una_correccion_pendiente_con_nc_rechazada_tambien_retiene_el_reemplazo(): void
    {
        Http::fake(['sandbox.msl.test/*' => Http::response(self::RECHAZO_MSL, 422)]);
        ['invoice' => $invoice] = $this->facturaCertificadaConCotizacion($this->userIdByEmail(self::CONTABILIDAD_EMAIL), 100);
        $note = AdminContCreditNote::create([
            'numero' => 'NC-'.static::uniqueTestNumber(), 'master_client_id' => $invoice->master_client_id, 'cliente' => $invoice->cliente,
            'tipo' => AdminContCreditNote::TIPO_ANULACION_COMPLETA, 'subtipo_anulacion' => AdminContCreditNote::SUBTIPO_CORRECCION,
            'factura_origen_id' => $invoice->id, 'monto' => 100, 'subtotal' => 93.46, 'itbms' => 6.54, 'motivo' => 'Corrección de ITBMS',
            'estado' => AdminContCreditNote::ESTADO_PENDIENTE_APROBACION, 'motivo_correccion' => AdminContCreditNote::MOTIVO_CORRECCION_FECHA,
            'nueva_fecha_factura' => now()->toDateString(), 'correccion_pendiente_aprobacion' => true,
            'registrado_por' => $this->userIdByEmail(self::CONTABILIDAD_EMAIL), 'fecha' => now()->toDateString(),
            'comprobante_url' => 'admin-contab/credit-notes/test/soporte.pdf',
        ]);

        $this->withHeaders($this->authHeaders($this->loginAsMark()))
            ->putJson("/api/admin-contab/notas-credito/{$note->id}/decision", ['approve' => true])
            ->assertStatus(200);
        $this->reTenant();

        $note = $note->fresh();
        $this->assertSame('rechazado', $note->fe_estado);
        $this->assertSame(FiscalCertificationService::ESTADO_EN_ESPERA_NC, AdminContInvoice::findOrFail($note->factura_nueva_id)->fe_estado);
        Http::assertSentCount(1);
    }

    public function test_no_se_puede_corregir_una_factura_que_ya_tiene_notas_de_credito_aplicadas(): void
    {
        Http::fake(['sandbox.msl.test/*' => Http::response(['ok' => true, 'estado' => 'CERTIFICADO', 'cufe' => 'FE04...nc'], 200)]);
        ['invoice' => $invoice] = $this->facturaCertificadaConCotizacion($this->userIdByEmail(self::CONTABILIDAD_EMAIL), 500);
        $token = $this->loginAsContabilidad();
        $this->withHeaders($this->authHeaders($token))
            ->post('/api/admin-contab/notas-credito', $this->registerPayload($invoice, 100))
            ->assertStatus(201);
        $this->reTenant();
        $enviadosAntes = count(Http::recorded());

        $respuesta = $this->postCorreccionFecha($token, $invoice);
        $respuesta->assertStatus(422);
        $this->assertStringContainsString('ya tiene notas de crédito', (string) $respuesta->json('message'));

        $preview = $this->withHeaders($this->authHeaders($token))->postJson('/api/admin-contab/notas-credito/correccion/preview', [
            'master_client_id' => $invoice->master_client_id, 'factura_origen_id' => $invoice->id,
            'motivo_correccion' => AdminContCreditNote::MOTIVO_CORRECCION_FECHA, 'nueva_fecha' => now()->toDateString(),
            'motivo' => 'Fecha de facturación incorrecta',
        ]);
        $preview->assertStatus(422);

        $this->reTenant();
        $this->assertNull($invoice->fresh()->anulada_at, 'nada se anula si la corrección se bloquea');
        $this->assertCount($enviadosAntes, Http::recorded());
    }

    /** Senior Review B1 — original nunca certificada: no hay nada que anular ante la DGI, el reemplazo se certifica. */
    public function test_correccion_de_una_factura_nunca_certificada_no_retiene_el_reemplazo(): void
    {
        // SCRUM-1019 — antes de corregir una original rechazada se consulta (GET) si MSL ya la tiene: acá NO (404 "no encontrado en la cola").
        Http::fake([
            'sandbox.msl.test/v1/documentos/*' => Http::response(['ok' => false, 'error' => 'Documento no encontrado en la cola'], 404),
            'sandbox.msl.test/*' => Http::response(['ok' => true, 'estado' => 'CERTIFICADO', 'cufe' => 'FE01...reemplazo-sin-original'], 200),
        ]);
        ['invoice' => $invoice] = $this->facturaCertificadaConCotizacion($this->userIdByEmail(self::CONTABILIDAD_EMAIL), 100);
        $invoice->forceFill(['cufe' => null, 'fe_estado' => 'rechazado'])->save();

        $response = $this->postCorreccionFecha($this->loginAsContabilidad(), $invoice)->assertStatus(201);
        $this->reTenant();

        $note = AdminContCreditNote::findOrFail($response->json('id'));
        $this->assertSame('no_aplica', $note->fe_estado, 'la NC de anulación es solo interna: no existe CUFE que referenciar');
        $this->assertSame('certificado', AdminContInvoice::findOrFail($note->factura_nueva_id)->fe_estado);
        Http::assertSentCount(2); // la consulta previa a MSL (GET) + el reemplazo
    }

    public function test_no_se_puede_corregir_una_factura_pendiente_de_certificacion(): void
    {
        Http::fake();
        ['invoice' => $invoice] = $this->facturaCertificadaConCotizacion($this->userIdByEmail(self::CONTABILIDAD_EMAIL), 100);
        $invoice->forceFill(['cufe' => null, 'fe_estado' => 'pendiente'])->save();

        $this->postCorreccionFecha($this->loginAsContabilidad(), $invoice)->assertStatus(422);
        $this->reTenant();

        $this->assertNull($invoice->fresh()->anulada_at);
        Http::assertNothingSent();
    }

    public function test_no_se_puede_corregir_una_factura_certificada_si_las_notas_de_credito_electronicas_estan_deshabilitadas(): void
    {
        AdminContFiscalSettings::current()->forceFill(['pac_doc_factura_habilitado' => true, 'pac_doc_nota_credito_habilitado' => false])->save();
        Http::fake();
        ['invoice' => $invoice] = $this->facturaCertificadaConCotizacion($this->userIdByEmail(self::CONTABILIDAD_EMAIL), 100);

        $this->postCorreccionFecha($this->loginAsContabilidad(), $invoice)->assertStatus(422);
        $this->reTenant();

        $this->assertNull($invoice->fresh()->anulada_at);
        Http::assertNothingSent();
    }

    /** Senior Review S1 — la liberación se puede invocar directo (carrera con el polling) y es idempotente. */
    public function test_liberar_reemplazo_retenido_despacha_una_sola_vez_aunque_se_invoque_dos_veces(): void
    {
        ['note' => $note, 'nueva' => $nueva] = $this->correccionConNcRechazada();
        $note->forceFill(['fe_estado' => 'certificado'])->save();
        Event::fake([InvoiceCreated::class]);
        $servicio = app(FiscalCertificationService::class);

        $servicio->liberarReemplazoRetenido($note->fresh());
        $servicio->liberarReemplazoRetenido($note->fresh());

        Event::assertDispatchedTimes(InvoiceCreated::class, 1);
        $this->assertNotSame(FiscalCertificationService::ESTADO_EN_ESPERA_NC, $nueva->fresh()->fe_estado);
    }

    // ─── SCRUM-1019 ───────────────────────────────────────────────────────────────────────────────

    /** referencia.fecha = fecha de emisión del documento ORIGINAL tal como se le mandó al PAC (fe_emision_at) y referencia.cufe = su CUFE. */
    public function test_la_nc_referencia_la_fecha_de_emision_y_el_cufe_del_documento_original(): void
    {
        Http::fake(['sandbox.msl.test/*' => Http::response(['ok' => true, 'estado' => 'CERTIFICADO', 'cufe' => 'FE04...nc-ref'], 200)]);
        Carbon::setTestNow(Carbon::parse('2026-10-02 12:00:00', 'America/Panama'));
        ['invoice' => $invoice] = $this->facturaCertificadaConCotizacion($this->userIdByEmail(self::CONTABILIDAD_EMAIL), 500);
        // La original se emitió ayer a las 08:15 (fe_emision_at), distinto de su created_at (hoy): la referencia debe usar la emisión.
        $invoice->forceFill(['fe_emision_at' => Carbon::parse('2026-10-01 08:15:00', 'America/Panama')])->save();

        $this->withHeaders($this->authHeaders($this->loginAsContabilidad()))
            ->post('/api/admin-contab/notas-credito', $this->registerPayload($invoice, 100))
            ->assertStatus(201);

        Http::assertSent(function ($request) use ($invoice): bool {
            return str_ends_with($request->url(), '/v1/notas-credito')
                && $request['referencia']['cufe'] === $invoice->cufe
                && $request['referencia']['fecha'] === '2026-10-01T08:15:00-05:00'
                && strtotime((string) $request['fechaEmision']) > strtotime((string) $request['referencia']['fecha']);
        });
        Carbon::setTestNow();
    }

    public function test_nc_sobre_consumidor_final_no_envia_ruc_ni_dv_y_referencia_el_original(): void
    {
        Http::fake(['sandbox.msl.test/*' => Http::response(['ok' => true, 'estado' => 'CERTIFICADO', 'cufe' => 'FE04...nc-cf'], 200)]);
        ['invoice' => $invoice] = $this->facturaCertificadaConCotizacion($this->userIdByEmail(self::CONTABILIDAD_EMAIL), 500);
        $order = Order::findOrFail($invoice->order_id);
        $order->pipelineCard->subClient->forceFill(['receptor_tipo' => '02'])->save();
        $invoice->forceFill(['fe_emision_at' => now()->subHour()])->save();

        $this->withHeaders($this->authHeaders($this->loginAsContabilidad()))
            ->post('/api/admin-contab/notas-credito', $this->registerPayload($invoice, 100))
            ->assertStatus(201);

        Http::assertSent(function ($request) use ($invoice): bool {
            $receptor = $request['receptor'];

            return str_ends_with($request->url(), '/v1/notas-credito')
                && $receptor['tipo'] === '02' && ! array_key_exists('ruc', $receptor) && ! array_key_exists('dv', $receptor)
                && $request['referencia']['cufe'] === $invoice->cufe
                && isset($request['referencia']['fecha']);
        });
    }

    public function test_no_se_corrige_una_original_rechazada_que_msl_ya_tiene_hasta_reconciliarla(): void
    {
        Http::fake(['sandbox.msl.test/v1/documentos/*' => Http::response(['ok' => true, 'estado' => 'CERTIFICADO', 'cufe' => 'FE01...ya-en-msl'], 200)]);
        ['invoice' => $invoice] = $this->facturaCertificadaConCotizacion($this->userIdByEmail(self::CONTABILIDAD_EMAIL), 100);
        $invoice->forceFill(['cufe' => null, 'fe_estado' => 'rechazado'])->save();
        $token = $this->loginAsContabilidad();

        $r = $this->postCorreccionFecha($token, $invoice)->assertStatus(422);
        $this->assertStringContainsString('MSL ya tiene la factura', (string) $r->json('message'));
        $this->assertStringContainsString('Reconciliar con MSL', (string) $r->json('message'));

        $this->withHeaders($this->authHeaders($token))->postJson('/api/admin-contab/notas-credito/correccion/preview', [
            'master_client_id' => $invoice->master_client_id, 'factura_origen_id' => $invoice->id,
            'motivo_correccion' => AdminContCreditNote::MOTIVO_CORRECCION_FECHA, 'nueva_fecha' => now()->toDateString(),
            'motivo' => 'Fecha de facturación incorrecta',
        ])->assertStatus(422);

        $this->reTenant();
        $this->assertNull($invoice->fresh()->anulada_at, 'nada se anula');
        Http::assertNotSent(fn ($request): bool => $request->method() === 'POST');
    }

    public function test_no_se_corrige_una_original_rechazada_si_un_envio_previo_recibio_9022(): void
    {
        Http::fake(['sandbox.msl.test/v1/documentos/*' => Http::response(['ok' => false, 'error' => 'Documento no encontrado en la cola'], 404)]);
        ['invoice' => $invoice] = $this->facturaCertificadaConCotizacion($this->userIdByEmail(self::CONTABILIDAD_EMAIL), 100);
        $invoice->forceFill(['cufe' => null, 'fe_estado' => 'rechazado'])->save();
        \App\Modules\AdminCont\Models\AdminContFeTransmision::create([
            'documento_tipo' => 'factura', 'documento_id' => $invoice->id, 'provider' => 'msl', 'ambiente' => 'pruebas',
            'operacion' => 'certificar', 'origen' => 'certificacion', 'serie' => 'ILU', 'numero_fiscal' => '1',
            'http_status' => 422, 'resultado' => 'rechazado',
            'response_body' => ['ok' => false, 'estado' => 'RECHAZADO', 'detalles' => ['9022 - La referencia INTERNA ya fue enviada a certificar']],
        ]);

        $this->postCorreccionFecha($this->loginAsContabilidad(), $invoice)->assertStatus(422);
    }

    public function test_si_no_se_puede_verificar_con_msl_la_correccion_de_una_original_rechazada_se_bloquea(): void
    {
        Http::fake(['sandbox.msl.test/v1/documentos/*' => Http::response('boom', 503)]);
        ['invoice' => $invoice] = $this->facturaCertificadaConCotizacion($this->userIdByEmail(self::CONTABILIDAD_EMAIL), 100);
        $invoice->forceFill(['cufe' => null, 'fe_estado' => 'error_configuracion'])->save();

        $r = $this->postCorreccionFecha($this->loginAsContabilidad(), $invoice)->assertStatus(422);
        $this->assertStringContainsString('No se pudo verificar con MSL', (string) $r->json('message'));
    }

    /** Menor de 957 — corregir una factura ya anulada da 404 con mensaje en español (no el genérico del framework). */
    public function test_corregir_una_factura_ya_anulada_responde_404_en_espanol(): void
    {
        Http::fake();
        ['invoice' => $invoice] = $this->facturaCertificadaConCotizacion($this->userIdByEmail(self::CONTABILIDAD_EMAIL), 100);
        $invoice->forceFill(['anulada_at' => now()])->save();
        $token = $this->loginAsContabilidad();

        $r = $this->postCorreccionFecha($token, $invoice)->assertStatus(404);
        $this->assertSame('La factura ya fue anulada o no existe: no se puede corregir.', $r->json('message'));

        $this->withHeaders($this->authHeaders($token))->postJson('/api/admin-contab/notas-credito/correccion/preview', [
            'master_client_id' => $invoice->master_client_id, 'factura_origen_id' => $invoice->id,
            'motivo_correccion' => AdminContCreditNote::MOTIVO_CORRECCION_FECHA, 'nueva_fecha' => now()->toDateString(),
            'motivo' => 'Fecha de facturación incorrecta',
        ])->assertStatus(404);
    }

    // ─── SCRUM-1140 (Senior Review M1) — receptor de la factura de reemplazo de una corrección ───

    /** La NC de anulación lleva el receptor de la original certificada; el reemplazo hereda ese mismo receptor congelado. */
    public function test_el_reemplazo_de_una_original_certificada_hereda_su_receptor_congelado(): void
    {
        Http::fake(['sandbox.msl.test/*' => Http::response(['ok' => true, 'estado' => 'CERTIFICADO', 'cufe' => 'FE01...x'], 200)]);
        ['invoice' => $invoice] = $this->facturaCertificadaConCotizacion($this->userIdByEmail(self::CONTABILIDAD_EMAIL), 100);
        $invoice->forceFill([
            'receptor_tipo' => '02', 'receptor_ruc' => null, 'receptor_dv' => null, 'receptor_nombre' => 'CONSUMIDOR FINAL', 'receptor_validado_at' => null,
        ])->save();

        $origen = $invoice->fresh(); // antes del login: el login HTTP del test deja el tenant sin activar
        $response = $this->postCorreccionFecha($this->loginAsContabilidad(), $origen)->assertStatus(201);
        $this->reTenant();

        $nueva = AdminContInvoice::findOrFail(AdminContCreditNote::findOrFail($response->json('id'))->factura_nueva_id);
        $this->assertSame('02', $nueva->receptor_tipo, 'Mismo receptor que la factura original (la DGI rechaza una NC con otro, 9026).');
        $this->assertNull($nueva->receptor_ruc);
        $this->assertSame('CONSUMIDOR FINAL', $nueva->receptor_nombre);
    }

    public function test_el_reemplazo_de_una_original_con_receptor_contribuyente_congelado_conserva_la_razon_social_de_la_dgi(): void
    {
        Http::fake(['sandbox.msl.test/*' => Http::response(['ok' => true, 'estado' => 'CERTIFICADO', 'cufe' => 'FE01...x'], 200)]);
        ['invoice' => $invoice] = $this->facturaCertificadaConCotizacion($this->userIdByEmail(self::CONTABILIDAD_EMAIL), 100);
        $invoice->forceFill([
            'receptor_tipo' => '01', 'receptor_ruc' => '155704849-2-2021', 'receptor_dv' => '32',
            'receptor_nombre' => 'DIGIFACT SERVICIOS, SOCIEDAD ANÓNIMA', 'receptor_validado_at' => now(),
        ])->save();

        $origen = $invoice->fresh(); // antes del login: el login HTTP del test deja el tenant sin activar
        $response = $this->postCorreccionFecha($this->loginAsContabilidad(), $origen)->assertStatus(201);
        $this->reTenant();

        $nueva = AdminContInvoice::findOrFail(AdminContCreditNote::findOrFail($response->json('id'))->factura_nueva_id);
        $this->assertSame('01', $nueva->receptor_tipo);
        $this->assertSame('DIGIFACT SERVICIOS, SOCIEDAD ANÓNIMA', $nueva->receptor_nombre);
        $this->assertSame('32', $nueva->receptor_dv);
    }

    /** Una original que nunca se certificó no tiene nada que igualar: el reemplazo toma el cliente vigente (receptor sin congelar). */
    public function test_el_reemplazo_de_una_original_sin_certificar_no_hereda_el_receptor(): void
    {
        // SCRUM-1019 — antes de corregir una original rechazada se consulta (GET) si MSL ya la tiene: acá NO (404).
        Http::fake([
            'sandbox.msl.test/v1/documentos/*' => Http::response(['ok' => false, 'error' => 'Documento no encontrado en la cola'], 404),
            'sandbox.msl.test/*' => Http::response(['ok' => true, 'estado' => 'CERTIFICADO', 'cufe' => 'FE01...x'], 200),
        ]);
        ['invoice' => $invoice] = $this->facturaCertificadaConCotizacion($this->userIdByEmail(self::CONTABILIDAD_EMAIL), 100);
        $invoice->forceFill([
            'cufe' => null, 'fe_estado' => 'rechazado',
            'receptor_tipo' => '01', 'receptor_ruc' => '999-9-9999', 'receptor_dv' => '11', 'receptor_nombre' => 'RUC MALO', 'receptor_validado_at' => null,
        ])->save();

        $origen = $invoice->fresh(); // antes del login: el login HTTP del test deja el tenant sin activar
        $response = $this->postCorreccionFecha($this->loginAsContabilidad(), $origen)->assertStatus(201);
        $this->reTenant();

        $nueva = AdminContInvoice::findOrFail(AdminContCreditNote::findOrFail($response->json('id'))->factura_nueva_id);
        $this->assertNull($nueva->receptor_tipo, 'Sin receptor congelado: el builder lee el cliente vigente.');
        $this->assertNull($nueva->receptor_ruc);
    }
}
