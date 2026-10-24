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
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-1007 — reintento manual de certificación ("Reintentar certificación") y "Actualizar estado" de un
 * documento pendiente. Solo Mark/superadmin. Ver docs/specs/scrum-1007-recertificar-documentos-fe.md.
 */
final class FeReintentoTest extends FeatureTestCase
{
    use BuildsPartiallyInvoicedOrders;
    use StubsReceptorGuard;

    private const MARK_EMAIL = 'gerencia3@test.com';

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
        return $this->loginAs(self::MARK_EMAIL, self::MARK_EMAIL, 'management');
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

    // ── Reintentar (rechazado / error_configuracion) ─────────────────────────────────────────

    public function test_reintentar_factura_rechazada_la_certifica_y_deja_transmision_y_auditoria(): void
    {
        $factura = $this->facturaConRespuestas([[self::RECHAZO, 422], [self::CERTIFICADO, 200]]);
        $this->assertSame('rechazado', $factura->fresh()->fe_estado);

        $r = $this->api($this->loginAsMark(), 'POST', $this->urlFactura($factura, 'fe-reintentar'))->assertStatus(200);

        $r->assertJsonPath('accion', 'reenviado');
        $r->assertJsonPath('fe_estado', 'certificado');
        $r->assertJsonPath('cufe', 'FE0120000...reintento');
        $r->assertJsonPath('fe_detalle', null);
        $this->assertSame('certificado', $factura->fresh()->fe_estado);

        $this->assertSame(2, AdminContFeTransmision::query()->count());
        $segunda = AdminContFeTransmision::query()->orderByDesc('id')->firstOrFail();
        $this->assertSame('reintento_manual', $segunda->origen);
        $this->assertSame('certificar', $segunda->operacion);
        $this->assertSame(200, $segunda->http_status);

        $auditoria = AuditLog::query()->where('action', AuditService::ADMIN_CONT_FE_DOCUMENTO_RECERTIFICADO)->firstOrFail();
        $this->assertSame((string) $factura->id, (string) $auditoria->entity_id);
        $this->assertSame('rechazado', $auditoria->old_values['fe_estado']);
        $this->assertSame('certificado', $auditoria->new_values['fe_estado']);
    }

    public function test_reintentar_un_documento_con_error_de_configuracion(): void
    {
        $factura = $this->facturaConRespuestas([[['ok' => false, 'error' => 'API key inválida'], 401], [self::CERTIFICADO, 200]]);
        $this->assertSame('error_configuracion', $factura->fresh()->fe_estado);

        $this->api($this->loginAsMark(), 'POST', $this->urlFactura($factura, 'fe-reintentar'))
            ->assertStatus(200)
            ->assertJsonPath('fe_estado', 'certificado');
    }

    public function test_un_rechazo_que_persiste_devuelve_200_con_el_motivo_nuevo(): void
    {
        $nuevo = ['ok' => false, 'estado' => 'RECHAZADO', 'detalles' => ['9032 - El identificador tributario es incorrecto']];
        $factura = $this->facturaConRespuestas([[self::RECHAZO, 422], [$nuevo, 422]]);

        $r = $this->api($this->loginAsMark(), 'POST', $this->urlFactura($factura, 'fe-reintentar'))->assertStatus(200);

        $r->assertJsonPath('fe_estado', 'rechazado');
        $this->assertStringContainsString('9032', (string) $r->json('fe_detalle'));
        $this->assertStringContainsString('9032', (string) $factura->fresh()->fe_detalle);
    }

    public function test_no_se_reintenta_un_documento_certificado_ni_se_llama_a_msl(): void
    {
        $factura = $this->facturaConRespuestas([[self::CERTIFICADO, 200]]);
        $this->assertSame('certificado', $factura->fresh()->fe_estado);

        $this->api($this->loginAsMark(), 'POST', $this->urlFactura($factura, 'fe-reintentar'))
            ->assertStatus(409)
            ->assertJsonPath('codigo', 'estado_no_reintentable');

        Http::assertSentCount(1); // solo la certificación original
    }

    public function test_con_la_fe_apagada_el_reintento_no_llama_a_msl_ni_toca_el_estado(): void
    {
        $factura = $this->facturaConRespuestas([[self::RECHAZO, 422]]);
        $detalleAntes = $factura->fresh()->fe_detalle;
        AdminContFiscalSettings::current()->forceFill(['pac_ambiente' => 'apagado'])->save();

        $this->api($this->loginAsMark(), 'POST', $this->urlFactura($factura, 'fe-reintentar'))
            ->assertStatus(409)
            ->assertJsonPath('codigo', 'fe_apagada');

        $fresca = $factura->fresh();
        $this->assertSame('rechazado', $fresca->fe_estado); // NO se sobrescribe a no_aplica
        $this->assertSame($detalleAntes, $fresca->fe_detalle);
        Http::assertSentCount(1);
    }

    public function test_no_se_reintenta_una_factura_anulada(): void
    {
        $factura = $this->facturaConRespuestas([[self::RECHAZO, 422]]);
        $factura->forceFill(['anulada_at' => now()])->save();

        $this->api($this->loginAsMark(), 'POST', $this->urlFactura($factura, 'fe-reintentar'))
            ->assertStatus(409)
            ->assertJsonPath('codigo', 'documento_anulado');
    }

    public function test_solo_mark_puede_reintentar_y_sin_token_es_401(): void
    {
        $factura = $this->facturaConRespuestas([[self::RECHAZO, 422]]);

        // Sin token primero: `withHeaders()` persiste entre llamadas del mismo test.
        $this->flushHeaders();
        $this->postJson($this->urlFactura($factura, 'fe-reintentar'))->assertStatus(401);
        $this->api($this->token(), 'POST', $this->urlFactura($factura, 'fe-reintentar'))->assertStatus(403); // Felix
        $this->api($this->token(), 'POST', $this->urlFactura($factura, 'fe-actualizar-estado'))->assertStatus(403);
        Http::assertSentCount(1);
    }

