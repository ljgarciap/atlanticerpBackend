<?php

declare(strict_types=1);

namespace App\Modules\CRM\Services;

use App\Shared\Catalogs\CatalogEntry;
use App\Shared\Catalogs\CatalogService;

/**
 * Fase 22 (B5) — fachada de las etapas del CRM (dominio `crm_stage`). El código pregunta por la CATEGORÍA de una etapa
 * (abierta/ganada/perdida), no por su clave: una etapa propia de la empresa (siempre `abierta`) se intercala entre Lead y Cerrado
 * sin que los reportes, el tablero o los contactos tengan que conocerla. Mismo patrón que `PipelineStages` (Ventas & Diseño).
 */
final class CrmStages
{
    public const DOMAIN = 'crm_stage';

    public const LEAD = 'lead';

    public const CLOSED = 'cerrado';

    public const LOST = 'perdido';

    public const ABIERTA = 'abierta';

    public const GANADA = 'ganada';

    public const PERDIDA = 'perdida';

    /** @return list<string> claves activas, en el orden del catálogo */
    public static function keys(): array
    {
        return CatalogService::keys(self::DOMAIN);
    }

    /** @return list<string> etapas abiertas (en curso) */
    public static function open(): array
    {
        return self::byCategory(self::ABIERTA);
    }

    /** @return list<string> etapas ganadas */
    public static function won(): array
    {
        return self::byCategory(self::GANADA);
    }

    /** @return list<string> etapas perdidas */
    public static function lost(): array
    {
        return self::byCategory(self::PERDIDA);
    }

    public static function categoria(?string $stage): string
    {
        return (string) CatalogService::prop(self::DOMAIN, $stage, 'categoria');
    }

    public static function isLost(?string $stage): bool
    {
        return self::categoria($stage) === self::PERDIDA;
    }

    public static function isWon(?string $stage): bool
    {
        return self::categoria($stage) === self::GANADA;
    }

    /** @return array<string, string> etiquetas de TODAS las etapas (también desactivadas), clave => etiqueta */
    public static function labels(): array
    {
        return CatalogService::labels(self::DOMAIN, onlyActive: false);
    }

    /** @return list<string> */
    private static function byCategory(string $categoria): array
    {
        return array_values(array_map(
            static fn (CatalogEntry $e): string => $e->key,
            array_filter(
                CatalogService::entries(self::DOMAIN)->all(),
                static fn (CatalogEntry $e): bool => (string) ($e->props['categoria'] ?? self::ABIERTA) === $categoria,
            ),
        ));
    }
}
