<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Data;

/**
 * Resultado completo de `App\Modules\Legacy\Extractors\PagoExtractor::extract()` — la única tabla
 * origen (TESORERIA), ya mapeada a DTOs. Sin Matcher (ver docblock de la migración de
 * `legacy.pagos` — TESORERIA no referencia cliente en absoluto), así que a diferencia de
 * `ExtractedAlbaranes`/`ExtractedFacturas` no expone `compositeKeys()` — no hace falta resolver
 * ids internos para ningún paso posterior.
 */
final readonly class ExtractedPagos
{
    /** @param list<PagoRow> $pagos */
    public function __construct(
        public array $pagos,
    ) {
    }
}
