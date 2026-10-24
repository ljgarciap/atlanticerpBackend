<?php

declare(strict_types=1);

namespace Tests\Feature\Legacy;

use App\Modules\Legacy\Contracts\PagoExtractorContract;
use App\Modules\Legacy\Data\ExtractedPagos;

/**
 * SCRUM-838 — variante mutable de FakePagoExtractor (mismo gotcha real de `$this->artisan()`
 * reutilizando la instancia del comando entre corridas — ver MutableFakeProductoExtractor).
 */
final class MutableFakePagoExtractor implements PagoExtractorContract
{
    public function __construct(private ExtractedPagos $result)
    {
    }

    public function setResult(ExtractedPagos $result): void
    {
        $this->result = $result;
    }

    public function extractPage(int $offset, int $limit): array
    {
        return array_values(array_slice($this->result->pagos, $offset, $limit));
    }
}
