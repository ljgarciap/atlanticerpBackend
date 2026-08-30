<?php

declare(strict_types=1);

namespace Tests\Feature\Legacy;

use App\Modules\Legacy\Contracts\ArticuloFotoExtractorContract;
use Throwable;

/**
 * Test double de `App\Modules\Legacy\Extractors\ArticuloFotoExtractor` — ningún Feature test de
 * `SyncProductoFotoJob`/`LegacySyncProductosFotosCommand` requiere SQL Server real (mismo patrón
 * que `FakeProductoExtractor` de SCRUM-750).
 */
final class FakeArticuloFotoExtractor implements ArticuloFotoExtractorContract
{
    /** @param array<int, string|null> $byCodArticulo Bytes BMP (o null = "sin foto") indexados por CODARTICULO. */
    public function __construct(
        private readonly array $byCodArticulo = [],
        private readonly ?Throwable $throws = null,
    ) {
    }

    public function extract(int $codArticulo): ?string
    {
        if ($this->throws !== null) {
            throw $this->throws;
        }

        return $this->byCodArticulo[$codArticulo] ?? null;
    }
}
