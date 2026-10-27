<?php

declare(strict_types=1);

namespace App\Shared\Fiscal\TaxId;

/**
 * Fase 6c — reglas de identificación fiscal por país (`config/fiscal.php` → `tax_id_rules`). Un país sin entrada usa `GenericTaxIdRules`.
 * Agregar un país = una clase que implemente `TaxIdCountryRules` + una fila en la config.
 */
final class TaxIdRulesRegistry
{
    public static function forCountry(string $country): TaxIdCountryRules
    {
        $clase = ((array) config('fiscal.tax_id_rules'))[strtoupper($country)] ?? GenericTaxIdRules::class;

        $reglas = app($clase);

        return $reglas instanceof TaxIdCountryRules ? $reglas : new GenericTaxIdRules;
    }
}