    public function test_un_lock_ya_tomado_responde_409_en_curso_sin_llamar_a_msl(): void
    {
        $factura = $this->facturaConRespuestas([[self::RECHAZO, 422]]);
        $lock = Cache::lock("fe-recertificar:atlantic:factura:{$factura->id}", 90);
        $this->assertTrue($lock->get());

        try {
            $this->api($this->loginAsMark(), 'POST', $this->urlFactura($factura, 'fe-reintentar'))
                ->assertStatus(409)
                ->assertJsonPath('codigo', 'en_curso');
            Http::assertSentCount(1);
        } finally {
            $lock->release();
        }
    }

    public function test_un_documento_inexistente_es_404(): void
    {
        $this->api($this->loginAsMark(), 'POST', '/api/admin-contab/notas-credito/999999/fe-reintentar')->assertStatus(404);
        $this->api($this->loginAsMark(), 'POST', '/api/admin-contab/invoices/999999/fe-reintentar')->assertStatus(404);
    }

    // ── Notas de crédito ─────────────────────────────────────────────────────────────────────

    private function notaRechazada(): AdminContCreditNote
    {
        $factura = $this->facturaConRespuestas([[self::CERTIFICADO, 200]]);
        $nota = AdminContCreditNote::create([
            'numero' => 'NC-RT-'.self::uniqueTestNumber(), 'master_client_id' => $factura->master_client_id ?? 1,
            'cliente' => $factura->cliente, 'tipo' => AdminContCreditNote::TIPO_DESCUENTO_COMERCIAL,
            'factura_origen_id' => $factura->id, 'monto' => 100, 'subtotal' => 93.46, 'itbms' => 6.54,
            'motivo' => 'Test reintento', 'estado' => AdminContCreditNote::ESTADO_APLICADA,
            'registrado_por' => $this->ownerId(), 'fecha' => now()->toDateString(),
        ]);
        Http::fake(['sandbox.msl.test/v1/notas-credito' => Http::sequence()
            ->push(self::RECHAZO, 422)
            ->push(['ok' => true, 'estado' => 'CERTIFICADO', 'cufe' => 'FE0420000...nc-reintento'], 200)]);
        $this->servicio()->certificarNotaCredito($nota);

        return $nota->fresh();
    }

    public function test_reintentar_una_nota_de_credito_rechazada_la_certifica(): void
    {
        $nota = $this->notaRechazada();
        $this->assertSame('rechazado', $nota->fe_estado);

        $this->api($this->loginAsMark(), 'POST', "/api/admin-contab/notas-credito/{$nota->id}/fe-reintentar")
            ->assertStatus(200)
            ->assertJsonPath('fe_estado', 'certificado')
            ->assertJsonPath('cufe', 'FE0420000...nc-reintento');

        $ultima = AdminContFeTransmision::query()->where('documento_tipo', 'nota_credito')->orderByDesc('id')->firstOrFail();
        $this->assertSame('reintento_manual', $ultima->origen);
        $this->assertSame('/v1/notas-credito', $ultima->endpoint);
    }

    public function test_no_se_reintenta_una_nc_que_no_esta_aplicada(): void
    {
        $nota = $this->notaRechazada();
        $nota->forceFill(['estado' => AdminContCreditNote::ESTADO_PENDIENTE_APROBACION])->save();

        $this->api($this->loginAsMark(), 'POST', "/api/admin-contab/notas-credito/{$nota->id}/fe-reintentar")
            ->assertStatus(409)
            ->assertJsonPath('codigo', 'nota_no_aplicada');
    }

    // ── Actualizar estado (pendiente) ────────────────────────────────────────────────────────

    /** @param list<array{0: array<string, mixed>, 1: int}> $siguientes respuestas que MSL dará a los POST posteriores al 202 inicial */
    private function facturaPendiente(array $siguientes = []): AdminContInvoice
    {
        Queue::fake(); // el 202 agenda el polling; con cola síncrona correría en el acto
        $factura = $this->facturaConRespuestas([[['ok' => true, 'estado' => 'PENDIENTE'], 202], ...$siguientes]);
        $this->assertSame('pendiente', $factura->fresh()->fe_estado);

        return $factura->fresh();
    }

    private function fakeConsulta(AdminContInvoice $factura, mixed $respuesta): void
    {
        $numero = app(\App\Modules\AdminCont\FacturacionElectronica\Services\FiscalDocumentBuilder::class)->numeroFiscal($factura->numero);
        Http::fake(["sandbox.msl.test/v1/documentos/ILU/{$numero}" => $respuesta]);
    }

    public function test_actualizar_estado_resuelve_un_pendiente_que_msl_ya_certifico(): void
    {
        $factura = $this->facturaPendiente();
        $this->fakeConsulta($factura, Http::response(['ok' => true, 'estado' => 'CERTIFICADO', 'cufe' => 'FE01...cola', 'qr' => 'https://qr.test'], 200));

        $r = $this->api($this->loginAsMark(), 'POST', $this->urlFactura($factura, 'fe-actualizar-estado'))->assertStatus(200);

        $r->assertJsonPath('accion', 'resuelto');
        $r->assertJsonPath('fe_estado', 'certificado');
        $this->assertSame('FE01...cola', $factura->fresh()->cufe);

        $ultima = AdminContFeTransmision::query()->orderByDesc('id')->firstOrFail();
        $this->assertSame('consulta_manual', $ultima->origen);
        $this->assertSame('consultar_estado', $ultima->operacion);
        $this->assertNull($ultima->request_payload);
        $this->assertNotNull(AuditLog::query()->where('action', AuditService::ADMIN_CONT_FE_DOCUMENTO_ESTADO_ACTUALIZADO)->first());
    }

