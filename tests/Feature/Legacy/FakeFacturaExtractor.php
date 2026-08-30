<?php

declare(strict_types=1);

namespace Tests\Feature\Legacy;

use App\Modules\Legacy\Contracts\FacturaExtractorContract;
use App\Modules\Legacy\Data\ExtractedFacturas;
use RuntimeException;
use Throwable;

/**
 * Test double de `App\Modules\Legacy\Extractors\FacturaExtractor` — el comando
 * `legacy:sync-facturas` nunca debe requerir SQL Server real en la suite de tests. Mismo patrón
 * que `FakeAlbaranExtractor`.
 */
final class FakeFacturaExtractor implements FacturaExtractorContract
{
    public function __construct(
        private readonly ?ExtractedFacturas $result = null,
        private readonly ?Throwable $throws = null,
    ) {
    }

    public function extract(): ExtractedFacturas
    {
        if ($this->throws !== null) {
            throw $this->throws;
        }

        return $this->result ?? throw new RuntimeException('FakeFacturaExtractor sin resultado configurado.');
    }
}
