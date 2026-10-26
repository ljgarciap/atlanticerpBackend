<?php

declare(strict_types=1);

namespace App\Shared\Multitenancy;

/**
 * Fase 6a — resuelve el contexto regional de un tenant a partir de las opciones de un comando: parte del país (catálogo
 * `config/regions.php`) y deja que cada opción explícita lo sobrescriba. Devuelve el mensaje de error (string) si algo es inválido.
 */
final class RegionOptions
{
    /** @return array{country: string, currency: string, timezone: string, locale: string}|string */
    public static function resolve(?string $country, ?string $currency, ?string $timezone, ?string $locale): array|string
    {
        /** @var array<string, array{name: string, currency: string, timezone: string, locale: string}> $catalogo */
        $catalogo = (array) config('regions.countries');
        $country = strtoupper($country !== null && $country !== '' ? $country : (string) config('regions.default', 'PA'));

        if (! isset($catalogo[$country])) {
            return "País [{$country}] fuera del catálogo (config/regions.php): ".implode(', ', array_keys($catalogo)).'.';
        }

        $region = [
            'country' => $country,
            'currency' => strtoupper($currency ?: $catalogo[$country]['currency']),
            'timezone' => $timezone ?: $catalogo[$country]['timezone'],
            'locale' => $locale ?: $catalogo[$country]['locale'],
        ];

        if (! TenantRegion::isValidTimezone($region['timezone'])) {
            return "Zona horaria [{$region['timezone']}] no es una zona IANA válida.";
        }
        if (preg_match('/^[A-Z]{3}$/', $region['currency']) !== 1) {
            return "Moneda [{$region['currency']}] debe ser un código ISO-4217 de 3 letras.";
        }

        return $region;
    }
}