    public function test_actualizar_estado_que_sigue_en_cola_no_persiste_ni_encadena_otro_polling(): void
    {
        $factura = $this->facturaPendiente();
        $this->fakeConsulta($factura, Http::response(['ok' => true, 'estado' => 'PENDIENTE'], 202));

        $this->api($this->loginAsMark(), 'POST', $this->urlFactura($factura, 'fe-actualizar-estado'))
            ->assertStatus(200)
            ->assertJsonPath('accion', 'sigue_en_cola')
            ->assertJsonPath('fe_estado', 'pendiente');

        // No se encadena otro polling por cada clic: solo queda el de la certificación original.
        Queue::assertPushed(PollMslDocumentEstadoJob::class, 1);
    }

    public function test_actualizar_estado_con_404_de_la_cola_reenvia_el_documento(): void
    {
        $factura = $this->facturaPendiente([[self::CERTIFICADO, 200]]); // el POST del reenvío
        $this->fakeConsulta($factura, Http::response(['ok' => false, 'error' => 'Documento no encontrado en la cola'], 404));

        $r = $this->api($this->loginAsMark(), 'POST', $this->urlFactura($factura, 'fe-actualizar-estado'))->assertStatus(200);

        $r->assertJsonPath('accion', 'reenviado');
        $r->assertJsonPath('fe_estado', 'certificado');
        $origenes = AdminContFeTransmision::query()->orderBy('id')->pluck('origen')->all();
        $this->assertSame(['certificacion', 'consulta_manual', 'reintento_manual'], $origenes);
    }

    public function test_un_404_seguido_de_9022_avisa_que_ya_estaba_certificado_sin_duplicar(): void
    {
        $rechazo9022 = ['ok' => false, 'estado' => 'RECHAZADO', 'detalles' => ['9022 - La referencia INTERNA ya ha sido enviada a certificar']];
        $factura = $this->facturaPendiente([[$rechazo9022, 422]]);
        $this->fakeConsulta($factura, Http::response(['ok' => false, 'error' => 'Documento no encontrado en la cola'], 404));

        $r = $this->api($this->loginAsMark(), 'POST', $this->urlFactura($factura, 'fe-actualizar-estado'))->assertStatus(200);

        $r->assertJsonPath('accion', 'reenviado');
        $r->assertJsonPath('codigo', 'ya_certificado_9022');
        $r->assertJsonPath('fe_estado', 'pendiente'); // 9022 NO es un rechazo: el estado fiscal real no se toca
        $this->assertStringContainsString('9022', (string) $r->json('mensaje'));
        $this->assertStringContainsString('contacta a MSL', (string) $r->json('mensaje'));

        $fresca = $factura->fresh();
        $this->assertSame('pendiente', $fresca->fe_estado);
        $this->assertNull($fresca->cufe);
        $this->assertNull($fresca->fe_detalle);
        $this->assertSame('reintento_manual', AdminContFeTransmision::query()->orderByDesc('id')->firstOrFail()->origen); // la transmisión sí queda registrada
    }

    public function test_actualizar_estado_con_msl_caido_no_cambia_nada_ni_reenvia(): void
    {
        $factura = $this->facturaPendiente();
        Redis::set('cb:msl_fe_pruebas:opened_at', (string) time());

        try {
            Http::fake();
            $this->api($this->loginAsMark(), 'POST', $this->urlFactura($factura, 'fe-actualizar-estado'))
                ->assertStatus(200)
                ->assertJsonPath('accion', 'sin_cambios')
                ->assertJsonPath('fe_estado', 'pendiente');
            Http::assertNothingSent();
        } finally {
            Redis::del('cb:msl_fe_pruebas:opened_at');
            Redis::del('cb:msl_fe_pruebas:failures');
        }
    }

    public function test_actualizar_estado_solo_aplica_a_documentos_pendientes(): void
    {
        $factura = $this->facturaConRespuestas([[self::RECHAZO, 422]]);

        $this->api($this->loginAsMark(), 'POST', $this->urlFactura($factura, 'fe-actualizar-estado'))
            ->assertStatus(409)
            ->assertJsonPath('codigo', 'estado_no_actualizable');
    }

    // ── Indicador accion_fe en el detalle ────────────────────────────────────────────────────

    public function test_el_detalle_ofrece_la_accion_solo_a_mark_y_segun_el_estado(): void
    {
        $factura = $this->facturaConRespuestas([[self::RECHAZO, 422]]);
        $detalle = "/api/admin-contab/invoices/{$factura->order_id}/detail?invoice_id={$factura->id}";

        $this->assertSame('reintentar', $this->api($this->loginAsMark(), 'GET', $detalle)->json('accion_fe'));
        $this->assertNull($this->api($this->token(), 'GET', $detalle)->json('accion_fe')); // Felix

        $factura->forceFill(['fe_estado' => 'pendiente'])->save();
        $this->assertSame('actualizar_estado', $this->api($this->loginAsMark(), 'GET', $detalle)->json('accion_fe'));

        $factura->forceFill(['fe_estado' => 'certificado'])->save();
        $this->assertNull($this->api($this->loginAsMark(), 'GET', $detalle)->json('accion_fe'));

        $factura->forceFill(['fe_estado' => 'rechazado'])->save();
        AdminContFiscalSettings::current()->forceFill(['pac_ambiente' => 'apagado'])->save();
        $this->assertNull($this->api($this->loginAsMark(), 'GET', $detalle)->json('accion_fe')); // FE apagada
    }

    public function test_el_detalle_de_una_nc_ofrece_la_accion_a_mark(): void
    {
        $nota = $this->notaRechazada();

        $this->assertSame('reintentar', $this->api($this->loginAsMark(), 'GET', "/api/admin-contab/notas-credito/{$nota->id}")->json('accion_fe'));
        $this->assertNull($this->api($this->token(), 'GET', "/api/admin-contab/notas-credito/{$nota->id}")->json('accion_fe'));
    }

    // ── Correcciones de la Senior Review ─────────────────────────────────────────────────────

