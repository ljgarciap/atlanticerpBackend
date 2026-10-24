<?php

declare(strict_types=1);

/**
 * SCRUM-984 — catálogo técnico de la biblioteca de manuales/videos.
 *
 * Aquí solo vive lo que NO es un umbral de negocio: la lista de módulos (definida por el ticket),
 * el mapeo extensión → tipo/mime y las firmas de archivo. Topes de tamaño, extensiones permitidas
 * y TTL de las URLs viven en `auth.system_settings` (ver LibrarySettings) — configurables por
 * superadmin sin deploy.
 */
return [
    // Claves de módulo aceptadas (lista blanca). Agregar un módulo nuevo = agregar una línea acá.
    'modules' => [
        'crm', 'ventas_diseno', 'compras', 'bodega', 'servicios', 'admin_contabilidad', 'gerencia',
    ],

    // extensión => [tipo, mime canónico]. El mime servido sale SIEMPRE de acá, nunca del sidecar.
    'extensions' => [
        'pdf'  => ['type' => 'pdf',   'mime' => 'application/pdf'],
        'mp4'  => ['type' => 'video', 'mime' => 'video/mp4'],
        'webm' => ['type' => 'video', 'mime' => 'video/webm'],
    ],

    // Catálogo técnico del escaneo de contenido activo en PDF (hallazgo M-3): nombres de objeto PDF que
    // indican JavaScript o acciones/archivos embebidos. No son umbrales de negocio. /OpenAction no va:
    // un /OpenAction legítimo es un GoTo (abrir en la página 1); el peligroso apunta a /JavaScript o
    // /Launch, que ya están en la lista. Límite conocido: un nombre dentro de un object stream
    // comprimido no se ve en un escaneo barato (riesgo residual N-4, ACEPTADO por Luis el 2026-09-30 como
    // control anti-error, no anti-adversario; ClamAV sería el siguiente paso, fuera del ticket).
    // Falso positivo conocido: un token dentro de una cadena literal sin comprimir, p. ej.
    // `/URI (https://x/JS/a)`, se rechaza. NO se ignoran las cadenas entre paréntesis a propósito: un
    // comentario `%(` esconde un `/JavaScript` real del escáner y no del lector PDF (bypass). Se prefiere
    // rechazar de más a dejar pasar.
    'pdf_active_content_tokens' => ['/JavaScript', '/JS', '/Launch', '/EmbeddedFile', '/RichMedia', '/SubmitForm', '/ImportData'],
    'pdf_scan_chunk_bytes'      => 8 * 1024 * 1024,
    // Tope del escaneo. Un PDF más grande NO se escanea parcialmente: se rechaza (falla cerrado). Es también el
    // techo de `library_max_pdf_mb`: PUT /config no acepta más que esto (LibrarySettings::maxPdfMbCeiling) y el
    // tope efectivo de subida (maxBytesFor) nunca lo supera, aunque haya un valor mayor ya guardado.
    'pdf_scan_max_bytes'        => 100 * 1024 * 1024,

    // Desfase máximo aceptado entre entornos (cada uno cachea su listado en su Redis, ver diseño AWS §4).
    'catalog_cache_seconds' => 60,
];
