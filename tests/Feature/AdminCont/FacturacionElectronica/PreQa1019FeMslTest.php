<?php

declare(strict_types=1);

namespace Tests\Feature\AdminCont\FacturacionElectronica;

use App\Modules\AdminCont\FacturacionElectronica\Adapters\Msl\PollMslDocumentEstadoJob;
use App\Modules\AdminCont\FacturacionElectronica\Services\FiscalCertificationService;
use App\Modules\AdminCont\Models\AdminContCreditNote;
use App\Modules\AdminCont\Models\AdminContFeTransmision;
use App\Modules\AdminCont\Models\AdminContFiscalSettings;
use App\Modules\AdminCont\Models\AdminContInvoice;
use App\Modules\Auth\Models\AuditLog;
use App\Shared\Services\AuditService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\AdminCont\Concerns\BuildsPartiallyInvoicedOrders;
use Tests\Feature\AdminCont\Concerns\StubsReceptorGuard;
use App\Shared\Multitenancy\Tenant;
use App\Modules\AdminCont\Models\AdminContItbmsRate;
use App\Modules\Bodega\Models\Order;
use App\Modules\VentasDiseno\Models\MasterClient;
use App\Modules\VentasDiseno\Models\PipelineCard;
use App\Modules\VentasDiseno\Models\Quote;
use App\Modules\VentasDiseno\Models\SalesProject;
use App\Modules\VentasDiseno\Models\SubClient;
use Illuminate\Http\UploadedFile;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-1007 — reintento manual de certificación ("Reintentar certificación") y "Actualizar estado" de un
 * documento pendiente. Solo Gerencia/superadmin. Ver docs/specs/scrum-1007-recertificar-documentos-fe.md.
 */
/**
 * Pre-QA adversarial de SCRUM-1019 (cierre consolidado FE MSL, 2026-10-02): ventana de reenvío con bordes exactos,
 * validación del PUT, idempotencia 200 sin CUFE, reconciliación (permisos/estados/doble clic/libera reemplazo),
 * backfill real de la migración, bloqueo 1009(b), 404 de factura anulada y normalización de RUC/DV. Todo con Http::fake.
 */
final class PreQa1019FeMslTest extends FeatureTestCase
{
    use BuildsPartiallyInvoicedOrders;
    use StubsReceptorGuard;

    private const APPROVER_EMAIL = 'gerencia3@test.com';

    private const RECHAZO = ['ok' => false, 'estado' => 'RECHAZADO', 'detalles' => ['9026 - Falló la validación de algunos escenarios.']];
    private const CERTIFICADO = ['ok' => true, 'estado' => 'CERTIFICADO', 'cufe' => 'FE0120000...reintento', 'qr' => 'https://qr.test'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->reTenant();
        $this->stubReceptorGuard();
        config(['services.msl_fe.sandbox' => ['base_url' => 'https://sandbox.msl.test', 'api_key' => 'sandbox-key']]);
        AdminContFiscalSettings::current()->forceFill(['pac_provider' => 'msl', 'pac_ambiente' => 'testing'])->save();
        Storage::fake('s3-private');
    }

    private function loginAsMark(): string
    {
        return $this->loginAs(self::APPROVER_EMAIL, self::APPROVER_EMAIL, 'management');
    }

    /** La creación de la factura ya dispara la primera certificación (listener): `$respuestas` son las respuestas en orden. */
    private function facturaConRespuestas(array $respuestas): AdminContInvoice
    {
        $secuencia = Http::sequence();
        foreach ($respuestas as [$cuerpo, $status]) {
            $secuencia->push($cuerpo, $status);
        }
        Http::fake(['sandbox.msl.test/v1/facturas' => $secuencia]);

        ['root' => $root] = $this->makePedido([['desc' => 'Panel LED', 'qty' => 9, 'price' => 100.00]], [[9]]);
        $this->facturar($this->token(), [$root->id], 'completo')->assertStatus(201);
        $this->reTenant();

        return AdminContInvoice::forOrder($root)->whereNull('anulada_at')->firstOrFail();
    }

    private function urlFactura(AdminContInvoice $f, string $accion): string
    {
        return "/api/admin-contab/invoices/{$f->order_id}/{$accion}?invoice_id={$f->id}";
    }

    private function servicio(): FiscalCertificationService
    {
        return app(FiscalCertificationService::class);
    }


