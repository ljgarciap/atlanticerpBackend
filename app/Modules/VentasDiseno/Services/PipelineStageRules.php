<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Services;

use App\Shared\Catalogs\CatalogEntry;
use App\Shared\Catalogs\Contracts\CatalogDomainRules;
use Illuminate\Validation\ValidationException;

/**
 * Fase 7c — reglas del dominio `pipeline_stage`. Una etapa propia de la empresa solo puede intercalarse ENTRE Lead y Aprobado (su orden
 * cae estrictamente entre los de esas dos etapas de sistema) y sin pisar el orden de una de sistema; su categoría es siempre `abierta`
 * (lo garantiza el `system_only` de la propiedad). Las de sistema no se reordenan (`locked_when_system`).
 */
final class PipelineStageRules implements CatalogDomainRules
{
    private const FALLBACK_LEAD = 100;

    private const FALLBACK_PROPOSAL = 400;

    private const FALLBACK_APPROVED = 500;

    public static function defaultSortOrder(): int
    {
        return self::orderOf(PipelineStages::PROPOSAL, self::FALLBACK_PROPOSAL) + 10;
    }

    public static function validate(string $domain, array $data, ?CatalogEntry $entry): void
    {
        if ($entry !== null && $entry->is_system) {
            return; // las de sistema no cambian de orden (lo bloquea CatalogService)
        }

        $sort = $data['sort_order'];
        $lead = self::orderOf(PipelineStages::LEAD, self::FALLBACK_LEAD);
        $approved = self::orderOf(PipelineStages::APPROVED, self::FALLBACK_APPROVED);
        if ($sort <= $lead || $sort >= $approved) {
            throw ValidationException::withMessages(['sort_order' => "Una etapa propia va entre Lead y Aprobado: su orden debe estar entre {$lead} y {$approved}."]);
        }

        $taken = CatalogEntry::query()->where('domain', $domain)->where('is_system', true)->where('sort_order', $sort)->exists();
        if ($taken) {
            throw ValidationException::withMessages(['sort_order' => 'Ese orden lo ocupa una etapa de sistema; elige otro para que el flujo no quede ambiguo.']);
        }
    }

    private static function orderOf(string $key, int $fallback): int
    {
        $order = CatalogEntry::query()->where('domain', PipelineStages::DOMAIN)->where('key', $key)->value('sort_order');

        return $order === null ? $fallback : (int) $order;
    }
}