    public function test_un_9022_como_400_sin_estado_tampoco_cambia_un_rechazado(): void
    {
        $factura = $this->facturaConRespuestas([
            [self::RECHAZO, 422],
            [['ok' => false, 'error' => '9022 - La referencia INTERNA ya ha sido enviada a certificar'], 400],
        ]);
        $detalleAntes = $factura->fresh()->fe_detalle;

        $r = $this->api($this->loginAsMark(), 'POST', $this->urlFactura($factura, 'fe-reintentar'))->assertStatus(200);

        $r->assertJsonPath('codigo', 'ya_certificado_9022');
        $fresca = $factura->fresh();
        $this->assertSame('rechazado', $fresca->fe_estado);
        $this->assertSame($detalleAntes, $fresca->fe_detalle); // no se sobrescribe el motivo con el 9022
    }

    public function test_una_falla_de_transporte_en_el_reintento_no_cambia_el_estado_ni_agenda_polling(): void
    {
        Queue::fake();
        $factura = $this->facturaConRespuestas([[self::RECHAZO, 422]]);
        Redis::set('cb:msl_fe_pruebas:opened_at', (string) time());

        try {
            $this->api($this->loginAsMark(), 'POST', $this->urlFactura($factura, 'fe-reintentar'))
                ->assertStatus(200)
                ->assertJsonPath('accion', 'sin_cambios')
                ->assertJsonPath('fe_estado', 'rechazado');

            $this->assertSame('rechazado', $factura->fresh()->fe_estado); // no pasó a pendiente
            Queue::assertNothingPushed();
        } finally {
            Redis::del('cb:msl_fe_pruebas:opened_at');
            Redis::del('cb:msl_fe_pruebas:failures');
        }
    }

    public function test_un_404_sin_el_cuerpo_de_la_cola_no_se_trata_como_no_esta_en_cola(): void
    {
        $factura = $this->facturaPendiente([[self::CERTIFICADO, 200]]); // si reenviara, este POST certificaría
        $this->fakeConsulta($factura, Http::response(['ok' => false, 'error' => 'Not Found'], 404)); // p. ej. base_url mal configurada

        $this->api($this->loginAsMark(), 'POST', $this->urlFactura($factura, 'fe-actualizar-estado'))
            ->assertStatus(200)
            ->assertJsonPath('accion', 'sin_cambios')
            ->assertJsonPath('fe_estado', 'pendiente');

        $this->assertSame(['certificacion', 'consulta_manual'], AdminContFeTransmision::query()->orderBy('id')->pluck('origen')->all()); // sin POST de reenvío
    }

    public function test_una_consulta_con_401_no_reenvia(): void
    {
        $factura = $this->facturaPendiente([[self::CERTIFICADO, 200]]);
        $this->fakeConsulta($factura, Http::response(['ok' => false, 'error' => 'API key inválida'], 401));

        $this->api($this->loginAsMark(), 'POST', $this->urlFactura($factura, 'fe-actualizar-estado'))
            ->assertStatus(200)
            ->assertJsonPath('accion', 'sin_cambios');

        $this->assertSame('pendiente', $factura->fresh()->fe_estado);
        $this->assertSame(2, AdminContFeTransmision::query()->count()); // certificación + consulta, sin reenvío
    }

    public function test_una_consulta_que_responde_rechazado_se_persiste_como_rechazado(): void
    {
        $factura = $this->facturaPendiente();
        $this->fakeConsulta($factura, Http::response(['ok' => false, 'estado' => 'RECHAZADO', 'detalles' => ['9032 - El identificador tributario es incorrecto']], 422));

        $r = $this->api($this->loginAsMark(), 'POST', $this->urlFactura($factura, 'fe-actualizar-estado'))->assertStatus(200);

        $r->assertJsonPath('accion', 'resuelto');
        $r->assertJsonPath('fe_estado', 'rechazado');
        $this->assertStringContainsString('9032', (string) $factura->fresh()->fe_detalle);
    }

    public function test_no_se_reintenta_tras_cambiar_de_ambiente(): void
    {
        $factura = $this->facturaConRespuestas([[self::RECHAZO, 422]]); // última transmisión: ambiente 'pruebas'
        AdminContFiscalSettings::current()->forceFill(['pac_ambiente' => 'production'])->save();

        $this->api($this->loginAsMark(), 'POST', $this->urlFactura($factura, 'fe-reintentar'))
            ->assertStatus(409)
            ->assertJsonPath('codigo', 'ambiente_distinto');

        Http::assertSentCount(1); // un documento del sandbox no se convierte en una emisión real
        $this->assertNull($this->api($this->loginAsMark(), 'GET', "/api/admin-contab/invoices/{$factura->order_id}/detail?invoice_id={$factura->id}")->json('accion_fe'));
    }

    public function test_el_limitador_es_por_usuario_y_responde_429_a_la_septima_accion(): void
    {
        $factura = $this->facturaConRespuestas([[self::RECHAZO, 422]]);
        AdminContFiscalSettings::current()->forceFill(['pac_ambiente' => 'apagado'])->save(); // 409 baratos: solo cuentan como intentos
        $token = $this->loginAsMark();

        for ($i = 1; $i <= 6; $i++) {
            $this->api($token, 'POST', $this->urlFactura($factura, 'fe-reintentar'))->assertStatus(409);
        }
        $this->api($token, 'POST', $this->urlFactura($factura, 'fe-reintentar'))->assertStatus(429);
    }

    public function test_el_cupo_es_por_usuario_no_por_ip_otro_usuario_no_gasta_el_de_mark(): void
    {
        $factura = $this->facturaConRespuestas([[self::RECHAZO, 422]]);
        AdminContFiscalSettings::current()->forceFill(['pac_ambiente' => 'apagado'])->save();
        $url = $this->urlFactura($factura, 'fe-reintentar');
        $felix = $this->token();

        // Felix (misma IP de los tests) agota SU cupo: 6 respuestas 403 y luego 429.
        for ($i = 1; $i <= 6; $i++) {
            $this->api($felix, 'POST', $url)->assertStatus(403);
        }
        $this->api($felix, 'POST', $url)->assertStatus(429);

        // Mark, desde la misma IP, conserva el suyo: recibe la respuesta normal (409 por FE apagada), no un 429.
        $this->api($this->loginAsMark(), 'POST', $url)->assertStatus(409)->assertJsonPath('codigo', 'fe_apagada');
    }

