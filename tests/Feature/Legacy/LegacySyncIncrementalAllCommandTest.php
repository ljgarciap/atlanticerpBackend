<?php

declare(strict_types=1);

namespace Tests\Feature\Legacy;

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-918 — reescrito 2026-09-15: `legacy:sync-incremental-all` ya no corre las 9 entidades con
 * `$this->call()` (mismo proceso PHP — un OOM del kernel sobre ese proceso mataba el wrapper
 * ENTERO, ver docblock de LegacySyncIncrementalAllCommand). Ahora cada entidad es un proceso de SO
 * separado vía `Illuminate\Support\Facades\Process`, así que este test ya no puede verificar la
 * lógica real de staging/circuit-breaker de cada entidad inyectando extractores fake en el mismo
 * proceso — eso ya está cubierto por el test dedicado de cada comando individual
 * (LegacySyncIncrementalProductosCommandTest, etc.). Este test cubre solo la ORQUESTACIÓN: orden
 * de las 9 llamadas, propagación de --dry-run, y que un fallo en una entidad no corte la cadena —
 * usando Process::fake() (nunca lanza un proceso real).
 */
final class LegacySyncIncrementalAllCommandTest extends FeatureTestCase
{
    /**
     * OJO con el orden: Process::fake() matchea con Str::is() (wildcards de glob) y devuelve el
     * PRIMER patrón que matchea en orden de inserción — `*legacy:sync-incremental-albaranes*`
     * también matchea la línea de comando de `legacy:sync-incremental-albaranes-compra` (substring
     * real), así que el patrón MÁS ESPECÍFICO (albaranes_compra) va primero a propósito.
     */
    private const ENTITY_PATTERNS = [
        'albaranes_compra'  => '*legacy:sync-incremental-albaranes-compra*',
        'productos'         => '*legacy:sync-incremental-productos*',
        'stock'             => '*legacy:sync-incremental-stock*',
        'clientes'          => '*legacy:sync-incremental-clientes*',
        'proveedores'       => '*legacy:sync-incremental-proveedores*',
        'cotizaciones'      => '*legacy:sync-incremental-cotizaciones*',
        'pedidos'           => '*legacy:sync-incremental-pedidos*',
        'albaranes'         => '*legacy:sync-incremental-albaranes*',
        'facturas'          => '*legacy:sync-incremental-facturas*',
        'pagos'             => '*legacy:sync-incremental-pagos*',
    ];

    public function test_corre_las_10_entidades_en_el_orden_de_dependencia_correcto(): void
    {
        $order = [];
        Process::fake($this->fakeHandlersRecordingOrderInto($order, exitCode: 0));

        $this->artisan('legacy:sync-incremental-all', ['--tenant' => 'atlantic'])->assertExitCode(0);

        $this->assertSame([
            'productos', 'stock', 'clientes', 'proveedores', 'cotizaciones', 'pedidos',
            'albaranes', 'albaranes_compra', 'facturas', 'pagos',
        ], $order, 'Las 10 entidades deben correr en este orden exacto (dependencia real, §14.1)');
    }

    public function test_dry_run_se_propaga_a_las_10_entidades(): void
    {
        $commands = [];
        Process::fake($this->fakeHandlersRecordingCommandInto($commands, exitCode: 0));

        $this->artisan('legacy:sync-incremental-all', ['--tenant' => 'atlantic', '--dry-run' => true])
            ->assertExitCode(0);

        $this->assertCount(10, $commands);
        foreach ($commands as $command) {
            $this->assertContains('--dry-run', $command, 'Cada entidad debe recibir --dry-run cuando el wrapper lo recibe');
        }
    }

    public function test_un_fallo_en_una_entidad_no_detiene_la_cadena(): void
    {
        $order = [];
        $handlers = $this->fakeHandlersRecordingOrderInto($order, exitCode: 0);
        // Productos "falla" (exit code distinto de 0) — las 8 restantes deben igual intentar correr.
        $handlers[self::ENTITY_PATTERNS['productos']] = function (PendingProcess $process) use (&$order) {
            $order[] = 'productos';

            return Process::result(exitCode: 1);
        };
        Process::fake($handlers);

        $this->artisan('legacy:sync-incremental-all', ['--tenant' => 'atlantic'])->assertExitCode(1);

        $this->assertSame([
            'productos', 'stock', 'clientes', 'proveedores', 'cotizaciones', 'pedidos',
            'albaranes', 'albaranes_compra', 'facturas', 'pagos',
        ], $order, 'Las 8 entidades restantes deben intentar correr aunque Productos haya fallado');
    }

    public function test_procesos_matados_por_señal_no_detienen_la_cadena(): void
    {
        // Reproduce el hallazgo real de SCRUM-918: un OOM del kernel mata al proceso hijo con
        // SIGKILL. Process::result() con exitCode negativo simula justamente eso (Symfony reporta
        // los procesos matados por señal con exit code negativo/137, nunca 0).
        $order = [];
        $handlers = $this->fakeHandlersRecordingOrderInto($order, exitCode: 0);
        $handlers[self::ENTITY_PATTERNS['pagos']] = function (PendingProcess $process) use (&$order) {
            $order[] = 'pagos';

            return Process::result(exitCode: 137);
        };
        Process::fake($handlers);

        $this->artisan('legacy:sync-incremental-all', ['--tenant' => 'atlantic'])->assertExitCode(1);

        $this->assertSame([
            'productos', 'stock', 'clientes', 'proveedores', 'cotizaciones', 'pedidos',
            'albaranes', 'albaranes_compra', 'facturas', 'pagos',
        ], $order, 'Pagos muerto por señal no debe impedir que corran las entidades anteriores en la cadena — y de hecho corre último');
    }

    /** @param list<string> $order */
    private function fakeHandlersRecordingOrderInto(array &$order, int $exitCode): array
    {
        $handlers = [];
        foreach (self::ENTITY_PATTERNS as $entidad => $pattern) {
            $handlers[$pattern] = function (PendingProcess $process) use (&$order, $entidad, $exitCode) {
                $order[] = $entidad;

                return Process::result(exitCode: $exitCode);
            };
        }

        return $handlers;
    }

    /** @param list<array<int, string>> $commands */
    private function fakeHandlersRecordingCommandInto(array &$commands, int $exitCode): array
    {
        $handlers = [];
        foreach (self::ENTITY_PATTERNS as $pattern) {
            $handlers[$pattern] = function (PendingProcess $process) use (&$commands, $exitCode) {
                $commands[] = $process->command;

                return Process::result(exitCode: $exitCode);
            };
        }

        return $handlers;
    }
}
