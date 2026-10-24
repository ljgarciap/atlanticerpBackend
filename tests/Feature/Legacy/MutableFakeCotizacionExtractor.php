<?php

declare(strict_types=1);

namespace Tests\Feature\Legacy;

use App\Modules\Legacy\Contracts\CotizacionExtractorContract;
use App\Modules\Legacy\Data\ExtractedCotizaciones;

/**
 * Variante mutable de `FakeCotizacionExtractor` — mismo motivo que
 * `Tests\Feature\Legacy\MutableFakeProductoExtractor` (ver su docblock): `$this->artisan()`
 * reutiliza la misma instancia de comando entre 2 llamadas dentro del mismo test, así que
 * re-bindear el contenedor a mitad de test no tiene efecto si la segunda corrida necesita datos
 * distintos.
 */
final class MutableFakeCotizacionExtractor implements CotizacionExtractorContract
{
    public function __construct(private ExtractedCotizaciones $result)
    {
    }

    public function setResult(ExtractedCotizaciones $result): void
    {
        $this->result = $result;
    }

    public function extractCotizaciones(): array
    {
        return $this->result->cotizaciones;
    }

    public function extractItemsPage(int $offset, int $limit): array
    {
        return array_values(array_slice($this->result->items, $offset, $limit));
    }
}
