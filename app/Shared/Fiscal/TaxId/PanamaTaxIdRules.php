<?php

declare(strict_types=1);

namespace App\Shared\Fiscal\TaxId;

/**
 * Panamá: RUC (jurídicas, naturales y extranjeras) o cédula, más un DV de 1 o 2 dígitos. El formato de la identificación NO se restringe
 * aquí (los hay de muchas formas: `8-123-456`, `PE-1-234`, `155704849-2-2021`) y el RUC real lo valida la DGI a través del PAC; solo se
 * cuida el DV, igual que antes de la Fase 6c (cero cambio de comportamiento para Panamá).
 */
final class PanamaTaxIdRules implements TaxIdCountryRules
{
    public function label(): string
    {
        return 'RUC';
    }

    public function usesDv(): bool
    {
        return true;
    }

    public function dvMaxLength(): int
    {
        return 2;
    }

    public function validate(string $taxId, ?string $dv): ?string
    {
        if ($dv !== null && $dv !== '' && preg_match('/^\d{1,2}$/', $dv) !== 1) {
            return 'El DV debe tener 1 o 2 dígitos.';
        }

        return null;
    }

    public function describe(): array
    {
        return ['label' => $this->label(), 'uses_dv' => true, 'dv_max_length' => 2];
    }
}
