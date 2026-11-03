<?php

declare(strict_types=1);

namespace App\Shared\Multitenancy;

use App\Shared\Fiscal\TaxId\TaxIdRulesRegistry;

/**
 * Fase 6a — punto único para leer el contexto regional del tenant activo (zona horaria, moneda, país, locale).
 * Sin tenant activo (comandos, tests sin tenant) cae en la zona horaria de la aplicación (`config('app.timezone')`).
 */
final class TenantRegion
{
    /** Zona horaria del negocio: la del tenant activo, o la de la aplicación si no hay tenant. */
    public static function timezone(): string
    {
        $tz = Tenant::current()?->timezone;

        return is_string($tz) && $tz !== '' ? $tz : (string) config('app.timezone');
    }

    public static function currency(): string
    {
        return Tenant::current()?->region()['currency'] ?? 'USD';
    }

    public static function country(): string
    {
        return Tenant::current()?->region()['country'] ?? (string) config('regions.default', 'PA');
    }

    public static function locale(): string
    {
        return Tenant::current()?->region()['locale'] ?? 'es-PA';
    }

    /** Fase 6d — nombre visible del impuesto sobre ventas del país del tenant (ITBMS en Panamá, IVA en Colombia, Sales tax en EE. UU.). */
    public static function taxLabel(?string $country = null): string
    {
        $labels = (array) config('fiscal.tax_labels', []);

        return (string) ($labels[$country ?? self::country()] ?? $labels['default'] ?? 'ITBMS');
    }

    /** Fase 17b — nombre de la identificación fiscal del país del tenant («RUC» en Panamá, «NIT» en Colombia, «EIN / Tax ID» en EE. UU.). */
    public static function fiscalIdLabel(?string $country = null): string
    {
        return TaxIdRulesRegistry::forCountry($country ?? self::country())->label();
    }

    /** Fase 17b — cómo nombrar el campo en un mensaje: en Panamá «RUC o cédula» (el campo admite ambos); en otro país, solo su identificación. */
    public static function fiscalIdPhrase(?string $country = null): string
    {
        $pais = $country ?? self::country();

        return $pais === 'PA' ? 'RUC o cédula' : self::fiscalIdLabel($pais);
    }

    /** ¿Es una zona horaria IANA válida? */
    public static function isValidTimezone(string $timezone): bool
    {
        return in_array($timezone, \DateTimeZone::listIdentifiers(), true);
    }
}
