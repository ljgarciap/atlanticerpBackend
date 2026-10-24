<?php

declare(strict_types=1);

namespace Tests\Feature\Legacy;

use App\Modules\Legacy\Contracts\ProveedorExtractorContract;
use App\Modules\Legacy\Data\ExtractedProveedores;

/**
 * SCRUM-838 — variante mutable de `FakeProveedorExtractor`, mismo motivo que
 * `MutableFakeProductoExtractor` (ver ese docblock).
 */
final class MutableFakeProveedorExtractor implements ProveedorExtractorContract
{
    public function __construct(private ExtractedProveedores $result)
    {
    }

    public function setResult(ExtractedProveedores $result): void
    {
        $this->result = $result;
    }

    public function extract(): ExtractedProveedores
    {
        return $this->result;
    }
}