    private function conAhora(string $iso, \Closure $fn): void
    {
        Carbon::setTestNow(Carbon::parse($iso, 'America/Panama'));
        try { $fn(); } finally { Carbon::setTestNow(); }
    }

    private const T0 = '2026-10-02 09:00:00';

    // ── Ventana: límites exactos ──────────────────────────────────────────
    public function test_preqa_23h59_dentro_de_la_ventana_reenvia(): void
    {
        $this->conAhora(self::T0, function (): void {
            $f = $this->facturaConRespuestas([[self::RECHAZO, 422], [self::CERTIFICADO, 200]]);
            Carbon::setTestNow(Carbon::parse('2026-10-03 08:59:00', 'America/Panama'));
            $this->api($this->loginAsMark(), 'POST', $this->urlFactura($f, 'fe-reintentar'))->assertStatus(200)->assertJsonPath('fe_estado', 'certificado');
        });
    }

    public function test_preqa_24h00_exacto_borde(): void
    {
        $this->conAhora(self::T0, function (): void {
            $f = $this->facturaConRespuestas([[self::RECHAZO, 422], [self::CERTIFICADO, 200]]);
            Carbon::setTestNow(Carbon::parse('2026-10-03 09:00:00', 'America/Panama'));
            $r = $this->api($this->loginAsMark(), 'POST', $this->urlFactura($f, 'fe-reintentar'))->assertStatus(200);
            $r->assertJsonPath('fe_estado', 'certificado'); // borde inclusivo: "más de 24 horas" bloquea, 24 h exactas todavía reenvía
        });
    }

    public function test_preqa_24h01_fuera_no_reenvia(): void
    {
        $this->conAhora(self::T0, function (): void {
            $f = $this->facturaConRespuestas([[self::RECHAZO, 422], [self::CERTIFICADO, 200]]);
            Carbon::setTestNow(Carbon::parse('2026-10-03 09:01:00', 'America/Panama'));
            $this->api($this->loginAsMark(), 'POST', $this->urlFactura($f, 'fe-reintentar'))->assertStatus(200)->assertJsonPath('fe_estado', 'error_configuracion')->assertJsonPath('codigo', 'ventana_vencida');
            Http::assertSentCount(1);
            // reintentar de nuevo sigue sin reenviar (y sin explotar)
            $this->api($this->loginAsMark(), 'POST', $this->urlFactura($f, 'fe-reintentar'))->assertStatus(200)->assertJsonPath('codigo', 'ventana_vencida');
            Http::assertSentCount(1);
            $this->assertSame(Carbon::parse(self::T0, 'America/Panama')->timestamp, $f->fresh()->fe_emision_at->timestamp, 'fe_emision_at intacto');
        });
    }

    public function test_preqa_ventana_1_hora_y_720(): void
    {
        $this->conAhora(self::T0, function (): void {
            AdminContFiscalSettings::current()->forceFill(['fe_reintento_max_horas' => 1])->save();
            $f = $this->facturaConRespuestas([[self::RECHAZO, 422], [self::CERTIFICADO, 200]]);
            Carbon::setTestNow(Carbon::parse('2026-10-02 10:01:00', 'America/Panama'));
            $this->api($this->loginAsMark(), 'POST', $this->urlFactura($f, 'fe-reintentar'))->assertJsonPath('codigo', 'ventana_vencida');
            Carbon::setTestNow(Carbon::parse('2026-10-02 09:59:00', 'America/Panama'));
            $this->api($this->loginAsMark(), 'POST', $this->urlFactura($f, 'fe-reintentar'))->assertJsonPath('fe_estado', 'certificado');
        });
    }

    public function test_preqa_ventana_720_h(): void
    {
        $this->conAhora(self::T0, function (): void {
            AdminContFiscalSettings::current()->forceFill(['fe_reintento_max_horas' => 720])->save();
            $f = $this->facturaConRespuestas([[self::RECHAZO, 422], [self::CERTIFICADO, 200]]);
            Carbon::setTestNow(Carbon::parse('2026-10-02 09:00:00', 'America/Panama')->addHours(719));
            $this->api($this->loginAsMark(), 'POST', $this->urlFactura($f, 'fe-reintentar'))->assertJsonPath('fe_estado', 'certificado');
        });
    }

