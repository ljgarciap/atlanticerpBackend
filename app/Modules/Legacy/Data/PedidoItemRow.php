<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Data;

/**
 * DTO normalizado de una fila de `PEDVENTALIN` — salida pura de
 * `App\Modules\Legacy\Extractors\PedidoExtractor::mapItemRow()`, sin dependencia de DB.
 *
 * `icgNumserie`/`icgNumpedido`/`icgN` NO son una llave propia de la línea — PEDVENTALIN no
 * declara una (ver docblock de la migración de `legacy.pedido_items`), son la llave natural
 * compuesta de la CABECERA a la que pertenece esta línea (el FK real declarado en ICG).
 * `App\Modules\Legacy\Services\PedidoStagingLoader` las usa para resolver `legacy_pedido_id` (el
 * `id` interno de `legacy.pedidos`) antes de insertar.
 */
final readonly class PedidoItemRow
{
    public function __construct(
        public string $icgNumserie,
        public int $icgNumpedido,
        public int $icgN,
        public ?int $icgCodarticulo,
        public ?string $referencia,
        public ?string $descripcion,
        public ?float $cantidad,
        public ?float $precio,
        public ?float $total,
        // L1 (pedidos gemelos): unidades que ICG aún tiene pendientes / ya servidas de esta línea (UNIDADESPEN/UNIDADESREC).
        public ?float $unidadesPendientes = null,
        public ?float $unidadesRecibidas = null,
    ) {
    }
}
