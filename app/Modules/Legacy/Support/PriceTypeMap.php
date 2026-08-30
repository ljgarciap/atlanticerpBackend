<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Support;

/**
 * Mapeo confirmado con data real 2026-08-06 (Illumination/migracion-icg-diseno.md §6.1) entre
 * `PRECIOSVENTA.IDTARIFAV` y `price_type` de AtlanticERP. La tarifa 4 (D.BAHIA) está vencida desde
 * 2024-02-21 y NO tiene equivalente — `forIdTarifav(4)` devuelve `null` a propósito, que es lo
 * que `ProductoExtractor::mapPrecioRow()` usa para excluirla del staging.
 */
final class PriceTypeMap
{
    public const PUBLIC = 'public';
    public const PROJECT = 'project';
    public const SPECIAL = 'special';
    public const PARTNER = 'partner';

    /** @var array<int, string> */
    private const MAP = [
        1 => self::PUBLIC,
        2 => self::PROJECT,
        3 => self::SPECIAL,
        5 => self::PARTNER,
        // 4 => D.BAHIA, vencida — sin equivalente, se excluye a propósito.
    ];

    public static function forIdTarifav(int $idtarifav): ?string
    {
        return self::MAP[$idtarifav] ?? null;
    }

    public static function isMapped(int $idtarifav): bool
    {
        return array_key_exists($idtarifav, self::MAP);
    }
}
