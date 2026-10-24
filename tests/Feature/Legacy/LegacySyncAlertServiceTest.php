<?php

declare(strict_types=1);

namespace Tests\Feature\Legacy;

use App\Modules\Legacy\Mail\LegacySyncAlertMail;
use App\Modules\Legacy\Models\StockSyncSettings;
use App\Modules\Legacy\Models\SyncRun;
use App\Modules\Legacy\Services\LegacySyncAlertService;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Testing\Fakes\MailFake;
use Tests\Feature\FeatureTestCase;

/** SCRUM-1114 — canal de correo del `LegacySyncAlertService` (nunca correos reales: `Mail::fake()`). */
final class LegacySyncAlertServiceTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    /** Cuántos correos recibió el superadmin sembrado (los demás superadmins reciben el suyo, uno por destinatario). */
    private function recibidos(?string $entidad = null): int
    {
        return Mail::sent(LegacySyncAlertMail::class, fn (LegacySyncAlertMail $m): bool => $m->hasTo('superadmin@atlantic.test') && ($entidad === null || $m->entidad === $entidad))->count();
    }

    public function test_la_corrida_retenida_avisa_a_los_superadmins_activos(): void
    {
        app(LegacySyncAlertService::class)->alertHeldForReview('stock', 'motivo de prueba', 'cmd manual');

        Mail::assertSent(LegacySyncAlertMail::class, function (LegacySyncAlertMail $m): bool {
            return $m->hasTo('superadmin@atlantic.test') && $m->entidad === 'stock' && in_array('Motivo: motivo de prueba', $m->lineas, true);
        });
    }

    public function test_agrega_los_correos_adicionales_en_correos_individuales_sin_duplicar(): void
    {
        StockSyncSettings::current()->update(['correos_alerta' => ['Ops@Atlantic.com.pa', 'superadmin@atlantic.test']]);

        app(LegacySyncAlertService::class)->alertFailed('stock', 'boom');

        Mail::assertSent(LegacySyncAlertMail::class, fn (LegacySyncAlertMail $m): bool => $m->hasTo('ops@atlantic.com.pa'));
        // Un correo por destinatario (nadie ve a los demás) y sin repetir al superadmin que también estaba en la lista.
        Mail::assertSent(LegacySyncAlertMail::class, fn (LegacySyncAlertMail $m): bool => count($m->to) === 1);
        $this->assertSame(1, $this->recibidos());
    }

    public function test_si_el_envio_falla_la_deduplicacion_no_silencia_el_siguiente_intento(): void
    {
        $fake = new class(new \Illuminate\Mail\MailManager($this->app)) extends MailFake {
            public bool $falla = true;

            public function to($users)
            {
                if ($this->falla) {
                    throw new \RuntimeException('SMTP caído');
                }

                return parent::to($users);
            }
        };
        Mail::swap($fake);

        app(LegacySyncAlertService::class)->alertFailed('stock', 'primera'); // falla: no debe marcar el dedupe
        $fake->falla = false;                                                 // el SMTP "volvió"
        app(LegacySyncAlertService::class)->alertFailed('stock', 'segunda');

        $this->assertSame(1, $this->recibidos('stock'));
    }

    public function test_los_eventos_operativos_nunca_se_deduplican(): void
    {
        $alerts = app(LegacySyncAlertService::class);

        $alerts->alertEvento('stock', 'Ajustes modificados', ['a']);
        $alerts->alertEvento('stock', 'Ajustes modificados', ['b']);

        $this->assertSame(2, $this->recibidos('stock'));
    }

    public function test_los_mensajes_de_excepcion_se_aplanan_y_recortan(): void
    {
        $largo = "linea1\nlinea2 " . str_repeat('x', 500);

        $limpio = LegacySyncAlertService::sanitizar($largo);

        $this->assertStringNotContainsString("\n", $limpio);
        $this->assertSame(300, mb_strlen($limpio));
    }

    public function test_un_solo_correo_por_entidad_tipo_y_dia(): void
    {
        $alerts = app(LegacySyncAlertService::class);

        $alerts->alertFailed('stock', 'primera');
        $alerts->alertFailed('stock', 'segunda');
        $alerts->alertFailed('productos', 'otra entidad');

        $this->assertSame(1, $this->recibidos('stock'));
        $this->assertSame(1, $this->recibidos('productos'));
    }

    public function test_la_alerta_de_corrida_saltada_de_las_entidades_existentes_tambien_envia_correo(): void
    {
        app(LegacySyncAlertService::class)->alertSkippedOrFailed('pagos', 'se saltó', null, 'infra/legacy-sync.sh <entorno> --pagos');

        Mail::assertSent(LegacySyncAlertMail::class, fn (LegacySyncAlertMail $m): bool => $m->entidad === 'pagos' && str_contains($m->titulo, 'saltada'));
    }

    public function test_un_fallo_de_envio_nunca_rompe_a_quien_avisa(): void
    {
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('SMTP caído'));

        app(LegacySyncAlertService::class)->alertFailed('stock', 'boom');

        $this->addToAssertionCount(1); // llegó hasta acá sin excepción
    }

    public function test_el_digest_de_una_corrida_exitosa_no_envia_correo(): void
    {
        $run = SyncRun::query()->create([
            'entidad' => 'stock', 'origen' => SyncRun::ORIGEN_SCHEDULER, 'extraidos' => 1, 'staged' => 1, 'mapeados' => 0,
            'cargados' => 0, 'excluidos' => 0, 'errores' => 0, 'duracion_ms' => 1, 'status' => SyncRun::STATUS_SUCCESS,
            'started_at' => now(), 'finished_at' => now(), 'created_at' => now(),
        ]);

        app(LegacySyncAlertService::class)->publishDigest($run);

        Mail::assertNothingSent();
    }
}
