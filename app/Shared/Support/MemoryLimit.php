<?php

declare(strict_types=1);

namespace App\Shared\Support;

/**
 * Sube el `memory_limit` del proceso actual a un mínimo, sin bajarlo nunca. Los jobs/comandos que
 * necesitan más que el default de Horizon (128M) usaban `ini_set('memory_limit', '512M')` a secas,
 * que también BAJA un límite ya mayor: en la suite de CI (setup-php `memory_limit=1G`, con pcov)
 * los tests de esos jobs dejaban el proceso en 512M para el resto de la corrida y pcov se quedaba
 * sin memoria al final (exit 255, el CD de dev/test/prod quedaba saltado).
 */
final class MemoryLimit
{
    public static function atLeast(string $minimum): void
    {
        $current = self::toBytes((string) ini_get('memory_limit'));

        if ($current === -1 || $current >= self::toBytes($minimum)) {
            return;
        }

        ini_set('memory_limit', $minimum);
    }

    /** Convierte "512M"/"1G"/"128K"/"-1"/bytes a bytes; `-1` (sin límite) se devuelve tal cual. */
    public static function toBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '-1') {
            return -1;
        }

        $number = (int) $value;

        return match (strtoupper(substr($value, -1))) {
            'G'     => $number * 1024 ** 3,
            'M'     => $number * 1024 ** 2,
            'K'     => $number * 1024,
            default => $number,
        };
    }
}
