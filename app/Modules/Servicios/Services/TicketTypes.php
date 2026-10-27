<?php

declare(strict_types=1);

namespace App\Modules\Servicios\Services;

use App\Shared\Catalogs\CatalogEntry;
use App\Shared\Catalogs\CatalogService;

/**
 * Fase 7b — fachada de los tipos de ticket (dominio `ticket_type` del catálogo). El código de Servicios pregunta por el COMPORTAMIENTO
 * de un tipo («¿es un reclamo?»), no por su clave: un tipo nuevo elige un comportamiento base y hereda su flujo. Un tipo desconocido
 * se trata como `generico`, con los valores por omisión del dominio.
 */
final class TicketTypes
{
    public const DOMAIN = 'ticket_type';

    public const INSTALACION = 'instalacion';

    public const GARANTIA = 'garantia';

    public const RECLAMO = 'reclamo';

    public const RETROFIT = 'retrofit';

    public const GENERICO = 'generico';

    /** @return list<string> claves activas (para reglas `in:`) */
    public static function keys(): array
    {
        return CatalogService::keys(self::DOMAIN);
    }

    /** @return array<string, string> clave => etiqueta de los tipos activos, en el orden del catálogo */
    public static function labels(): array
    {
        return CatalogService::entries(self::DOMAIN)->pluck('label', 'key')->map(strval(...))->all();
    }

    public static function comportamiento(?string $tipo): string
    {
        return (string) CatalogService::prop(self::DOMAIN, $tipo, 'comportamiento');
    }

    public static function is(?string $tipo, string $comportamiento): bool
    {
        return self::comportamiento($tipo) === $comportamiento;
    }

    /** @return list<string> claves activas cuyo comportamiento es `$comportamiento` (un comportamiento puede tener varios tipos) */
    public static function keysWith(string $comportamiento): array
    {
        return array_values(array_map(
            static fn (CatalogEntry $e): string => $e->key,
            array_filter(
                CatalogService::entries(self::DOMAIN)->all(),
                static fn (CatalogEntry $e): bool => ($e->props['comportamiento'] ?? 'generico') === $comportamiento,
            ),
        ));
    }

    public static function prefijo(?string $tipo): string
    {
        return (string) CatalogService::prop(self::DOMAIN, $tipo, 'prefijo');
    }

    public static function slaDias(?string $tipo): int
    {
        return (int) CatalogService::prop(self::DOMAIN, $tipo, 'sla_dias');
    }

    /** @return list<string> subtipos permitidos; vacío = el tipo no maneja subtipo (`subtipo` debe quedar null) */
    public static function subtipos(?string $tipo): array
    {
        /** @var list<string> $subtipos */
        $subtipos = (array) CatalogService::prop(self::DOMAIN, $tipo, 'subtipos');

        return $subtipos;
    }
}
