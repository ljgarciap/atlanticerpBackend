<?php

declare(strict_types=1);

namespace Tests\Feature\Legacy;

use App\Modules\Legacy\Contracts\MovimientoExtractorContract;
use RuntimeException;

/**
 * Test double del extractor de MOVIMENTS — sin SQL Server. Sirve filas ya mapeadas con la misma semántica
 * keyset que el real (`icg_id > $afterId`, ascendente, hasta $limit) y registra los `afterId` pedidos.
 * `failOnCall` (base 1) simula una caída de la conexión a ICG en esa llamada.
 */
final class FakeMovimientoExtractor implements MovimientoExtractorContract
{
    /** @var list<int> */
    public array $requestedAfterIds = [];

    /** @var list<array<string, mixed>> */
    private array $rows;

    /** @param list<array<string, mixed>> $rows */
    public function __construct(array $rows = [], private readonly ?int $failOnCall = null)
    {
        usort($rows, static fn (array $a, array $b): int => $a['icg_id'] <=> $b['icg_id']);
        $this->rows = $rows;
    }

    public function extractAfter(int $afterId, int $limit): array
    {
        $this->requestedAfterIds[] = $afterId;

        if ($this->failOnCall !== null && count($this->requestedAfterIds) >= $this->failOnCall) {
            throw new RuntimeException('Conexión a ICG perdida (simulada).');
        }

        $out = [];
        foreach ($this->rows as $row) {
            if ($row['icg_id'] > $afterId) {
                $out[] = $row;
                if (count($out) >= $limit) {
                    break;
                }
            }
        }

        return $out;
    }
}
