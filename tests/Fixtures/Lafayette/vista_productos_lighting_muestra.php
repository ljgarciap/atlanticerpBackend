<?php

declare(strict_types=1);

/**
 * SCRUM-990 — muestra de 20 filas con la FORMA REAL de `app_lafayette.vista_productos_lighting` (columnas y tipos tal como
 * las devuelve PDO/mysqlnd: enteros como int, `precio_5` DECIMAL(15,4) como string, fechas como string `Y-m-d H:i:s`).
 *
 * NOTA HONESTA: son filas REPRESENTATIVAS armadas a mano con los valores de muestra de la spec de SCRUM-969 (ej.
 * `LNP0000001` / `331819AP2-PAB`) y los casos borde que la fuente ya mostró (sin referencia, sin descripción, `disponible`
 * negativo o cero, precio nulo, cadenas vacías). NO se capturaron en vivo: el desarrollo no consulta la MariaDB de Lafayette.
 * La comparación contra la fuente real (`SELECT COUNT(*)` vs `count(lafayette_productos)`) es criterio de Pre-QA en dev.
 *
 * Se pasan por `ProductoExtractor::mapRow()` (la función pura REAL del extractor), no por objetos armados a mano.
 *
 * @return list<array<string, mixed>>
 */
return [
    ['id' => 'LNP0000001', 'referencia' => '331819AP2-PAB', 'descripcion' => 'LAMP COLG DECO 90W', 'marca' => 'FLUA', 'url_imagen' => 'https://lafayette.example/img/LNP0000001.jpg', 'categoria' => 'LAMPARAS', 'disponible' => 31, 'precio_5' => '900.8000', 'precio_6' => '901.3000', 'cache_updated_at' => '2026-09-25 16:58:46'],
    ['id' => 'LNP0000002', 'referencia' => '331819AP2-NEG', 'descripcion' => 'LAMP COLG DECO 90W NEGRA', 'marca' => 'FLUA', 'url_imagen' => null, 'categoria' => 'LAMPARAS', 'disponible' => 0, 'precio_5' => '912.5000', 'precio_6' => '100.0000', 'cache_updated_at' => '2026-09-25 16:58:46'],
    ['id' => 'LNP0000003', 'referencia' => 'LED-A60-9W-65', 'descripcion' => 'LÁMPARA LED BULBO A60 9W 6500K LUZ FRÍA', 'marca' => 'PHILIPS', 'url_imagen' => 'https://lafayette.example/img/LNP0000003.jpg', 'categoria' => 'LÁMPARAS LED', 'disponible' => 1250, 'precio_5' => '2.1500', 'precio_6' => '2.6500', 'cache_updated_at' => '2026-09-25 16:58:47'],
    ['id' => 'LNP0000004', 'referencia' => 'LED-A60-9W-30', 'descripcion' => 'LÁMPARA LED BULBO A60 9W 3000K LUZ CÁLIDA', 'marca' => 'PHILIPS', 'url_imagen' => null, 'categoria' => 'LÁMPARAS LED', 'disponible' => 980, 'precio_5' => '2.1500', 'precio_6' => '2.6500', 'cache_updated_at' => '2026-09-25 16:58:47'],
    ['id' => 'LNP0000005', 'referencia' => 'PAN-60X60-40W', 'descripcion' => 'PANEL LED EMPOTRAR 60X60 40W', 'marca' => 'OSRAM', 'url_imagen' => null, 'categoria' => 'PANELES', 'disponible' => 57, 'precio_5' => '38.7000', 'precio_6' => '39.2000', 'cache_updated_at' => '2026-09-25 16:58:48'],
    ['id' => 'LNP0000006', 'referencia' => 'PAN-30X30-18W', 'descripcion' => 'PANEL LED EMPOTRAR 30X30 18W', 'marca' => 'OSRAM', 'url_imagen' => null, 'categoria' => 'PANELES', 'disponible' => 0, 'precio_5' => null, 'precio_6' => null, 'cache_updated_at' => '2026-09-25 16:58:48'],
    ['id' => 'LNP0000007', 'referencia' => 'RIEL-3F-1M-BL', 'descripcion' => 'RIEL TRIFÁSICO 1M BLANCO', 'marca' => 'LEDVANCE', 'url_imagen' => null, 'categoria' => 'RIELES', 'disponible' => 14, 'precio_5' => '21.0000', 'precio_6' => '21.5000', 'cache_updated_at' => '2026-09-25 16:58:49'],
    ['id' => 'LNP0000008', 'referencia' => 'FOCO-RIEL-30W', 'descripcion' => 'FOCO PARA RIEL LED 30W 3000K', 'marca' => 'LEDVANCE', 'url_imagen' => 'https://lafayette.example/img/LNP0000008.jpg', 'categoria' => 'RIELES', 'disponible' => 203, 'precio_5' => '17.4500', 'precio_6' => '17.9500', 'cache_updated_at' => '2026-09-25 16:58:49'],
    ['id' => 'LNP0000009', 'referencia' => 'CINTA-LED-5M-RGB', 'descripcion' => 'CINTA LED 5M RGB 12V IP20', 'marca' => 'GENÉRICA', 'url_imagen' => null, 'categoria' => 'CINTAS LED', 'disponible' => 88, 'precio_5' => '9.9900', 'precio_6' => '10.4900', 'cache_updated_at' => '2026-09-25 16:58:50'],
    ['id' => 'LNP0000010', 'referencia' => 'DRV-12V-60W', 'descripcion' => 'FUENTE DE PODER 12V 60W', 'marca' => 'MEAN WELL', 'url_imagen' => null, 'categoria' => 'FUENTES', 'disponible' => 0, 'precio_5' => '14.2000', 'precio_6' => '14.7000', 'cache_updated_at' => '2026-09-25 16:58:50'],
    // Sin referencia (antes el Mapper lo EXCLUÍA): ahora se guarda tal cual.
    ['id' => 'LNP0000011', 'referencia' => null, 'descripcion' => 'ACCESORIO SIN REFERENCIA EN LA FUENTE', 'marca' => 'FLUA', 'url_imagen' => null, 'categoria' => 'ACCESORIOS', 'disponible' => 5, 'precio_5' => '1.0000', 'precio_6' => '1.5000', 'cache_updated_at' => '2026-09-25 16:58:51'],
    // Sin descripción (antes EXCLUIDO).
    ['id' => 'LNP0000012', 'referencia' => 'REF-SIN-DESC', 'descripcion' => null, 'marca' => null, 'url_imagen' => null, 'categoria' => null, 'disponible' => 0, 'precio_5' => null, 'precio_6' => null, 'cache_updated_at' => null],
    // Cadenas vacías y espacios (el extractor las normaliza a null).
    ['id' => 'LNP0000013', 'referencia' => '  ', 'descripcion' => '', 'marca' => '   ', 'url_imagen' => '', 'categoria' => '', 'disponible' => 3, 'precio_5' => '0.0000', 'precio_6' => '0.5000', 'cache_updated_at' => '2026-09-25 16:58:52'],
    // `disponible` negativo en la fuente: se trunca a 0.
    ['id' => 'LNP0000014', 'referencia' => 'NEG-STOCK', 'descripcion' => 'PRODUCTO CON DISPONIBLE NEGATIVO', 'marca' => 'FLUA', 'url_imagen' => null, 'categoria' => 'ACCESORIOS', 'disponible' => -7, 'precio_5' => '3.5000', 'precio_6' => '4.0000', 'cache_updated_at' => '2026-09-25 16:58:52'],
    ['id' => 'LNP0000015', 'referencia' => 'DOWNLIGHT-12W', 'descripcion' => 'DOWNLIGHT LED 12W REDONDO EMPOTRAR', 'marca' => 'PHILIPS', 'url_imagen' => null, 'categoria' => 'DOWNLIGHTS', 'disponible' => 412, 'precio_5' => '6.8000', 'precio_6' => '7.3000', 'cache_updated_at' => '2026-09-25 16:58:53'],
    ['id' => 'LNP0000016', 'referencia' => 'DOWNLIGHT-18W', 'descripcion' => 'DOWNLIGHT LED 18W REDONDO EMPOTRAR', 'marca' => 'PHILIPS', 'url_imagen' => null, 'categoria' => 'DOWNLIGHTS', 'disponible' => 0, 'precio_5' => '9.4000', 'precio_6' => '9.9000', 'cache_updated_at' => '2026-09-25 16:58:53'],
    ['id' => 'LNP0000017', 'referencia' => 'PROY-EXT-50W', 'descripcion' => 'PROYECTOR LED EXTERIOR 50W IP65', 'marca' => 'OSRAM', 'url_imagen' => 'https://lafayette.example/img/LNP0000017.jpg', 'categoria' => 'PROYECTORES', 'disponible' => 26, 'precio_5' => '44.9000', 'precio_6' => '45.4000', 'cache_updated_at' => '2026-09-25 16:58:54'],
    ['id' => 'LNP0000018', 'referencia' => 'SENSOR-MOV-360', 'descripcion' => 'SENSOR DE MOVIMIENTO 360 GRADOS', 'marca' => 'GENÉRICA', 'url_imagen' => null, 'categoria' => 'SENSORES', 'disponible' => 19, 'precio_5' => '7.7500', 'precio_6' => '8.2500', 'cache_updated_at' => '2026-09-25 16:58:54'],
    ['id' => 'LNP0000019', 'referencia' => 'BALASTRO-2X36', 'descripcion' => 'BALASTRO ELECTRÓNICO 2X36W', 'marca' => 'OSRAM', 'url_imagen' => null, 'categoria' => 'BALASTROS', 'disponible' => 2, 'precio_5' => '11.0000', 'precio_6' => '11.5000', 'cache_updated_at' => '2026-09-25 16:58:55'],
    // Mismo `id` repetido (la fuente es una VISTA: la unicidad no está garantizada). Gana la última aparición.
    ['id' => 'LNP0000001', 'referencia' => '331819AP2-PAB', 'descripcion' => 'LAMP COLG DECO 90W (DUPLICADO EN LA VISTA)', 'marca' => 'FLUA', 'url_imagen' => null, 'categoria' => 'LAMPARAS', 'disponible' => 29, 'precio_5' => '900.8000', 'precio_6' => '901.3000', 'cache_updated_at' => '2026-09-25 17:10:00'],
];
