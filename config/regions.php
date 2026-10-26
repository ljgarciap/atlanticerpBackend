<?php

declare(strict_types=1);

/*
| Fase 6a — catálogo de países con contexto regional por omisión (moneda, zona horaria y locale). `tenant:create --country=XX` y
| `tenant:set-region` parten de aquí; cada empresa puede ajustar moneda/zona/locale a su caso (p. ej. EE. UU. cubre varias zonas).
| Agregar un país es agregar una fila: no requiere código.
*/
return [
    'default' => 'PA',

    'countries' => [
        'PA' => ['name' => 'Panamá',          'currency' => 'USD', 'timezone' => 'America/Panama',    'locale' => 'es-PA'],
        'CO' => ['name' => 'Colombia',        'currency' => 'COP', 'timezone' => 'America/Bogota',    'locale' => 'es-CO'],
        'US' => ['name' => 'Estados Unidos',  'currency' => 'USD', 'timezone' => 'America/New_York',  'locale' => 'en-US'],
    ],
];