    public function test_preqa_ventana_cambiada_a_la_baja_aplica_a_docs_existentes(): void
    {
        $this->conAhora(self::T0, function (): void {
            $f = $this->facturaConRespuestas([[self::RECHAZO, 422], [self::CERTIFICADO, 200]]);
            AdminContFiscalSettings::current()->forceFill(['fe_reintento_max_horas' => 2])->save();
            Carbon::setTestNow(Carbon::parse('2026-10-02 12:00:00', 'America/Panama'));
            $this->api($this->loginAsMark(), 'POST', $this->urlFactura($f, 'fe-reintentar'))->assertJsonPath('codigo', 'ventana_vencida');
        });
    }

    // ── PUT fiscal-settings: validación y forma ───────────────────────────
    public function test_preqa_put_fiscal_settings_valores(): void
    {
        $approver = $this->loginAsMark();
        $esperado = [[null, 422], [0, 422], [-1, 422], [1, 200], [720, 200], [721, 422], [24.5, 422], ['24', 200], ['abc', 422], ['', 422], [[], 422]];
        foreach ($esperado as [$v, $status]) {
            $this->api($approver, 'PUT', '/api/admin-contab/fiscal-settings', ['fe_reintento_max_horas' => $v])->assertStatus($status);
        }
        $this->api($approver, 'GET', '/api/admin-contab/fiscal-settings')->assertStatus(200)->assertJsonStructure(['fe_reintento_max_horas']);
    }

    public function test_preqa_put_sin_el_campo_no_lo_toca_y_contabilidad_no_puede(): void
    {
        $approver = $this->loginAsMark();
        $this->api($approver, 'PUT', '/api/admin-contab/fiscal-settings', ['fe_reintento_max_horas' => 48])->assertStatus(200);
        $this->api($approver, 'PUT', '/api/admin-contab/fiscal-settings', ['dias_credito_factura' => 30])->assertStatus(200)->assertJsonPath('fe_reintento_max_horas', 48);
        $this->api($this->token(), 'PUT', '/api/admin-contab/fiscal-settings', ['fe_reintento_max_horas' => 1])->assertStatus(403);
        $this->api($this->token(), 'GET', '/api/admin-contab/fiscal-settings')->assertStatus(403);
    }

    // ── 200 ya certificado: variantes ─────────────────────────────────────
    public function test_preqa_200_ya_certificado_sin_cufe_no_es_certificado(): void
    {
        $f = $this->facturaConRespuestas([[['ok' => true, 'mensaje' => 'Documento ya certificado previamente'], 200]]);
        $this->assertSame('error_configuracion', $f->fresh()->fe_estado);
        $this->assertNull($f->fresh()->cufe);
    }

    public function test_preqa_200_ya_certificado_cufe_vacio_o_espacios(): void
    {
        $f = $this->facturaConRespuestas([[['ok' => true, 'mensaje' => 'Documento ya certificado previamente', 'cufe' => '   '], 200]]);
        $this->assertNotSame('certificado', $f->fresh()->fe_estado);
        $this->assertNull($f->fresh()->cufe);
    }

    public function test_preqa_200_con_cufe_pero_texto_distinto_no_certifica(): void
    {
        $f = $this->facturaConRespuestas([[['ok' => true, 'mensaje' => 'otra cosa', 'cufe' => 'X-1'], 200]]);
        $this->assertSame('error_configuracion', $f->fresh()->fe_estado); // falla cerrado si MSL cambia la frase
        $this->assertNull($f->fresh()->cufe);
    }

    public function test_preqa_200_ya_certificado_en_message_ingles_clave(): void
    {
        $f = $this->facturaConRespuestas([[['ok' => true, 'message' => 'DOCUMENTO YA CERTIFICADO PREVIAMENTE', 'cufe' => 'CUFE-ORIG-1', 'qr' => 'q'], 200]]);
        $this->assertSame('certificado', $f->fresh()->fe_estado);
        $this->assertSame('CUFE-ORIG-1', $f->fresh()->cufe);
    }

    public function test_preqa_422_con_texto_ya_certificado_no_es_exito(): void
    {
        $f = $this->facturaConRespuestas([[['ok' => false, 'mensaje' => 'Documento ya certificado previamente', 'cufe' => 'X', 'estado' => 'RECHAZADO'], 422]]);
        $this->assertNotSame('certificado', $f->fresh()->fe_estado);
        $this->assertNull($f->fresh()->cufe);
    }

