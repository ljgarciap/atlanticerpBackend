<?php

declare(strict_types=1);

namespace Tests\Feature\Legacy;

use App\Modules\Legacy\Contracts\AlbaranExtractorContract;
use App\Modules\Legacy\Data\ExtractedAlbaranes;

/**
 * SCRUM-838 — variante mutable de FakeAlbaranExtractor, para tests que invocan el comando
 * incremental 2+ veces con datos DISTINTOS en cada corrida (ver MutableFakeProductoExtractor para
 * el gotcha real de `$this->artisan()` que motiva esto).
 */
final class MutableFakeAlbaranExtractor implements AlbaranExtractorContract
{
    public function __construct(private ExtractedAlbaranes $result)
    {
    }

    public function setResult(ExtractedAlbaranes $result): void
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
