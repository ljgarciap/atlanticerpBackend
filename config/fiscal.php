<?php

declare(strict_types=1);

/*
| Fase 6b — registro de proveedores de facturación electrónica por país. Cada proveedor declara los países en los que sirve (`*` = todos);
| `default_by_country` es el proveedor que corresponde a un país que no tiene una elección propia (si falta, `fallback`). `none` es el
| «proveedor» de los países sin paquete fiscal: la empresa emite documentos internos no fiscales y nada viaja a ninguna autoridad.
| Agregar el paquete fiscal de un país = su adaptador (`PacProviderContract`) + una fila aquí + su construcción en `PacProviderFactory`.
*/
return [
    'providers' => [
        'msl' => ['label' => 'MSL (Panamá)',                    'countries' => ['PA']],
        'digifact' => ['label' => 'Digifact NUC (Panamá)',           'countries' => ['PA']],
        'none' => ['label' => 'Sin facturación electrónica',     'countries' => ['*']],
    ],

    // Países con una elección propia; el resto cae en `fallback`. Colombia (DIAN) y EE. UU. quedan en `none` hasta tener su adaptador.
    'default_by_country' => [
        'PA' => 'msl',
    ],

    'fallback' => 'none',

    // Fase 6d — nombre VISIBLE del impuesto sobre ventas por país (etiquetas de pantallas, PDF y exportes). Solo cambia el texto: el modelo
    // interno sigue llamándose «ITBMS» (tablas, columnas, API). Un país sin entrada usa 'default'.
    'tax_labels' => [
        'PA'      => 'ITBMS',
        'CO'      => 'IVA',
        'US'      => 'Sales tax',
        'default' => 'Impuesto',
    ],

    // Fase 6c — reglas de la identificación fiscal por país (RUC, NIT, EIN…); un país sin entrada usa GenericTaxIdRules.
    'tax_id_rules' => [
        'PA' => \App\Shared\Fiscal\TaxId\PanamaTaxIdRules::class,
        'CO' => \App\Shared\Fiscal\TaxId\ColombiaTaxIdRules::class,
        'US' => \App\Shared\Fiscal\TaxId\UnitedStatesTaxIdRules::class,
    ],
];