    public function test_un_numero_9022_dentro_del_motivo_no_se_confunde_con_el_codigo_9022(): void
    {
        $motivo = ['ok' => false, 'estado' => 'RECHAZADO', 'detalles' => ['9032 - RUC 8-9022-33 inválido, monto 9022.50']];
        $factura = $this->facturaConRespuestas([[self::RECHAZO, 422], [$motivo, 422]]);

        $r = $this->api($this->loginAsMark(), 'POST', $this->urlFactura($factura, 'fe-reintentar'))->assertStatus(200);

        $r->assertJsonPath('codigo', null); // NO es un 9022 real
        $this->assertStringContainsString('8-9022-33', (string) $factura->fresh()->fe_detalle); // el rechazo nuevo sí se guardó
    }

    public function test_un_modelo_en_memoria_desactualizado_se_relee_bajo_el_lock(): void
    {
        $factura = $this->facturaConRespuestas([[self::RECHAZO, 422]]);
        $obsoleta = AdminContInvoice::findOrFail($factura->id); // en memoria: rechazado
        AdminContInvoice::query()->whereKey($factura->id)->update(['fe_estado' => 'certificado']); // otro usuario la resolvió

        try {
            $this->servicio()->recertificar($obsoleta, 'factura');
            $this->fail('debió rechazar por estado no reintentable');
        } catch (\App\Modules\AdminCont\FacturacionElectronica\Exceptions\RecertificacionNoPermitidaException $e) {
            $this->assertSame('estado_no_reintentable', $e->codigo);
        }
        Http::assertSentCount(1);
    }

    public function test_un_fallo_de_ensamblado_deja_error_de_configuracion_y_responde_200(): void
    {
        $nota = $this->notaRechazada();
        $nota->facturaOrigen->forceFill(['cufe' => null])->save(); // la referencia ya no existe: el builder lanza

        $r = $this->api($this->loginAsMark(), 'POST', "/api/admin-contab/notas-credito/{$nota->id}/fe-reintentar")->assertStatus(200);

        $r->assertJsonPath('fe_estado', 'error_configuracion');
        $this->assertNotEmpty($nota->fresh()->fe_detalle);
    }

    public function test_una_nc_pendiente_con_404_de_la_cola_se_reenvia_con_su_serie(): void
    {
        $factura = $this->facturaConRespuestas([[self::CERTIFICADO, 200]]);
        $nota = AdminContCreditNote::create([
            'numero' => 'NC-PD-'.self::uniqueTestNumber(), 'master_client_id' => $factura->master_client_id ?? 1,
            'cliente' => $factura->cliente, 'tipo' => AdminContCreditNote::TIPO_DESCUENTO_COMERCIAL,
            'factura_origen_id' => $factura->id, 'monto' => 100, 'subtotal' => 93.46, 'itbms' => 6.54,
            'motivo' => 'Test pendiente', 'estado' => AdminContCreditNote::ESTADO_APLICADA,
            'registrado_por' => $this->ownerId(), 'fecha' => now()->toDateString(),
        ]);
        Queue::fake();
        Http::fake([
            'sandbox.msl.test/v1/notas-credito' => Http::sequence()
                ->push(['ok' => true, 'estado' => 'PENDIENTE'], 202)
                ->push(['ok' => true, 'estado' => 'CERTIFICADO', 'cufe' => 'FE0420000...nc-cola'], 200),
            'sandbox.msl.test/v1/documentos/*' => Http::response(['ok' => false, 'error' => 'Documento no encontrado en la cola'], 404),
        ]);
        $this->servicio()->certificarNotaCredito($nota);
        $this->assertSame('pendiente', $nota->fresh()->fe_estado);

        $this->api($this->loginAsMark(), 'POST', "/api/admin-contab/notas-credito/{$nota->id}/fe-actualizar-estado")
            ->assertStatus(200)
            ->assertJsonPath('accion', 'reenviado')
            ->assertJsonPath('fe_estado', 'certificado');

        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/v1/documentos/NC-ILU/'));
    }

    public function test_accion_fe_es_null_para_una_factura_anulada_y_para_una_nc_no_aplicada(): void
    {
        $factura = $this->facturaConRespuestas([[self::RECHAZO, 422]]);
        $factura->forceFill(['anulada_at' => now()])->save();
        $this->assertNull($this->api($this->loginAsMark(), 'GET', "/api/admin-contab/invoices/{$factura->order_id}/detail?invoice_id={$factura->id}")->json('accion_fe'));

        $nota = $this->notaRechazada();
        $nota->forceFill(['estado' => AdminContCreditNote::ESTADO_PENDIENTE_APROBACION])->save();
        $this->assertNull($this->api($this->loginAsMark(), 'GET', "/api/admin-contab/notas-credito/{$nota->id}")->json('accion_fe'));
    }

    // ── SCRUM-1019 — idempotencia 200, reconciliación y fe_emision_at (ventana de reenvío) ──────────

    private const YA_CERTIFICADO = ['ok' => true, 'mensaje' => 'Documento ya certificado previamente', 'cufe' => 'FE0120000...original-msl', 'qr' => 'https://qr.test/original', 'xmlFirmado' => 'PHhtbC8+'];
    private const NO_EN_COLA = ['ok' => false, 'error' => 'Documento no encontrado en la cola'];

