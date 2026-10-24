<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Extractors;

use App\Modules\Legacy\Contracts\CostoAlmacenExtractorContract;
use App\Modules\Legacy\Support\IcgRowMapper;

/**
 * SCRUM-1136 F1 — COSTESPORALMACEN de ICG (~11.031 filas): costo medio, de stock y último, en moneda e
 * importe local (`*DMN`). Todas las columnas copiables se tipan (esquema real de ICG, ver migración);
 * `VERSION` se excluye en la base.
 */
final class CostoAlmacenExtractor extends IcgTablaExtractor implements CostoAlmacenExtractorContract
{
    protected static function tabla(): string
    {
        return 'COSTESPORALMACEN';
    }

    /** @return list<string> */
    protected static function orderBy(): array
    {
        return ['CODARTICULO', 'TALLA', 'COLOR', 'CODALMACEN'];
    }

    public static function mapRow(array $row): array
    {
        return IcgRowMapper::map(
            $row,
            [
                'codarticulo' => ['CODARTICULO', 'int'],
                'talla' => ['TALLA', 'string'],
                'color' => ['COLOR', 'string'],
                'codalmacen' => ['CODALMACEN', 'string'],
            ],
            [
                'costemedio' => ['COSTEMEDIO', 'float'],
                'costestock' => ['COSTESTOCK', 'float'],
                'ultimocoste' => ['ULTIMOCOSTE', 'float'],
                'precioultcompra' => ['PRECIOULTCOMPRA', 'float'],
                'ultdesccomercial' => ['ULTDESCCOMERCIAL', 'float'],
                'unidadescompradas' => ['UNIDADESCOMPRADAS', 'float'],
                'fechaultcompra' => ['FECHAULTCOMPRA', 'datetime'],
                'ultdtocomercial' => ['ULTDTOCOMERCIAL', 'float'],
                'preciocomprareal' => ['PRECIOCOMPRAREAL', 'float'],
                'codmoneda' => ['CODMONEDA', 'int'],
                'costemediodmn' => ['COSTEMEDIODMN', 'float'],
                'costestockdmn' => ['COSTESTOCKDMN', 'float'],
                'ultimocostedmn' => ['ULTIMOCOSTEDMN', 'float'],
                'precioultcompradmn' => ['PRECIOULTCOMPRADMN', 'float'],
                'preciocomprarealdmn' => ['PRECIOCOMPRAREALDMN', 'float'],
                'ultdesccomercialdmn' => ['ULTDESCCOMERCIALDMN', 'float'],
                'ultdtocomercialdmn' => ['ULTDTOCOMERCIALDMN', 'float'],
                'codmonedadmn' => ['CODMONEDADMN', 'int'],
                'unidadescompradasdmn' => ['UNIDADESCOMPRADASDMN', 'float'],
                'importecargo1' => ['IMPORTECARGO1', 'float'],
                'importecargo2' => ['IMPORTECARGO2', 'float'],
                'importecargo1dmn' => ['IMPORTECARGO1DMN', 'float'],
                'importecargo2dmn' => ['IMPORTECARGO2DMN', 'float'],
            ],
            self::tabla(),
        );
    }
}
