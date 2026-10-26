<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Extractors;

use App\Modules\Legacy\Contracts\ProveedorExtractorContract;
use App\Modules\Legacy\Data\ExtractedProveedores;
use App\Modules\Legacy\Data\ProveedorRow;
use Illuminate\Support\Facades\DB;

/**
 * SCRUM-756 (épica SCRUM-749 — Migración ICG→AtlanticERP). Extrae PROVEEDORES de ICG (SQL Server,
 * conexión `legacy_icg`, 114 filas) para el batch de Proveedores
 * (Illumination/migracion-icg-diseno.md §2/§4/§5).
 *
 * Sin filtro de "solo activos" — a diferencia de `ProductoExtractor` (ARTICULOS.DESCATALOGADO='F'),
 * el subconjunto documentado de columnas de PROVEEDORES (§2/§4) no incluye ninguna columna de
 * estado/baja, así que no hay criterio de exclusión que aplicar: se extrae todo PROVEEDORES.
 *
 * Sin gotcha NDBOOLEANF — a diferencia de `ClienteExtractor`/`ProductoExtractor`, ninguna de las
 * columnas del subconjunto documentado de PROVEEDORES (§2/§4) es booleana. Si al confirmar el
 * shape completo contra ICG real (fuera de alcance de este batch, ver docblock de
 * `App\Modules\Legacy\Console\Commands\LegacySyncProveedoresCommand`) aparece una columna de ese tipo, verificar
 * su tipo real antes de mapearla — nunca asumir uniformidad entre tablas (mismo criterio que el
 * resto del módulo).
 *
 * Separación construcción-de-query / mapeo-de-filas / acto-de-conectar (mismo patrón que
 * `ProductoExtractor`/`ClienteExtractor`, a propósito, para que `mapProveedorRow()` sea testeable
 * con arrays de filas fake, sin ninguna conexión real — ver
 * tests/Unit/Legacy/ProveedorExtractorTest.php): es `static`, pura, y `extract()` es el único
 * método que toca la red.
 */
final class ProveedorExtractor implements ProveedorExtractorContract
{
    public function __construct(private readonly string $connection = 'legacy_icg')
    {
    }

    public function extract(): ExtractedProveedores
    {
        $proveedorRows = DB::connection($this->connection)->select(self::proveedoresQuery());

        $proveedores = array_values(array_map(
            static fn (object $row): ProveedorRow => self::mapProveedorRow((array) $row),
            $proveedorRows,
        ));

        return new ExtractedProveedores($proveedores);
    }

    public static function proveedoresQuery(): string
    {
        return <<<'SQL'
            SELECT p.CODPROVEEDOR, p.NOMPROVEEDOR, p.NOMCOMERCIAL, p.CIF, p.DIRECCION1,
                   p.TELEFONO1, p.E_MAIL
            FROM PROVEEDORES p
            SQL;
    }

    /**
     * Pura — sin DB. `$row` tal como vendría de un `SELECT` de `proveedoresQuery()` (claves de
     * columna de SQL Server, ya castadas a array asociativo).
     *
     * @param array<string, mixed> $row
     */
    public static function mapProveedorRow(array $row): ProveedorRow
    {
        return new ProveedorRow(
            icgCodproveedor: (int) $row['CODPROVEEDOR'],
            // NOMPROVEEDOR es obligatorio en ICG (razón social/nombre legal) — nunca nulo.
            nombre: (string) $row['NOMPROVEEDOR'],
            nombreComercial: self::nullableString($row['NOMCOMERCIAL'] ?? null),
            cif: self::nullableString($row['CIF'] ?? null),
            direccion: self::nullableString($row['DIRECCION1'] ?? null),
            telefono1: self::nullableString($row['TELEFONO1'] ?? null),
            email: self::nullableString($row['E_MAIL'] ?? null),
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
}
