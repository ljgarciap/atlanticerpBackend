<?php

declare(strict_types=1);

namespace App\Shared\Fiscal\TaxId;

/**
 * País sin reglas propias: identificación alfanumérica de 3 a 100 caracteres (letras, números, guiones, puntos y espacios), sin DV.
 */
final class GenericTaxIdRules implements TaxIdCountryRules
{
    public function label(): string
    {
        return 'ID fiscal';
    }

    public function usesDv(): bool
    {
        return false;
    }

    public function dvMaxLength(): int
    {
        return 0;
    }

    public function validate(string $taxId, ?string $dv): ?string
    {
        if ($dv !== null && $dv !== '') {
            return 'Este país no usa dígito verificador.';
        }

        if (preg_match('/^[0-9A-Za-z.\-\s]{3,100}$/', $taxId) !== 1) {
            return 'La identificación fiscal admite letras, números, guiones y puntos (3 a 100 caracteres).';
        }

        return null;
    }

    public function describe(): array
    {
        return ['label' => $this->label(), 'uses_dv' => false, 'dv_max_length' => 0];
    }
}
