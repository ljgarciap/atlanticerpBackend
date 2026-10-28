<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use Illuminate\Routing\Route as RutaLaravel;
use Illuminate\Support\Facades\Route;
use Tests\Feature\FeatureTestCase;

/**
 * Un id de ruta que no es numérico («abc») debe responder 404, no 500: los controladores reciben `int $id` y un string lanzaba TypeError
 * (218 de 353 rutas con parámetro). La restricción vive en `AppServiceProvider` (`Route::pattern`).
 */
class IdsNumericosEnRutasTest extends FeatureTestCase
{
    /** Parámetros que admiten texto y que no son ids numéricos. */
    private const NO_NUMERICOS = ['jobId', 'uuid', 'token', 'type', 'domain', 'key', 'numero', 'model', 'order', 'masterClient', 'customerReturn', 'creditNote'];

    public function test_todo_parametro_de_ruta_tipo_id_tiene_restriccion_numerica(): void
    {
        $patrones = Route::getPatterns();
        $sinRestriccion = [];

        foreach (Route::getRoutes()->getRoutes() as $ruta) {
            /** @var RutaLaravel $ruta */
            if (! str_starts_with($ruta->uri(), 'api/')) {
                continue;
            }
            foreach ($ruta->parameterNames() as $nombre) {
                $esId = $nombre === 'id' || str_ends_with($nombre, 'Id');
                if (! $esId || in_array($nombre, self::NO_NUMERICOS, true)) {
                    continue;
                }
                if (! isset($ruta->wheres[$nombre]) && ! isset($patrones[$nombre])) {
                    $sinRestriccion[] = $ruta->methods()[0].' '.$ruta->uri().' {'.$nombre.'}';
                }
            }
        }

        $this->assertSame([], array_slice($sinRestriccion, 0, 20), count($sinRestriccion).' parámetro(s) de ruta tipo id sin restricción numérica (se muestran 20)');
    }

    public function test_un_id_no_numerico_responde_404_y_no_500(): void
    {
        $token = $this->loginAs('superadmin@test.com', 'superadmin@test.com', 'superadmin');

        foreach ([
            ['GET', '/api/bodega/orders/abc'],
            ['POST', '/api/bodega/orders/abc/start-picking'],
            ['GET', '/api/compras/orders/abc'],
            ['PATCH', '/api/compras/orders/abc/advance'],
            ['GET', '/api/servicios/tickets/abc'],
            ['PATCH', '/api/servicios/tickets/abc/estado'],
            ['GET', '/api/ventas-diseno/quotes/abc/validate'],
            ['POST', '/api/ventas-diseno/pipeline/abc/pass-to-order'],
            ['GET', '/api/crm/projects/abc'],
        ] as [$metodo, $ruta]) {
            $this->withHeaders($this->authHeaders($token))->json($metodo, $ruta)->assertStatus(404);
        }
    }
}