    public function test_reintentar_y_recibir_200_ya_certificado_previamente_persiste_el_cufe_qr_y_xml_originales(): void
    {
        $factura = $this->facturaConRespuestas([[self::RECHAZO, 422], [self::YA_CERTIFICADO, 200]]);

        $this->api($this->loginAsMark(), 'POST', $this->urlFactura($factura, 'fe-reintentar'))
            ->assertStatus(200)
            ->assertJsonPath('fe_estado', 'certificado')
            ->assertJsonPath('cufe', 'FE0120000...original-msl');

        $f = $factura->fresh();
        $this->assertSame('https://qr.test/original', $f->qr_content);
        $this->assertNotNull($f->xml_firmado_path);
        Storage::disk('s3-private')->assertExists($f->xml_firmado_path);
        $this->assertNull($f->fe_detalle);
    }

    public function test_reconciliar_factura_que_msl_ya_tiene_en_su_cola_recupera_el_cufe_sin_reenviar(): void
    {
        $factura = $this->facturaConRespuestas([[self::RECHAZO, 422]]);
        Http::fake(['sandbox.msl.test/v1/documentos/*' => Http::response(self::CERTIFICADO, 200)]);

        $this->api($this->loginAsMark(), 'POST', $this->urlFactura($factura, 'fe-reconciliar'))
            ->assertStatus(200)
            ->assertJsonPath('accion', 'resuelto')
            ->assertJsonPath('fe_estado', 'certificado')
            ->assertJsonPath('cufe', 'FE0120000...reintento');

        $this->assertSame('certificado', $factura->fresh()->fe_estado);
        Http::assertSentCount(1); // solo el GET (el segundo fake reinicia el registro); nunca un segundo POST
        Http::assertSent(fn ($request): bool => $request->method() === 'GET' && str_contains($request->url(), '/v1/documentos/'));
        $this->assertSame('consulta_manual', AdminContFeTransmision::query()->orderByDesc('id')->firstOrFail()->origen);
        $auditoria = AuditLog::query()->where('action', AuditService::ADMIN_CONT_FE_DOCUMENTO_RECONCILIADO)->firstOrFail();
        $this->assertSame('rechazado', $auditoria->old_values['fe_estado']);
        $this->assertSame('certificado', $auditoria->new_values['fe_estado']);
    }

    public function test_reconciliar_cuando_msl_no_lo_tiene_en_cola_reenvia_la_misma_serie_y_numero_y_recupera_el_cufe(): void
    {
        $factura = $this->facturaConRespuestas([[self::RECHAZO, 422], [self::YA_CERTIFICADO, 200]]);
        Http::fake(['sandbox.msl.test/v1/documentos/*' => Http::response(self::NO_EN_COLA, 404)]);

        $this->api($this->loginAsMark(), 'POST', $this->urlFactura($factura, 'fe-reconciliar'))
            ->assertStatus(200)
            ->assertJsonPath('fe_estado', 'certificado')
            ->assertJsonPath('cufe', 'FE0120000...original-msl');

        $ultima = AdminContFeTransmision::query()->orderByDesc('id')->firstOrFail();
        $this->assertSame('reconciliacion', $ultima->origen);
        $this->assertSame('certificar', $ultima->operacion);
    }

    public function test_reconciliar_con_9022_no_cambia_el_estado(): void
    {
        $nueve = ['ok' => false, 'estado' => 'RECHAZADO', 'detalles' => ['9022 - La referencia INTERNA ya fue enviada a certificar']];
        $factura = $this->facturaConRespuestas([[self::RECHAZO, 422], [$nueve, 422]]);
        Http::fake(['sandbox.msl.test/v1/documentos/*' => Http::response(self::NO_EN_COLA, 404)]);

        $this->api($this->loginAsMark(), 'POST', $this->urlFactura($factura, 'fe-reconciliar'))
            ->assertStatus(200)
            ->assertJsonPath('codigo', 'ya_certificado_9022')
            ->assertJsonPath('fe_estado', 'rechazado');

        $this->assertSame('rechazado', $factura->fresh()->fe_estado);
        $this->assertNull($factura->fresh()->cufe);
    }

    public function test_reconciliar_exige_mark_y_un_estado_reconciliable(): void
    {
        $factura = $this->facturaConRespuestas([[self::CERTIFICADO, 200]]);

        $this->api($this->token(), 'POST', $this->urlFactura($factura, 'fe-reconciliar'))->assertStatus(403); // Felix
        $this->api($this->loginAsMark(), 'POST', $this->urlFactura($factura, 'fe-reconciliar'))
            ->assertStatus(409)
            ->assertJsonPath('codigo', 'estado_no_reconciliable');
        Http::assertSentCount(1);
    }

    public function test_reconciliar_la_nc_la_certifica_con_el_cufe_de_msl(): void
    {
        $nota = $this->notaRechazada();
        $this->assertSame('rechazado', $nota->fe_estado);
        Http::fake(['sandbox.msl.test/v1/documentos/*' => Http::response(['ok' => true, 'estado' => 'CERTIFICADO', 'cufe' => 'FE0420000...nc-msl'], 200)]);

        $this->api($this->loginAsMark(), 'POST', "/api/admin-contab/notas-credito/{$nota->id}/fe-reconciliar")
            ->assertStatus(200)
            ->assertJsonPath('fe_estado', 'certificado')
            ->assertJsonPath('cufe', 'FE0420000...nc-msl');
    }

    public function test_el_detalle_ofrece_reconciliar_solo_a_mark_y_solo_si_el_estado_lo_admite(): void
    {
        $factura = $this->facturaConRespuestas([[self::RECHAZO, 422]]);
        $url = "/api/admin-contab/invoices/{$factura->order_id}/detail?invoice_id={$factura->id}";

        $this->assertTrue($this->api($this->loginAsMark(), 'GET', $url)->json('puede_reconciliar_fe'));
        $this->assertNotTrue($this->api($this->token(), 'GET', $url)->json('puede_reconciliar_fe'));

        $factura->forceFill(['fe_estado' => 'certificado'])->save();
        $this->assertFalse($this->api($this->loginAsMark(), 'GET', $url)->json('puede_reconciliar_fe'));
    }