    // ── Reconciliar ───────────────────────────────────────────────────────
    public function test_preqa_reconciliar_permisos_y_forma(): void
    {
        $f = $this->facturaConRespuestas([[self::RECHAZO, 422]]);
        $url = $this->urlFactura($f, 'fe-reconciliar');
        $this->flushHeaders();
        $this->postJson($url)->assertStatus(401);
        $this->api($this->loginAs('designer@atlantic.test', 'Password123!', 'designer'), 'POST', $url)->assertStatus(403);
        $this->api($this->loginAs('liderbodega@test.com', 'liderbodega@test.com', 'lider_bodega'), 'POST', $url)->assertStatus(403);
        $this->api($this->token(), 'POST', $url)->assertStatus(403);
        Http::assertSentCount(1);
        Http::fake(['sandbox.msl.test/v1/documentos/*' => Http::response(self::CERTIFICADO, 200)]);
        $r = $this->api($this->loginAs('superadmin@atlantic.test', 'Password123!', 'superadmin'), 'POST', $url)->assertStatus(200);
        foreach (['accion', 'fe_estado', 'fe_detalle', 'cufe', 'mensaje', 'codigo'] as $k) { $this->assertArrayHasKey($k, $r->json(), $k); }
    }

    public function test_preqa_reconciliar_doble_clic_el_segundo_es_409_y_un_solo_cufe(): void
    {
        $f = $this->facturaConRespuestas([[self::RECHAZO, 422]]);
        Http::fake(['sandbox.msl.test/v1/documentos/*' => Http::response(self::CERTIFICADO, 200)]);
        $m = $this->loginAsMark();
        $this->api($m, 'POST', $this->urlFactura($f, 'fe-reconciliar'))->assertStatus(200);
        $this->api($m, 'POST', $this->urlFactura($f, 'fe-reconciliar'))->assertStatus(409)->assertJsonPath('codigo', 'estado_no_reconciliable')->assertJsonStructure(['message', 'codigo']);
        $this->assertSame(1, AuditLog::query()->where('action', AuditService::ADMIN_CONT_FE_DOCUMENTO_RECONCILIADO)->count());
    }

    public function test_preqa_reconciliar_lock_tomado_es_409_en_curso(): void
    {
        $f = $this->facturaConRespuestas([[self::RECHAZO, 422]]);
        $lock = Cache::lock("fe-recertificar:atlantic:factura:{$f->id}", 90);
        $this->assertTrue($lock->get());
        try {
            $this->api($this->loginAsMark(), 'POST', $this->urlFactura($f, 'fe-reconciliar'))->assertStatus(409)->assertJsonPath('codigo', 'en_curso');
        } finally { $lock->release(); }
    }

    public function test_preqa_reconciliar_estados_no_reconciliables_409(): void
    {
        $f = $this->facturaConRespuestas([[self::RECHAZO, 422]]);
        $m = $this->loginAsMark();
        foreach (['no_aplica', 'en_espera_nc', 'certificado', null] as $e) {
            $this->reTenant();
            $f->forceFill(['fe_estado' => $e])->save();
            $r = $this->api($m, 'POST', $this->urlFactura($f, 'fe-reconciliar'));
            $this->assertSame(409, $r->status(), 'estado '.json_encode($e).' => '.$r->status().' '.$r->getContent());
        }
        $this->reTenant();
        $f->forceFill(['fe_estado' => 'pendiente'])->save();
        Http::fake(['sandbox.msl.test/v1/documentos/*' => Http::response(['ok' => true, 'estado' => 'PENDIENTE'], 202)]);
        $this->api($m, 'POST', $this->urlFactura($f, 'fe-reconciliar'))->assertStatus(200)->assertJsonPath('accion', 'sigue_en_cola');
    }

    public function test_preqa_reconciliar_anulada_fe_apagada_y_404(): void
    {
        $f = $this->facturaConRespuestas([[self::RECHAZO, 422]]);
        $m = $this->loginAsMark();
        $this->api($m, 'POST', '/api/admin-contab/invoices/999999/fe-reconciliar')->assertStatus(404);
        $this->api($m, 'POST', '/api/admin-contab/notas-credito/999999/fe-reconciliar')->assertStatus(404);
        $this->api($m, 'POST', "/api/admin-contab/invoices/{$f->order_id}/fe-reconciliar?invoice_id=abc")->assertStatus(422);
        AdminContFiscalSettings::current()->forceFill(['pac_ambiente' => 'apagado'])->save();
        $this->api($m, 'POST', $this->urlFactura($f, 'fe-reconciliar'))->assertStatus(409)->assertJsonPath('codigo', 'fe_apagada');
        AdminContFiscalSettings::current()->forceFill(['pac_ambiente' => 'testing'])->save();
        $f->forceFill(['anulada_at' => now()])->save();
        $this->api($m, 'POST', $this->urlFactura($f, 'fe-reconciliar'))->assertStatus(409)->assertJsonPath('codigo', 'documento_anulado');
        $d = $this->api($m, 'GET', "/api/admin-contab/invoices/{$f->order_id}/detail?invoice_id={$f->id}")->assertStatus(200);
        $this->assertFalse($d->json('puede_reconciliar_fe'));
    }

