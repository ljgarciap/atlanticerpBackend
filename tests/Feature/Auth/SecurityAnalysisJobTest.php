<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Modules\AdminCont\Models\AdminContPayment;
use App\Modules\AdminCont\Models\AdminContPaymentConfirmation;
use App\Modules\Auth\Jobs\SecurityAnalysisJob;
use App\Modules\Auth\Mail\SecurityAnomalyMail;
use App\Modules\Bodega\Models\AdjustmentRequest;
use App\Modules\Bodega\Models\AdjustmentRequestLine;
use App\Modules\Bodega\Models\RelocationRequest;
use App\Modules\Bodega\Models\Warehouse;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Modules\VentasDiseno\Models\MasterClient;
use App\Shared\Multitenancy\Tenant;
use App\Shared\Services\AuditService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Tests\Feature\FeatureTestCase;

/**
 * Epic 11 — Seguridad Agéntica. Ver docs/ai/security-analysis-job-prompt.md para el contrato
 * completo. El job nunca bloquea por sí mismo — solo crea sugerencias en security_anomalies.
 *
 * La ventana que analiza el job es el día calendario anterior completo (ver fix de revisión
 * de código en SecurityAnalysisJob::handle() — Carbon::yesterday()->startOfDay() a
 * Carbon::today()->startOfDay(), no Carbon::now()). Por eso el reloj se congela en setUp() a
 * un instante fijo, y los audit_logs de fixture se insertan explícitamente "ayer" respecto a
 * ese instante — insertarlos con now() caería en la ventana de HOY, fuera de lo que el job mira.
 */
class SecurityAnalysisJobTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.anthropic.api_key' => 'test-anthropic-key']);
        Redis::del('cb:anthropic:failures', 'cb:anthropic:opened_at');
        Carbon::setTestNow(Carbon::parse('2026-07-05 03:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function withTenant(\Closure $fn): mixed
    {
        $tenant = Tenant::where('slug', 'atlantic')->firstOrFail();
        $tenant->makeCurrent();

        try {
            return $fn();
        } finally {
            Tenant::forgetCurrent();
        }
    }

    private function findUser(string $email): User
    {
        return $this->withTenant(fn () => User::where('email', $email)->firstOrFail());
    }

    private function refreshUser(User $user): void
    {
        $this->withTenant(fn () => $user->refresh());
    }

    /**
     * spatie/laravel-multitenancy trata todo Job como tenant-aware por defecto y necesita
     * el tenant activo AL MOMENTO DEL DISPATCH para inyectar tenantId en el payload — no
     * alcanza con que el job lo resuelva por slug dentro de su propio handle(). Mismo fix
     * aplicado a SecurityAnalyzeCommand.
     */
    private function dispatchJob(): void
    {
        $this->withTenant(fn () => SecurityAnalysisJob::dispatchSync('atlantic'));
    }

    /** @param array<string, mixed> $body */
    private function fakeClaudeResponse(array $body): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'text', 'text' => json_encode($body, JSON_THROW_ON_ERROR)]],
            ], 200),
        ]);
    }

    /** Por defecto cae a media tarde de ayer — cómodamente dentro de la ventana que analiza el job. */
    private function insertAuditLog(int $userId, string $action, ?string $newValues = null, ?Carbon $createdAt = null, ?string $ipAddress = null): void
    {
        DB::table('atlantic_auth.audit_logs')->insert([
            'user_id'    => $userId,
            'action'     => $action,
            'new_values' => $newValues,
            'ip_address' => $ipAddress,
            'request_id' => (string) Str::uuid(),
            'metadata'   => '{}',
            'created_at' => $createdAt ?? Carbon::yesterday()->setTime(14, 0),
        ]);
    }

    public function test_sin_actividad_no_crea_ninguna_anomalia_ni_llama_a_claude(): void
    {
        Http::fake();

        $this->dispatchJob();

        Http::assertNothingSent();
        $this->assertSame(0, DB::table('atlantic_auth.security_anomalies')->count());
    }

    public function test_actividad_de_hoy_no_se_analiza_todavia(): void
    {
        // Confirma la semántica del fix: el job mira el día calendario ANTERIOR, no lo que
        // pasó "hoy" hasta el momento en que corre.
        $user = $this->findUser('designer@atlantic.test');
        $this->insertAuditLog($user->id, AuditService::AUTH_FAILED, createdAt: Carbon::today()->setTime(1, 0));

        Http::fake();

        $this->dispatchJob();

        Http::assertNothingSent();
        $this->assertSame(0, DB::table('atlantic_auth.security_anomalies')->count());
    }

    public function test_detecta_anomalia_la_persiste_y_notifica_a_superadmins(): void
    {
        $user = $this->findUser('designer@atlantic.test');
        $this->insertAuditLog($user->id, AuditService::AUTH_FAILED);

        $this->fakeClaudeResponse([
            'anomalies' => [[
                'affected_user_id' => $user->id,
                'severity'         => 'high',
                'reasoning'        => 'Múltiples intentos fallidos de login en poco tiempo.',
            ]],
        ]);

        $this->dispatchJob();

        $row = DB::table('atlantic_auth.security_anomalies')->first();
        $this->assertNotNull($row);
        $this->assertSame('high', $row->severity);
        $this->assertSame($user->id, $row->affected_user_id);
        $this->assertSame('pending', $row->status);

        Mail::assertSent(SecurityAnomalyMail::class);

        // El job nunca bloquea por sí mismo.
        $this->refreshUser($user);
        $this->assertFalse($user->isSecurityBlocked());
    }

    /** SCRUM-1033 (A-03): el reuso de un refresh token revocado llega al resumen que analiza Claude. */
    public function test_el_reuso_de_refresh_token_se_cuenta_en_el_resumen_por_usuario(): void
    {
        $user = $this->findUser('designer@atlantic.test');
        $this->insertAuditLog($user->id, AuditService::AUTH_REFRESH_TOKEN_REUSE);
        $this->insertAuditLog($user->id, AuditService::AUTH_REFRESH_TOKEN_REUSE);

        $this->fakeClaudeResponse(['anomalies' => []]);
        $this->dispatchJob();

        // El resumen viaja como JSON dentro del texto del mensaje, de ahí el escape de las comillas.
        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.anthropic.com')
            && str_contains($request->body(), 'refresh_token_reuse_count\\":2,'));
    }

    /**
     * SCRUM-814 (Epic SCRUM-788) — SecurityAnalysisJob no extiende BaseAiJob (ver su propio
     * docblock), así que necesita su propia llamada a AiUsageAuditor::log() para quedar cubierto
     * por la misma trazabilidad de uso de IA que el resto de los Jobs.
     */
    public function test_llamada_a_claude_deja_registro_de_uso_de_ia(): void
    {
        $user = $this->findUser('designer@atlantic.test');
        $this->insertAuditLog($user->id, AuditService::AUTH_FAILED);

        $this->fakeClaudeResponse(['anomalies' => []]);

        $this->dispatchJob();

        $log = DB::table('atlantic_auth.audit_logs')
            ->where('action', AuditService::AI_USAGE_REQUESTED)
            ->first();

        $this->assertNotNull($log, 'Se esperaba un AI_USAGE_REQUESTED para la corrida de SecurityAnalysisJob.');
        $metadata = json_decode((string) $log->metadata, true);
        $this->assertSame('App\Modules\Auth\Jobs\SecurityAnalysisJob', $metadata['job']);
        $this->assertNotEmpty($metadata['prompt_preview']);
    }

    public function test_hallazgo_con_user_id_fuera_del_resumen_enviado_se_descarta(): void
    {
        $user = $this->findUser('designer@atlantic.test');
        $this->insertAuditLog($user->id, AuditService::AUTH_FAILED);

        // Claude "inventa" un user_id que el job nunca le mandó — debe descartarse.
        $this->fakeClaudeResponse([
            'anomalies' => [[
                'affected_user_id' => 999999,
                'severity'         => 'high',
                'reasoning'        => 'Hallazgo inválido.',
            ]],
        ]);

        $this->dispatchJob();

        $this->assertSame(0, DB::table('atlantic_auth.security_anomalies')->count());
    }

    public function test_respuesta_con_schema_invalido_no_crea_filas(): void
    {
        $user = $this->findUser('designer@atlantic.test');
        $this->insertAuditLog($user->id, AuditService::AUTH_FAILED);

        $this->fakeClaudeResponse(['unexpected_key' => 'sin anomalies']);

        $this->dispatchJob();

        $this->assertSame(0, DB::table('atlantic_auth.security_anomalies')->count());
    }

    public function test_upload_a_zona_publica_se_marca_como_hallazgo_deterministico(): void
    {
        $user = $this->findUser('designer@atlantic.test');
        $this->insertAuditLog(
            $user->id,
            AuditService::CRM_DOCUMENT_UPLOADED,
            json_encode(['storage_zone' => 'public']),
        );

        $this->fakeClaudeResponse(['anomalies' => []]);

        $this->dispatchJob();

        $row = DB::table('atlantic_auth.security_anomalies')->first();
        $this->assertNotNull($row);
        $this->assertSame('low', $row->severity);
        $rawResponse = json_decode((string) $row->raw_response, true);
        $this->assertSame('deterministic', $rawResponse['source'] ?? null);
    }

    public function test_no_duplica_si_corre_dos_veces_el_mismo_dia_aunque_pase_el_tiempo(): void
    {
        // Simula el escenario real que motivó el fix (revisión de código): un reintento o
        // re-disparo manual de security:analyze más tarde el mismo día — no dos llamadas en
        // el mismo instante exacto. Avanza el reloj unos minutos entre corridas para probar
        // que el dedupe sigue funcionando (antes del fix, esto SÍ duplicaba).
        $user = $this->findUser('designer@atlantic.test');
        $this->insertAuditLog(
            $user->id,
            AuditService::CRM_DOCUMENT_UPLOADED,
            json_encode(['storage_zone' => 'public']),
        );

        $this->fakeClaudeResponse(['anomalies' => []]);

        $this->dispatchJob();
        $this->assertSame(1, DB::table('atlantic_auth.security_anomalies')->count());

        Carbon::setTestNow(Carbon::parse('2026-07-05 03:07:00')); // mismo día, 7 min después

        $this->dispatchJob();
        $this->assertSame(
            1,
            DB::table('atlantic_auth.security_anomalies')->count(),
            'No debe duplicar hallazgos aunque pase tiempo real entre corridas del mismo día',
        );
    }

    // ── REQ-635 (SCRUM-811) — reglas de fraude en Bodega y Admin&Cont ──────────────────────────

    private function makeProduct(): CatalogProduct
    {
        return $this->withTenant(fn () => CatalogProduct::create([
            'reference' => 'REF-FRAUDE-'.self::uniqueTestNumber(), 'name' => 'Producto fraude test',
            'description' => 'x', 'price_full' => 100, 'cost' => 60, 'stock_quantity' => 0, 'is_active' => true,
        ]));
    }

    private function findRule(string $rule): ?object
    {
        return DB::table('atlantic_auth.security_anomalies')
            ->get()
            ->first(fn ($row) => (json_decode((string) $row->raw_response, true)['rule'] ?? null) === $rule);
    }

    public function test_ajuste_de_inventario_grande_se_marca_como_hallazgo_deterministico(): void
    {
        $user    = $this->findUser('designer@atlantic.test');
        $product = $this->makeProduct();

        $this->withTenant(function () use ($user, $product) {
            $request = AdjustmentRequest::create(['catalog_product_id' => $product->id, 'solicitado_por' => $user->id]);
            $line = AdjustmentRequestLine::create([
                'adjustment_request_id' => $request->id,
                'warehouse_id'          => Warehouse::where('name', 'Bodega Central')->first()->id,
                'tipo' => 'Sumar', 'cantidad' => 25, 'motivo' => 'Test', 'estado' => 'Pendiente',
            ]);
            $line->forceFill(['created_at' => Carbon::yesterday()->setTime(14, 0)])->save();
        });

        $this->fakeClaudeResponse(['anomalies' => []]);
        $this->dispatchJob();

        $row = $this->findRule('bodega_ajuste_grande');
        $this->assertNotNull($row);
        $this->assertSame('medium', $row->severity);
        $this->assertSame($user->id, $row->affected_user_id);
    }

    public function test_ajuste_de_inventario_por_debajo_del_umbral_no_dispara_hallazgo(): void
    {
        $user    = $this->findUser('designer@atlantic.test');
        $product = $this->makeProduct();

        $this->withTenant(function () use ($user, $product) {
            $request = AdjustmentRequest::create(['catalog_product_id' => $product->id, 'solicitado_por' => $user->id]);
            $line = AdjustmentRequestLine::create([
                'adjustment_request_id' => $request->id,
                'warehouse_id'          => Warehouse::where('name', 'Bodega Central')->first()->id,
                'tipo' => 'Sumar', 'cantidad' => 3, 'motivo' => 'Test', 'estado' => 'Pendiente',
            ]);
            $line->forceFill(['created_at' => Carbon::yesterday()->setTime(14, 0)])->save();
        });

        $this->fakeClaudeResponse(['anomalies' => []]);
        $this->dispatchJob();

        $this->assertNull($this->findRule('bodega_ajuste_grande'));
    }

    public function test_ajuste_en_madrugada_se_marca_como_hallazgo_fuera_de_horario(): void
    {
        $user    = $this->findUser('designer@atlantic.test');
        $product = $this->makeProduct();

        // App::timezone es America/Panama (config/app.php, SCRUM-883) — Carbon::yesterday()
        // ->setTime(3, 0) ya construye 03:00 hora de Panamá directamente, dentro de la ventana
        // [00:00, 05:00) que evalúa la regla (antes del fix de timezone esto se armaba como
        // 08:00 UTC = 03:00 Panamá; ahora el default ya es Panamá, no hace falta ese offset).
        $this->withTenant(function () use ($user, $product) {
            $request = AdjustmentRequest::create(['catalog_product_id' => $product->id, 'solicitado_por' => $user->id]);
            $request->forceFill(['created_at' => Carbon::yesterday()->setTime(3, 0)])->save();
            // cantidad chica, a propósito, para no disparar también bodega_ajuste_grande.
            $line = AdjustmentRequestLine::create([
                'adjustment_request_id' => $request->id,
                'warehouse_id'          => Warehouse::where('name', 'Bodega Central')->first()->id,
                'tipo' => 'Sumar', 'cantidad' => 2, 'motivo' => 'Test', 'estado' => 'Pendiente',
            ]);
            $line->forceFill(['created_at' => Carbon::yesterday()->setTime(3, 0)])->save();
        });

        $this->fakeClaudeResponse(['anomalies' => []]);
        $this->dispatchJob();

        $row = $this->findRule('bodega_fuera_de_horario');
        $this->assertNotNull($row);
        $this->assertSame($user->id, $row->affected_user_id);
    }

    public function test_correlacion_ajuste_y_reubicacion_mismo_producto_ventana_corta(): void
    {
        $user    = $this->findUser('designer@atlantic.test');
        $product = $this->makeProduct();

        $this->withTenant(function () use ($user, $product) {
            $central = Warehouse::where('name', 'Bodega Central')->first();
            $merma   = Warehouse::where('name', 'Merma')->first();

            $request = AdjustmentRequest::create(['catalog_product_id' => $product->id, 'solicitado_por' => $user->id]);
            $request->forceFill(['created_at' => Carbon::yesterday()->setTime(14, 0)])->save();
            $line = AdjustmentRequestLine::create([
                'adjustment_request_id' => $request->id, 'warehouse_id' => $central->id,
                'tipo' => 'Restar', 'cantidad' => 2, 'motivo' => 'Test', 'estado' => 'Pendiente',
            ]);
            $line->forceFill(['created_at' => Carbon::yesterday()->setTime(14, 0)])->save();

            $relocation = RelocationRequest::create([
                'catalog_product_id' => $product->id, 'origin_warehouse_id' => $central->id,
                'destination_warehouse_id' => $merma->id, 'cantidad' => 2, 'motivo' => 'Test',
                'solicitado_por' => $user->id, 'estado' => RelocationRequest::ESTADO_PENDIENTE,
            ]);
            $relocation->forceFill(['created_at' => Carbon::yesterday()->setTime(14, 4)])->save();
        });

        $this->fakeClaudeResponse(['anomalies' => []]);
        $this->dispatchJob();

        $row = $this->findRule('bodega_correlacion_ajuste_reubicacion');
        $this->assertNotNull($row);
        $this->assertSame($user->id, $row->affected_user_id);
    }

    public function test_ip_nueva_dispara_hallazgo_si_el_usuario_ya_tiene_historial(): void
    {
        $user = $this->findUser('designer@atlantic.test');

        // Historial previo con otra IP, dentro de la ventana de lookback (default 90 días).
        $this->insertAuditLog($user->id, 'auth.login', createdAt: Carbon::yesterday()->subDays(5)->setTime(9, 0), ipAddress: '10.0.0.1');
        // Evento de Bodega "ayer" desde una IP nueva para este usuario.
        $this->insertAuditLog($user->id, 'bodega.adjustment_request.created', createdAt: Carbon::yesterday()->setTime(14, 0), ipAddress: '10.0.0.99');

        $this->fakeClaudeResponse(['anomalies' => []]);
        $this->dispatchJob();

        $row = $this->findRule('bodega_ip_nueva');
        $this->assertNotNull($row);
        $this->assertSame($user->id, $row->affected_user_id);
    }

    public function test_ip_nueva_no_dispara_en_la_primera_accion_del_usuario(): void
    {
        $user = $this->findUser('designer@atlantic.test');

        // Sin ningún historial previo — arranque en frío, la regla no debe evaluarse.
        $this->insertAuditLog($user->id, 'bodega.adjustment_request.created', createdAt: Carbon::yesterday()->setTime(14, 0), ipAddress: '10.0.0.99');

        $this->fakeClaudeResponse(['anomalies' => []]);
        $this->dispatchJob();

        $this->assertNull($this->findRule('bodega_ip_nueva'));
    }

    /** @param list<float> $amounts */
    private function makeBaselinePayments(int $masterClientId, array $amounts, Carbon $anchor): void
    {
        foreach ($amounts as $i => $amount) {
            $payment = AdminContPayment::create([
                'numero_recibo' => 'REC-'.self::uniqueTestNumber(), 'master_client_id' => $masterClientId,
                'monto_recibido' => $amount, 'saldo_favor_aplicado' => 0,
                'metodo_pago' => AdminContPayment::METODO_EFECTIVO, 'estado' => AdminContPayment::ESTADO_CONFIRMADO,
            ]);
            $payment->forceFill(['created_at' => $anchor->copy()->subDays($i + 1)->setTime(10, 0)])->save();
        }
    }

    public function test_pago_fuera_de_rango_dispara_hallazgo_con_suficiente_historial(): void
    {
        $masterClient = $this->withTenant(fn () => MasterClient::create(['name' => 'Cliente Fraude Test '.self::uniqueTestNumber()]));
        $yesterday    = Carbon::yesterday();

        $this->withTenant(function () use ($masterClient, $yesterday) {
            // 12 pagos baseline con dispersión real (no todos iguales — desviación 0 hace que la
            // regla no se evalúe a propósito, ver docblock de flagAdminContUnusualPaymentAmounts).
            $this->makeBaselinePayments($masterClient->id, [90, 95, 100, 105, 110, 98, 102, 97, 103, 101, 99, 104], $yesterday);

            $outlier = AdminContPayment::create([
                'numero_recibo' => 'REC-'.self::uniqueTestNumber(), 'master_client_id' => $masterClient->id,
                'monto_recibido' => 100000, 'saldo_favor_aplicado' => 0,
                'metodo_pago' => AdminContPayment::METODO_EFECTIVO, 'estado' => AdminContPayment::ESTADO_CONFIRMADO,
            ]);
            $outlier->forceFill(['created_at' => $yesterday->copy()->setTime(14, 0)])->save();
        });

        $this->fakeClaudeResponse(['anomalies' => []]);
        $this->dispatchJob();

        $row = $this->findRule('admin_cont_monto_fuera_de_rango');
        $this->assertNotNull($row);
        $this->assertSame('high', $row->severity);
    }

    public function test_pago_sin_suficiente_historial_no_dispara_hallazgo(): void
    {
        $masterClient = $this->withTenant(fn () => MasterClient::create(['name' => 'Cliente Fraude Test '.self::uniqueTestNumber()]));
        $yesterday    = Carbon::yesterday();

        $this->withTenant(function () use ($masterClient, $yesterday) {
            // Solo 5 pagos baseline (< 10 mínimo) — la regla no debe evaluarse aunque el pago de
            // hoy sea un outlier evidente.
            $this->makeBaselinePayments($masterClient->id, [90, 95, 100, 105, 110], $yesterday);

            $outlier = AdminContPayment::create([
                'numero_recibo' => 'REC-'.self::uniqueTestNumber(), 'master_client_id' => $masterClient->id,
                'monto_recibido' => 100000, 'saldo_favor_aplicado' => 0,
                'metodo_pago' => AdminContPayment::METODO_EFECTIVO, 'estado' => AdminContPayment::ESTADO_CONFIRMADO,
            ]);
            $outlier->forceFill(['created_at' => $yesterday->copy()->setTime(14, 0)])->save();
        });

        $this->fakeClaudeResponse(['anomalies' => []]);
        $this->dispatchJob();

        $this->assertNull($this->findRule('admin_cont_monto_fuera_de_rango'));
    }

    public function test_aprobaciones_repetidas_dispara_hallazgo(): void
    {
        $approver     = $this->findUser('management@atlantic.test');
        $masterClient = $this->withTenant(fn () => MasterClient::create(['name' => 'Cliente Fraude Test '.self::uniqueTestNumber()]));

        $this->withTenant(function () use ($approver, $masterClient) {
            for ($i = 0; $i < 5; $i++) {
                $payment = AdminContPayment::create([
                    'numero_recibo' => 'REC-'.self::uniqueTestNumber(), 'master_client_id' => $masterClient->id,
                    'monto_recibido' => 50, 'saldo_favor_aplicado' => 0,
                    'metodo_pago' => AdminContPayment::METODO_TRANSFERENCIA, 'estado' => AdminContPayment::ESTADO_ESPERANDO_CONFIRMACION,
                ]);
                AdminContPaymentConfirmation::create([
                    'payment_id' => $payment->id, 'numero_confirmacion' => 'CONF-'.self::uniqueTestNumber(),
                    'confirmed_by' => $approver->id, 'confirmed_at' => Carbon::yesterday()->setTime(10, $i),
                ]);
            }
        });

        $this->fakeClaudeResponse(['anomalies' => []]);
        $this->dispatchJob();

        $row = $this->findRule('admin_cont_aprobaciones_repetidas');
        $this->assertNotNull($row);
        $this->assertSame($approver->id, $row->affected_user_id);
    }
}
