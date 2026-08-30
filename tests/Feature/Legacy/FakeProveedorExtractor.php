<?php

declare(strict_types=1);

namespace Tests\Feature\Legacy;

use App\Modules\Legacy\Contracts\ProveedorExtractorContract;
use App\Modules\Legacy\Data\ExtractedProveedores;
use RuntimeException;
use Throwable;

/**
 * Test double de `App\Modules\Legacy\Extractors\ProveedorExtractor` — el comando
 * `legacy:sync-proveedores` nunca debe requerir SQL Server real en la suite de tests (ver
 * docblock de `App\Providers\AppServiceProvider::register()`).
 */
final class FakeProveedorExtractor implements ProveedorExtractorContract
{
    public function __construct(
        private readonly ?ExtractedProveedores $result = null,
        private readonly ?Throwable $throws = null,
    ) {
    }

    public function extract(): ExtractedProveedores
    {
        if ($this->throws !== null) {
            throw $this->throws;
        }

        return $this->result ?? throw new RuntimeException('FakeProveedorExtractor sin resultado configurado.');
    }
}
