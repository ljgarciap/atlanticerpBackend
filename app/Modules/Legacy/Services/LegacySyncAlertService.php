<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Services;

use App\Models\User;
use App\Modules\Auth\Models\Role;
use App\Modules\Legacy\Mail\LegacySyncAlertMail;
use App\Modules\Legacy\Models\StockSyncSettings;
use App\Modules\Legacy\Models\SyncRun;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * SCRUM-839 — punto único de publicación de alertas/digest de la migración ICG. Interino: publica
 * a `Log::warning`/`Log::info` (visible en CloudWatch vía el agente ya existente, mismo log group
 * que laravel.log) en vez de al SNS `atlanticerp-alerts` real — la app hoy no tiene cliente SNS ni
 * permiso IAM `sns:Publish` (mismo gap ya documentado en SCRUM-838 para el circuit breaker de cada
 * comando incremental). Cuando se apruebe el permiso IAM y se agregue el SDK, el cambio real es
 * SOLO en esta clase — ningún call site (9 comandos incrementales + este chequeo) necesita tocarse.
 *
 * SCRUM-1114 (2026-10-06) — canal de CORREO agregado: cada aviso (`alertSkippedOrFailed` y los nuevos
 * `alertHeldForReview`/`alertFailed`/`alertDisabled`) sigue escribiendo al log Y se envía por correo a
 * los superadmins activos más la lista adicional `legacy.stock_sync_settings.correos_alerta`, con un
 * solo correo por entidad+tipo+día (sin spam si el chequeo se repite). Un fallo de envío se registra y
 * se traga: avisar nunca debe tumbar la corrida ni el chequeo. SNS/teléfono siguen pendientes (la app
 * no tiene cliente SNS ni `sns:Publish`).
 *
 * Reemplaza el `Log::warning()` inline que cada comando incremental de SCRUM-838 duplicaba para su
 * propio circuit breaker — no se retrofitea acá para no ampliar el alcance de este ticket, pero
 * cualquier comando nuevo debería llamar a este servicio, no repetir el patrón inline.
 */
final class LegacySyncAlertService
{
    /**
     * SCRUM-839 — corrida esperada que se saltó (sin fila en la ventana del slot) o falló
     * (status=failed o errores>0). Nunca se llama para held_for_review — eso ya lo alerta el
     * circuit breaker del comando incremental correspondiente (SCRUM-838), evita duplicar aviso
     * por el mismo evento.
     */
    public function alertSkippedOrFailed(
        string $entidad,
        string $motivo,
        ?string $ultimaCorridaExitosa,
        string $comandoManual,
    ): void {
        Log::warning('legacy:check-sync-health — corrida saltada o fallida', [
            'entidad'                 => $entidad,
            'motivo'                  => $motivo,
            'ultima_corrida_exitosa'  => $ultimaCorridaExitosa,
            'comando_manual'          => $comandoManual,
        ]);

        $this->notify($entidad, 'saltada_o_fallida', 'Corrida saltada o fallida', [
            "Motivo: {$motivo}",
            'Última corrida exitosa: ' . ($ultimaCorridaExitosa ?? 'nunca'),
            "Comando manual: {$comandoManual}",
        ]);
    }

    /** SCRUM-1114 — corrida retenida por el circuit breaker: no aplicó nada y espera revisión manual. */
    public function alertHeldForReview(string $entidad, string $motivo, string $comandoManual): void
    {
        Log::warning("legacy:sync-incremental-{$entidad} — corrida retenida por el circuit breaker", [
            'entidad' => $entidad, 'motivo' => $motivo, 'comando_manual' => $comandoManual,
        ]);

        $this->notify($entidad, 'retenida', 'Corrida retenida por el circuit breaker (no se aplicó nada)', [
            "Motivo: {$motivo}",
            "Para aplicarla tras revisar: {$comandoManual}",
        ]);
    }

    /** SCRUM-1114 — la corrida terminó en `failed` o con errores. */
    public function alertFailed(string $entidad, string $motivo): void
    {
        Log::error("legacy:sync-incremental-{$entidad} — corrida fallida", ['entidad' => $entidad, 'motivo' => $motivo]);

        $this->notify($entidad, 'fallida', 'Corrida fallida', ["Motivo: {$motivo}"]);
    }

    /** SCRUM-1114 — el sync de una entidad lleva demasiado tiempo apagado en producción. */
    public function alertDisabled(string $entidad, string $motivo): void
    {
        Log::warning("legacy:check-sync-health — sync de {$entidad} desactivado", ['entidad' => $entidad, 'motivo' => $motivo]);

        $this->notify($entidad, 'desactivado', 'Sincronización desactivada', [$motivo]);
    }

    /**
     * SCRUM-1114 (revisión de seguridad S-02/S-04) — evento operativo que SIEMPRE debe notificarse y nunca se
     * deduplica: un cambio del interruptor o de un umbral, o una corrida/reversa/línea base lanzada a mano
     * (con `--force`, `--apply` u `--operador`). Quien alterara el stock a su gusto dejaría rastro en el correo.
     *
     * @param list<string> $lineas
     */
    public function alertEvento(string $entidad, string $titulo, array $lineas): void
    {
        Log::warning("legacy-sync-evento: {$titulo}", ['entidad' => $entidad, 'lineas' => $lineas]);
        $this->enviar($entidad, $titulo, $lineas);
    }

