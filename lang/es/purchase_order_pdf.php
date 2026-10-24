<?php

declare(strict_types=1);

/**
 * SCRUM-1127 — textos fijos del PDF y del correo de la orden de compra. `lang/en/purchase_order_pdf.php` es su par (mismas claves, escrito a
 * mano: son textos fijos y conocidos, no pasan por la IA). Los valores en español reproducen exactamente lo que el PDF decía antes.
 */
return [
    'title'        => 'Orden de Compra #:id',
    'provider'     => 'Proveedor',
    'status'       => 'Estado',
    'modality'     => 'Modalidad',
    'shipping_type' => 'Tipo de envío',
    'estimated_arrival' => 'Llegada estimada',
    'created_at'   => 'Fecha de creación',
    'factory_reference' => 'Referencia de fábrica',
    'reference'    => 'Referencia',
    'description'  => 'Descripción',
    'quantity'     => 'Cantidad',
    'unit_cost'    => 'Costo unitario',
    'subtotal'     => 'Subtotal',
    'total'        => 'Total',
    'no_cost_note' => 'Documento sin información de costos.',
    'unspecified'  => 'Sin especificar',
    'modality_zona_libre' => 'Zona Libre',
    'modality_direct'     => 'Directo',
    'page'         => 'Pagina {PAGE_NUM} de {PAGE_COUNT}',

    'statuses' => [
        'por_aprobar'        => 'Por aprobar',
        'pendiente_liquidar' => 'Pendiente por liquidar',
        'ordenado'           => 'Ordenado',
        'en_transito'        => 'En tránsito',
        'en_aduana'          => 'En aduana',
        'en_transito_local'  => 'En tránsito local',
        'recibido'           => 'Recibido',
    ],

    'shipping' => [
        'aereo'     => 'Aéreo',
        'maritimo'  => 'Marítimo',
        'terrestre' => 'Terrestre',
    ],

    'mail' => [
        'subject'  => 'Atlantic — Orden de Compra #:id',
        'greeting' => 'Hola :provider,',
        'body'     => 'Adjuntamos la orden de compra #:id en PDF.',
        'footer'   => 'Atlantic · Este correo fue generado automáticamente',
    ],
];