    public function test_fe_emision_at_se_fija_en_el_primer_intento_y_los_reintentos_reutilizan_la_misma_fecha_de_emision(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-02 09:00:00', 'America/Panama'));
        $factura = $this->facturaConRespuestas([[self::RECHAZO, 422], [self::CERTIFICADO, 200]]);
        $fijada = $factura->fresh()->fe_emision_at;
        $this->assertNotNull($fijada);

        Carbon::setTestNow(Carbon::parse('2026-10-02 15:30:00', 'America/Panama')); // 6 h después, dentro de la ventana
        $this->api($this->loginAsMark(), 'POST', $this->urlFactura($factura, 'fe-reintentar'))->assertStatus(200)->assertJsonPath('fe_estado', 'certificado');

        $this->assertTrue($fijada->equalTo($factura->fresh()->fe_emision_at), 'la fecha de emisión nunca se regenera');
        $fechas = collect(Http::recorded())->map(fn ($par): string => (string) $par[0]['fechaEmision'])->unique();
        $this->assertCount(1, $fechas, 'ambos intentos mandaron la misma fechaEmision');
        Carbon::setTestNow();
    }

    public function test_un_reintento_pasadas_24_h_no_se_reenvia_y_queda_en_error_configuracion_con_mensaje_claro(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-02 09:00:00', 'America/Panama'));
        $factura = $this->facturaConRespuestas([[self::RECHAZO, 422], [self::CERTIFICADO, 200]]);
        $this->assertSame(24, AdminContFiscalSettings::current()->fe_reintento_max_horas);

        Carbon::setTestNow(Carbon::parse('2026-10-03 09:30:00', 'America/Panama')); // 24 h 30 min
        $r = $this->api($this->loginAsMark(), 'POST', $this->urlFactura($factura, 'fe-reintentar'))->assertStatus(200);

        $r->assertJsonPath('fe_estado', 'error_configuracion')->assertJsonPath('codigo', 'ventana_vencida')->assertJsonPath('accion', 'sin_cambios');
        $this->assertStringContainsString('24 horas', (string) $r->json('mensaje'));
        $this->assertStringContainsString('Reconciliar con MSL', (string) $factura->fresh()->fe_detalle);
        Http::assertSentCount(1); // nada se reenvió
        Carbon::setTestNow();
    }

    public function test_la_ventana_de_reenvio_es_un_parametro_de_configuracion_fiscal_no_una_constante(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-02 09:00:00', 'America/Panama'));
        $factura = $this->facturaConRespuestas([[self::RECHAZO, 422], [self::CERTIFICADO, 200]]);
        $this->api($this->loginAsMark(), 'PUT', '/api/admin-contab/fiscal-settings', ['fe_reintento_max_horas' => 72])->assertStatus(200)->assertJsonPath('fe_reintento_max_horas', 72);

        Carbon::setTestNow(Carbon::parse('2026-10-03 21:00:00', 'America/Panama')); // 36 h
        $this->api($this->loginAsMark(), 'POST', $this->urlFactura($factura, 'fe-reintentar'))->assertStatus(200)->assertJsonPath('fe_estado', 'certificado');
        Carbon::setTestNow();
    }

    public function test_reconciliar_sigue_disponible_tras_vencer_la_ventana_para_recuperar_un_documento_que_msl_ya_tiene(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-02 09:00:00', 'America/Panama'));
        $factura = $this->facturaConRespuestas([[self::RECHAZO, 422]]);
        Carbon::setTestNow(Carbon::parse('2026-10-05 09:00:00', 'America/Panama'));
        Http::fake(['sandbox.msl.test/v1/documentos/*' => Http::response(self::CERTIFICADO, 200)]);

        $this->api($this->loginAsMark(), 'POST', $this->urlFactura($factura, 'fe-reconciliar'))->assertStatus(200)->assertJsonPath('fe_estado', 'certificado');
        Carbon::setTestNow();
    }

    // ── SCRUM-1140 (A1) — el reintento envía el receptor corregido, no la copia vieja ───────────

    public function test_reintentar_una_factura_rechazada_envia_el_receptor_ya_corregido_del_cliente(): void
    {
        $factura = $this->facturaConRespuestas([[self::RECHAZO, 422], [self::CERTIFICADO, 200]]);
        $this->assertSame('rechazado', $factura->fresh()->fe_estado);
        $congeladoInicial = $factura->fresh()->receptor_ruc;

        // Quien factura corrige el RUC/DV/razón social del cliente DESPUÉS del rechazo.
        $sub = \App\Modules\Bodega\Models\Order::find($factura->family_root_id)?->pipelineCard?->subClient;
        $this->assertNotNull($sub);
        $sub->update(['tax_id' => '155704849-2-2021', 'tax_id_dv' => '32', 'business_name' => 'DIGIFACT SERVICIOS, SOCIEDAD ANÓNIMA']);

        $this->api($this->loginAsMark(), 'POST', $this->urlFactura($factura, 'fe-reintentar'))->assertStatus(200)->assertJsonPath('fe_estado', 'certificado');

        $fresco = $factura->fresh();
        $this->assertNotSame($congeladoInicial, $fresco->receptor_ruc);
        $this->assertSame('155704849-2-2021', $fresco->receptor_ruc);
        $this->assertSame('32', $fresco->receptor_dv);
        $this->assertSame('DIGIFACT SERVICIOS, SOCIEDAD ANÓNIMA', $fresco->receptor_nombre);
        $segunda = AdminContFeTransmision::query()->orderByDesc('id')->firstOrFail();
        $this->assertStringContainsString('155704849-2-2021', json_encode($segunda->request_payload, JSON_UNESCAPED_UNICODE));
    }

    public function test_reintentar_no_cambia_un_consumidor_final_decidido_por_quien_factura(): void
    {
        $factura = $this->facturaConRespuestas([[self::RECHAZO, 422], [self::CERTIFICADO, 200]]);
        $factura->forceFill(['receptor_tipo' => '02', 'receptor_ruc' => null, 'receptor_dv' => null, 'receptor_nombre' => 'CONSUMIDOR FINAL', 'receptor_validado_at' => null])->save();

        $this->api($this->loginAsMark(), 'POST', $this->urlFactura($factura, 'fe-reintentar'))->assertStatus(200);

        $this->assertSame('02', $factura->fresh()->receptor_tipo, 'El cliente tiene RUC, pero la decisión de esa factura fue Consumidor Final.');
        $this->assertNull($factura->fresh()->receptor_ruc);
    }

