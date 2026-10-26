<?php

declare(strict_types=1);

namespace App\Shared\Fiscal\TaxId;

use App\Shared\Multitenancy\TenantRegion;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Fase 6c — regla de validación de la identificación fiscal (campo `tax_id`) junto con su DV (`tax_id_dv`), según el país del tenant.
 * Como todo `ValidationRule`, no se evalúa cuando el campo viene vacío (Consumidor Final, identificación opcional). `$aplica` permite
 * saltarla según los datos del request (p. ej. un proveedor del exterior no usa las reglas del país de la empresa).
 */
final class ValidTaxId implements DataAwareRule, ValidationRule
{
    /** @var array<string, mixed> */
    private array $data = [];

    /** @param (Closure(array<string, mixed>): bool)|null $aplica */
    public function __construct(private readonly string $campoDv = 'tax_id_dv', private readonly ?Closure $aplica = null) {}

    /** @param array<string, mixed> $data */
    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($this->aplica !== null && ! ($this->aplica)($this->data)) {
            return;
        }

        $dv = $this->data[$this->campoDv] ?? null;
        $error = TaxIdRulesRegistry::forCountry(TenantRegion::country())
            ->validate(trim((string) $value), is_string($dv) ? trim($dv) : null);

        if ($error !== null) {
            $fail($error);
        }
    }
}
