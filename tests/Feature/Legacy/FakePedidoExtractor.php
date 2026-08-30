<?php

declare(strict_types=1);

namespace Tests\Feature\Legacy;

use App\Modules\Legacy\Contracts\PedidoExtractorContract;
use App\Modules\Legacy\Data\ExtractedPedidos;
use RuntimeException;
use Throwable;

/**
 * Test double de `App\Modules\Legacy\Extractors\PedidoExtractor` — el comando
 * `legacy:sync-pedidos` nunca debe requerir SQL Server real en la suite de tests. Mismo patrón
 * que `FakeCotizacionExtractor`/`FakeClienteExtractor`.
 *
 * Expone el contrato paginado real (`extractPedidos()`/`extractItemsPage()`, ver docblock de
 * `PedidoExtractorContract`) — `extractItemsPage()` pagina sobre `$result->items` con
 * `array_slice()`, mismo criterio que `FakeCotizacionExtractor`.
 */
final class FakePedidoExtractor implements PedidoExtractorContract
{
    public function __construct(
        private readonly ?ExtractedPedidos $result = null,
        private readonly ?Throwable $throws = null,
    ) {
    }

    public function extractPedidos(): array
    {
        return $this->result()->pedidos;
    }

    public function extractItemsPage(int $offset, int $limit): array
    {
        return array_values(array_slice($this->result()->items, $offset, $limit));
    }

    private function result(): ExtractedPedidos
    {
        if ($this->throws !== null) {
            throw $this->throws;
        }

        return $this->result ?? throw new RuntimeException('FakePedidoExtractor sin resultado configurado.');
    }
}
