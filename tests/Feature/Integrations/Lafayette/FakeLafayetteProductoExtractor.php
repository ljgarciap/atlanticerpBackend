<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations\Lafayette;

use App\Modules\Integrations\Lafayette\Contracts\ProductoExtractorContract;
use App\Modules\Integrations\Lafayette\Data\ExtractedProductos;
use RuntimeException;
use Throwable;

/**
 * Test double de `App\Modules\Integrations\Lafayette\Extractors\ProductoExtractor` — el comando
 * `lafayette:sync-productos` nunca debe requerir la MariaDB externa real de Lafayette en la suite
 * de tests (ver docblock de `App\Providers\AppServiceProvider::register()`).
 *
 * MUTABLE a propósito (`setResult()`) — `$this->artisan(...)` de Laravel Testing reusa la MISMA
 * instancia de Command (con sus dependencias ya inyectadas en el constructor) entre llamadas
 * dentro del mismo test, así que un segundo `$this->app->instance(Contract::class, new Fake(...))`
 * nunca llega al comando ya resuelto — hay que mutar el estado de la MISMA instancia del fake que
 * el comando ya tiene, no reemplazar el binding con una instancia nueva (hallazgo real, ver test
 * `test_re_correr_actualiza_en_vez_de_duplicar`).
 */
final class FakeLafayetteProductoExtractor implements ProductoExtractorContract
{
    public function __construct(
        private ?ExtractedProductos $result = null,
        private readonly ?Throwable $throws = null,
    ) {
    }

    public function setResult(ExtractedProductos $result): void
    {
        $this->result = $result;
    }

    public function extract(): ExtractedProductos
    {
        if ($this->throws !== null) {
            throw $this->throws;
        }

        return $this->result ?? throw new RuntimeException('FakeLafayetteProductoExtractor sin resultado configurado.');
    }
}
