<?php

declare(strict_types=1);

/**
 * SCRUM-1156 — PDF de factura certificada con QR y clave de acceso. Lista de SEGURIDAD (no es un umbral ni un parámetro de
 * negocio: no hay CRUD de superadmin para ella). El PDF solo se pinta como certificado si el enlace de consulta de la DGI
 * que devolvió el PAC (`qr_content`) apunta a uno de estos hosts; cualquier otro host cae al formato «Sin validez fiscal».
 * Ver docs/architecture/scrum1156-pdf-factura-diseno.md §4.
 */
return [
    // Hosts de consulta de la DGI aceptados en `qr_content` (el puerto no cuenta: el de test usa :40001).
    'allowed_hosts' => [
        'dgi-fep.mef.gob.pa',
        'dgi-fep-test.mef.gob.pa',
    ],

    // Subconjunto de arriba que es PRODUCCIÓN: solo se usa cuando la transmisión no guardó el ambiente, para deducirlo del host.
    'production_hosts' => [
        'dgi-fep.mef.gob.pa',
    ],
];
