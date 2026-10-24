<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Extractors;

use App\Modules\Legacy\Contracts\MovimientoExtractorContract;
use App\Modules\Legacy\Support\IcgRowMapper;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * SCRUM-1136 F2 — MOVIMENTS de ICG (1.384.677 filas, PK `ID` int no contiguo hasta 1.830.964). Todas las
 * columnas (32 según INFORMATION_SCHEMA, ninguna binaria), `tipo` tal cual (REG/TMP/ENV/MOD; el filtro de TMP es de análisis).
 *
 * Paginación por LLAVE, no por OFFSET: `SELECT TOP (n) ... WHERE [ID] > ? ORDER BY [ID]`. El OFFSET profundo
 * sobre 1,38 M de filas es cuadrático; con la llave cada página es una búsqueda por la PK. El `ID` va como
 * parámetro; `n` se inyecta como entero ya validado (`(int)` y >= 1), no texto. Las columnas se descubren de
 * INFORMATION_SCHEMA (`TABLE_SCHEMA = 'dbo'`, igual que F1, reutilizando `IcgTablaExtractor`) y se citan tras
 * validar que sean identificadores simples. Solo SELECT (usuario `illumiread`).
 *
 * Fechas: los `datetime` de ICG no traen zona; se interpretan en America/Panama (zona de la app y de la sesión
 * Postgres) y se guardan en `timestamptz` (ver `IcgRowMapper`). `HORA` trae una fecha base sin sentido (1899)
 * con la hora real y pasa por `castDateTime` igual que `FECHA`: se guarda como timestamptz interpretado en
 * America/Panama. Las fechas de 1899 usan el offset LMT histórico de Postgres (minutos raros); el análisis debe
 * leer la hora con `hora AT TIME ZONE 'America/Panama'`.
 *
 * Las funciones de construcción de query y mapeo son `static` y puras (testeables sin red); solo
 * `extractAfter()` toca la conexión `legacy_icg`.
 */
final class MovimientoExtractor implements MovimientoExtractorContract
{
    private const TABLA = 'MOVIMENTS';

    private const LLAVE = 'ID';

    /** @var list<string>|null */
    private ?array $columns = null;

    public function __construct(private readonly string $connection = 'legacy_icg')
    {
    }

    public function extractAfter(int $afterId, int $limit): array
    {
        $db = DB::connection($this->connection);

        $this->columns ??= IcgTablaExtractor::selectableColumns(
            $db->select(IcgTablaExtractor::columnsQuery(), [self::TABLA]),
            [self::LLAVE],
        );

        $rows = $db->select(self::pageQuery($this->columns, $limit), [$afterId]);

        return array_values(array_map(
            static fn (object $row): array => self::mapRow((array) $row),
            $rows,
        ));
    }

    /**
     * Pura. Un solo parámetro posicional: el `ID` de la última fila ya cargada.
     *
     * @param list<string> $columns
     */
    public static function pageQuery(array $columns, int $limit): string
    {
        if ($columns === []) {
            throw new RuntimeException('pageQuery requiere columnas.');
        }
        if ($limit < 1) {
            throw new RuntimeException('El tamaño de página debe ser >= 1.');
        }

        $select = implode(', ', array_map(IcgTablaExtractor::quote(...), $columns));
        $llave  = IcgTablaExtractor::quote(self::LLAVE);

        return "SELECT TOP ({$limit}) {$select} FROM [dbo]." . IcgTablaExtractor::quote(self::TABLA)
            . " WHERE {$llave} > ? ORDER BY {$llave}";
    }

    /**
     * Pura — fila cruda de ICG a fila de staging (30 columnas). `ID` nulo o no numérico aborta.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function mapRow(array $row): array
    {
        return IcgRowMapper::map(
            $row,
            ['icg_id' => [self::LLAVE, 'int']],
            [
                'codalmacenorigen' => ['CODALMACENORIGEN', 'string'],
                'codalmacendestino' => ['CODALMACENDESTINO', 'string'],
                'numserie' => ['NUMSERIE', 'string'],
                'codarticulo' => ['CODARTICULO', 'int'],
                'talla' => ['TALLA', 'string'],
                'color' => ['COLOR', 'string'],
                'precio' => ['PRECIO', 'float'],
                'fecha' => ['FECHA', 'datetime'],
                'hora' => ['HORA', 'datetime'],
                'codprocli' => ['CODPROCLI', 'float'],
                'tipo' => ['TIPO', 'string'],
                'unidades' => ['UNIDADES', 'float'],
                'seriedoc' => ['SERIEDOC', 'string'],
                'numdoc' => ['NUMDOC', 'float'],
                'seriecompra' => ['SERIECOMPRA', 'string'],
                'numfaccompra' => ['NUMFACCOMPRA', 'int'],
                'caja' => ['CAJA', 'string'],
                'stock' => ['STOCK', 'float'],
                'pvp' => ['PVP', 'float'],
                'codmonedapvp' => ['CODMONEDAPVP', 'int'],
                'calcmovpost' => ['CALCMOVPOST', 'string'],
                'udmedida2' => ['UDMEDIDA2', 'float'],
                'zona' => ['ZONA', 'string'],
                'pvpdmn' => ['PVPDMN', 'float'],
                'preciodmn' => ['PRECIODMN', 'float'],
                'stock2' => ['STOCK2', 'float'],
                'unid1' => ['UNID1', 'float'],
                'unid2' => ['UNID2', 'float'],
                'unid3' => ['UNID3', 'float'],
                'unid4' => ['UNID4', 'float'],
                'codformato' => ['CODFORMATO', 'int'],
            ],
            self::TABLA,
        );
    }
}
