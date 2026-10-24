<?php

declare(strict_types=1);

namespace Tests\Feature\Legacy;

use App\Modules\Legacy\Contracts\ProductoExtractorContract;
use App\Modules\Legacy\Data\ExtractedProductos;

/**
 * SCRUM-838 — variante mutable de `FakeProductoExtractor`, para tests que invocan un comando 2+
 * veces dentro del mismo método con datos DISTINTOS en cada corrida. `$this->artisan()` reutiliza
 * la misma instancia ya resuelta del comando entre invocaciones dentro de un mismo test — el
 * extractor inyectado por constructor nunca se vuelve a resolver del contenedor, así que
 * re-bindear con `$this->app->instance()` a mitad de camino NO tiene efecto si la segunda corrida
 * necesita datos distintos (sí funciona, por casualidad, cuando ambas corridas usan la misma data
 * — que es el único caso que la suite existente probaba antes de este ticket). Este fake resuelve
 * eso: es la MISMA instancia inyectada desde el principio, y el test muta su contenido con
 * `setResult()` entre corridas en vez de rebindear el contenedor.
 */
final class MutableFakeProductoExtractor implements ProductoExtractorContract
{
    public function __construct(private ExtractedProductos $result)
    {
    }

    public function setResult(ExtractedProductos $result): void
    {
        $this->result = $result;
    }

    public function extract(): ExtractedProductos
    {
        return $this->result;
    }
}
