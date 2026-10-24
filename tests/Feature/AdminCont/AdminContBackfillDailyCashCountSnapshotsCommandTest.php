<?php

declare(strict_types=1);

namespace Tests\Feature\AdminCont;

use App\Modules\AdminCont\Models\AdminContBankMovement;
use App\Modules\AdminCont\Models\AdminContDailyCashCount;
use App\Modules\AdminCont\Models\AdminContInvoice;
use App\Modules\AdminCont\Models\AdminContPayment;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Feature\FeatureTestCase;

/**
 * Pre-QA SCRUM-878/879 (2026-09-10) — `admin-contab:backfill-daily-cash-count-snapshots` congela
 * el total de un arqueo LEGADO (cerrado antes de que existiera `daily_cash_counts.snapshot`)
 * reconstruido a `cerrado_at`, no al valor de hoy — es exactamente el caso real encontrado en dev
 * (arqueo del 2026-08-25, comisión backfillada después del cierre).
 *
 * SCRUM-937 (PQ-934-2, hallazgo de Pre-QA de SCRUM-934) — mismo comando, segundo caso: arqueos que
 * ya tienen `snapshot` pero le faltan `total_facturado_dia`/`total_pasado_a_pedido_dia` (SCRUM-934
 * se agregó después de que esos arqueos ya estaban cerrados).
 */
