<?php

declare(strict_types=1);

namespace App\Shared\Fiscal\TaxId;

/**
 * Estados Unidos: EIN de empresas (`NN-NNNNNNN`) o, para personas, SSN/ITIN (`NNN-NN-NNNN`); 9 dígitos con o sin guiones. No hay DV.
 */
final class UnitedStatesTaxIdRules implements TaxIdCountryRules
{
    public function label(): string
    {
        return 'EIN / Tax ID';
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
            return 'En Estados Unidos la identificación fiscal no lleva dígito verificador.';
        }

        if (preg_match('/^(\d{2}-?\d{7}|\d{3}-?\d{2}-?\d{4})$/', trim($taxId)) !== 1) {
            return 'El EIN debe tener 9 dígitos (NN-NNNNNNN); un SSN/ITIN, NNN-NN-NNNN.';
        }

        return null;
    }

    public function describe(): array
    {
        return ['label' => $this->label(), 'uses_dv' => false, 'dv_max_length' => 0];
    }
}
