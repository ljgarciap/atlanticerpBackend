<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Contracts;

/**
 * SCRUM-1137 F1 — agregados del lado ICG para `legacy:verify-clientes-raw` (solo conteos y llaves, nunca
 * valores de columnas). Permite fakearlo en los tests sin SQL Server.
 */
interface ClienteRawVerificationExtractorContract
{
    /**
     * Conteo de filas de ICG por entidad de `ClienteRawEntidades`.
     *
     * @return array<string, int>
     */
    public function conteos(): array;

    /**
     * Llaves naturales (CODCLIENTE / ID) presentes hoy en ICG para la entidad.
     *
     * @return list<int>
     */
    public function llaves(string $entidad): array;
}
