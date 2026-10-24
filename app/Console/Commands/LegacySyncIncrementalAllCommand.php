<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

/**
 * SCRUM-838 — CORREGIDO 2026-09-14/15, hallazgo real durante la primera corrida manual en
 * dev.atlanticerp.ai: el diseño original agendaba las 10 entidades como 9 entradas independientes de
 * `Schedule::` con offsets de minutos (:00/:05/:07/:15/:17/:20/:25/:30/:32) para que el scheduler
 * nunca las DISPARE en el mismo tick — pero `withoutOverlapping()` de Laravel solo evita que un
 * comando se superponga CONSIGO MISMO, nunca con un comando distinto. El bootstrap real de
 * Cotizaciones (primera corrida contra ICG real, ~95k cabeceras + ~715k líneas) tardó 48m17s —
 * muy por encima de los 2 minutos de margen hasta Pedidos — lo que habría solapado Cotizaciones
 * con Pedidos, Albaranes, Albaranes de Compra, Facturas y Pagos ese mismo día: exactamente el
 * escenario de OOM (2+ entidades de esta migración corriendo a la vez en el mismo host) que todo
 * este diseño existe para evitar.
 *
 * Fix (1ra versión): un solo comando que orquesta las 10 entidades en el orden de dependencia real
 * (Illumination/migracion-icg-diseno.md §14.1). Se agenda UNA sola entrada de `Schedule::`, donde
 * `withoutOverlapping()` sí protege de verdad (es el mismo comando comparado consigo mismo).
 *
 * CORREGIDO DE NUEVO 2026-09-15 (SCRUM-918) — hallazgo real durante el bootstrap manual completo
 * contra dev.atlanticerp.ai: la 1ra versión llamaba a las 10 entidades con `$this->call()`, que las corre
 * DENTRO DEL MISMO PROCESO PHP (Laravel resuelve y ejecuta el comando en el mismo request/proceso,
 * no lanza un proceso de SO nuevo). `sudo dmesg -T` confirmó 2 kills reales del kernel Linux sobre
 * ese proceso PHP (03:58:29 y 04:58:22 UTC, éste último justo al arrancar Pagos, ~435MB de RSS) —
 * la t3.small de 2GB no tiene margen para sostener la memoria acumulada de las 10 entidades en un
 * solo proceso de principio a fin, compitiendo con Postgres/Horizon/nginx/CrowdSec/CloudWatch
 * agent. Cuando el kernel mata ESE proceso, muere el wrapper ENTERO — ninguna entidad posterior a
 * la que estaba corriendo llega a ejecutarse esa vez (confirmado: Pagos murió sin dejar ni una fila
 * `FAILED` en `legacy.sync_runs`, un SIGKILL no puede ser capturado por ningún try/catch de PHP).
 *
 * Fix real: cada una de las 10 entidades corre como un PROCESO DE SISTEMA OPERATIVO separado
 * (`Illuminate\Support\Facades\Process`, no `$this->call()`). Cada entidad arranca con memoria
 * limpia — y si el kernel mata a UNA (OOM u otra causa), el proceso padre (este wrapper) sigue
 * vivo y continúa con la siguiente entidad de la cadena, en vez de perder el resto de la corrida.
 * Verificado en real contra dev.atlanticerp.ai el mismo día del hallazgo: cada entidad corrida suelta
 * (proceso nuevo) completó sin problema, incluida Pagos (146k filas, la más pesada de las 9).
 *
 * Timeout de 1h por entidad (self::PER_ENTITY_TIMEOUT_SECONDS): medido en vivo que el bootstrap de
 * Cotizaciones sola tardó 48m17s — 1h da margen razonable sin dejar que un proceso colgado (ej. una
 * conexión a ICG que nunca responde) bloquee la cadena indefinidamente.
 */
final class LegacySyncIncrementalAllCommand extends Command
{
    private const ORDERED_ENTITY_COMMANDS = [
        'legacy:sync-incremental-productos',
        // SCRUM-1114 — stock de AL4 como delta; DESPUÉS de productos (los productos nuevos ya traen su base).
        'legacy:sync-incremental-stock',
        'legacy:sync-incremental-clientes',
        'legacy:sync-incremental-proveedores',
        'legacy:sync-incremental-cotizaciones',
        'legacy:sync-incremental-pedidos',
        'legacy:sync-incremental-albaranes',
        'legacy:sync-incremental-albaranes-compra',
        'legacy:sync-incremental-facturas',
        'legacy:sync-incremental-pagos',
    ];

    private const PER_ENTITY_TIMEOUT_SECONDS = 3600;

    /** @var string */
    protected $signature = 'legacy:sync-incremental-all
        {--tenant=atlantic : Slug del tenant a sincronizar}
        {--dry-run : Propaga --dry-run a las 10 entidades}';

    /** @var string */
    protected $description = 'SCRUM-838 — corre las 10 entidades incrementales en secuencia estricta (cada una como proceso de SO separado), pensado para el Scheduler';

    public function handle(): int
    {
        $tenant   = (string) $this->option('tenant');
        $isDryRun = (bool) $this->option('dry-run');

        $overallExit = self::SUCCESS;

        foreach (self::ORDERED_ENTITY_COMMANDS as $command) {
            $this->info("==> {$command}");

            $args = ['php', 'artisan', $command, "--tenant={$tenant}"];
            if ($isDryRun) {
                $args[] = '--dry-run';
            }

            $result = Process::path(base_path())
                ->timeout(self::PER_ENTITY_TIMEOUT_SECONDS)
                ->run($args, function (string $type, string $output): void {
                    $this->output->write($output);
                });

            if (! $result->successful()) {
                $this->error("{$command} terminó con código {$result->exitCode()} — se continúa con la siguiente entidad de todas formas.");
                $overallExit = self::FAILURE;
            }
        }

        return $overallExit;
    }
}
