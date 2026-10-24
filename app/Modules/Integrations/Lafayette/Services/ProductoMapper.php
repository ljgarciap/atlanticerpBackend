<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Lafayette\Services;

use App\Modules\Integrations\Lafayette\Data\ExtractedProductos;
use App\Modules\Integrations\Lafayette\Data\LafayetteProductoRow;
use App\Modules\Integrations\Lafayette\Data\MappedBatch;
use App\Modules\Integrations\Lafayette\Data\MappedLafayetteProducto;
use App\Modules\Integrations\Lafayette\Exceptions\FilaRechazadaException;
use Carbon\Carbon;

/**
 * SCRUM-971 / SCRUM-990 (épica SCRUM-969) — mapea `LafayetteProductoRow` (fuente EXTERNA, controlada por otra empresa) al shape de
 * `bodega.lafayette_productos`. Pura: sin DB, sin I/O, testeable con filas fake.
 *
 * Los datos de un tercero son hostiles por defecto (P1 de la revisión de seguridad): una sola fila malformada no puede abortar la
 * sincronización completa ni inflar el índice. Por fila:
 *  - CUARENTENA (se omite y se cuenta por motivo, sin abortar): `id` vacío, con UTF-8 inválido o caracteres de control, o de más de 50
 *    caracteres, y `referencia` de más de 100 (son identificadores: truncarlos produciría datos equivocados).
 *  - SANEADO (se conserva la fila): se corrige UTF-8 inválido, se quitan caracteres de control (incluido `\0`), se truncan `descripcion`
 *    (tope configurable), `marca` y `categoria` (255); `url_imagen` solo si es `http(s)` con host y de largo acotado, otro esquema (`javascript:`,
 *    `data:`...) => `null`; `disponible` se acota a [0, 2^31-1]; `precio_5` => `null` si no es finito, es negativo o excede el tope; fecha
 *    ilegible o fuera de 1970-2100 (p. ej. `0000-00-00` de MariaDB) => `null`.
 *
 * Sin exclusiones por falta de `referencia` o `descripcion` (el espejo guarda lo que la fuente trae, `null` incluido).
 */
final class ProductoMapper
{
    public const MAX_ID = 50;

    public const MAX_REFERENCIA = 100;

    public const MAX_TEXTO = 255;

    private const MAX_INT = 2147483647;

    /** Caracteres de control salvo tab, salto de línea y retorno de carro (y DEL). */
    private const CONTROL = '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u';

    /** @return list<MappedLafayetteProducto> solo las filas aceptadas */
    public function buildPayloads(ExtractedProductos $extracted): array
    {
        return $this->buildBatch($extracted)->productos;
    }

    /** Mapea toda la extracción con cuarentena por fila. */
    public function buildBatch(ExtractedProductos $extracted): MappedBatch
    {
        $accepted = [];
        $reasons = [];
        $rejected = 0;

        foreach ($extracted->productos as $row) {
            try {
                $accepted[] = self::mapOne($row);
            } catch (FilaRechazadaException $e) {
                $rejected++;
                $reasons[$e->motivo] = ($reasons[$e->motivo] ?? 0) + 1;
            }
        }

        return new MappedBatch($accepted, $rejected, $reasons);
    }

    /** @throws FilaRechazadaException si la fila debe ir a cuarentena */
    public static function mapOne(LafayetteProductoRow $row): MappedLafayetteProducto
    {
        $id = self::identificador($row->id);
        $referencia = self::texto($row->referencia, PHP_INT_MAX);
        if ($referencia !== null && mb_strlen($referencia) > self::MAX_REFERENCIA) {
            // Es un identificador: truncarla produciría un dato equivocado, así que la fila va a cuarentena.
            throw new FilaRechazadaException(FilaRechazadaException::REFERENCIA_DEMASIADO_LARGA);
        }

        return new MappedLafayetteProducto(
            lafayetteId: $id,
            referencia: $referencia,
            descripcion: self::texto($row->descripcion, max(1, (int) config('lafayette.descripcion_max', 2000))),
            marca: self::texto($row->marca, self::MAX_TEXTO),
            categoria: self::texto($row->categoria, self::MAX_TEXTO),
            urlImagen: self::urlHttp($row->urlImagen),
            disponible: max(0, min(self::MAX_INT, $row->disponible)),
            precio5: self::precio($row->precio5),
            precio6: self::precio($row->precio6),
            fuenteActualizadoAt: self::fecha($row->cacheUpdatedAt),
        );
    }

    /**
     * `id` de la fuente (clave de upsert): no se "arregla", se rechaza la fila si está vacío, trae UTF-8 inválido o caracteres de control, o
     * excede la columna (50).
     *
     * @throws FilaRechazadaException
     */
    private static function identificador(string $value): string
    {
        $valid = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
        if ($valid !== $value || preg_match(self::CONTROL, $valid) === 1) {
            throw new FilaRechazadaException(FilaRechazadaException::ID_INVALIDO);
        }

        $valid = trim($valid);
        if ($valid === '') {
            throw new FilaRechazadaException(FilaRechazadaException::ID_VACIO);
        }
        if (mb_strlen($valid) > self::MAX_ID) {
            throw new FilaRechazadaException(FilaRechazadaException::ID_DEMASIADO_LARGO);
        }

        return $valid;
    }

    private static function texto(?string $value, int $max): ?string
    {
        if ($value === null) {
            return null;
        }

        $clean = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
        $clean = preg_replace(self::CONTROL, '', $clean) ?? '';
        $clean = trim($clean);
        if ($clean === '') {
            return null;
        }

        return mb_strlen($clean) > $max ? mb_substr($clean, 0, $max) : $clean;
    }

    /** Solo `http`/`https` con host, sin espacios ni caracteres de control, de largo acotado; cualquier otra cosa => null. */
    private static function urlHttp(?string $value): ?string
    {
        $url = self::texto($value, PHP_INT_MAX);
        if ($url === null || mb_strlen($url) > max(1, (int) config('lafayette.url_imagen_max', 2048)) || preg_match('/\s/u', $url) === 1) {
            return null;
        }

        $parts = parse_url($url);
        $scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';
        $host = is_array($parts) ? (string) ($parts['host'] ?? '') : '';

        return in_array($scheme, ['http', 'https'], true) && $host !== '' ? $url : null;
    }

    private static function precio(?float $value): ?float
    {
        if ($value === null || ! is_finite($value) || $value < 0 || $value > (float) config('lafayette.precio_max', 1.0e10)) {
            return null;
        }

        return $value;
    }

    private static function fecha(?string $value): ?Carbon
    {
        if ($value === null) {
            return null;
        }

        try {
            $date = Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }

        return $date->year >= 1970 && $date->year <= 2100 ? $date : null;
    }
}
