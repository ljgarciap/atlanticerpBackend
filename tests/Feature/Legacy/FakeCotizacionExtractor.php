<?php

declare(strict_types=1);

namespace Tests\Feature\Legacy;

use App\Modules\Legacy\Contracts\CotizacionExtractorContract;
use App\Modules\Legacy\Data\ExtractedCotizaciones;
use RuntimeException;
use Throwable;

/**
 * Test double de `App\Modules\Legacy\Extractors\CotizacionExtractor` — el comando
 * `legacy:sync-cotizaciones` nunca debe requerir SQL Server real en la suite de tests. Mismo
 * patrón que `FakeClienteExtractor`/`FakeProductoExtractor`.
 *
 * Sigue configurándose con un `ExtractedCotizaciones` completo (cotizaciones+items) — más simple
 * para los fixtures de test que ya existen — pero lo expone a través del contrato paginado real
 * (`extractCotizaciones()`/`extractItemsPage()`, ver docblock de `CotizacionExtractorContract`
 * para el porqué): `extractItemsPage()` pagina sobre el array de `$result->items` en memoria con
 * `array_slice()`, que en el volumen chico de los tests (nunca SQL Server real) se resuelve en la
 * primera página siempre — no hace falta simular SQL Server para probar la paginación en sí, eso
 * lo cubre `CotizacionStagingLoaderTest`/`CotizacionExtractorTest` a otro nivel.
 */
final class FakeCotizacionExtractor implements CotizacionExtractorContract
{
    public function __construct(
        private readonly ?ExtractedCotizaciones $result = null,
        private readonly ?Throwable $throws = null,
    ) {
    }

    public function extractCotizaciones(): array
    {
        return $this->result()->cotizaciones;
    }

    public function extractItemsPage(int $offset, int $limit): array
    {
        return array_values(array_slice($this->result()->items, $offset, $limit));
    }

    private function result(): ExtractedCotizaciones
    {
        if ($this->throws !== null) {
            throw $this->throws;
        }

        return $this->result ?? throw new RuntimeException('FakeCotizacionExtractor sin resultado configurado.');
    }
}
