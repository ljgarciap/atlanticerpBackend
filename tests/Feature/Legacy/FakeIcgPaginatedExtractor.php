<?php

declare(strict_types=1);

namespace Tests\Feature\Legacy;

use App\Modules\Legacy\Contracts\AlmacenExtractorContract;
use App\Modules\Legacy\Contracts\CostoAlmacenExtractorContract;
use App\Modules\Legacy\Contracts\InventarioCabExtractorContract;
use App\Modules\Legacy\Contracts\StockAlmacenExtractorContract;
use App\Modules\Legacy\Contracts\TraspasoCabExtractorContract;
use RuntimeException;

/**
 * Test double de los 5 extractores paginados de almacenes de ICG — sin SQL Server. Sirve filas ya
 * mapeadas (columnas de staging) con `array_slice`, igual que lo haría el extractor real, y registra los
 * offsets pedidos. `failFromOffset` simula una caída de la conexión a ICG a partir de ese offset.
 */
final class FakeIcgPaginatedExtractor implements
    AlmacenExtractorContract,
    StockAlmacenExtractorContract,
    CostoAlmacenExtractorContract,
    TraspasoCabExtractorContract,
    InventarioCabExtractorContract
{
    /** @var list<int> */
    public array $requestedOffsets = [];

    /** @param list<array<string, mixed>> $rows */
    public function __construct(
        private readonly array $rows = [],
        private readonly ?int $failFromOffset = null,
    ) {
    }

    public function extractPage(int $offset, int $limit): array
    {
        $this->requestedOffsets[] = $offset;

        if ($this->failFromOffset !== null && $offset >= $this->failFromOffset) {
            throw new RuntimeException('Conexión a ICG perdida (simulada).');
        }

        return array_values(array_slice($this->rows, $offset, $limit));
    }
}
