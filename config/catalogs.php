<?php

declare(strict_types=1);
use App\Modules\Compras\Models\Provider;
use App\Modules\Servicios\Models\Ticket;
use App\Modules\VentasDiseno\Models\PipelineCard;
use App\Modules\VentasDiseno\Services\PipelineStageRules;
use App\Modules\VentasDiseno\Models\Architect;
use App\Modules\VentasDiseno\Models\CatalogProduct;

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
        // Fase 9a — categorías de producto del catálogo. La lógica nunca pregunta por una categoría concreta (es vocabulario de la empresa); el
        // `color` solo lo usa la foto de relleno de los datos de prueba. `general` es de sistema: el campo es obligatorio al crear un producto.
        'product_category' => [
            'label' => 'Categorías de producto',
            'usage' => [CatalogProduct::class, 'category'],
            'props' => [
                'color' => ['type' => 'string', 'max' => 7, 'pattern' => '/^#[0-9a-fA-F]{6}$/', 'default' => null],
            ],
        ],
        // Fase 9c — campos de la ficha técnica estructurada del producto (`technical_spec`, JSONB clave → valor). `usage_json` = columna JSONB cuyas
        // claves son las del dominio (una entrada con datos no se borra). La lógica nunca pregunta por un campo concreto: solo lista, etiqueta y ordena.
        'technical_spec_field' => [
            'label' => 'Campos de la ficha técnica',
            'usage_json' => [CatalogProduct::class, 'technical_spec'],
            'props' => [],
        ],
        // Fase 7c — etapas del pipeline de Ventas & Diseño. La lógica pregunta por `categoria`, no por la clave. Las 6 de sistema no se
        // reordenan ni cambian de categoría (las compuertas, las comisiones y «pasar a pedido» dependen de ellas); las propias van entre
        // Lead y Aprobado y son siempre `abierta` (ganada/perdida son `system_only`).
        'pipeline_stage' => [
            'label' => 'Etapas del pipeline (Ventas & Diseño)',
            'usage' => [PipelineCard::class, 'stage'],
            'rules' => PipelineStageRules::class,
            'locked_when_system' => ['sort_order', 'categoria'],
            'props' => [
                'categoria' => ['type' => 'enum', 'values' => ['abierta', 'ganada', 'perdida'], 'system_only' => ['ganada', 'perdida'], 'default' => 'abierta'],
                'color' => ['type' => 'string', 'max' => 7, 'pattern' => '/^#[0-9a-fA-F]{6}$/', 'default' => null],
            ],
        ],
        // Fase 7b — tipos de ticket de Servicios. La lógica pregunta por `comportamiento` (qué flujo hereda), no por la clave.
        'ticket_type' => [
            'label' => 'Tipos de ticket (Servicios)',
            'usage' => [Ticket::class, 'tipo'],
            'props' => [
                'comportamiento' => ['type' => 'enum', 'values' => ['instalacion', 'garantia', 'reclamo', 'retrofit', 'generico'], 'default' => 'generico'],
                'prefijo' => ['type' => 'string', 'max' => 5, 'pattern' => '/^[A-Z0-9]{1,5}$/', 'default' => 'TCK'],
                'sla_dias' => ['type' => 'int', 'min' => 1, 'max' => 365, 'default' => 7],
                'color' => ['type' => 'string', 'max' => 7, 'pattern' => '/^#[0-9a-fA-F]{6}$/', 'default' => null],
                'subtipos' => ['type' => 'list', 'max' => 20, 'default' => []],
            ],
        ],
    ],
];
