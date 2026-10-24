<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Lafayette\Data;

/**
 * SCRUM-990 (P1) — resultado de mapear una extracción completa: las filas aceptadas, cuántas se rechazaron (cuarentena) y por qué motivo
 * (código estable => cantidad; nunca el contenido de la fila).
 */
final readonly class MappedBatch
{
    /**
     * @param list<MappedLafayetteProducto> $productos
     * @param array<string, int> $motivos
     */
    public function __construct(
        public array $productos,
        public int $rechazadas,
        public array $motivos,
    ) {
    }
}
