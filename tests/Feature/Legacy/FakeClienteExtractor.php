<?php

declare(strict_types=1);

namespace Tests\Feature\Legacy;

use App\Modules\Legacy\Contracts\ClienteExtractorContract;
use App\Modules\Legacy\Data\ExtractedClientes;
use RuntimeException;
use Throwable;

/**
 * Test double de `App\Modules\Legacy\Extractors\ClienteExtractor` — el comando
 * `legacy:sync-clientes` nunca debe requerir SQL Server real en la suite de tests. Mismo patrón
 * que `FakeProductoExtractor` (SCRUM-750).
 */
final class FakeClienteExtractor implements ClienteExtractorContract
{
    public function __construct(
        private readonly ?ExtractedClientes $result = null,
        private readonly ?Throwable $throws = null,
    ) {
    }

    public function extract(): ExtractedClientes
    {
        if ($this->throws !== null) {
            throw $this->throws;
        }

        return $this->result ?? throw new RuntimeException('FakeClienteExtractor sin resultado configurado.');
    }
}
