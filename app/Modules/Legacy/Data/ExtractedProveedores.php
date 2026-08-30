<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Data;

/**
 * Resultado completo de `App\Modules\Legacy\Extractors\ProveedorExtractor::extract()` — la única
 * tabla origen (PROVEEDORES), ya mapeada a DTOs. Sin tablas relacionadas (a diferencia de
 * `ExtractedClientes`, que también trae CONTACTOSCLIENTES) — PROVEEDORES no tiene una tabla de
 * contactos equivalente en el subconjunto documentado (§2/§4 del diseño).
 */
final readonly class ExtractedProveedores
{
    /** @param list<ProveedorRow> $proveedores */
    public function __construct(
        public array $proveedores,
    ) {
    }

    /** @return list<int> Códigos de proveedor únicos de esta extracción. */
    public function icgCodproveedores(): array
    {
        return array_values(array_unique(array_map(
            fn (ProveedorRow $proveedor): int => $proveedor->icgCodproveedor,
            $this->proveedores,
        )));
    }
}