    // ── SCRUM-1140 — reglas de reemplazo del receptor congelado (Senior Review: cobertura) ──────

    private function refrescar(AdminContInvoice $factura): void
    {
        $metodo = new \ReflectionMethod(FiscalCertificationService::class, 'refrescarReceptorCongelado');
        $metodo->invoke($this->servicio(), $factura);
    }

    private function conReceptorViejoYClienteCorregido(AdminContInvoice $factura): void
    {
        $factura->forceFill(['receptor_tipo' => '01', 'receptor_ruc' => '999-9-9999', 'receptor_dv' => '11', 'receptor_nombre' => 'RUC VIEJO', 'receptor_validado_at' => null])->save();
        $sub = \App\Modules\Bodega\Models\Order::find($factura->family_root_id)?->pipelineCard?->subClient;
        $sub?->update(['tax_id' => '155704849-2-2021', 'tax_id_dv' => '32', 'business_name' => 'DIGIFACT SERVICIOS, SOCIEDAD ANÓNIMA']);
    }

    public function test_una_factura_certificada_o_pendiente_no_reemplaza_su_receptor(): void
    {
        foreach (['certificado', 'pendiente'] as $estado) {
            $factura = $this->facturaConRespuestas([[self::RECHAZO, 422]]);
            $this->conReceptorViejoYClienteCorregido($factura);
            $factura->forceFill(['fe_estado' => $estado])->save();

            $this->refrescar($factura->fresh());

            $this->assertSame('999-9-9999', $factura->fresh()->receptor_ruc, "Una factura {$estado} conserva el receptor con el que se envió.");
            $this->assertNull(AuditLog::query()->where('action', AuditService::ADMIN_CONT_FE_RECEPTOR_REFRESCADO)->where('entity_id', (string) $factura->id)->first());
        }
    }

    public function test_el_reemplazo_del_receptor_de_una_factura_no_certificada_queda_auditado(): void
    {
        $factura = $this->facturaConRespuestas([[self::RECHAZO, 422]]);
        $this->conReceptorViejoYClienteCorregido($factura);

        $this->refrescar($factura->fresh());

        $fresco = $factura->fresh();
        $this->assertSame('155704849-2-2021', $fresco->receptor_ruc);
        $this->assertSame('DIGIFACT SERVICIOS, SOCIEDAD ANÓNIMA', $fresco->receptor_nombre);
        $auditoria = AuditLog::query()->where('action', AuditService::ADMIN_CONT_FE_RECEPTOR_REFRESCADO)->where('entity_id', (string) $factura->id)->firstOrFail();
        $this->assertSame('RUC VIEJO', $auditoria->old_values['receptor_nombre']);
        $this->assertSame('DIGIFACT SERVICIOS, SOCIEDAD ANÓNIMA', $auditoria->new_values['receptor_nombre']);
    }

    /** R-01 — el reemplazo de una corrección conserva el receptor heredado: la NC de anulación lleva el mismo y la DGI lo exige. */
    public function test_el_reemplazo_de_una_correccion_no_refresca_su_receptor_heredado(): void
    {
        $factura = $this->facturaConRespuestas([[self::RECHAZO, 422]]);
        $this->conReceptorViejoYClienteCorregido($factura);
        AdminContCreditNote::create([
            'numero' => 'NC-'.self::uniqueTestNumber(), 'master_client_id' => $factura->master_client_id ?? 1, 'cliente' => $factura->cliente,
            'tipo' => AdminContCreditNote::TIPO_DEVOLUCION_MERCANCIA, 'factura_origen_id' => $factura->id, 'factura_nueva_id' => $factura->id,
            'monto' => 107.00, 'subtotal' => 100.00, 'itbms' => 7.00, 'motivo' => 'Corrección de datos',
            'estado' => AdminContCreditNote::ESTADO_APLICADA, 'registrado_por' => $this->ownerId(), 'fecha' => now()->toDateString(),
        ]);

        $this->refrescar($factura->fresh());

        $this->assertSame('999-9-9999', $factura->fresh()->receptor_ruc, 'Una factura de reemplazo no cambia el receptor que heredó.');
        $this->assertNull(AuditLog::query()->where('action', AuditService::ADMIN_CONT_FE_RECEPTOR_REFRESCADO)->first());
    }

    public function test_revalidar_los_mismos_datos_no_deja_evento_de_auditoria(): void
    {
        $factura = $this->facturaConRespuestas([[self::RECHAZO, 422]]);

        $this->refrescar($factura->fresh());

        $this->assertNull(AuditLog::query()->where('action', AuditService::ADMIN_CONT_FE_RECEPTOR_REFRESCADO)->first());
    }

    public function test_la_primera_certificacion_de_una_factura_creada_con_el_pac_apagado_revalida_el_receptor(): void
    {
        Http::fake(['sandbox.msl.test/v1/facturas' => Http::response(self::CERTIFICADO, 200)]);
        $factura = $this->facturaConRespuestas([[self::CERTIFICADO, 200]]);
        $this->conReceptorViejoYClienteCorregido($factura);
        $factura->forceFill(['fe_estado' => null, 'cufe' => null])->save();
        Http::fake(['sandbox.msl.test/v1/facturas' => Http::response(self::CERTIFICADO, 200)]);

        $this->servicio()->certificarFactura($factura->fresh());

        $fresco = $factura->fresh();
        $this->assertSame('155704849-2-2021', $fresco->receptor_ruc, 'Se creó sin validar (receptor_validado_at nulo): se revalida antes de enviar.');
        $this->assertNotNull($fresco->receptor_validado_at);
    }
}
