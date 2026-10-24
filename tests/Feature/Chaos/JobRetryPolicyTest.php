<?php

declare(strict_types=1);

namespace Tests\Feature\Chaos;

use Illuminate\Contracts\Queue\ShouldQueue;
use ReflectionClass;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * SCRUM-965 — política de reintentos de los Jobs (CLAUDE.md: "Retry + Backoff en Jobs: [5s, 30s, 120s]").
 *
 * Inventario REAL por reflexión (no por grep): todo Job encolable declara `tries` explícito y, salvo los listados abajo,
 * un `backoff()`. La lista de excepciones es una decisión visible: un Job nuevo sin backoff rompe este test y obliga a
 * decidir (agregar backoff o justificar la excepción aquí), en vez de reintentar sin espera en silencio.
 */
final class JobRetryPolicyTest extends TestCase
{
    /**
     * Jobs concretos sin `backoff()` (SCRUM-1036, R-08: los otros 3 ya lo tienen). `GenerateCatalogPdfVolumesJob` tiene `tries = 1` A PROPÓSITO
     * (reintentar a ciegas repetiría minutos de trabajo; el fallo se avisa al usuario vía `catalog_pdf_exports`): sin reintentos no hay
     * nada que espaciar. Cualquier otro Job sin backoff debe tener también `tries = 1` (ver `test_un_job_sin_backoff_tiene_un_solo_intento`).
     *
     * @var list<string>
     */
    private const WITHOUT_BACKOFF = [
        'App\Modules\VentasDiseno\Jobs\GenerateCatalogPdfVolumesJob',
    ];

    /** @return list<ReflectionClass<object>> */
    private function queueableJobs(): array
    {
        $jobs = [];
        foreach ((new Finder())->files()->in(app_path())->name('*.php') as $file) {
            $src = $file->getContents();
            if (! str_contains($src, 'ShouldQueue') || ! preg_match('/^namespace\s+([^;]+);/m', $src, $ns) || ! preg_match('/^(?:final\s+|abstract\s+)*class\s+(\w+)/m', $src, $cls)) {
                continue;
            }
            $class = $ns[1] . '\\' . $cls[1];
            if (! class_exists($class)) {
                continue;
            }
            $ref = new ReflectionClass($class);
            if ($ref->isAbstract() || ! $ref->implementsInterface(ShouldQueue::class) || ! str_ends_with($ref->getShortName(), 'Job')) {
                continue;
            }
            $jobs[] = $ref;
        }

        return $jobs;
    }

    public function test_hay_jobs_y_el_inventario_se_detecta(): void
    {
        $this->assertGreaterThanOrEqual(10, count($this->queueableJobs()), 'el inventario por reflexión debe encontrar los Jobs conocidos');
    }

    public function test_todo_job_declara_tries_explicito(): void
    {
        $sin = [];
        foreach ($this->queueableJobs() as $ref) {
            $declara = $ref->hasProperty('tries') || $ref->hasMethod('tries');
            if (! $declara) {
                $sin[] = $ref->getName();
            }
        }

        $this->assertSame([], $sin, 'Jobs sin `tries` explícito (usarían el default del worker): ' . implode(', ', $sin));
    }

    public function test_los_jobs_sin_backoff_son_exactamente_los_conocidos(): void
    {
        $sinBackoff = [];
        foreach ($this->queueableJobs() as $ref) {
            if (! $ref->hasMethod('backoff') && ! $ref->hasProperty('backoff')) {
                $sinBackoff[] = $ref->getName();
            }
        }
        sort($sinBackoff);
        $esperados = self::WITHOUT_BACKOFF;
        sort($esperados);

        $this->assertSame($esperados, $sinBackoff, 'cambió el conjunto de Jobs sin backoff: agregar backoff() o justificarlo en WITHOUT_BACKOFF');
    }

    public function test_un_job_sin_backoff_tiene_un_solo_intento(): void
    {
        foreach ($this->queueableJobs() as $ref) {
            if ($ref->hasMethod('backoff') || $ref->hasProperty('backoff')) {
                continue;
            }
            $tries = $ref->hasProperty('tries') ? $ref->getProperty('tries')->getDefaultValue() : null;

            $this->assertSame(1, $tries, "{$ref->getName()} reintenta ({$tries} intentos) sin backoff: agregar backoff() o dejar tries = 1");
        }
    }

    public function test_los_backoff_declarados_son_crecientes_y_no_vacios(): void
    {
        foreach ($this->queueableJobs() as $ref) {
            if (! $ref->hasMethod('backoff') || $ref->getMethod('backoff')->getNumberOfRequiredParameters() > 0) {
                continue;
            }
            $m = $ref->getMethod('backoff');
            $args = [];
            foreach ($ref->getConstructor()?->getParameters() ?? [] as $p) {
                $args[] = $p->isDefaultValueAvailable() ? $p->getDefaultValue() : match ((string) $p->getType()) {
                    'int' => 1, 'string' => 'x', 'array' => [], 'bool' => false, 'float' => 1.0, default => null,
                };
            }
            try {
                $job = $ref->newInstanceArgs($args);
            } catch (\Throwable) {
                $this->markTestIncomplete('no se pudo instanciar ' . $ref->getName() . ' para leer su backoff');
            }
            $values = $m->invoke($job);
            $values = is_array($values) ? $values : [$values];

            $this->assertNotEmpty($values, $ref->getName());
            $sorted = $values;
            sort($sorted);
            $this->assertSame($sorted, $values, "backoff no creciente en {$ref->getName()}");
        }
    }
}
