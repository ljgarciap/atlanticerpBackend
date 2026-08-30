<?php

declare(strict_types=1);

namespace Tests\Feature\Legacy;

use App\Modules\Legacy\Contracts\PagoExtractorContract;
use App\Modules\Legacy\Data\ExtractedPagos;
use RuntimeException;
use Throwable;

/**
 * Test double de `App\Modules\Legacy\Extractors\PagoExtractor` — el comando `legacy:sync-pagos`
 * nunca debe requerir SQL Server real en la suite de tests.
 *
 * Sigue configurándose con un `ExtractedPagos` completo — más simple para los fixtures de test que
 * ya existen — pero lo expone a través del contrato paginado real (`extractPage()`, ver docblock de
 * `PagoExtractorContract` para el porqué): pagina sobre el array de `$result->pagos` en memoria con
 * `array_slice()`, que en el volumen chico de los tests (nunca SQL Server real) se resuelve en la
 * primera página siempre.
 */
final class FakePagoExtractor implements PagoExtractorContract
{
    public function __construct(
        private readonly ?ExtractedPagos $result = null,
        private readonly ?Throwable $throws = null,
    ) {
    }

    public function extractPage(int $offset, int $limit): array
    {
        return array_values(array_slice($this->result()->pagos, $offset, $limit));
    }

    private function result(): ExtractedPagos
    {
        if ($this->throws !== null) {
            throw $this->throws;
        }

        return $this->result ?? throw new RuntimeException('FakePagoExtractor sin resultado configurado.');
    }
}
