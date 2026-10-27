<?php

declare(strict_types=1);
use App\Modules\Compras\Models\Provider;
use App\Modules\Servicios\Models\Ticket;
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
        // Fase 7b — tipos de ticket de Servicios. La lógica pregunta por `comportamiento` (qué flujo hereda), no por la clave.
        'ticket_type' => [
            'label' => 'Tipos de ticket (Servicios)',
            'usage' => [Ticket::class, 'tipo'],
            'props' => [
                'comportamiento' => ['type' => 'enum', 'values' => ['instalacion', 'garantia', 'reclamo', 'retrofit', 'generico'], 'default' => 'generico'],
                'prefijo' => ['type' => 'string', 'max' => 5, 'default' => 'TCK'],
                'sla_dias' => ['type' => 'int', 'min' => 1, 'max' => 365, 'default' => 7],
                'color' => ['type' => 'string', 'max' => 30, 'default' => null],
                'subtipos' => ['type' => 'list', 'max' => 20, 'default' => []],
            ],
        ],
    ],
];
