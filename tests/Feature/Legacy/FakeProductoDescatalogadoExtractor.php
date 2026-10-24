<?php

declare(strict_types=1);

namespace Tests\Feature\Legacy;

use App\Modules\Legacy\Contracts\ProductoDescatalogadoExtractorContract;
use App\Modules\Legacy\Data\ExtractedProductos;
use RuntimeException;
use Throwable;

/** Test double de `ProductoDescatalogadoExtractor`: el comando nunca debe requerir SQL Server real en la suite. */
final class FakeProductoDescatalogadoExtractor implements ProductoDescatalogadoExtractorContract
{
    public function __construct(
        private readonly ?ExtractedProductos $result = null,
        private readonly ?Throwable $throws = null,
    ) {
    }

    public function extract(): ExtractedProductos
    {
        if ($this->throws !== null) {
            throw $this->throws;
        }

        return $this->result ?? throw new RuntimeException('FakeProductoDescatalogadoExtractor sin resultado configurado.');
    }
}
