<?php

declare(strict_types=1);

/**
 * SCRUM-990 — parámetros TÉCNICOS de la integración con Lafayette Lighting (no son umbrales de negocio: no hay CRUD
 * de superadmin para ellos). Las decisiones de negocio (precio mostrado, qué se oculta) viven en el diseño aprobado.
 */
return [
    // Filas por INSERT ... ON CONFLICT del upsert. 12 parámetros por fila (SCRUM-1125 sumó precio_6): 1.000 filas = 12.000 (< 65.535 de PDO pgsql).
    'lote_upsert' => 1000,

    // Saneo por fila de los datos de la fuente externa (P1 de la revisión de seguridad). Topes TÉCNICOS (no son umbrales de negocio):
    // `descripcion_max` caracteres que se conservan de una descripción (el resto se trunca), `url_imagen_max` largo máximo de una URL de imagen
    // (más larga => null), `precio_max` tope de un precio plausible (fuera de [0, tope] o no finito => null).
    'descripcion_max' => 2000,
    'url_imagen_max'  => 2048,
    'precio_max'      => 1.0e10,

    // Fracción de filas RECHAZADAS (cuarentena) sobre las extraídas a partir de la cual la corrida queda "partial" (señal de que la fuente cambió
    // de contrato o está corrupta). Por debajo, las filas rechazadas solo se cuentan y la corrida sigue "success".
    'filas_rechazadas_max_ratio' => 0.01,

    // Guarda del sincronizador (VALOR POR DEFECTO; el parámetro real es `auth.system_settings.lafayette_guarda_min_ratio`, ver LafayetteSettings): si una corrida extrae menos de esta fracción de lo que trajo la corrida exitosa
    // previa (o 0 filas), NO marca productos como "fuera de la fuente" (una vista vacía/recortada no debe ocultar el catálogo).
    'guarda_extraccion_min_ratio' => 0.9,

    // Comando `lafayette:aislar-catalogo`: se niega a aplicar si `lafayette_productos` tiene menos de esta fracción de los
    // productos de origen Lafayette del catálogo (aún no se repobló desde la fuente: la limpieza perdería el dato).
    'aislar_min_poblado_ratio' => 0.9,

    // TTL (segundos) de la caché en memoria del conjunto de bodegas `solo_consulta` que consulta el guard de `ProductWarehouseStock::saving`.
    // 0 = consultar siempre. Un cambio de la bodega por SQL directo tarda hasta este tiempo en verse en un proceso de larga vida (el trigger de BD no depende de esto).
    'cache_bodegas_solo_consulta_segundos' => 30,

    // Comando `lafayette:aislar-catalogo` fuera de local/testing: la conexión DEBE ser el rol de migración (DB_MIGRATE_*), distinto del rol de la aplicación.
    // Se leen aquí (no con env() en el comando) para que funcionen también con `config:cache`.
    'migrate_username'          => env('DB_MIGRATE_USERNAME'),
    'migrate_password_definida' => env('DB_MIGRATE_PASSWORD') !== null && env('DB_MIGRATE_PASSWORD') !== '',
    'app_role'                  => env('DB_APP_ROLE', 'atlanticerp_app'),

    // Caché (segundos) de `meta.categorias` / `meta.total_catalogo` del listado de Zona Libre.
    'cache_meta_segundos' => 300,
];