class AdminContBackfillDailyCashCountSnapshotsCommandTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    public function test_reconstruye_el_snapshot_a_la_fecha_de_cierre_sin_el_movimiento_retroactivo(): void
    {
        $fecha    = now()->subDays(5)->toDateString();
        $cerradoAt = now()->subDays(4);

        $payment = AdminContPayment::create([
            'numero_recibo' => 'REC-9020', 'master_client_id' => 1, 'monto_recibido' => 500, 'saldo_favor_aplicado' => 0,
            'metodo_pago' => AdminContPayment::METODO_EFECTIVO, 'estado' => AdminContPayment::ESTADO_CONFIRMADO,
        ]);
        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');
        DB::table("{$slug}_admin_contab.payments")->where('id', $payment->id)->update(['created_at' => $fecha.' 10:00:00']);

        // legado: cerrado ANTES de que existiera el snapshot — igual que cualquier daily_cash_count
        // pre-2026-09-11.
        $record = AdminContDailyCashCount::create([
            'fecha' => $fecha, 'numero' => 77, 'estado' => AdminContDailyCashCount::ESTADO_PENDIENTE_APROBACION,
            'cerrado_por' => 1, 'cerrado_at' => $cerradoAt,
        ]);

        // comisión fechada el mismo día, pero creada DESPUÉS del cierre — el escenario real del
        // backfill de SCRUM-879, no debe entrar al snapshot reconstruido.
        $movement = AdminContBankMovement::create([
            'bank_account_id' => null, 'fecha' => $fecha, 'tipo' => AdminContBankMovement::TIPO_COMISION,
            'concepto' => 'Comisión arquitecto Arquitecto 12 — PED-2026-0070', 'monto' => 845.81,
            'source_type' => AdminContBankMovement::SOURCE_ARCHITECT_COMMISSION, 'source_id' => 1,
        ]);
        DB::table("{$slug}_admin_contab.bank_movements")->where('id', $movement->id)->update(['created_at' => now()]);

        $this->artisan('admin-contab:backfill-daily-cash-count-snapshots', ['--tenant' => 'atlantic'])
            ->assertExitCode(0);

        $record->refresh();
        $this->assertNotNull($record->snapshot);
        $this->assertEquals(500.0, $record->snapshot['total_cobrado']);
        $this->assertEquals(0.0, $record->snapshot['total_comisiones']);
        $this->assertEmpty($record->snapshot['comisiones']);
        $this->assertEquals(500.0, $record->snapshot['total_neto']);
    }

    public function test_dry_run_no_escribe_nada(): void
    {
        $record = AdminContDailyCashCount::create([
            'fecha' => now()->subDays(3)->toDateString(), 'numero' => 78,
            'estado' => AdminContDailyCashCount::ESTADO_APROBADO, 'cerrado_por' => 1, 'cerrado_at' => now()->subDays(2),
        ]);

        $this->artisan('admin-contab:backfill-daily-cash-count-snapshots', ['--tenant' => 'atlantic', '--dry-run' => true])
            ->assertExitCode(0);

        $this->assertNull($record->refresh()->snapshot);
    }

    // SCRUM-937 — un snapshot ya congelado ANTES de SCRUM-934 no trae los 2 indicadores nuevos; el
    // backfill los completa sin tocar el resto de lo ya congelado (no es el mismo caso de
    // "nunca se congeló" de arriba).
    public function test_completa_indicadores_de_snapshot_existente_sin_tocar_el_resto(): void
    {
        $fecha     = now()->subDays(5)->toDateString();
        $cerradoAt = now()->subDays(4);

        $existing = ['fecha' => $fecha, 'cobros' => [], 'notas_credito' => [], 'comisiones' => [], 'total_cobrado' => 1.0, 'total_notas_credito' => 0.0, 'total_comisiones' => 0.0, 'total_neto' => 1.0, 'observacion_general' => null];
        $record   = AdminContDailyCashCount::create([
            'fecha' => $fecha, 'numero' => 79,
            'estado' => AdminContDailyCashCount::ESTADO_APROBADO, 'cerrado_por' => 1, 'cerrado_at' => $cerradoAt,
            'snapshot' => $existing,
        ]);

        $invoice = AdminContInvoice::create(['numero' => 'F-9937', 'order_id' => 993700, 'cliente' => 'Cliente SCRUM-937', 'monto' => 600]);
        $slug    = (string) Tenant::where('slug', 'atlantic')->value('slug');
        DB::table("{$slug}_admin_contab.invoices")->where('id', $invoice->id)->update(['created_at' => $fecha.' 09:00:00']);

        $this->artisan('admin-contab:backfill-daily-cash-count-snapshots', ['--tenant' => 'atlantic'])
            ->assertExitCode(0);

        $snapshot = $record->refresh()->snapshot;
        $this->assertEquals(600.0, $snapshot['total_facturado_dia']);
        $this->assertEquals(0.0, $snapshot['total_pasado_a_pedido_dia']);
        // el resto del snapshot ya congelado no se toca
        $this->assertEquals(1.0, $snapshot['total_neto']);
        $this->assertEquals(1.0, $snapshot['total_cobrado']);
    }

    public function test_no_toca_una_fila_con_snapshot_que_ya_tiene_los_indicadores(): void
    {
        $existing = [
            'fecha' => '2026-01-01', 'cobros' => [], 'notas_credito' => [], 'comisiones' => [],
            'total_cobrado' => 1.0, 'total_notas_credito' => 0.0, 'total_comisiones' => 0.0, 'total_neto' => 1.0,
            'total_facturado_dia' => 500.0, 'total_pasado_a_pedido_dia' => 200.0, 'observacion_general' => null,
        ];
        $record = AdminContDailyCashCount::create([
            'fecha' => now()->subDays(3)->toDateString(), 'numero' => 80,
            'estado' => AdminContDailyCashCount::ESTADO_APROBADO, 'cerrado_por' => 1, 'cerrado_at' => now()->subDays(2),
            'snapshot' => $existing,
        ]);

        $this->artisan('admin-contab:backfill-daily-cash-count-snapshots', ['--tenant' => 'atlantic'])
            ->assertExitCode(0);

        $this->assertEquals($existing, $record->refresh()->snapshot);
    }

    public function test_dry_run_no_completa_indicadores_de_snapshot_existente(): void
    {
        $fecha    = now()->subDays(5)->toDateString();
        $existing = ['fecha' => $fecha, 'cobros' => [], 'notas_credito' => [], 'comisiones' => [], 'total_cobrado' => 1.0, 'total_notas_credito' => 0.0, 'total_comisiones' => 0.0, 'total_neto' => 1.0, 'observacion_general' => null];
        $record   = AdminContDailyCashCount::create([
            'fecha' => $fecha, 'numero' => 81,
            'estado' => AdminContDailyCashCount::ESTADO_APROBADO, 'cerrado_por' => 1, 'cerrado_at' => now()->subDays(4),
            'snapshot' => $existing,
        ]);

        $this->artisan('admin-contab:backfill-daily-cash-count-snapshots', ['--tenant' => 'atlantic', '--dry-run' => true])
            ->assertExitCode(0);

        $this->assertEquals($existing, $record->refresh()->snapshot);
    }

    // Criterio de aceptación central del ticket: una vez completado el snapshot, anular una
    // factura de un día ya cerrado no debe cambiar el arqueo — antes de este backfill,
    // DailyCashCountService::totalsFor() recalculaba en vivo cuando faltaba la clave, y ese
    // recálculo filtra por `anulada_at IS NULL` con el estado ACTUAL de la factura, no el estado
    // al momento del cierre (reproducido en Pre-QA: 20 pasó a 0).
    public function test_snapshot_completado_no_cambia_si_se_anula_una_factura_despues(): void
    {
        $fecha     = now()->subDays(5)->toDateString();
        $cerradoAt = now()->subDays(4);

        $existing = ['fecha' => $fecha, 'cobros' => [], 'notas_credito' => [], 'comisiones' => [], 'total_cobrado' => 1.0, 'total_notas_credito' => 0.0, 'total_comisiones' => 0.0, 'total_neto' => 1.0, 'observacion_general' => null];
        $record   = AdminContDailyCashCount::create([
            'fecha' => $fecha, 'numero' => 82,
            'estado' => AdminContDailyCashCount::ESTADO_APROBADO, 'cerrado_por' => 1, 'cerrado_at' => $cerradoAt,
            'snapshot' => $existing,
        ]);

        $invoice = AdminContInvoice::create(['numero' => 'F-9938', 'order_id' => 993800, 'cliente' => 'Cliente SCRUM-937-b', 'monto' => 1200]);
        $slug    = (string) Tenant::where('slug', 'atlantic')->value('slug');
        DB::table("{$slug}_admin_contab.invoices")->where('id', $invoice->id)->update(['created_at' => $fecha.' 09:00:00']);

        $this->artisan('admin-contab:backfill-daily-cash-count-snapshots', ['--tenant' => 'atlantic'])
            ->assertExitCode(0);

        $this->assertEquals(1200.0, $record->refresh()->snapshot['total_facturado_dia']);

        // se anula la factura DESPUÉS de correr el backfill — como en el caso real reportado
        $invoice->update(['anulada_at' => now()]);

        $this->assertEquals(1200.0, $record->refresh()->snapshot['total_facturado_dia']);
    }
}
