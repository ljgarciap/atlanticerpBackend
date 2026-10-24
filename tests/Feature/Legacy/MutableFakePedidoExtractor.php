<?php

declare(strict_types=1);

namespace Tests\Feature\Legacy;

use App\Modules\Legacy\Contracts\PedidoExtractorContract;
use App\Modules\Legacy\Data\ExtractedPedidos;

/** Variante mutable de FakePedidoExtractor — ver docblock de MutableFakeCotizacionExtractor. */
final class MutableFakePedidoExtractor implements PedidoExtractorContract
{
    public function __construct(private ExtractedPedidos $result)
    {
    }

    public function setResult(ExtractedPedidos $result): void
    {
        $this->result = $result;
    }

    public function extractPedidos(): array
    {
        return $this->result->pedidos;
    }

    public function extractItemsPage(int $offset, int $limit): array
    {
        return array_values(array_slice($this->result->items, $offset, $limit));
    }
}
