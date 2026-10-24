<?php

declare(strict_types=1);

namespace Tests\Feature\Legacy;

use App\Modules\Legacy\Contracts\ClienteExtractorContract;
use App\Modules\Legacy\Data\ExtractedClientes;

/**
 * SCRUM-838 — variante mutable de `FakeClienteExtractor`, mismo motivo que
 * `MutableFakeProductoExtractor` (ver ese docblock): `$this->artisan()` reutiliza la misma
 * instancia de comando entre 2 llamadas dentro del mismo test — rebindear el contenedor a mitad
 * de camino no tiene efecto si la segunda corrida necesita datos distintos.
 */
final class MutableFakeClienteExtractor implements ClienteExtractorContract
{
    public function __construct(private ExtractedClientes $result)
    {
    }

    public function setResult(ExtractedClientes $result): void
    {
        $this->result = $result;
    }

    public function extract(): ExtractedClientes
    {
        return $this->result;
    }
}
