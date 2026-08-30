<?php

declare(strict_types=1);

namespace Tests\Feature\Legacy;

use App\Modules\Legacy\Contracts\ProductoExtractorContract;
use App\Modules\Legacy\Data\ExtractedProductos;
use RuntimeException;
use Throwable;

/**
 * Test double de `App\Modules\Legacy\Extractors\ProductoExtractor` — el comando
 * `legacy:sync-productos` nunca debe requerir SQL Server real en la suite de tests (ver docblock
 * de `App\Providers\AppServiceProvider::register()`).
 */
final class FakeProductoExtractor implements ProductoExtractorContract
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

        return $this->result ?? throw new RuntimeException('FakeProductoExtractor sin resultado configurado.');
    }
}