    /** Recorta y aplana un mensaje de excepción antes de ponerlo en un correo, un resumen o una respuesta (S-07). */
    public static function sanitizar(string $mensaje): string
    {
        return mb_substr((string) preg_replace('/\s+/u', ' ', $mensaje), 0, 300);
    }

    /**
     * Log + correo con deduplicación (una vez por entidad, tipo y día). La clave de deduplicación se marca
     * DESPUÉS de un envío exitoso (S-05): si el SMTP falla, el siguiente intento del día vuelve a probar en vez
     * de quedar silenciado. El correo nunca propaga una excepción.
     *
     * @param list<string> $lineas
     */
    private function notify(string $entidad, string $tipo, string $titulo, array $lineas): void
    {
        $clave = "legacy-sync-alert:{$entidad}:{$tipo}:" . now()->toDateString();
        try {
            if (Cache::has($clave)) {
                return;
            }
        } catch (Throwable) {
            // Caché caído: se envía igual (mejor un aviso repetido que ninguno).
        }

        if ($this->enviar($entidad, $titulo, $lineas)) {
            try {
                Cache::put($clave, 1, now()->addHours(20));
            } catch (Throwable) {
                // Sin caché el dedupe se pierde; el aviso ya salió.
            }
        }
    }

    /**
     * Un correo individual por destinatario (S-03: `Mail::to(lista)` dejaría a todos visibles entre sí).
     * Devuelve true si salió al menos uno.
     *
     * @param list<string> $lineas
     */
    private function enviar(string $entidad, string $titulo, array $lineas): bool
    {
        try {
            $destinatarios = $this->destinatarios();
            if ($destinatarios === []) {
                Log::warning('legacy-sync-alert.sin_destinatarios', ['entidad' => $entidad, 'titulo' => $titulo]);

                return false;
            }

            $enviados = 0;
            foreach ($destinatarios as $correo) {
                try {
                    Mail::to($correo)->send(new LegacySyncAlertMail($entidad, $titulo, $lineas, (string) config('app.env')));
                    $enviados++;
                } catch (Throwable $e) {
                    Log::error('legacy-sync-alert.envio_fallido', ['entidad' => $entidad, 'error' => self::sanitizar($e->getMessage())]);
                }
            }

            return $enviados > 0;
        } catch (Throwable $e) {
            Log::error('legacy-sync-alert.envio_fallido', ['entidad' => $entidad, 'error' => self::sanitizar($e->getMessage())]);

            return false;
        }
    }

    /** @return list<string> superadmins activos + `correos_alerta` configurados, sin duplicados */
    private function destinatarios(): array
    {
        /** @var list<string> $correos */
        $correos = User::query()
            ->where('is_active', true)
            ->withRoleKey([Role::KEY_SUPERADMIN])
            ->pluck('email')
            ->map(static fn (mixed $e): string => strtolower(trim((string) $e)))
            ->all();

        try {
            foreach (StockSyncSettings::current()->correos_alerta as $extra) {
                $correos[] = strtolower(trim((string) $extra));
            }
        } catch (Throwable) {
            // Sin tenant actual o sin la tabla todavía: se avisa solo a los superadmins.
        }

        return array_values(array_unique(array_filter($correos, static fn (string $e): bool => $e !== '')));
    }

    /**
     * SCRUM-839 — resumen informativo de una corrida programada exitosa (status=success), para
     * recuperar algo de la visibilidad que el --dry-run manual daba antes de automatizar todo.
     * Nunca es una alerta de error — se llama solo desde el branch status=success de cada comando
     * incremental, nunca desde partial/failed/held_for_review. Reusa los mismos números que ya
     * quedan en `legacy.sync_runs` (extraidos/staged/mapeados/cargados/excluidos) — el ticket pide
     * exactamente esto, no un cálculo nuevo: "los mismos números que ya calcula sync_runs, solo
     * que hoy nadie los ve fuera de una consulta manual a Postgres".
     *
     * Un mensaje por corrida (no consolidado por slot) — cada entidad corre como un proceso
     * `php artisan` independiente disparado por el scheduler, sin estado compartido entre sí;
     * consolidar por slot requeriría coordinación entre 9 procesos separados, que no se justifica
     * frente a simplemente publicar 9 mensajes cortos.
     */
    public function publishDigest(SyncRun $syncRun): void
    {
        Log::info('legacy:sync-incremental — digest de corrida', [
            'entidad'   => $syncRun->entidad,
            'origen'    => $syncRun->origen,
            'extraidos' => $syncRun->extraidos,
            'staged'    => $syncRun->staged,
            'mapeados'  => $syncRun->mapeados,
            'cargados'  => $syncRun->cargados,
            'excluidos' => $syncRun->excluidos,
        ]);
    }
}
