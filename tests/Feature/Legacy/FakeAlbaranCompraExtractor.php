<?php

declare(strict_types=1);

namespace Tests\Feature\Legacy;

use App\Modules\Legacy\Contracts\AlbaranCompraExtractorContract;
use App\Modules\Legacy\Data\ExtractedAlbaranesCompra;
use RuntimeException;
use Throwable;

/**
 * Test double de `App\Modules\Legacy\Extractors\AlbaranCompraExtractor` — mismo patrón que
 * `FakeAlbaranExtractor`.
 */
final class FakeAlbaranCompraExtractor implements AlbaranCompraExtractorContract
{
    public function __construct(
        private readonly ?ExtractedAlbaranesCompra $result = null,
        private readonly ?Throwable $throws = null,
    ) {
    }

    public function extractAlbaranes(): array
    {
        return $this->result()->albaranes;
    }

    public function extractItemsPage(int $offset, int $limit): array
    {
        return array_values(array_slice($this->result()->items, $offset, $limit));
    }

    private function result(): ExtractedAlbaranesCompra
    {
        if ($this->throws !== null) {
            throw $this->throws;
        }

        return $this->result ?? throw new RuntimeException('FakeAlbaranCompraExtractor sin resultado configurado.');
    }
}
