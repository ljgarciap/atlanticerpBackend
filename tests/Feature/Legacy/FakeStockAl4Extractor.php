<?php

declare(strict_types=1);

namespace Tests\Feature\Legacy;

use App\Modules\Legacy\Contracts\StockAl4ExtractorContract;
use RuntimeException;

/** Test double de `StockAl4Extractor` — la suite nunca requiere SQL Server real. */
final class FakeStockAl4Extractor implements StockAl4ExtractorContract
{
    /** @param array<int, float> $stock */
    public function __construct(private array $stock = [], private readonly bool $falla = false)
    {
    }

    /** @param array<int, float> $stock */
    public function poner(array $stock): void
    {
        $this->stock = $stock;
    }

    public function extract(): array
    {
        if ($this->falla) {
            throw new RuntimeException('ICG no responde (fake)');
        }

        return $this->stock;
    }
}
