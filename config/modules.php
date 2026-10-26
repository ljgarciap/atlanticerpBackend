<?php

declare(strict_types=1);

/*
| Fase 5a — módulos que NO forman parte del núcleo y se apagan por configuración. APAGADOS por defecto: un tenant nuevo no
| necesita ICG ni el catálogo de un proveedor concreto. Apagado, no se registran sus bindings, rutas, comandos ni schedules
| (el código queda dormido; las tablas `legacy_*`/`lafayette_*` ya creadas por migraciones históricas no se tocan).
*/
return [
    // Migración desde el ERP anterior del cliente (SQL Server de ICG): `App\Modules\Legacy`, rutas `/api/legacy`, comandos `legacy:*`.
    'legacy_icg' => [
        'enabled' => (bool) env('MODULE_LEGACY_ICG', false),
    ],

    // Catálogo de solo consulta de un proveedor externo (Zona Libre): `App\Modules\Integrations\Lafayette`, comandos `lafayette:*`.
    'lafayette' => [
        'enabled' => (bool) env('MODULE_LAFAYETTE', false),
    ],
];
