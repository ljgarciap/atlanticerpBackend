<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use Illuminate\Routing\Route as RutaLaravel;
use Illuminate\Support\Facades\Route;
use ReflectionClass;
use Tests\Feature\FeatureTestCase;

/**
 * Fase 25 — toda ruta GET cuyo controlador lee el parámetro `scope` (ver el alcance «equipo») lleva el middleware `team.scope`, que responde 403
 * a quien no puede ver el equipo. Una pantalla nueva con ese filtro no puede olvidarlo: o lo lleva, o entra a la lista de excepciones con su motivo
 * (la lista solo puede encogerse).
 */
final class AlcanceDeEquipoTest extends FeatureTestCase
{
    /**
     * Revisadas a mano (2026-11-04): el servicio solo concede el alcance de equipo a quien `canViewTeamV2()` y, si no puede, degrada en silencio
     * a «propio» (sin fuga); `OrdersController` ya filtra por permiso en la ruta y el calendario por módulo. Responden 200 en vez de 403: si se
     * quiere uniformar, se agrega `team.scope` y se quita de esta lista. Los métodos sin `scope` (show, fileUrl…) están por compartir controlador.
     */
    private const EXCEPCIONES = [
        'App\\Modules\\VentasDiseno\\Http\\Controllers\\PipelineController@index',
        'App\\Modules\\VentasDiseno\\Http\\Controllers\\PipelineController@show',
        'App\\Modules\\VentasDiseno\\Http\\Controllers\\PipelineController@fileUrl',
        'App\\Modules\\VentasDiseno\\Http\\Controllers\\HomeController@vendors',
        'App\\Modules\\VentasDiseno\\Http\\Controllers\\HomeController@approvalsPending',
        'App\\Modules\\VentasDiseno\\Http\\Controllers\\OrdersController@index',
        'App\\Modules\\VentasDiseno\\Http\\Controllers\\OrdersController@show',
        'App\\Modules\\Integrations\\Outlook\\Http\\Controllers\\OutlookCalendarController@index',
        'App\\Modules\\Bodega\\Http\\Controllers\\BodegaHomeController@team',
        'App\\Modules\\Bodega\\Http\\Controllers\\BodegaHomeController@summary',
        'App\\Modules\\Bodega\\Http\\Controllers\\BodegaHomeController@rutasDia',
        'App\\Modules\\Bodega\\Http\\Controllers\\BodegaHomeController@approvalsPending',
    ];

    public function test_las_rutas_con_scope_llevan_team_scope(): void
    {
        $faltan = [];
        foreach (Route::getRoutes()->getRoutes() as $ruta) {
            if (! in_array('GET', $ruta->methods(), true) || ! $this->leeScope($ruta)) {
                continue;
            }
            $clave = $ruta->getActionName();
            if (! in_array('team.scope', Route::gatherRouteMiddleware($ruta), true) && ! in_array($clave, self::EXCEPCIONES, true)) {
                $faltan[] = $clave.' ('.$ruta->uri().')';
            }
        }

        $this->assertSame([], $faltan, "Rutas que leen scope sin team.scope:\n".implode("\n", $faltan));
    }

    private function leeScope(RutaLaravel $ruta): bool
    {
        $accion = $ruta->getActionName();
        if (! str_contains($accion, '@')) {
            return false;
        }
        [$clase] = explode('@', $accion);
        if (! class_exists($clase)) {
            return false;
        }
        $archivo = (new ReflectionClass($clase))->getFileName();
        $codigo = $archivo !== false ? (string) file_get_contents($archivo) : '';

        return (bool) preg_match("/(only\(\[[^\]]*'scope'|query\('scope'\)|input\('scope'\)|\\['scope'\\]\s*=>\s*\\\$request)/", $codigo);
    }
}
