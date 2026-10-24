<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Lafayette\Extractors;

use App\Modules\Integrations\Lafayette\Contracts\ProductoExtractorContract;
use App\Modules\Integrations\Lafayette\Data\ExtractedProductos;
use App\Modules\Integrations\Lafayette\Data\LafayetteProductoRow;
use Illuminate\Support\Facades\DB;

/**
 * SCRUM-971 (épica SCRUM-969) — extrae `app_lafayette.vista_productos_lighting` (conexión
 * `lafayette_lighting`, ver config/database.php) para el sync de Bodega Zona Libre.
 *
 * Un solo `SELECT`, sin paginar — medido en vivo el 2026-09-25: 0.44s para las 25,802 filas
 * completas, muy por debajo del límite de 30s por consulta que impone el proveedor (ver spec en
 * SCRUM-969). Selecciona solo las columnas con mapeo cerrado — evita transferir texto largo sin
 * uso (`descripcion_ingles`, `detalle_empaque`) y documenta explícitamente cuáles columnas de la
 * vista SÍ se usan.
 *
 * Separación construcción-de-query / mapeo-de-filas / acto-de-conectar (mismo patrón que
 * `App\Modules\Legacy\Extractors\ProductoExtractor`) — `mapRow()` es pura y testeable sin
 * conexión real.
 */
final class ProductoExtractor implements ProductoExtractorContract
{
    public function __construct(private readonly string $connection = 'lafayette_lighting')
    {
    }

    public function extract(): ExtractedProductos
    {
        $rows = DB::connection($this->connection)->select(self::query());

        $productos = array_values(array_map(
            static fn (object $row): LafayetteProductoRow => self::mapRow((array) $row),
            $rows,
        ));

        return new ExtractedProductos($productos);
    }

    public static function query(): string
    {
        return <<<'SQL'
            SELECT id, referencia, descripcion, marca, url_imagen, categoria, disponible,
                   precio_5, precio_6, cache_updated_at
            FROM vista_productos_lighting
            SQL;
    }

    /**
     * Pura — sin DB. `$row` tal como vendría de un `SELECT` de {@see query()}.
     *
     * @param array<string, mixed> $row
     */
    public static function mapRow(array $row): LafayetteProductoRow
    {
        return new LafayetteProductoRow(
            id: (string) $row['id'],
            referencia: self::nullableString($row['referencia'] ?? null),
            descripcion: self::nullableString($row['descripcion'] ?? null),
            marca: self::nullableString($row['marca'] ?? null),
            urlImagen: self::nullableString($row['url_imagen'] ?? null),
            categoria: self::nullableString($row['categoria'] ?? null),
            disponible: (int) ($row['disponible'] ?? 0),
            precio5: self::nullableFloat($row['precio_5'] ?? null),
            precio6: self::nullableFloat($row['precio_6'] ?? null),
            cacheUpdatedAt: self::nullableString($row['cache_updated_at'] ?? null),
        );
    }

    private static function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    private static function nullableFloat(mixed $value): ?float
    {
        return $value === null ? null : (float) $value;
    }
}
