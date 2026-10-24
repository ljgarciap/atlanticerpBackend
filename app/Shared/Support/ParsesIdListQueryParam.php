<?php

declare(strict_types=1);

namespace App\Shared\Support;

use Illuminate\Http\Request;

/**
 * SCRUM-854 — generaliza el patrón ya usado en `InventoryController::parseWarehouseIds()`
 * (SCRUM-743) para cualquier filtro multi-selección por id. `?key[]=1&key[]=2` (serialización
 * estándar de arrays de Axios/PHP) llega como array nativo; se tolera además un único valor
 * escalar (`?key=3`) para no exigirle al frontend el sufijo `[]` cuando hay un solo id
 * seleccionado. IDs no numéricos/≤0 se descartan en silencio en vez de romper la query.
 */
trait ParsesIdListQueryParam
{
    /** @return list<int> */
    private function parseIdListParam(Request $request, string $key): array
    {
        $raw = $request->query($key, []);
        if (! is_array($raw)) {
            $raw = [$raw];
        }

        return array_values(array_unique(array_filter(
            array_map(fn ($v): int => is_numeric($v) ? (int) $v : 0, $raw),
            fn (int $id): bool => $id > 0,
        )));
    }
}
