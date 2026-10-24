<?php

declare(strict_types=1);

namespace App\Modules\Compras\Support;

/**
 * SCRUM-1127 — validaciones en CÓDIGO de lo que devuelve la IA al traducir descripciones de producto. El prompt le pide conservar las
 * especificaciones técnicas, pero el prompt no es un control: acá se verifica. Cualquier violación hace fallar TODA la generación (nunca
 * se guarda ni se entrega una traducción parcial). Puro: sin DB, sin red.
 */
final class TranslationGuard
{
    /**
     * Extrae el objeto JSON `{ "<id>": "<traducción>" }` de la respuesta del modelo (tolera texto o ``` alrededor).
     * Exige EXACTAMENTE el conjunto de ids enviados, y solo textos; si no, lanza.
     *
     * @param list<string> $expectedIds
     * @return array<string, string>
     * @throws \UnexpectedValueException
     */
    public static function parseResponse(string $raw, array $expectedIds): array
    {
        $start = strpos($raw, '{');
        $end   = strrpos($raw, '}');
        if ($start === false || $end === false || $end < $start) {
            throw new \UnexpectedValueException('La respuesta de la IA no trae un objeto JSON.');
        }

        $data = json_decode(substr($raw, $start, $end - $start + 1), true);
        if (! is_array($data)) {
            throw new \UnexpectedValueException('La respuesta de la IA no es un JSON válido.');
        }

        $out = [];
        foreach ($data as $id => $text) {
            if (! is_string($text) || trim($text) === '') {
                throw new \UnexpectedValueException("La traducción del ítem {$id} está vacía o no es texto.");
            }
            $out[(string) $id] = trim($text);
        }

        $missing = array_diff($expectedIds, array_keys($out));
        $extra   = array_diff(array_keys($out), $expectedIds);
        if ($missing !== [] || $extra !== []) {
            throw new \UnexpectedValueException('La IA no devolvió exactamente los ítems pedidos.');
        }

        return $out;
    }

    /**
     * Fragmentos técnicos del original: cada pieza (separada por espacios o "/") que contiene un dígito — 3K, 24V, 10W, 5M, IP67, 60X60,
     * 6500K, referencias de modelo — recortada de puntuación. Deben aparecer IDÉNTICOS en la traducción.
     *
     * @return list<string>
     */
    public static function technicalTokens(string $source): array
    {
        $tokens = [];
        foreach (preg_split('/[\s\/]+/u', $source, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $piece) {
            $piece = trim($piece, " \t\n\r\0\x0B,.;:()[]{}\"'");
            if ($piece !== '' && preg_match('/\d/u', $piece) === 1) {
                $tokens[] = $piece;
            }
        }

        return array_values(array_unique($tokens));
    }

    /** @throws \UnexpectedValueException si falta algún fragmento técnico o la traducción es desproporcionada */
    public static function assertValid(string $source, string $translated, int $lengthFactor, int $lengthMargin): void
    {
        foreach (self::technicalTokens($source) as $token) {
            if (! str_contains($translated, $token)) {
                throw new \UnexpectedValueException("La traducción alteró el dato técnico «{$token}».");
            }
        }

        if (mb_strlen($translated) > mb_strlen($source) * $lengthFactor + $lengthMargin) {
            throw new \UnexpectedValueException('La traducción es desproporcionadamente larga respecto al original.');
        }

        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $translated) === 1) {
            throw new \UnexpectedValueException('La traducción contiene caracteres de control.');
        }
    }

    /** Clave de caché: sha256 del texto con los espacios colapsados (las mayúsculas SÍ cuentan: «CINTA» y «Cinta» se traducen distinto). */
    public static function hashText(string $text): string
    {
        return hash('sha256', preg_replace('/\s+/u', ' ', trim($text)) ?? '');
    }
}
