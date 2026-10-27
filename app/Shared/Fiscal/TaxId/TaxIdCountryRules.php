<?php

declare(strict_types=1);

namespace App\Shared\Fiscal\TaxId;

/**
 * Fase 6c — reglas de la identificación fiscal de un país (RUC, NIT, EIN…): cómo se llama, si lleva dígito verificador (DV) y si un
 * valor concreto es válido. Es validación LOCAL de formato y de DV; la validación contra la autoridad (DGI, DIAN) es del PAC.
 */
interface TaxIdCountryRules
{
    /** Nombre corto que ve el usuario: «RUC», «NIT», «EIN». */
    public function label(): string;

    public function usesDv(): bool;

    /** Largo máximo del DV (0 si el país no usa DV). */
    public function dvMaxLength(): int;

    /**
     * Mensaje de error (en español) si el par identificación + DV no es válido; null si lo es. La identificación llega sin vacíos.
     */
    public function validate(string $taxId, ?string $dv): ?string;

    /** @return array{label: string, uses_dv: bool, dv_max_length: int} lo que el frontend necesita para armar el formulario */
    public function describe(): array;
}
