<?php

namespace App\Shared\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use OpenApi\Attributes as OA;

#[OA\Tag(
    name: 'Salud',
    description: 'Verificación de disponibilidad del servicio'
)]
class HealthController
{
    #[OA\Get(
        path: '/api/health',
        summary: 'Verificación de salud del servicio',
        description: 'Endpoint público sin autenticación. Devuelve `{"status":"ok"}` cuando el proceso de Laravel está levantado. Usado por Docker healthcheck y load balancers.',
        tags: ['Salud'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Servicio disponible',
                content: new OA\JsonContent(
                    required: ['status'],
                    properties: [
                        new OA\Property(property: 'status', type: 'string', example: 'ok'),
                    ]
                )
            ),
        ]
    )]
    public function __invoke(): JsonResponse
    {
        return response()->json(['status' => 'ok']);
    }

    #[OA\Get(
        path: '/api/health/deep',
        summary: 'Verificación profunda de dependencias',
        description: 'Público y sin detalles internos. Comprueba la base de datos (`SELECT 1`) y Redis (`PING`): 200 `{"status":"ok"}` si responden ambas, 503 `{"status":"degraded","checks":{...}}` si alguna falla. `/api/health` y `/up` solo confirman que el proceso vive.',
        tags: ['Salud'],
        responses: [
            new OA\Response(response: 200, description: 'Dependencias críticas disponibles'),
            new OA\Response(response: 503, description: 'Alguna dependencia crítica no responde'),
        ]
    )]
    public function deep(): JsonResponse
    {
        $checks = [
            'db'    => $this->probe(static fn () => DB::select('select 1')),
            'redis' => $this->probe(static fn () => Redis::connection()->ping()),
        ];

        if (in_array('fail', $checks, true)) {
            return response()->json(['status' => 'degraded', 'checks' => $checks], 503);
        }

        return response()->json(['status' => 'ok', 'checks' => $checks]);
    }

    /** "ok" si la comprobación no lanza; nunca devuelve el mensaje de error (no revela host ni credenciales). */
    private function probe(callable $check): string
    {
        try {
            $check();

            return 'ok';
        } catch (\Throwable) {
            return 'fail';
        }
    }
}
