<?php

declare(strict_types=1);

namespace App\Modules\CRM\Services;

use App\Shared\Catalogs\CatalogEntry;
use App\Shared\Catalogs\Contracts\CatalogDomainRules;
use Illuminate\Validation\ValidationException;

/**
 * Fase 22 (B5) — reglas del dominio `crm_stage`. Una etapa propia de la empresa solo puede intercalarse ENTRE Lead y Cerrado y sin pisar
 * el orden de una de sistema; su categoría es siempre `abierta` (lo garantiza el `system_only` de la propiedad).
 */
final class CrmStageRules implements CatalogDomainRules
{
    private const FALLBACK_LEAD = 100;

    private const FALLBACK_PROPOSAL = 400;

    private const FALLBACK_CLOSED = 500;

    public static function defaultSortOrder(): int
    {
        return self::orderOf('propuesta', self::FALLBACK_PROPOSAL) + 10;
    }

    public static function validate(string $domain, array $data, ?CatalogEntry $entry): void
    {
        if ($entry !== null && $entry->is_system) {
            return;
        }

        $sort = $data['sort_order'];
        $lead = self::orderOf(CrmStages::LEAD, self::FALLBACK_LEAD);
        $closed = self::orderOf(CrmStages::CLOSED, self::FALLBACK_CLOSED);
        if ($sort <= $lead || $sort >= $closed) {
            throw ValidationException::withMessages(['sort_order' => "Una etapa propia va entre Lead y Cerrado: su orden debe estar entre {$lead} y {$closed}."]);
        }

        $taken = CatalogEntry::query()->where('domain', $domain)->where('is_system', true)->where('sort_order', $sort)->exists();
        if ($taken) {
            throw ValidationException::withMessages(['sort_order' => 'Ese orden lo ocupa una etapa de sistema; elige otro para que el flujo no quede ambiguo.']);
        }
    }

    private static function orderOf(string $key, int $fallback): int
    {
        $order = CatalogEntry::query()->where('domain', CrmStages::DOMAIN)->where('key', $key)->value('sort_order');

        return $order === null ? $fallback : (int) $order;
    }
}
