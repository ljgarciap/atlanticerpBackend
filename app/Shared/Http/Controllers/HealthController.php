<?php

namespace App\Shared\Http\Controllers;

use Illuminate\Http\JsonResponse;
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
}