    public function test_preqa_reconciliar_con_msl_caido_o_401_no_reenvia(): void
    {
        $f = $this->facturaConRespuestas([[self::RECHAZO, 422]]);
        $m = $this->loginAsMark();
        Http::fake(['sandbox.msl.test/v1/documentos/*' => Http::response(['ok' => false, 'error' => 'API key inválida'], 401)]);
        $r = $this->api($m, 'POST', $this->urlFactura($f, 'fe-reconciliar'))->assertStatus(200);
        $this->assertSame('sin_cambios', $r->json('accion'));
        Http::assertNotSent(fn ($q) => $q->method() === 'POST');
        Http::fake(['sandbox.msl.test/v1/documentos/*' => Http::response('boom', 500)]);
        $r = $this->api($m, 'POST', $this->urlFactura($f, 'fe-reconciliar'))->assertStatus(200);
        $this->assertSame('sin_cambios', $r->json('accion'));
        Http::assertNotSent(fn ($q) => $q->method() === 'POST');
        $this->assertSame('rechazado', $f->fresh()->fe_estado);
        // 404 con cuerpo distinto (base_url mal) NO reenvía
        Http::fake(['sandbox.msl.test/v1/documentos/*' => Http::response('<html>nope</html>', 404)]);
        $this->api($m, 'POST', $this->urlFactura($f, 'fe-reconciliar'))->assertStatus(200)->assertJsonPath('accion', 'sin_cambios');
        Http::assertNotSent(fn ($q) => $q->method() === 'POST');
    }

    public function test_preqa_reconciliar_ventana_vencida_msl_no_lo_tiene_no_reenvia(): void
    {
        $this->conAhora(self::T0, function (): void {
            $f = $this->facturaConRespuestas([[self::RECHAZO, 422]]);
            Carbon::setTestNow(Carbon::parse('2026-10-09 09:00:00', 'America/Panama'));
            Http::fake(['sandbox.msl.test/v1/documentos/*' => Http::response(self::NO_EN_COLA_P, 404)]);
            $r = $this->api($this->loginAsMark(), 'POST', $this->urlFactura($f, 'fe-reconciliar'))->assertStatus(200);
            Http::assertNotSent(fn ($q) => $q->method() === 'POST');
            $this->assertSame('error_configuracion', $f->fresh()->fe_estado);
            $this->assertSame(1, AuditLog::query()->where('action', AuditService::ADMIN_CONT_FE_DOCUMENTO_RECONCILIADO)->count());
        });
    }
    private const NO_EN_COLA_P = ['ok' => false, 'error' => 'Documento no encontrado en la cola'];

