<?php

namespace App\Shared\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Anotaciones globales de la especificación OpenAPI 3.0 para AtlanticERP.
 *
 * Este archivo existe únicamente para alojar las anotaciones de nivel
 * raíz (Info, Server, SecurityScheme). No contiene lógica de aplicación.
 */
#[OA\Info(
    version: '1.0.0',
    title: 'AtlanticERP API',
    description: 'API REST del CRM Atlantic — AtlanticERP Backend (Laravel 12 + JWT)',
    contact: new OA\Contact(
        name: 'AtlanticERP'
    ),
    license: new OA\License(name: 'MIT')
)]
#[OA\Server(
    url: '/api',
    description: 'Servidor actual'
)]
#[OA\SecurityScheme(
    securityScheme: 'bearerAuth',
    type: 'http',
    description: 'JWT firmado por el servidor. Obtenerlo vía POST /api/auth/login y enviarlo como: Authorization: Bearer <token>',
    name: 'bearerAuth',
    scheme: 'bearer',
    bearerFormat: 'JWT'
)]
class ApiInfo {}
