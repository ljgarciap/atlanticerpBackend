<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use Illuminate\Routing\Route as RutaLaravel;
use Illuminate\Support\Facades\Route;
use Tests\Feature\FeatureTestCase;

/**
 * Fase 31a — toda ruta de Ventas & Diseño cuyo controlador lee el alcance Mías/Equipo (`scope`) debe llevar la guarda `team.scope`:
 * sin ella, pedir `scope=team` sin permiso se degrada en silencio a lo propio (candidato 8 de `docs/flujos/ventas-diseno.md`).
 * Una pantalla nueva con alcance que no la lleve rompe este test; las excepciones tienen dueño y motivo.
 */
class AlcanceMiasEquipoTest extends FeatureTestCase
{
    /** Controladores que resuelven el alcance con una regla propia (documentado en la Fase 23). */
    private const REGLA_PROPIA = [
        'PipelineController' => 'el Pipeline usa pipeline.read_all',
        'OrdersController'   => 'Pedidos conserva su regla propia',
        'OutlookCalendarController' => 'el calendario de Outlook decide Mío/Equipo por módulo (MODULES_WITH_TEAM_CALENDAR)',
    ];

    public function test_toda_ruta_con_alcance_mias_equipo_lleva_la_guarda(): void
    {
        $sinGuarda = [];

        foreach (Route::getRoutes()->getRoutes() as $ruta) {
            /** @var RutaLaravel $ruta */
            if (! str_starts_with($ruta->uri(), 'api/ventas-diseno/')) {
                continue;
            }
            $accion = $ruta->getAction('controller');
            if (! is_string($accion) || ! str_contains($accion, '@')) {
                continue;
            }
            [$clase, $metodo] = explode('@', $accion);
            $corto   = substr((string) strrchr($clase, '\\'), 1);
            if (isset(self::REGLA_PROPIA[$corto]) || ! class_exists($clase)) {
                continue;
            }
            if (! method_exists($clase, $metodo)) {
                continue;
            }
            // Solo el método de la ruta: otros métodos del mismo controlador pueden no tener alcance.
            $reflexion = new \ReflectionMethod($clase, $metodo);
            $archivo   = $reflexion->getFileName();
            $lineas    = $archivo !== false ? array_slice((array) file($archivo), $reflexion->getStartLine() - 1, $reflexion->getEndLine() - $reflexion->getStartLine() + 1) : [];
            if (! str_contains(implode('', $lineas), "'scope'")) {
                continue;
            }
            // Solo lecturas: un POST/PUT con un campo llamado scope no es un alcance de listado.
            if (! in_array('GET', $ruta->methods(), true)) {
                continue;
            }
            $tiene = collect($ruta->gatherMiddleware())->contains(fn ($m): bool => is_string($m) && str_starts_with($m, 'team.scope'));
            if (! $tiene) {
                $sinGuarda[] = 'GET '.$ruta->uri().' ('.$corto.')';
            }
        }

        $this->assertSame([], $sinGuarda, "Rutas con alcance Mías/Equipo sin la guarda team.scope:\n".implode("\n", $sinGuarda));
    }
}
