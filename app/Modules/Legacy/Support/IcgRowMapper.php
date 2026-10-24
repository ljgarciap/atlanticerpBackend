<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Support;

use DateTimeImmutable;
use DateTimeInterface;
use RuntimeException;
use Throwable;

/**
 * SCRUM-1136 F1 — mapeo puro (sin DB, sin red) de una fila cruda de ICG a una fila de staging
 * `legacy.*` en snake_case. Lo usan los extractores de almacenes/stock/costos/traspasos/inventarios.
 *
 * Especificación de columna: `staging_col => ['COLUMNA_ICG' | ['CANDIDATA_1','CANDIDATA_2'], tipo]`
 * con tipo `string` (trim; vacío => null salvo en llaves), `text` (sin trim), `float`, `int`, `bool` o
 * `datetime` (`Y-m-d H:i:s`). La primera candidata presente en la fila gana; si ninguna existe el
 * valor es null.
 *
 * Fechas: los `datetime` de ICG no traen zona; se interpretan en America/Panama (zona de la app y de la
 * sesión Postgres) y se guardan en `timestamptz` con precisión de segundos (`Y-m-d H:i:s`). Ojo:
 * `inventarios_cab.fecha` es parte de la llave natural, así que una fecha con fracción de segundo se trunca.
 *
 * Las columnas de ICG que no estén en la especificación se ignoran (el esquema real está tipado completo
 * en las migraciones). Las claves de la fila se normalizan a mayúsculas.
 */
final class IcgRowMapper
{
    /**
     * @param array<string, mixed>                                   $row   fila cruda de ICG
     * @param array<string, array{0: string|list<string>, 1: string}> $keys  llaves naturales (obligatorias)
     * @param array<string, array{0: string|list<string>, 1: string}> $typed columnas tipadas opcionales
     * @param string $tabla tabla de ICG (solo para los mensajes de error)
     * @return array<string, mixed> staging_col => valor
     */
    public static function map(array $row, array $keys, array $typed, string $tabla = ''): array
    {
        $upper = [];
        foreach ($row as $name => $value) {
            $upper[strtoupper((string) $name)] = $value;
        }

        $out = [];

        foreach ($keys as $stagingCol => [$candidates, $type]) {
            [, $value] = self::pick($upper, $candidates);
            $cast      = self::cast($value, $type, true);
            if ($cast === null) {
                $partial = [];
                foreach ($keys as [$keyCandidates]) {
                    [$keyCol, $keyValue] = self::pick($upper, $keyCandidates);
                    if ($keyCol !== null && $keyValue !== null) {
                        $partial[] = $keyCol . '=' . (is_scalar($keyValue) ? (string) $keyValue : get_debug_type($keyValue));
                    }
                }
                throw new RuntimeException(
                    "Llave natural '{$stagingCol}' nula o inválida en una fila de ICG"
                    . ($tabla !== '' ? " (tabla {$tabla})" : '') . ' (columna '
                    . (is_array($candidates) ? implode('|', $candidates) : $candidates) . '). Llave parcial: '
                    . ($partial === [] ? '(ninguna)' : implode(', ', $partial)) . '.'
                );
            }
            $out[$stagingCol] = $cast;
        }

        foreach ($typed as $stagingCol => [$candidates, $type]) {
            [, $value] = self::pick($upper, $candidates);
            $out[$stagingCol] = self::cast($value, $type, false);
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $upper
     * @param string|list<string>  $candidates
     * @return array{0: string|null, 1: mixed}
     */
    private static function pick(array $upper, string|array $candidates): array
    {
        foreach ((array) $candidates as $candidate) {
            $candidate = strtoupper($candidate);
            if (array_key_exists($candidate, $upper)) {
                return [$candidate, $upper[$candidate]];
            }
        }

        return [null, null];
    }

    private static function cast(mixed $value, string $type, bool $isKey): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($type) {
            'string'   => self::castString($value, $isKey),
            'text'     => self::stripNul((string) $value),
            'float'    => is_numeric($value) ? (float) $value : null,
            'int'      => is_numeric($value) ? (int) $value : null,
            'bool'     => self::castBool($value),
            'datetime' => self::castDateTime($value),
            default    => throw new RuntimeException("Tipo de columna desconocido '{$type}'."),
        };
    }

    private static function castString(mixed $value, bool $isKey): ?string
    {
        $trimmed = trim(self::stripNul((string) $value));

        // Una llave vacía es un valor legítimo (ej. TALLA/COLOR sin variante); una columna opcional
        // vacía se trata como "sin dato".
        return ($trimmed === '' && ! $isKey) ? null : $trimmed;
    }

    private static function castBool(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if ($value === '' ) {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }

    private static function castDateTime(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }
        if ($value === '') {
            return null;
        }

        try {
            return (new DateTimeImmutable((string) $value))->format('Y-m-d H:i:s');
        } catch (Throwable) {
            return null;
        }
    }

    /** Postgres (text/jsonb) rechaza el byte NUL. */
    private static function stripNul(string $value): string
    {
        return str_replace("\0", '', $value);
    }
}
