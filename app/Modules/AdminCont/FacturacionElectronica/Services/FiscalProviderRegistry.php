<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\FacturacionElectronica\Services;

/**
 * Fase 6b — catálogo de proveedores de facturación electrónica por país (`config/fiscal.php`). Solo responde preguntas: qué proveedores
 * sirven en un país, cuál es el que corresponde por omisión y si un proveedor es válido para ese país. No construye adaptadores
 * (eso es `PacProviderFactory`) ni toca la base.
 */
final class FiscalProviderRegistry
{
    public const NINGUNO = 'none';

    /**
     * Proveedores disponibles para un país, con su etiqueta.
     *
     * @return array<string, string> clave => etiqueta
     */
    public function paraPais(string $country): array
    {
        $country = strtoupper($country);
        $out = [];
        foreach ((array) config('fiscal.providers') as $key => $def) {
            $paises = (array) ($def['countries'] ?? []);
            if (in_array('*', $paises, true) || in_array($country, $paises, true)) {
                $out[(string) $key] = (string) $def['label'];
            }
        }

        return $out;
    }

    public function permitido(string $provider, string $country): bool
    {
        return array_key_exists($provider, $this->paraPais($country));
    }

    /** Proveedor que corresponde a un país sin elección explícita: el de `default_by_country`, o `fallback` (`none`). */
    public function porDefecto(string $country): string
    {
        $porPais = (array) config('fiscal.default_by_country');

        return (string) ($porPais[strtoupper($country)] ?? config('fiscal.fallback', self::NINGUNO));
    }

    /** @return list<string> todas las claves de proveedor conocidas */
    public function claves(): array
    {
        return array_map('strval', array_keys((array) config('fiscal.providers')));
    }

    public function etiqueta(string $provider): string
    {
        return (string) (((array) config('fiscal.providers'))[$provider]['label'] ?? $provider);
    }
}
