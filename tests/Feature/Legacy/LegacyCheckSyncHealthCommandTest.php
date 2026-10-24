<?php

declare(strict_types=1);

namespace Tests\Feature\Legacy;

use App\Modules\Legacy\Models\SyncRun;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Facades\Log;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-839 — `legacy:check-sync-health`: alerta si una corrida esperada (según la ventana pasada
 * por --window-start/--window-end, para no depender de now() en el test) se saltó o falló.
 */
final class LegacyCheckSyncHealthCommandTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    private function createRun(string $entidad, string $status, string $startedAt, int $errores = 0): SyncRun
    {
        return SyncRun::query()->create([
            'entidad' => $entidad, 'origen' => SyncRun::ORIGEN_SCHEDULER,
            'extraidos' => 1, 'staged' => 1, 'mapeados' => 1, 'cargados' => 1,
            'excluidos' => 0, 'errores' => $errores, 'duracion_ms' => 10,
            'status' => $status, 'started_at' => $startedAt, 'finished_at' => $startedAt,
            'created_at' => $startedAt,
        ]);
    }

    public function test_entidad_sin_ninguna_corrida_nunca_alerta(): void
    {
        Log::shouldReceive('warning')->never();
        Log::spy();

        $this->artisan('legacy:check-sync-health', [
            '--tenant' => 'atlantic',
            '--window-start' => '2026-09-14T01:00:00',
            '--window-end' => '2026-09-14T02:00:00',
        ])->assertExitCode(0);

        Log::shouldNotHaveReceived('warning');
    }

    public function test_corrida_esperada_que_ocurrio_y_fue_success_no_alerta(): void
    {
        Log::spy();
        $this->createRun('productos', SyncRun::STATUS_SUCCESS, '2026-09-14 01:00:30');

        $this->artisan('legacy:check-sync-health', [
            '--tenant' => 'atlantic',
            '--window-start' => '2026-09-14T01:00:00',
            '--window-end' => '2026-09-14T02:00:00',
        ])->assertExitCode(0);

        Log::shouldNotHaveReceived('warning');
    }

    public function test_corrida_esperada_que_se_salto_si_alerta(): void
    {
        Log::spy();
        // Existe historial (no es la primera vez), pero NADA cae dentro de la ventana actual.
        $this->createRun('productos', SyncRun::STATUS_SUCCESS, '2026-09-07 01:00:30');

        $this->artisan('legacy:check-sync-health', [
            '--tenant' => 'atlantic',
            '--window-start' => '2026-09-14T01:00:00',
            '--window-end' => '2026-09-14T02:00:00',
        ])->assertExitCode(0);

        Log::shouldHaveReceived('warning')->once()->withArgs(
            fn (string $message, array $context) => $context['entidad'] === 'productos'
                && str_contains((string) $context['motivo'], 'se saltó'),
        );
    }

    public function test_corrida_que_ocurrio_pero_fallo_si_alerta(): void
    {
        Log::spy();
        $this->createRun('productos', SyncRun::STATUS_FAILED, '2026-09-14 01:00:30', errores: 1);

        $this->artisan('legacy:check-sync-health', [
            '--tenant' => 'atlantic',
            '--window-start' => '2026-09-14T01:00:00',
            '--window-end' => '2026-09-14T02:00:00',
        ])->assertExitCode(0);

        Log::shouldHaveReceived('warning')->once()->withArgs(
            fn (string $message, array $context) => $context['entidad'] === 'productos',
        );
    }

    public function test_corrida_held_for_review_no_se_cuenta_como_saltada(): void
    {
        Log::spy();
        $this->createRun('productos', SyncRun::STATUS_HELD_FOR_REVIEW, '2026-09-14 01:00:30');

        $this->artisan('legacy:check-sync-health', [
            '--tenant' => 'atlantic',
            '--window-start' => '2026-09-14T01:00:00',
            '--window-end' => '2026-09-14T02:00:00',
        ])->assertExitCode(0);

        Log::shouldNotHaveReceived('warning');
    }

    public function test_tenant_inexistente_falla(): void
    {
        $this->artisan('legacy:check-sync-health', ['--tenant' => 'no-existe'])->assertExitCode(1);
    }

    /**
     * Pre-QA 2026-09-14 — la ventana se documenta como semiabierta [start, end). Antes del fix,
     * `whereBetween()` generaba un BETWEEN inclusive en ambos extremos, así que una corrida con
     * started_at EXACTAMENTE en windowEnd contaba como "dentro" — divergía del comportamiento
     * documentado (sin impacto real dada la cadencia de 12h/semanal del cron, pero corregido para
     * que el código haga lo que dice el comentario).
     */
    public function test_corrida_exactamente_en_el_limite_superior_de_la_ventana_no_cuenta_como_dentro(): void
    {
        Log::spy();
        $this->createRun('productos', SyncRun::STATUS_SUCCESS, '2026-09-14 02:00:00');

        $this->artisan('legacy:check-sync-health', [
            '--tenant' => 'atlantic',
            '--window-start' => '2026-09-14T01:00:00',
            '--window-end' => '2026-09-14T02:00:00',
        ])->assertExitCode(0);

        Log::shouldHaveReceived('warning')->once()->withArgs(
            fn (string $message, array $context) => $context['entidad'] === 'productos'
                && str_contains((string) $context['motivo'], 'se saltó'),
        );
    }

    public function test_corrida_exactamente_en_el_limite_inferior_de_la_ventana_cuenta_como_dentro(): void
    {
        Log::spy();
        $this->createRun('productos', SyncRun::STATUS_SUCCESS, '2026-09-14 01:00:00');

        $this->artisan('legacy:check-sync-health', [
            '--tenant' => 'atlantic',
            '--window-start' => '2026-09-14T01:00:00',
            '--window-end' => '2026-09-14T02:00:00',
        ])->assertExitCode(0);

        Log::shouldNotHaveReceived('warning');
    }

    /** El criterio de aceptación dice "errores>0 alerta sin importar el status exacto". */
    public function test_status_partial_con_errores_mayor_a_cero_alerta(): void
    {
        Log::spy();
        $this->createRun('productos', SyncRun::STATUS_PARTIAL, '2026-09-14 01:00:30', errores: 2);

        $this->artisan('legacy:check-sync-health', [
            '--tenant' => 'atlantic',
            '--window-start' => '2026-09-14T01:00:00',
            '--window-end' => '2026-09-14T02:00:00',
        ])->assertExitCode(0);

        Log::shouldHaveReceived('warning')->once()->withArgs(
            fn (string $message, array $context) => $context['entidad'] === 'productos',
        );
    }

    public function test_solo_window_start_sin_window_end_cae_al_calculo_automatico(): void
    {
        $this->artisan('legacy:check-sync-health', [
            '--tenant' => 'atlantic',
            '--window-start' => '2026-09-14T01:00:00',
        ])->assertExitCode(0);
    }

    // ── SCRUM-1114 — entidad `stock` ─────────────────────────────────────────

    private function chequear(): void
    {
        $this->artisan('legacy:check-sync-health', [
            '--tenant' => 'atlantic',
            '--window-start' => '2026-09-14T01:00:00',
            '--window-end' => '2026-09-14T02:00:00',
        ])->assertExitCode(0);
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    public function test_stock_activo_sin_corrida_en_la_ventana_alerta_por_correo(): void
    {
        \App\Modules\Legacy\Models\StockSyncSettings::current()->update(['activo' => true, 'baseline_inicializada_at' => now()]);
        $this->createRun('stock', SyncRun::STATUS_SUCCESS, '2026-09-07 01:00:30'); // corrió antes, no en esta ventana

        $this->chequear();

        \Illuminate\Support\Facades\Mail::assertSent(\App\Modules\Legacy\Mail\LegacySyncAlertMail::class, fn ($m): bool => $m->entidad === 'stock' && str_contains($m->titulo, 'saltada'));
    }

    public function test_stock_apagado_no_se_considera_corrida_saltada(): void
    {
        $this->createRun('stock', SyncRun::STATUS_SUCCESS, '2026-09-07 01:00:30');

        $this->chequear(); // activo = false (default)

        \Illuminate\Support\Facades\Mail::assertNothingSent();
    }

    public function test_stock_apagado_en_produccion_pasado_el_periodo_de_gracia_avisa(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');
        \App\Modules\Legacy\Models\StockSyncSettings::current()->update(['activo' => false, 'baseline_inicializada_at' => now()]);
        $this->createRun('stock', SyncRun::STATUS_SUCCESS, now()->subDays(3)->toDateTimeString());

        $this->chequear();

        \Illuminate\Support\Facades\Mail::assertSent(\App\Modules\Legacy\Mail\LegacySyncAlertMail::class, fn ($m): bool => $m->entidad === 'stock' && str_contains($m->titulo, 'desactivada'));
    }

    public function test_stock_apagado_en_produccion_dentro_del_periodo_de_gracia_no_avisa(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');
        \App\Modules\Legacy\Models\StockSyncSettings::current()->update(['activo' => false, 'baseline_inicializada_at' => now()]);
        $this->createRun('stock', SyncRun::STATUS_SUCCESS, now()->subHours(5)->toDateTimeString());

        $this->chequear();

        \Illuminate\Support\Facades\Mail::assertNothingSent();
    }
}
