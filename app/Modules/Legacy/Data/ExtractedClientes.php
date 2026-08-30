<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Data;

/**
 * Resultado completo de `App\Modules\Legacy\Extractors\ClienteExtractor::extract()` — las 2 tablas
 * origen (CLIENTES/CONTACTOSCLIENTES), ya mapeadas a DTOs.
 */
final readonly class ExtractedClientes
{
    /**
     * @param list<ClienteRow>         $clientes
     * @param list<ContactoClienteRow> $contactos
     */
    public function __construct(
        public array $clientes,
        public array $contactos,
    ) {
    }

    /** @return list<int> Códigos de cliente únicos de esta extracción (para scopear el Matcher a esta corrida, no a todo el historial de staging). */
    public function icgCodclientes(): array
    {
        return array_values(array_unique(array_map(
            fn (ClienteRow $cliente): int => $cliente->icgCodcliente,
            $this->clientes,
        )));
    }
}
