<?php

declare(strict_types=1);

/**
 * SCRUM-990 (P1) — filas HOSTILES o malformadas con la forma que devuelve PDO/mysqlnd para `vista_productos_lighting` (fuente de un TERCERO: nada de
 * lo que traiga debe poder abortar la sincronización ni inflar el índice). Cada fila documenta qué debe pasar con ella.
 * Se pasan por `ProductoExtractor::mapRow()` (el real) y luego por `ProductoMapper`.
 *
 * @return list<array<string, mixed>>
 */
$base = ['referencia' => 'REF-OK', 'descripcion' => 'DESCRIPCION', 'marca' => 'MARCA', 'url_imagen' => null, 'categoria' => 'CAT', 'disponible' => 1, 'precio_5' => '1.0000', 'precio_6' => '1.5000', 'cache_updated_at' => '2026-09-25 16:58:46'];

return [
    // 0 — fila sana (control).
    ['id' => 'HST0000', ...$base],
    // 1..5 — CUARENTENA por identificador.
    ['id' => str_repeat('X', 51), ...$base],                       // id de 51 caracteres (columna de 50)
    ['id' => '', ...$base],                                        // id vacío
    ['id' => "HST\0003", ...$base],                                // id con NUL
    ['id' => "HST\xC3\x28004", ...$base],                          // id con UTF-8 inválido
    ['id' => 'HST0005', ...[...$base, 'referencia' => str_repeat('R', 101)]], // referencia de 101 (columna de 100)
    // 6..9 — SANEADO de textos (la fila se conserva).
    ['id' => 'HST0006', ...[...$base, 'marca' => str_repeat('M', 300), 'categoria' => str_repeat('C', 300)]],
    ['id' => 'HST0007', ...[...$base, 'descripcion' => str_repeat('D', 50000)]],
    ['id' => 'HST0008', ...[...$base, 'descripcion' => "con\0nul y \x07campana\x7F y\ttab", 'marca' => "MAR\0CA"]],
    ['id' => 'HST0009', ...[...$base, 'descripcion' => "utf8 inv\xC3\x28alido"]],
    // 10..13 — enteros y precios fuera de rango.
    ['id' => 'HST0010', ...[...$base, 'disponible' => 99999999999]],     // > 2^31-1
    ['id' => 'HST0011', ...[...$base, 'disponible' => -5]],
    ['id' => 'HST0012', ...[...$base, 'precio_5' => '-5.0000', 'precio_6' => '-4.5000']],
    ['id' => 'HST0013', ...[...$base, 'precio_5' => '1e999', 'precio_6' => '1e999']],           // INF
    ['id' => 'HST0014', ...[...$base, 'precio_5' => '99999999999999.0', 'precio_6' => '99999999999999.5000']], // > tope plausible
    // 15..16 — fechas.
    ['id' => 'HST0015', ...[...$base, 'cache_updated_at' => 'no-es-una-fecha']],
    ['id' => 'HST0016', ...[...$base, 'cache_updated_at' => '0000-00-00 00:00:00']], // fecha "cero" de MariaDB
    // 17..24 — url_imagen: solo http(s) con host y largo acotado.
    ['id' => 'HST0017', ...[...$base, 'url_imagen' => 'javascript:alert(1)']],
    ['id' => 'HST0018', ...[...$base, 'url_imagen' => 'data:text/html,<script>alert(1)</script>']],
    ['id' => 'HST0019', ...[...$base, 'url_imagen' => 'ftp://files.example/a.png']],
    ['id' => 'HST0020', ...[...$base, 'url_imagen' => 'http://ok.example/a.png']],
    ['id' => 'HST0021', ...[...$base, 'url_imagen' => 'https://ok.example/b.png']],
    ['id' => 'HST0022', ...[...$base, 'url_imagen' => 'https://host.example/' . str_repeat('a', 3000)]],
    ['id' => 'HST0023', ...[...$base, 'url_imagen' => 'https:///sin-host']],
    ['id' => 'HST0024', ...[...$base, 'url_imagen' => 'https://con espacio.example/x']],
];
