<?php

declare(strict_types=1);

namespace App\Shared\Http;

use Illuminate\Http\Request;

/**
 * SCRUM-992 (Pre-QA H8) — lectura segura de parámetros de query de tipo texto.
 *
 * `(string) $request->query('search')` con `?search[]=x` (un array) lanza "Array to string
 * conversion" y termina en 500. Convención única para los buscadores/listados: un parámetro NO
 * escalar (array, objeto) se trata como AUSENTE y cae al valor por defecto — la misma convención
 * que ya usaban los filtros con guarda `is_string()` (`rotation`, `brand`, `family_id`...). Nunca
 * se responde 422: la UI jamás manda un array en estos parámetros, así que solo una request
 * manipulada llega acá y el resultado inofensivo es "sin ese filtro".
 */
final class QueryParams
{
    public static function string(Request $request, string $key, string $default = ''): string
    {
        $value = $request->query($key);

        if ($value === null) {
            return $default;
        }

        return is_scalar($value) ? (string) $value : $default;
    }
}