    // ── Migración: backfill real, up/down ─────────────────────────────────
    public function test_preqa_migracion_backfill_solo_estados_enviados_y_reversible(): void
    {
        $base = $this->facturaConRespuestas([[self::RECHAZO, 422]]);
        $mig = require base_path('database/migrations/tenant/2026_10_02_100000_add_fe_emision_at_and_ventana_reintento.php');
        $estados = ['certificado', 'pendiente', 'rechazado', 'error_configuracion', 'no_aplica', 'en_espera_nc', null];
        $ids = [];
        foreach ($estados as $i => $e) {
            $c = $base->replicate();
            $c->numero = 'PQ-'.$i.'-'.self::uniqueTestNumber();
            $c->fe_estado = $e;
            $c->anulada_at = now();
            $c->save();
            $ids[$i] = $c->id;
        }
        $mig->down();
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn((new AdminContInvoice)->getTable(), 'fe_emision_at'));
        $cr = '2026-03-04 05:06:07+00';
        \Illuminate\Support\Facades\DB::table((new AdminContInvoice)->getTable())->whereIn('id', $ids)->update(['created_at' => $cr]);
        $mig->up();
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasColumn((new AdminContInvoice)->getTable(), 'fe_emision_at'));
        $rows = \Illuminate\Support\Facades\DB::table((new AdminContInvoice)->getTable())->whereIn('id', $ids)->get()->keyBy('id');
        foreach ($estados as $i => $e) {
            $r = $rows[$ids[$i]];
            if (in_array($e, ['certificado', 'pendiente', 'rechazado', 'error_configuracion'], true)) {
                $this->assertNotNull($r->fe_emision_at, "estado $e debe backfillear");
                $this->assertSame(strtotime($r->created_at), strtotime($r->fe_emision_at), "estado $e: fe_emision_at = created_at");
            } else {
                $this->assertNull($r->fe_emision_at, 'estado '.json_encode($e).' NO debe backfillear');
            }
        }
        $this->assertSame(24, (int) AdminContFiscalSettings::current()->fe_reintento_max_horas);
        // ciclo down/up otra vez
        $mig->down(); $mig->up();
        $this->assertSame(24, (int) AdminContFiscalSettings::current()->fresh()->fe_reintento_max_horas);
    }

    public function test_preqa_validar_ruc_vacio_no_consulta_y_dv_vacio_se_normaliza(): void
    {
        Http::fake(['sandbox.msl.test/*' => Http::response(['valido' => true, 'nombre' => 'ACME', 'dv' => '', 'tipoRuc' => 'J', 'estado' => 'ACTIVO'], 200)]);
        $svc = app(\App\Modules\AdminCont\FacturacionElectronica\Services\TaxIdValidationService::class);
        foreach (['', '   ', "\t\n"] as $v) {
            $r = $svc->validar($v);
            $this->assertFalse($r['disponible'], json_encode($v));
        }
        Http::assertNothingSent();
        $r = $svc->validar('155704849-2-2021');
        $this->assertTrue($r['disponible']);
        $this->assertNull($r['dv']);
        Http::fake(['sandbox.msl.test/*' => Http::response(['valido' => true, 'nombre' => 'ACME', 'dv' => '  ', 'tipoRuc' => 'J', 'estado' => 'ACTIVO'], 200)]);
        $this->assertNull($svc->validar('155704849-2-2021')['dv']);
    }


    private const CONTABILIDAD_EMAIL = 'contabilidad@test.com';

    private const RECHAZO_MSL = ['ok' => false, 'estado' => 'RECHAZADO', 'detalles' => '9026 - Falló la validación', 'error' => 'VALIDATE_B07a'];

    private function userIdByEmail(string $email): int
    {
        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');

        return (int) \Illuminate\Support\Facades\DB::table("{$slug}_auth.users")->where('email', $email)->value('id');
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

    private function loginAsFelix(): string
    {
        return $this->loginAs(self::CONTABILIDAD_EMAIL, self::CONTABILIDAD_EMAIL, 'lider_admin_contab');
    }

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

        $response = $this->postCorreccionFecha($this->loginAsFelix(), $invoice)->assertStatus(201);
        $this->reTenant();

        $note = AdminContCreditNote::findOrFail($response->json('id'));

        return ['note' => $note, 'nueva' => AdminContInvoice::findOrFail($note->factura_nueva_id), 'invoice' => $invoice];
    }

    // ═══ PRE-QA SCRUM-1019 ═══
    public function test_preqa_reconciliar_nc_de_anulacion_libera_y_certifica_el_reemplazo_una_vez(): void
    {
        // NC rechazada inicial; luego GET (consulta) => certificada y POST del reemplazo => certificado.
        ['note' => $note, 'nueva' => $nueva] = $this->correccionConNcRechazada(certificacionesPosteriores: 2);
        $this->assertSame('en_espera_nc', $nueva->fe_estado);
        $r = $this->withHeaders($this->authHeaders($this->loginAsMark()))->postJson("/api/admin-contab/notas-credito/{$note->id}/fe-reconciliar")->assertStatus(200);
        $this->reTenant();
        $this->assertSame('certificado', $note->fresh()->fe_estado);
        $this->assertSame('certificado', $nueva->fresh()->fe_estado, 'el reemplazo en_espera_nc se libera');
        $posts = collect(Http::recorded())->filter(fn ($p) => $p[0]->method() === 'POST')->count();
        $gets  = collect(Http::recorded())->filter(fn ($p) => $p[0]->method() === 'GET')->count();
        $this->assertSame(2, $posts, 'POST NC rechazada + POST reemplazo');
        $this->assertSame(1, $gets);
        // segundo clic: ya no reconciliable
        $this->withHeaders($this->authHeaders($this->loginAsMark()))->postJson("/api/admin-contab/notas-credito/{$note->id}/fe-reconciliar")->assertStatus(409)->assertJsonPath('codigo', 'estado_no_reconciliable');
        // detalle: puede_reconciliar_fe false para Gerencia tras certificar y ausente/false para Contabilidad
        $d = $this->withHeaders($this->authHeaders($this->loginAsMark()))->getJson("/api/admin-contab/notas-credito/{$note->id}")->assertStatus(200);
        $this->assertFalse($d->json('puede_reconciliar_fe'));
    }

    public function test_preqa_detalle_nc_puede_reconciliar_solo_mark(): void
    {
        ['note' => $note] = $this->correccionConNcRechazada();
        $dm = $this->withHeaders($this->authHeaders($this->loginAsMark()))->getJson("/api/admin-contab/notas-credito/{$note->id}")->assertStatus(200);
        $this->assertTrue($dm->json('puede_reconciliar_fe'));
        $df = $this->withHeaders($this->authHeaders($this->loginAsFelix()))->getJson("/api/admin-contab/notas-credito/{$note->id}");
        $this->assertNotTrue($df->json('puede_reconciliar_fe'));
        $this->withHeaders($this->authHeaders($this->loginAsFelix()))->postJson("/api/admin-contab/notas-credito/{$note->id}/fe-reconciliar")->assertStatus(403);
    }

    public function test_preqa_1009b_msl_la_tiene_pendiente_bloquea_en_registro_y_preview(): void
    {
        Http::fake(['sandbox.msl.test/v1/documentos/*' => Http::response(['ok' => true, 'estado' => 'PENDIENTE'], 202)]);
        ['invoice' => $invoice] = $this->facturaCertificadaConCotizacion($this->userIdByEmail(self::CONTABILIDAD_EMAIL), 100);
        $invoice->forceFill(['cufe' => null, 'fe_estado' => 'error_configuracion'])->save();
        $token = $this->loginAsFelix();
        $this->postCorreccionFecha($token, $invoice)->assertStatus(422);
        $p = $this->withHeaders($this->authHeaders($token))->postJson('/api/admin-contab/notas-credito/correccion/preview', [
            'master_client_id' => $invoice->master_client_id, 'factura_origen_id' => $invoice->id,
            'motivo_correccion' => AdminContCreditNote::MOTIVO_CORRECCION_FECHA, 'nueva_fecha' => now()->toDateString(), 'motivo' => 'Fecha de facturación incorrecta',
        ]);
        $p->assertStatus(422);
        $this->reTenant();
        $this->assertNull($invoice->fresh()->anulada_at);
        Http::assertNotSent(fn ($q) => $q->method() === 'POST');
    }

    public function test_preqa_1009b_msl_500_y_timeout_no_se_puede_verificar_422(): void
    {
        Http::fake(['sandbox.msl.test/v1/documentos/*' => Http::response('boom', 500)]);
        ['invoice' => $invoice] = $this->facturaCertificadaConCotizacion($this->userIdByEmail(self::CONTABILIDAD_EMAIL), 100);
        $invoice->forceFill(['cufe' => null, 'fe_estado' => 'rechazado'])->save();
        $r = $this->postCorreccionFecha($this->loginAsFelix(), $invoice);
        $r->assertStatus(422);
        Http::fake(['sandbox.msl.test/v1/documentos/*' => fn () => throw new \Illuminate\Http\Client\ConnectionException('timeout')]);
        $r2 = $this->postCorreccionFecha($this->loginAsFelix(), $invoice);
        $r2->assertStatus(422);
        $this->reTenant();
        $this->assertNull($invoice->fresh()->anulada_at);
    }

    public function test_preqa_1009b_original_error_config_sin_serie_msl_no_puede_tenerla_pasa(): void
    {
        Http::fake(['sandbox.msl.test/*' => Http::response(['ok' => true, 'estado' => 'CERTIFICADO', 'cufe' => 'FE01...x'], 200)]);
        ['invoice' => $invoice] = $this->facturaCertificadaConCotizacion($this->userIdByEmail(self::CONTABILIDAD_EMAIL), 100);
        $invoice->forceFill(['cufe' => null, 'fe_estado' => 'error_configuracion', 'serie' => null])->save();
        $r = $this->postCorreccionFecha($this->loginAsFelix(), $invoice);
        $this->assertContains($r->status(), [201, 422]);
    }

    public function test_preqa_404_anulada_en_preview_y_registro(): void
    {
        Http::fake();
        ['invoice' => $invoice] = $this->facturaCertificadaConCotizacion($this->userIdByEmail(self::CONTABILIDAD_EMAIL), 100);
        $invoice->forceFill(['anulada_at' => now()])->save();
        $t = $this->loginAsFelix();
        $payload = ['master_client_id' => $invoice->master_client_id, 'factura_origen_id' => $invoice->id,
            'motivo_correccion' => AdminContCreditNote::MOTIVO_CORRECCION_FECHA, 'nueva_fecha' => now()->toDateString(), 'motivo' => 'Fecha de facturación incorrecta'];
        $p = $this->withHeaders($this->authHeaders($t))->postJson('/api/admin-contab/notas-credito/correccion/preview', $payload)->assertStatus(404);
        $this->assertSame('La factura ya fue anulada o no existe: no se puede corregir.', $p->json('message'));
        $this->postCorreccionFecha($t, $invoice)->assertStatus(404)->assertJsonPath('message', 'La factura ya fue anulada o no existe: no se puede corregir.');
        $payload['factura_origen_id'] = 99999999;
        $this->withHeaders($this->authHeaders($t))->postJson('/api/admin-contab/notas-credito/correccion/preview', $payload)->assertStatus(422); // id inexistente: validación exists (preexistente)
    }

    public function test_preqa_nc_normal_sobre_original_con_fe_emision_distinta_referencia_correcta_y_nc_posterior_a_original(): void
    {
        Http::fake(['sandbox.msl.test/*' => Http::response(['ok' => true, 'estado' => 'CERTIFICADO', 'cufe' => 'FE04...nc'], 200)]);
        ['invoice' => $invoice] = $this->facturaCertificadaConCotizacion($this->userIdByEmail(self::CONTABILIDAD_EMAIL), 500);
        $emision = Carbon::parse('2026-10-01 08:15:00', 'America/Panama');
        $invoice->forceFill(['cufe' => 'CUFE-ORIGINAL-XYZ', 'fe_estado' => 'certificado', 'fe_emision_at' => $emision])->save();
        $this->withHeaders($this->authHeaders($this->loginAsFelix()))->post('/api/admin-contab/notas-credito', $this->registerPayload($invoice, 100))->assertStatus(201);
        Http::assertSent(function ($q) use ($emision): bool {
            $d = $q->data();
            if (! isset($d['referencia'])) { return false; }
            return $d['referencia']['cufe'] === 'CUFE-ORIGINAL-XYZ' && Carbon::parse($d['referencia']['fecha'])->equalTo($emision)
                && Carbon::parse($d['fechaEmision'])->gt(Carbon::parse($d['referencia']['fecha']));
        });
    }

    /** Payloads REALES que construye el FE (commit 1ee54a7, capturados con vitest): valor precargado 24 y editado 48. */
    public function test_preqa_put_con_el_payload_real_del_fe_devuelve_200(): void
    {
        $base = ['razon_social' => 'Atlantic S.A.', 'nombre_comercial' => 'Atlantic', 'ruc' => '1-2-3', 'dv' => '10',
            'direccion_fiscal' => 'Panamá', 'regimen_tributario' => 'general', 'pac_ambiente' => 'testing',
            'pac_doc_factura_habilitado' => true, 'pac_doc_nota_credito_habilitado' => false,
            'retencion_proveedores_activa' => true, 'dias_credito_factura' => 30, 'petty_cash_max_intentos_rechazo' => 2];
        $approver = $this->loginAsMark();
        foreach ([24, 48] as $horas) {
            $this->api($approver, 'PUT', '/api/admin-contab/fiscal-settings', $base + ['fe_reintento_max_horas' => $horas])
                ->assertStatus(200)->assertJsonPath('fe_reintento_max_horas', $horas);
            $this->api($approver, 'GET', '/api/admin-contab/fiscal-settings')->assertStatus(200)->assertJsonPath('fe_reintento_max_horas', $horas);
        }
    }
}
