<?php

declare(strict_types=1);
use App\Modules\Compras\Models\Provider;
use App\Modules\VentasDiseno\Models\Architect;

/*
| Fase 7a — dominios de los catálogos de vocabulario por empresa (tabla `catalog_entries`). Cada dominio declara su nombre para la
| pantalla de Ajustes, qué tabla/columna guarda su clave (una entrada en uso no se puede borrar) y las propiedades de comportamiento
| que admite. Agregar un dominio es agregar una fila aquí + sembrar sus entradas en una migración.
*/
return [
    'domains' => [
        'provider_category' => [
            'label' => 'Categorías de proveedor',
            'usage' => [Provider::class, 'category'],
            'props' => [
                'origen' => ['type' => 'enum', 'values' => ['local', 'internacional'], 'default' => 'internacional'],
                'valida_identificacion' => ['type' => 'bool', 'default' => false],
            ],
        ],
        'architect_type' => [
            'label' => 'Tipos de arquitecto / prescriptor',
            'usage' => [Architect::class, 'type'],
            'props' => [],
        ],
    ],
];
