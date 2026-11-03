<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Fase 17a — el tenant no admite dos subclientes con la misma identificación fiscal. Los fixtures que crean varios en un mismo test piden acá
 * una identificación distinta cada vez (`155-0000-1-0001`, `…-0002`, …); la secuencia no se reinicia entre tests, solo importa que no se repita.
 */
final class UniqueTaxId
{
    private static int $sequence = 0;

    public static function next(): string
    {
        self::$sequence++;

        return sprintf('155-0000-1-%04d', self::$sequence);
    }
}
