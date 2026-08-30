<?php

declare(strict_types=1);

namespace Tests\Feature\Bodega\Concerns;

use Symfony\Component\Process\Process;

/**
 * SCRUM-329 Oleada A / Batch A2 — Senior Review 2026-07-22 (Blocker 1b): los tests de "doble
 * submit" existentes (`OrderPickingFlowTest`, `InventoryReviewFlowTest`) hacían 2 llamadas HTTP
 * SECUENCIALES dentro del mismo proceso PHPUnit síncrono — la primera termina y commitea antes de
 * que la segunda arranque, así que nunca ejercitan la ventana de carrera real (dos requests que
 * leen el mismo estado ANTES de que cualquiera de las dos commitee), que es justo lo que rompió
 * en Batch A1 (`AdjustmentRequestControllerTest`, ver su propio docblock: "no es reproducible en
 * un solo proceso PHPUnit sincrónico... la verificación real se hizo con requests HTTP
 * concurrentes contra el servidor real corriendo (Pre-QA), no acá").
 *
 * Este trait SÍ logra concurrencia real y automatizada (parte de `infra/test.sh`, no una
 * verificación manual de una sola vez): cada snippet corre en su PROPIO proceso PHP real
 * (`Symfony\Process`), con su PROPIA conexión a Postgres — logrando el aislamiento MVCC real que
 * una sola conexión/transacción de PHPUnit no puede simular. Sincronizados con un barrier de
 * archivos (cada worker toca su "ready" file y espera el "go" file compartido) para maximizar que
 * ambos procesos lleguen al `lockForUpdate()` real casi al mismo tiempo — aunque la corrección que
 * se prueba (exactamente 1 gana, el otro ve el estado ya cambiado) no depende de un timing
 * perfecto: el lock de Postgres serializa correctamente sin importar el orden exacto de llegada.
 *
 * PRECONDICIÓN: la clase que usa este trait debe declarar `protected array $connectionsToTransact
 * = [];` para desactivar el wrapping transaccional de `RefreshDatabase` — si no, los fixtures
 * creados en el test quedan en una transacción sin commitear, invisibles para los procesos hijos
 * (exactamente el problema que documentó Batch A1). Como consecuencia, los datos SÍ quedan
 * commiteados de verdad — la clase es responsable de limpiar todo lo que crea en `tearDown()`
 * (no hay rollback automático).
 */
trait RunsConcurrentBodegaRequests
{
    /**
     * @param list<string> $phpSnippets sentencias PHP completas (con `;` final), una por
     *        proceso. Cada snippet corre con el tenant `atlantic` ya activo
     *        (`Tenant::current()`) y debe dejar su resultado en la variable `$outFile` ya
     *        definida por el wrapper (`file_put_contents($outFile, ...)`).
     * @return list<string> contenido de cada archivo de salida, en el mismo orden que $phpSnippets.
     */
    protected function runConcurrently(array $phpSnippets): array
    {
        $dir = base_path('storage/app/testing-concurrency-'.uniqid());
        mkdir($dir, 0777, true);

        $goFile = "{$dir}/go";

        /** @var list<Process> $processes */
        $processes  = [];
        $readyFiles = [];
        $outFiles   = [];

        foreach ($phpSnippets as $i => $snippet) {
            $readyFile = "{$dir}/ready-{$i}";
            $outFile   = "{$dir}/out-{$i}";
            $scriptFile = "{$dir}/worker-{$i}.php";

            $readyFiles[] = $readyFile;
            $outFiles[]   = $outFile;

            file_put_contents($scriptFile, $this->buildWorkerScript($snippet, $readyFile, $goFile, $outFile));

            $process = new Process(['php', $scriptFile], base_path());
            $process->setTimeout(20);
            $process->start();
            $processes[] = $process;
        }

        // Barrier: esperar a que TODOS los workers terminen de bootear Laravel y avisen que están
        // listos, antes de soltarlos a todos juntos con el "go".
        $deadline = microtime(true) + 10;
        do {
            $allReady = true;
            foreach ($readyFiles as $readyFile) {
                if (! file_exists($readyFile)) {
                    $allReady = false;
                    break;
                }
            }
            if ($allReady) {
                break;
            }
            usleep(2000);
        } while (microtime(true) < $deadline);

        file_put_contents($goFile, '1');

        foreach ($processes as $process) {
            $process->wait();
        }

        $results = [];
        foreach ($outFiles as $i => $outFile) {
            if (file_exists($outFile)) {
                $results[] = trim((string) file_get_contents($outFile));
            } else {
                $process     = $processes[$i];
                $results[]   = 'NO_OUTPUT stdout=['.$process->getOutput().'] stderr=['.$process->getErrorOutput().']';
            }
        }

        foreach (glob("{$dir}/*") ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($dir);

        return $results;
    }

    private function buildWorkerScript(string $snippet, string $readyFile, string $goFile, string $outFile): string
    {
        $basePath = base_path();

        return <<<PHP
<?php
require '{$basePath}/vendor/autoload.php';
\$app = require '{$basePath}/bootstrap/app.php';
\$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

\App\Shared\Multitenancy\Tenant::where('slug', 'atlantic')->first()?->makeCurrent();

\$outFile = '{$outFile}';

touch('{$readyFile}');
\$deadline = microtime(true) + 5;
while (! file_exists('{$goFile}') && microtime(true) < \$deadline) {
    usleep(1000);
}

try {
    {$snippet}
} catch (\Throwable \$e) {
    file_put_contents(\$outFile, 'UNCAUGHT:'.get_class(\$e).':'.\$e->getMessage());
}
PHP;
    }
}
