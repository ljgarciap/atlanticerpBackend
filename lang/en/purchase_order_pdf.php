<?php

declare(strict_types=1);

/** SCRUM-1127 — par en inglés de `lang/es/purchase_order_pdf.php`. Mismas claves; escrito a mano (textos fijos, sin IA). */
return [
    'title'        => 'Purchase Order #:id',
    'provider'     => 'Supplier',
    'status'       => 'Status',
    'modality'     => 'Modality',
    'shipping_type' => 'Shipping type',
    'estimated_arrival' => 'Estimated arrival',
    'created_at'   => 'Creation date',
    'factory_reference' => 'Factory reference',
    'reference'    => 'Reference',
    'description'  => 'Description',
    'quantity'     => 'Quantity',
    'unit_cost'    => 'Unit cost',
    'subtotal'     => 'Subtotal',
    'total'        => 'Total',
    'no_cost_note' => 'Document without cost information.',
    'unspecified'  => 'Not specified',
    'modality_direct'     => 'Direct',
    'page'         => 'Page {PAGE_NUM} of {PAGE_COUNT}',

    'statuses' => [
        'por_aprobar'        => 'Pending approval',
        'pendiente_liquidar' => 'Pending settlement',
        'ordenado'           => 'Ordered',
        'en_transito'        => 'In transit',
        'en_aduana'          => 'In customs',
        'en_transito_local'  => 'In local transit',
        'recibido'           => 'Received',
    ],

    'shipping' => [
        'aereo'     => 'Air',
        'maritimo'  => 'Sea',
        'terrestre' => 'Land',
    ],

    'mail' => [
        'subject'  => 'Atlantic — Purchase Order #:id',
        'greeting' => 'Hello :provider,',
        'body'     => 'Please find attached purchase order #:id as a PDF.',
        'footer'   => 'Atlantic · This email was generated automatically',
    ],
];
