<?php

declare(strict_types=1);

namespace Tests\Feature\Legacy;

use App\Modules\Legacy\Contracts\AlbaranCompraExtractorContract;
use App\Modules\Legacy\Data\ExtractedAlbaranesCompra;

/** SCRUM-838 — variante mutable de FakeAlbaranCompraExtractor, ver MutableFakeAlbaranExtractor. */
final class MutableFakeAlbaranCompraExtractor implements AlbaranCompraExtractorContract
{
    public function __construct(private ExtractedAlbaranesCompra $result)
    {
    }

    public function setResult(ExtractedAlbaranesCompra $result): void
    {
        $this->result = $result;
    }

    public function extractAlbaranes(): array
    {
        return $this->result->albaranes;
    }

    public function extractItemsPage(int $offset, int $limit): array
    {
        return array_values(array_slice($this->result->items, $offset, $limit));
    }
}
