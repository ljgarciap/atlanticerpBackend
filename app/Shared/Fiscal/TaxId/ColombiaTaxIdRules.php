<?php

declare(strict_types=1);

namespace App\Shared\Fiscal\TaxId;

/**
 * Colombia: NIT (6 a 10 dígitos, sin puntos ni guiones) con un DV de 1 dígito que se calcula con el módulo 11 de la DIAN (pesos 3, 7, 13,
 * 17, 19, 23, 29, 37, 41, 43, 47, 53, 59, 67, 71 de derecha a izquierda). El DV es opcional al capturar (igual que en Panamá: lo exige
 * quien factura), pero si viene tiene que ser el que corresponde al NIT.
 */
final class ColombiaTaxIdRules implements TaxIdCountryRules
{
    private const PESOS = [3, 7, 13, 17, 19, 23, 29, 37, 41, 43, 47, 53, 59, 67, 71];

    public function label(): string
    {
        return 'NIT';
    }

    public function usesDv(): bool
    {
        return true;
    }

    public function dvMaxLength(): int
    {
        return 1;
    }

    public function validate(string $taxId, ?string $dv): ?string
    {
        $nit = preg_replace('/[\s.\-]/', '', $taxId) ?? '';
        if (preg_match('/^\d{6,10}$/', $nit) !== 1) {
            return 'El NIT debe tener entre 6 y 10 dígitos (sin puntos ni guiones).';
        }

        if ($dv === null || $dv === '') {
            return null;
        }

        if (preg_match('/^\d$/', $dv) !== 1) {
            return 'El DV del NIT debe ser un solo dígito.';
        }

        $esperado = $this->calcularDv($nit);

        return (int) $dv === $esperado ? null : "El DV no corresponde a este NIT (debería ser {$esperado}).";
    }

    /** Dígito de verificación del NIT por el módulo 11. */
    public function calcularDv(string $nit): int
    {
        $suma = 0;
        foreach (array_reverse(str_split($nit)) as $i => $digito) {
            $suma += (int) $digito * self::PESOS[$i];
        }
        $resto = $suma % 11;

        return $resto > 1 ? 11 - $resto : $resto;
    }

    public function describe(): array
    {
        return ['label' => $this->label(), 'uses_dv' => true, 'dv_max_length' => 1];
    }
}
