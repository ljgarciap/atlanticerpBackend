<?php

declare(strict_types=1);

namespace Tests\Feature\AdminCont;

use App\Modules\AdminCont\Models\AdminContPettyCashExpense;
use App\Modules\AdminCont\Models\AdminContPettyCashReport;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\FeatureTestCase;

/** Batch 20 de Admin&Cont (SCRUM-612→617, REQ-535→540) — Caja Chica. */
class AdminContPettyCashControllerTest extends FeatureTestCase
{
    private const CONTABILIDAD_EMAIL = 'contabilidad@test.com';

    private const ASISTENTE_EMAIL = 'asistenteadministrativa@test.com';

    /** Mismo email real que `AdminContCreditNoteControllerTest`/`AdminContInvoiceControllerTest`. */
    private const APPROVER_EMAIL = 'gerencia3@test.com';

    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        Storage::fake('s3-private');
    }

    private function loginAsFelix(): string
    {
        return $this->loginAs(self::CONTABILIDAD_EMAIL, self::CONTABILIDAD_EMAIL, 'lider_admin_contab');
    }

    private function loginAsYaneth(): string
    {
        return $this->loginAs(self::ASISTENTE_EMAIL, self::ASISTENTE_EMAIL, 'asistente_administrativa');
    }

    private function loginAsMark(): string
    {
        return $this->loginAs(self::APPROVER_EMAIL, self::APPROVER_EMAIL, 'management');
    }

    private function userIdByEmail(string $email): int
    {
        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');

        return (int) DB::table("{$slug}_auth.users")->where('email', $email)->value('id');
    }

    /** Mismo patrón que `AdminContCreditNoteControllerTest::tableFqn()` — nunca usar el modelo
     *  Eloquent tenant-scoped para leer/contar directo desde el test después de una llamada HTTP:
     *  `Tenant::current()` puede perderse entre el request simulado y el resto del test. */
    private function tableFqn(string $table): string
    {
        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');

        return "{$slug}_admin_contab.{$table}";
    }

    /** REQ-540 RN1 — solo Gerencia aprueba, sin importar quién esté configurado como approver hoy. */
    private function ensureMarkIsApprover(): void
    {
        $markId = $this->userIdByEmail(self::APPROVER_EMAIL);
        DB::table($this->tableFqn('fiscal_settings'))->where('id', 1)->update(['primary_approver_user_id' => $markId]);
    }

    /** @return array<string, mixed> */
    private function lineaPayload(int $solicitanteId, string $proveedor = 'Cafetería Manolo', float $bruto = 18.50, float $itbms = 1.30): array
    {
        return [
            'fecha' => '2026-07-01',
            'solicitante_id' => $solicitanteId,
            'proveedor' => $proveedor,
            'descripcion' => 'Gasto de prueba',
            'monto_bruto' => $bruto,
            'itbms' => $itbms,
            'foto' => UploadedFile::fake()->image('recibo.jpg'),
        ];
    }

    public function test_registrar_multiples_lineas_de_2_solicitantes_aparecen_en_pendientes(): void
    {
        $token = $this->loginAsFelix();
        $felixId = $this->userIdByEmail(self::CONTABILIDAD_EMAIL);
        $markId = $this->userIdByEmail(self::APPROVER_EMAIL);

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/admin-contab/petty-cash/expenses', [
                'lineas' => [
                    $this->lineaPayload($felixId, 'Cafetería Manolo', 18.50, 1.30),
                    $this->lineaPayload($felixId, 'Farmacia Transporte', 32.00, 2.24),
                    $this->lineaPayload($markId, 'Estacionamiento Demo', 8.00, 0),
                ],
            ]);

        $response->assertStatus(201);
        $this->assertSame(3, DB::table($this->tableFqn('petty_cash_expenses'))->count());

        $pending = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/petty-cash/pending')
            ->assertStatus(200)
            ->json();

        $this->assertCount(2, $pending['grupos']);
        // Contabilidad: (18.50+1.30) + (32.00+2.24) = 54.04 · Gerencia: 8.00+0 = 8.00 · total 62.04
        $this->assertEquals(62.04, round($pending['total_general'], 2));
    }

    public function test_falta_foto_en_una_linea_bloquea_el_guardado_completo(): void
    {
        $token = $this->loginAsFelix();
        $felixId = $this->userIdByEmail(self::CONTABILIDAD_EMAIL);

        $lineaSinFoto = $this->lineaPayload($felixId);
        unset($lineaSinFoto['foto']);

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/admin-contab/petty-cash/expenses', [
                'lineas' => [$this->lineaPayload($felixId), $lineaSinFoto],
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['lineas.1.foto']);
        $this->assertSame(0, DB::table($this->tableFqn('petty_cash_expenses'))->count());
    }

    public function test_itbms_vacio_es_rechazado_nunca_asumido_como_cero(): void
    {
        $token = $this->loginAsFelix();
        $felixId = $this->userIdByEmail(self::CONTABILIDAD_EMAIL);

        $linea = $this->lineaPayload($felixId);
        $linea['itbms'] = '';

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/admin-contab/petty-cash/expenses', ['lineas' => [$linea]]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['lineas.0.itbms']);
    }

    public function test_generar_reporte_desmarcando_un_solicitante_completo_solo_incluye_al_otro(): void
    {
        $token = $this->loginAsFelix();
        $felixId = $this->userIdByEmail(self::CONTABILIDAD_EMAIL);
        $markId = $this->userIdByEmail(self::APPROVER_EMAIL);

        $this->withHeaders($this->authHeaders($token))->postJson('/api/admin-contab/petty-cash/expenses', [
            'lineas' => [$this->lineaPayload($felixId, 'A', 10, 0), $this->lineaPayload($markId, 'B', 20, 0)],
        ])->assertStatus(201);

        $soloFelix = DB::table($this->tableFqn('petty_cash_expenses'))->where('solicitante_id', $felixId)->pluck('id')->all();

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/admin-contab/petty-cash/reports', [
                'expense_ids' => $soloFelix,
                'forma_pago' => AdminContPettyCashReport::FORMA_PAGO_TRANSFERENCIA,
            ]);

        $response->assertStatus(201);
        $this->assertMatchesRegularExpression('/^\d{4}-2026$/', $response->json('numero'));

        $pending = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/petty-cash/pending')->json();
        $this->assertCount(1, $pending['grupos']);
        $this->assertEquals(20.0, $pending['total_general']);
    }

    // ── SCRUM-947 — paginación estándar ─────────────────────────────────────────────────────

    /**
     * Pendientes pagina a nivel de GRUPO (por solicitante), no de línea: `Pagination::ofArray()`
     * recibe el array YA agrupado (`$result['grupos']`), así que un grupo nunca puede partirse
     * entre 2 páginas por construcción — no por un chequeo especial. `per_page` real (10/20/50/100)
     * — 3 solicitantes reales alcanzan para confirmar el shape de `meta` y que `total_general`
     * sigue siendo el total real de TODO lo pendiente, no solo la página.
     */
    public function test_pending_pagina_a_nivel_de_grupo_sin_afectar_total_general(): void
    {
        $token = $this->loginAsFelix();
        $felixId = $this->userIdByEmail(self::CONTABILIDAD_EMAIL);
        $yanethId = $this->userIdByEmail(self::ASISTENTE_EMAIL);
        $markId = $this->userIdByEmail(self::APPROVER_EMAIL);

        $this->withHeaders($this->authHeaders($token))->postJson('/api/admin-contab/petty-cash/expenses', [
            'lineas' => [
                $this->lineaPayload($felixId, 'A', 10, 0),
                $this->lineaPayload($yanethId, 'B', 20, 0),
                $this->lineaPayload($markId, 'C', 30, 0),
            ],
        ])->assertStatus(201);

        $page1 = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/petty-cash/pending?per_page=10')
            ->assertStatus(200)->json();

        $this->assertCount(3, $page1['grupos']);
        $this->assertEquals(60.0, $page1['total_general'], 'total_general sigue siendo el total real de TODO lo pendiente, no solo la página');
        $this->assertSame(['total' => 3, 'per_page' => 10, 'current_page' => 1, 'last_page' => 1], $page1['meta']);

        $page2 = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/petty-cash/pending?per_page=10&page=2')
            ->assertStatus(200)->json();
        $this->assertCount(0, $page2['grupos'], 'los 3 grupos ya entraron en la página 1, la 2 debe venir vacía');
    }

    public function test_rejected_pagina_20_por_defecto(): void
    {
        $solicitanteId = $this->userIdByEmail(self::CONTABILIDAD_EMAIL);
        for ($i = 0; $i < 25; $i++) {
            AdminContPettyCashExpense::create([
                'fecha' => '2026-07-01', 'solicitante_id' => $solicitanteId, 'proveedor' => "Proveedor {$i}",
                'descripcion' => 'Gasto rechazado', 'monto_bruto' => 10, 'itbms' => 0,
                'report_id' => null, 'intentos_rechazo' => 5, 'created_by' => $solicitanteId,
            ]);
        }

        $response = $this->withHeaders($this->authHeaders($this->loginAsFelix()))
            ->getJson('/api/admin-contab/petty-cash/rejected')
            ->assertStatus(200)->json();

        $this->assertCount(20, $response['lineas']);
        $this->assertSame(['total' => 25, 'per_page' => 20, 'current_page' => 1, 'last_page' => 2], $response['meta']);
    }

    public function test_reports_pagina_20_por_defecto(): void
    {
        $realizadoPor = $this->userIdByEmail(self::CONTABILIDAD_EMAIL);
        for ($i = 0; $i < 25; $i++) {
            AdminContPettyCashReport::create([
                'numero' => sprintf('%04d-2026', $i + 1), 'forma_pago' => AdminContPettyCashReport::FORMA_PAGO_EFECTIVO,
                'estado' => AdminContPettyCashReport::ESTADO_PENDIENTE_APROBACION, 'realizado_por' => $realizadoPor,
            ]);
        }

        $response = $this->withHeaders($this->authHeaders($this->loginAsFelix()))
            ->getJson('/api/admin-contab/petty-cash/reports')
            ->assertStatus(200)->json();

        $this->assertCount(20, $response['data']);
        $this->assertSame(['total' => 25, 'per_page' => 20, 'current_page' => 1, 'last_page' => 2], $response['meta']);
    }

    public function test_generar_reporte_sin_seleccion_falla_validacion(): void
    {
        $token = $this->loginAsFelix();

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/admin-contab/petty-cash/reports', [
                'expense_ids' => [],
                'forma_pago' => AdminContPettyCashReport::FORMA_PAGO_EFECTIVO,
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['expense_ids']);
    }

    /** @return string numero del reporte generado */
    private function crearReportePendiente(string $tokenFelix, int $solicitanteId): string
    {
        $this->withHeaders($this->authHeaders($tokenFelix))->postJson('/api/admin-contab/petty-cash/expenses', [
            'lineas' => [$this->lineaPayload($solicitanteId, 'Subway', 42.50, 2.98)],
        ])->assertStatus(201);

        // Solo la línea recién creada por este helper — otros gastos "pendientes" ya existentes
        // en el mismo test (creados antes de llamar a este helper) deben quedar afuera del reporte.
        $ids = DB::table($this->tableFqn('petty_cash_expenses'))
            ->where('proveedor', 'Subway')
            ->whereNull('report_id')
            ->pluck('id')->all();

        $response = $this->withHeaders($this->authHeaders($tokenFelix))
            ->postJson('/api/admin-contab/petty-cash/reports', [
                'expense_ids' => $ids,
                'forma_pago' => AdminContPettyCashReport::FORMA_PAGO_CHEQUE,
            ])->assertStatus(201);

        return (string) $response->json('numero');
    }

    public function test_detalle_de_reporte_pendiente_indica_puede_aprobar_solo_para_mark(): void
    {
        $this->ensureMarkIsApprover();
        $tokenFelix = $this->loginAsFelix();
        $numero = $this->crearReportePendiente($tokenFelix, $this->userIdByEmail(self::CONTABILIDAD_EMAIL));

        $detalleFelix = $this->withHeaders($this->authHeaders($tokenFelix))
            ->getJson("/api/admin-contab/petty-cash/reports/{$numero}")
            ->assertStatus(200)
            ->json();
        $this->assertFalse($detalleFelix['puede_aprobar']);

        $tokenMark = $this->loginAsMark();
        $detalleMark = $this->withHeaders($this->authHeaders($tokenMark))
            ->getJson("/api/admin-contab/petty-cash/reports/{$numero}")
            ->assertStatus(200)
            ->json();
        $this->assertTrue($detalleMark['puede_aprobar']);
        $this->assertEquals('pendiente_aprobacion', $detalleMark['estado']);
    }

    public function test_aprobar_reporte_es_exclusivo_de_mark(): void
    {
        $this->ensureMarkIsApprover();
        $tokenFelix = $this->loginAsFelix();
        $numero = $this->crearReportePendiente($tokenFelix, $this->userIdByEmail(self::CONTABILIDAD_EMAIL));

        $this->withHeaders($this->authHeaders($tokenFelix))
            ->putJson("/api/admin-contab/petty-cash/reports/{$numero}/approve")
            ->assertStatus(403);

        $tokenMark = $this->loginAsMark();
        $this->withHeaders($this->authHeaders($tokenMark))
            ->putJson("/api/admin-contab/petty-cash/reports/{$numero}/approve")
            ->assertStatus(200)
            ->assertJson(['numero' => $numero, 'estado' => AdminContPettyCashReport::ESTADO_FINALIZADO]);

        // REQ-540 RN3 — ya finalizado, no puede volver a aprobarse.
        $this->withHeaders($this->authHeaders($tokenMark))
            ->putJson("/api/admin-contab/petty-cash/reports/{$numero}/approve")
            ->assertStatus(409);
    }

    public function test_summary_cuenta_pendientes_reportes_y_sin_aprobar(): void
    {
        $this->ensureMarkIsApprover();
        $tokenFelix = $this->loginAsFelix();
        $felixId = $this->userIdByEmail(self::CONTABILIDAD_EMAIL);

        $this->withHeaders($this->authHeaders($tokenFelix))->postJson('/api/admin-contab/petty-cash/expenses', [
            'lineas' => [$this->lineaPayload($felixId)],
        ])->assertStatus(201);

        $this->crearReportePendiente($tokenFelix, $felixId);

        $summary = $this->withHeaders($this->authHeaders($tokenFelix))
            ->getJson('/api/admin-contab/petty-cash/summary')
            ->assertStatus(200)
            ->json();

        $this->assertEquals(1, $summary['pendientes_count']);
        $this->assertEquals(1, $summary['reportes_count']);
        $this->assertEquals(1, $summary['reportes_sin_aprobar_count']);
        $this->assertEquals(0, $summary['rechazados_count']);
    }

    public function test_asistente_puede_registrar_gastos_pero_management_no(): void
    {
        $felixId = $this->userIdByEmail(self::CONTABILIDAD_EMAIL);
        $tokenMark = $this->loginAsMark();

        $this->withHeaders($this->authHeaders($tokenMark))
            ->postJson('/api/admin-contab/petty-cash/expenses', ['lineas' => [$this->lineaPayload($felixId)]])
            ->assertStatus(403);

        $tokenYaneth = $this->loginAsYaneth();
        $this->withHeaders($this->authHeaders($tokenYaneth))
            ->postJson('/api/admin-contab/petty-cash/expenses', ['lineas' => [$this->lineaPayload($felixId)]])
            ->assertStatus(201);
    }

    // ==================== Batch 21 (SCRUM-618→623, REQ-541→546) ====================

    /** @return array{numero: string, ids: list<int>} reporte con las líneas indicadas, en ese orden */
    private function crearReporteConLineas(string $tokenFelix, array $expenseIds): array
    {
        $response = $this->withHeaders($this->authHeaders($tokenFelix))
            ->postJson('/api/admin-contab/petty-cash/reports', [
                'expense_ids' => $expenseIds,
                'forma_pago' => AdminContPettyCashReport::FORMA_PAGO_EFECTIVO,
            ])->assertStatus(201);

        return ['numero' => (string) $response->json('numero'), 'ids' => $expenseIds];
    }

    private function crearGastoPendiente(string $tokenFelix, int $solicitanteId, string $proveedor): int
    {
        $this->withHeaders($this->authHeaders($tokenFelix))->postJson('/api/admin-contab/petty-cash/expenses', [
            'lineas' => [$this->lineaPayload($solicitanteId, $proveedor)],
        ])->assertStatus(201);

        return (int) DB::table($this->tableFqn('petty_cash_expenses'))->where('proveedor', $proveedor)->value('id');
    }

    public function test_primer_rechazo_de_linea_vuelve_a_pendientes_con_estado_temporal(): void
    {
        $this->ensureMarkIsApprover();
        $tokenFelix = $this->loginAsFelix();
        $felixId = $this->userIdByEmail(self::CONTABILIDAD_EMAIL);

        $idA = $this->crearGastoPendiente($tokenFelix, $felixId, 'Copy Centro El Dorado');
        $idB = $this->crearGastoPendiente($tokenFelix, $felixId, 'Ferretería Central');
        $reporte = $this->crearReporteConLineas($tokenFelix, [$idA, $idB]);

        $tokenMark = $this->loginAsMark();
        $response = $this->withHeaders($this->authHeaders($tokenMark))
            ->putJson("/api/admin-contab/petty-cash/expenses/{$idA}/reject", ['motivo' => 'Falta el recibo legible.']);

        $response->assertStatus(200)->assertJson(['destino' => 'pendientes', 'reporte_disuelto' => false]);

        $pending = $this->withHeaders($this->authHeaders($tokenFelix))
            ->getJson('/api/admin-contab/petty-cash/pending')->json();
        $linea = collect($pending['grupos'])->flatMap(fn ($g) => $g['lineas'])->firstWhere('id', $idA);
        $this->assertNotNull($linea);
        $this->assertSame('rechazado_temporal', $linea['estado']);
        $this->assertSame(1, $linea['intentos_rechazo']);

        // La otra línea del reporte sigue intacta, sin importar el rechazo de su compañera.
        $detalleReporte = $this->withHeaders($this->authHeaders($tokenFelix))
            ->getJson("/api/admin-contab/petty-cash/reports/{$reporte['numero']}")->json();
        $this->assertSame('pendiente_aprobacion', $detalleReporte['estado']);
    }

    public function test_segundo_rechazo_pasa_permanentemente_a_rechazados_y_disuelve_reporte_de_1_linea(): void
    {
        $this->ensureMarkIsApprover();
        $tokenFelix = $this->loginAsFelix();
        $felixId = $this->userIdByEmail(self::CONTABILIDAD_EMAIL);
        $tokenMark = $this->loginAsMark();

        $id = $this->crearGastoPendiente($tokenFelix, $felixId, 'Subway');
        DB::table($this->tableFqn('petty_cash_expenses'))->where('id', $id)->update(['intentos_rechazo' => 1]);

        $reporte = $this->crearReporteConLineas($tokenFelix, [$id]);

        $response = $this->withHeaders($this->authHeaders($tokenMark))
            ->putJson("/api/admin-contab/petty-cash/expenses/{$id}/reject", ['motivo' => 'El gasto no corresponde a la empresa.']);

        $response->assertStatus(200)->assertJson(['destino' => 'permanente', 'reporte_disuelto' => true]);

        $rejected = $this->withHeaders($this->authHeaders($tokenFelix))
            ->getJson('/api/admin-contab/petty-cash/rejected')->json();
        $this->assertTrue(collect($rejected['lineas'])->contains('id', $id));

        $this->assertSame(
            AdminContPettyCashReport::ESTADO_RECHAZADO,
            DB::table($this->tableFqn('petty_cash_reports'))->where('numero', $reporte['numero'])->value('estado'),
        );
    }

    /**
     * SCRUM-868 punto 2 — la pestaña "Rechazados" mostraba el Solicitante vacío: `expensePayload()`
     * (lista plana, sin agrupar por solicitante como Pendientes) nunca incluyó `solicitante_nombre`
     * ni `solicitante_id`, aunque el frontend ya los esperaba en la línea.
     */
    public function test_rejected_incluye_solicitante_nombre_e_id_en_cada_linea(): void
    {
        $this->ensureMarkIsApprover();
        $tokenFelix = $this->loginAsFelix();
        $felixId = $this->userIdByEmail(self::CONTABILIDAD_EMAIL);
        $tokenMark = $this->loginAsMark();

        $id = $this->crearGastoPendiente($tokenFelix, $felixId, 'Subway');
        DB::table($this->tableFqn('petty_cash_expenses'))->where('id', $id)->update(['intentos_rechazo' => 1]);
        $reporte = $this->crearReporteConLineas($tokenFelix, [$id]);

        $this->withHeaders($this->authHeaders($tokenMark))
            ->putJson("/api/admin-contab/petty-cash/expenses/{$id}/reject", ['motivo' => 'No corresponde.'])
            ->assertStatus(200);

        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');
        $contabilidad = DB::table("{$slug}_auth.users")->where('id', $felixId)->first();
        $nombreEsperado = trim("{$contabilidad->first_name} {$contabilidad->last_name}");

        $rejected = $this->withHeaders($this->authHeaders($tokenFelix))
            ->getJson('/api/admin-contab/petty-cash/rejected')->json();

        $linea = collect($rejected['lineas'])->firstWhere('id', $id);
        $this->assertNotNull($linea);
        $this->assertSame($felixId, $linea['solicitante_id']);
        $this->assertSame($nombreEsperado, $linea['solicitante_nombre']);
    }

    /**
     * Senior Review hallazgo #1 — `rejectExpense()` y `rejectReport()` deben bloquear en el MISMO
     * orden (reporte primero, línea(s) después) para que 2 rechazos concurrentes sobre el mismo
     * reporte nunca deadlockeen por bloquear en orden inverso. `RefreshDatabase` corre cada test
     * en una única conexión/transacción, así que no se puede simular concurrencia real de 2
     * conexiones acá — este test es la mejor aproximación práctica: ejercita ambos métodos, en
     * secuencia, sobre el mismo reporte (uno individual sobre una línea, el otro sobre el resto),
     * y confirma que ambos terminan en un estado consistente sin excepción — si el orden de locks
     * se rompiera de nuevo, el efecto no se vería en un test secuencial, pero al menos protege
     * contra una regresión de estado incorrecta en el camino feliz de ambos paths compartiendo
     * reporte.
     */
    public function test_reject_expense_y_reject_report_comparten_orden_de_locks_sobre_el_mismo_reporte(): void
    {
        $this->ensureMarkIsApprover();
        $tokenFelix = $this->loginAsFelix();
        $felixId = $this->userIdByEmail(self::CONTABILIDAD_EMAIL);
        $tokenMark = $this->loginAsMark();

        $idA = $this->crearGastoPendiente($tokenFelix, $felixId, 'Cafetería Manolo');
        $idB = $this->crearGastoPendiente($tokenFelix, $felixId, 'Farmacia Transporte');
        $reporte = $this->crearReporteConLineas($tokenFelix, [$idA, $idB]);

        // rejectExpense() primero — reporte pasa a tener solo la línea B.
        $this->withHeaders($this->authHeaders($tokenMark))
            ->putJson("/api/admin-contab/petty-cash/expenses/{$idA}/reject", ['motivo' => 'Falta soporte.'])
            ->assertStatus(200)
            ->assertJson(['reporte_disuelto' => false]);

        // rejectReport() después, sobre el mismo reporte — dispara el mismo camino de locks que
        // rejectExpense() acaba de tomar, ahora con la línea B como última.
        $this->withHeaders($this->authHeaders($tokenMark))
            ->putJson("/api/admin-contab/petty-cash/reports/{$reporte['numero']}/reject", ['motivo' => 'Se rechaza el resto también.'])
            ->assertStatus(200)
            ->assertJson(['a_pendientes' => 1, 'a_rechazados' => 0]);

        $this->assertSame(
            AdminContPettyCashReport::ESTADO_RECHAZADO,
            DB::table($this->tableFqn('petty_cash_reports'))->where('numero', $reporte['numero'])->value('estado'),
        );
        $this->assertSame(0, DB::table($this->tableFqn('petty_cash_expenses'))->where('report_id', DB::table($this->tableFqn('petty_cash_reports'))->where('numero', $reporte['numero'])->value('id'))->count());
    }

    public function test_rechazar_reporte_completo_reparte_lineas_entre_pendientes_y_rechazados(): void
    {
        $this->ensureMarkIsApprover();
        $tokenFelix = $this->loginAsFelix();
        $felixId = $this->userIdByEmail(self::CONTABILIDAD_EMAIL);
        $tokenMark = $this->loginAsMark();

        $idFresca = $this->crearGastoPendiente($tokenFelix, $felixId, 'Farmacia Transporte');
        $idYaRechazada = $this->crearGastoPendiente($tokenFelix, $felixId, 'Gasolinera Delta');
        DB::table($this->tableFqn('petty_cash_expenses'))->where('id', $idYaRechazada)->update(['intentos_rechazo' => 1]);

        $reporte = $this->crearReporteConLineas($tokenFelix, [$idFresca, $idYaRechazada]);

        $response = $this->withHeaders($this->authHeaders($tokenMark))
            ->putJson("/api/admin-contab/petty-cash/reports/{$reporte['numero']}/reject", ['motivo' => 'Ninguno de los 2 gastos está bien sustentado.']);

        $response->assertStatus(200)->assertJson(['a_pendientes' => 1, 'a_rechazados' => 1]);

        $this->assertSame(
            AdminContPettyCashReport::ESTADO_RECHAZADO,
            DB::table($this->tableFqn('petty_cash_reports'))->where('numero', $reporte['numero'])->value('estado'),
        );

        // Senior Review hallazgo #7 — no alcanza con contar cuántas líneas cayeron de cada lado,
        // hay que confirmar que fue la línea correcta la que aterrizó en cada bucket: la que tenía
        // 0 intentos previos a "pendientes" (ahora con 1), la que ya tenía 1 a "rechazados".
        $pending = $this->withHeaders($this->authHeaders($tokenFelix))
            ->getJson('/api/admin-contab/petty-cash/pending')->json();
        $lineaFresca = collect($pending['grupos'])->flatMap(fn ($g) => $g['lineas'])->firstWhere('id', $idFresca);
        $this->assertNotNull($lineaFresca, 'La línea que tenía 0 intentos debía volver a Pendientes.');
        $this->assertSame(1, $lineaFresca['intentos_rechazo']);

        $rejected = $this->withHeaders($this->authHeaders($tokenFelix))
            ->getJson('/api/admin-contab/petty-cash/rejected')->json();
        $lineaYaRechazada = collect($rejected['lineas'])->firstWhere('id', $idYaRechazada);
        $this->assertNotNull($lineaYaRechazada, 'La línea que ya tenía 1 intento debía pasar a Rechazados.');
        $this->assertSame(2, $lineaYaRechazada['intentos_rechazo']);

        // Y que ninguna terminó en el bucket del otro.
        $this->assertNull(collect($rejected['lineas'])->firstWhere('id', $idFresca));
        $this->assertFalse(collect($pending['grupos'])->flatMap(fn ($g) => $g['lineas'])->contains('id', $idYaRechazada));
    }

    /**
     * SCRUM-621 rebote — rejected() filtraba solo por intentos_rechazo, sin exigir report_id
     * NULL como sí hace locationOf() (usado por expenseDetail()/puede_reabrir). Bajo el flujo
     * normal de la app nunca diverge (applyRejection() siempre limpia report_id), pero forzamos
     * el estado inconsistente por DB directo para blindar el criterio: una línea que técnicamente
     * ya alcanzó el umbral pero sigue asociada a un reporte nunca debe listarse en "Rechazados".
     */
    public function test_rejected_no_incluye_lineas_que_siguen_asociadas_a_un_reporte(): void
    {
        $tokenFelix = $this->loginAsFelix();
        $felixId = $this->userIdByEmail(self::CONTABILIDAD_EMAIL);

        $id = $this->crearGastoPendiente($tokenFelix, $felixId, 'Copias Demo');
        $reporte = $this->crearReporteConLineas($tokenFelix, [$id]);
        DB::table($this->tableFqn('petty_cash_expenses'))->where('id', $id)->update(['intentos_rechazo' => 2]);

        $rejected = $this->withHeaders($this->authHeaders($tokenFelix))
            ->getJson('/api/admin-contab/petty-cash/rejected')->json();

        $this->assertNull(
            collect($rejected['lineas'])->firstWhere('id', $id),
            'Una línea con report_id activo no debe aparecer en Rechazados aunque su intentos_rechazo ya alcance el umbral.',
        );

        // Sanity check — el reporte creado sigue existiendo con la línea asociada.
        $this->assertNotNull($reporte['numero']);
    }

    public function test_reabrir_linea_rechazada_reinicia_contador_y_vuelve_a_pendientes(): void
    {
        $tokenFelix = $this->loginAsFelix();
        $felixId = $this->userIdByEmail(self::CONTABILIDAD_EMAIL);

        $id = $this->crearGastoPendiente($tokenFelix, $felixId, 'Estacionamiento Demo');
        DB::table($this->tableFqn('petty_cash_expenses'))->where('id', $id)->update(['intentos_rechazo' => 2]);

        $tokenYaneth = $this->loginAsYaneth();
        $this->withHeaders($this->authHeaders($tokenYaneth))
            ->putJson("/api/admin-contab/petty-cash/expenses/{$id}/reopen", ['motivo' => 'Ya se corrigió el problema original.'])
            ->assertStatus(200);

        $pending = $this->withHeaders($this->authHeaders($tokenFelix))
            ->getJson('/api/admin-contab/petty-cash/pending')->json();
        $linea = collect($pending['grupos'])->flatMap(fn ($g) => $g['lineas'])->firstWhere('id', $id);
        $this->assertNotNull($linea);
        $this->assertSame('reabierto', $linea['estado']);
        $this->assertSame(0, $linea['intentos_rechazo']);

        // Esta línea llegó a intentos_rechazo=2 vía UPDATE directo (fixture del test, no pasó por
        // applyRejection()), así que su historial real solo tiene la fila de esta reapertura.
        $this->assertSame(1, DB::table($this->tableFqn('petty_cash_expense_history'))->where('expense_id', $id)->count());
    }

    public function test_reabrir_es_de_contabilidad_asistente_no_de_mark(): void
    {
        $tokenFelix = $this->loginAsFelix();
        $felixId = $this->userIdByEmail(self::CONTABILIDAD_EMAIL);

        $id = $this->crearGastoPendiente($tokenFelix, $felixId, 'Copy Centro El Dorado');
        DB::table($this->tableFqn('petty_cash_expenses'))->where('id', $id)->update(['intentos_rechazo' => 2]);

        $tokenMark = $this->loginAsMark();
        $this->withHeaders($this->authHeaders($tokenMark))
            ->putJson("/api/admin-contab/petty-cash/expenses/{$id}/reopen", ['motivo' => 'Intento indebido.'])
            ->assertStatus(403);
    }

    public function test_rechazar_es_exclusivo_de_mark(): void
    {
        $this->ensureMarkIsApprover();
        $tokenFelix = $this->loginAsFelix();
        $felixId = $this->userIdByEmail(self::CONTABILIDAD_EMAIL);

        $id = $this->crearGastoPendiente($tokenFelix, $felixId, 'Ferretería Central');
        $this->crearReporteConLineas($tokenFelix, [$id]);

        $this->withHeaders($this->authHeaders($tokenFelix))
            ->putJson("/api/admin-contab/petty-cash/expenses/{$id}/reject", ['motivo' => 'Intento indebido.'])
            ->assertStatus(403);
    }

    public function test_editar_linea_bloqueado_dentro_de_reporte_permitido_en_pendientes_y_rechazados(): void
    {
        $this->ensureMarkIsApprover();
        $tokenFelix = $this->loginAsFelix();
        $felixId = $this->userIdByEmail(self::CONTABILIDAD_EMAIL);
        $tokenMark = $this->loginAsMark();

        $id = $this->crearGastoPendiente($tokenFelix, $felixId, 'Subway');
        $reporte = $this->crearReporteConLineas($tokenFelix, [$id]);

        $payload = ['fecha' => '2026-07-05', 'proveedor' => 'Subway Vía España', 'descripcion' => 'Corregido', 'monto_bruto' => 40, 'itbms' => 2.8];

        // Bloqueado mientras la línea está dentro de un reporte pendiente de aprobación.
        $this->withHeaders($this->authHeaders($tokenFelix))
            ->putJson("/api/admin-contab/petty-cash/expenses/{$id}", $payload)
            ->assertStatus(409);

        // Gerencia la rechaza — vuelve a Pendientes, ahora sí editable.
        $this->withHeaders($this->authHeaders($tokenMark))
            ->putJson("/api/admin-contab/petty-cash/expenses/{$id}/reject", ['motivo' => 'Monto no coincide con el recibo.'])
            ->assertStatus(200);

        $this->withHeaders($this->authHeaders($tokenFelix))
            ->putJson("/api/admin-contab/petty-cash/expenses/{$id}", $payload)
            ->assertStatus(200)
            ->assertJson(['proveedor' => 'Subway Vía España']);
    }

    public function test_agregar_soporte_bloqueado_en_reporte_finalizado_permitido_en_pendiente_de_aprobacion(): void
    {
        $this->ensureMarkIsApprover();
        $tokenFelix = $this->loginAsFelix();
        $felixId = $this->userIdByEmail(self::CONTABILIDAD_EMAIL);
        $tokenMark = $this->loginAsMark();

        $idPendienteAprobacion = $this->crearGastoPendiente($tokenFelix, $felixId, 'Farmacia Transporte');
        $this->crearReporteConLineas($tokenFelix, [$idPendienteAprobacion]);

        // RN2 — permitido mientras el reporte sigue pendiente de aprobación.
        $this->withHeaders($this->authHeaders($tokenFelix))
            ->post("/api/admin-contab/petty-cash/expenses/{$idPendienteAprobacion}/attachments", [
                'foto' => UploadedFile::fake()->image('soporte-extra.jpg'),
            ])
            ->assertStatus(200);

        $idFinalizado = $this->crearGastoPendiente($tokenFelix, $felixId, 'Cafetería Manolo');
        $reporteFinalizado = $this->crearReporteConLineas($tokenFelix, [$idFinalizado]);
        $this->withHeaders($this->authHeaders($tokenMark))
            ->putJson("/api/admin-contab/petty-cash/reports/{$reporteFinalizado['numero']}/approve")
            ->assertStatus(200);

        // RN3 — nunca en un reporte ya finalizado.
        $this->withHeaders($this->authHeaders($tokenFelix))
            ->post("/api/admin-contab/petty-cash/expenses/{$idFinalizado}/attachments", [
                'foto' => UploadedFile::fake()->image('soporte-tardio.jpg'),
            ])
            ->assertStatus(409);
    }

    public function test_detalle_unificado_refleja_los_3_contextos(): void
    {
        $this->ensureMarkIsApprover();
        $tokenFelix = $this->loginAsFelix();
        $tokenYaneth = $this->loginAsYaneth();
        $felixId = $this->userIdByEmail(self::CONTABILIDAD_EMAIL);

        $idPendiente = $this->crearGastoPendiente($tokenFelix, $felixId, 'Botiquín Transporte');
        $detallePendiente = $this->withHeaders($this->authHeaders($tokenFelix))
            ->getJson("/api/admin-contab/petty-cash/expenses/{$idPendiente}")->assertStatus(200)->json();
        $this->assertSame('pendientes', $detallePendiente['ubicacion']);
        $this->assertSame('pendiente', $detallePendiente['estado']);
        $this->assertTrue($detallePendiente['editable']);
        $this->assertFalse($detallePendiente['puede_rechazar']);

        $idEnReporte = $this->crearGastoPendiente($tokenFelix, $felixId, 'Estacionamiento Demo');
        $this->crearReporteConLineas($tokenFelix, [$idEnReporte]);
        $tokenMark = $this->loginAsMark();
        $detalleEnReporte = $this->withHeaders($this->authHeaders($tokenMark))
            ->getJson("/api/admin-contab/petty-cash/expenses/{$idEnReporte}")->assertStatus(200)->json();
        $this->assertSame('reporte', $detalleEnReporte['ubicacion']);
        $this->assertFalse($detalleEnReporte['editable']);
        $this->assertTrue($detalleEnReporte['puede_rechazar']);

        $idRechazado = $this->crearGastoPendiente($tokenFelix, $felixId, 'Copy Centro El Dorado');
        DB::table($this->tableFqn('petty_cash_expenses'))->where('id', $idRechazado)->update(['intentos_rechazo' => 2]);
        $detalleRechazado = $this->withHeaders($this->authHeaders($tokenYaneth))
            ->getJson("/api/admin-contab/petty-cash/expenses/{$idRechazado}")->assertStatus(200)->json();
        $this->assertSame('rechazados', $detalleRechazado['ubicacion']);
        // Senior Review hallazgo #3 — estado() no puede mentir 'pendiente' para una línea que ya
        // llegó al umbral de intentos.
        $this->assertSame('rechazado_permanente', $detalleRechazado['estado']);
        $this->assertTrue($detalleRechazado['editable']);
        $this->assertTrue($detalleRechazado['puede_reabrir']);

        // El mismo estado se expone también en el listado de la pestaña Rechazados, no solo en
        // el detalle unificado.
        $rejected = $this->withHeaders($this->authHeaders($tokenFelix))
            ->getJson('/api/admin-contab/petty-cash/rejected')->assertStatus(200)->json();
        $lineaEnListado = collect($rejected['lineas'])->firstWhere('id', $idRechazado);
        $this->assertNotNull($lineaEnListado);
        $this->assertSame('rechazado_permanente', $lineaEnListado['estado']);
    }

    /** Senior Review hallazgo #5 — si el umbral siguiera hardcodeado en `2`, bajarlo a 1 en
     *  settings no cambiaría nada de este test; con el fix, un solo rechazo ya manda la línea
     *  permanentemente a Rechazados. */
    public function test_umbral_de_intentos_es_configurable_no_hardcodeado(): void
    {
        $this->ensureMarkIsApprover();
        DB::table($this->tableFqn('fiscal_settings'))->where('id', 1)->update(['petty_cash_max_intentos_rechazo' => 1]);

        $tokenFelix = $this->loginAsFelix();
        $felixId = $this->userIdByEmail(self::CONTABILIDAD_EMAIL);
        $tokenMark = $this->loginAsMark();

        $id = $this->crearGastoPendiente($tokenFelix, $felixId, 'Ferretería Central');
        $this->crearReporteConLineas($tokenFelix, [$id]);

        $response = $this->withHeaders($this->authHeaders($tokenMark))
            ->putJson("/api/admin-contab/petty-cash/expenses/{$id}/reject", ['motivo' => 'No corresponde.']);

        // Con el umbral en 1, el PRIMER rechazo ya es el destino permanente — a diferencia de
        // todos los demás tests de este archivo, que corren con el default (2) y esperan
        // 'pendientes' en el primer rechazo.
        $response->assertStatus(200)->assertJson(['destino' => 'permanente']);

        $rejected = $this->withHeaders($this->authHeaders($tokenFelix))
            ->getJson('/api/admin-contab/petty-cash/rejected')->json();
        $linea = collect($rejected['lineas'])->firstWhere('id', $id);
        $this->assertNotNull($linea);
        $this->assertSame(1, $linea['intentos_rechazo']);

        // Reabrir también respeta el umbral configurado, no el literal 2.
        $tokenYaneth = $this->loginAsYaneth();
        $this->withHeaders($this->authHeaders($tokenYaneth))
            ->putJson("/api/admin-contab/petty-cash/expenses/{$id}/reopen", ['motivo' => 'Se corrigió.'])
            ->assertStatus(200);
    }

    public function test_descargar_pdf_de_reporte_rechazado_devuelve_409(): void
    {
        $this->ensureMarkIsApprover();
        $tokenFelix = $this->loginAsFelix();
        $felixId = $this->userIdByEmail(self::CONTABILIDAD_EMAIL);
        $tokenMark = $this->loginAsMark();

        $id = $this->crearGastoPendiente($tokenFelix, $felixId, 'Subway');
        $reporte = $this->crearReporteConLineas($tokenFelix, [$id]);

        $this->withHeaders($this->authHeaders($tokenMark))
            ->putJson("/api/admin-contab/petty-cash/reports/{$reporte['numero']}/reject", ['motivo' => 'No sustentado.'])
            ->assertStatus(200);

        $this->withHeaders($this->authHeaders($tokenFelix))
            ->getJson("/api/admin-contab/petty-cash/reports/{$reporte['numero']}/pdf")
            ->assertStatus(409);

        // El detalle (no el PDF) sigue respondiendo — grupos vacíos es el dato real, no un error.
        $detalle = $this->withHeaders($this->authHeaders($tokenFelix))
            ->getJson("/api/admin-contab/petty-cash/reports/{$reporte['numero']}")
            ->assertStatus(200)->json();
        $this->assertSame(AdminContPettyCashReport::ESTADO_RECHAZADO, $detalle['estado']);
        $this->assertCount(0, $detalle['grupos']);
    }
}
