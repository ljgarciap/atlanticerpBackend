<?php

declare(strict_types=1);

namespace Tests\Feature\Legacy;

use App\Modules\Legacy\Contracts\FacturaExtractorContract;
use App\Modules\Legacy\Data\ExtractedFacturas;

/**
 * SCRUM-838 — variante mutable de FakeFacturaExtractor, para tests que invocan un comando 2+ veces
 * dentro del mismo método con datos DISTINTOS en cada corrida. `$this->artisan()` reutiliza la
 * misma instancia ya resuelta del comando entre invocaciones dentro de un mismo test — re-bindear
 * el contenedor a mitad de camino no tiene efecto si la segunda corrida necesita datos distintos
 * (mismo gotcha real documentado en MutableFakeProductoExtractor).
 */
final class MutableFakeFacturaExtractor implements FacturaExtractorContract
{
    public function __construct(private ExtractedFacturas $result)
    {
    }

    public function setResult(ExtractedFacturas $result): void
    {
        $this->result = $result;
    }

    public function extract(): ExtractedFacturas
    {
        return $this->result;
    }
}
