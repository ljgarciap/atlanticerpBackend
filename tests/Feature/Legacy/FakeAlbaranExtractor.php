<?php

declare(strict_types=1);

namespace Tests\Feature\Legacy;

use App\Modules\Legacy\Contracts\AlbaranExtractorContract;
use App\Modules\Legacy\Data\ExtractedAlbaranes;
use RuntimeException;
use Throwable;

/**
 * Test double de `App\Modules\Legacy\Extractors\AlbaranExtractor` — el comando
 * `legacy:sync-albaranes` nunca debe requerir SQL Server real en la suite de tests. Mismo patrón
 * que `FakePedidoExtractor`/`FakeCotizacionExtractor`.
 *
 * Sigue configurándose con un `ExtractedAlbaranes` completo (albaranes+items) — más simple para los
 * fixtures de test que ya existen — pero lo expone a través del contrato paginado real
 * (`extractAlbaranes()`/`extractItemsPage()`, ver docblock de `AlbaranExtractorContract` para el
 * porqué): `extractItemsPage()` pagina sobre el array de `$result->items` en memoria con
 * `array_slice()`, que en el volumen chico de los tests (nunca SQL Server real) se resuelve en la
 * primera página siempre.
 */
final class FakeAlbaranExtractor implements AlbaranExtractorContract
{
    public function __construct(
        private readonly ?ExtractedAlbaranes $result = null,
        private readonly ?Throwable $throws = null,
    ) {
    }

    public function extractAlbaranes(): array
    {
        return $this->result()->albaranes;
    }

    public function extractItemsPage(int $offset, int $limit): array
    {
        return array_values(array_slice($this->result()->items, $offset, $limit));
    }

    private function result(): ExtractedAlbaranes
    {
        if ($this->throws !== null) {
            throw $this->throws;
        }

        return $this->result ?? throw new RuntimeException('FakeAlbaranExtractor sin resultado configurado.');
    }
}
