<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Extractors;

use App\Modules\Legacy\Contracts\TraspasoCabExtractorContract;
use App\Modules\Legacy\Support\IcgRowMapper;

/**
 * SCRUM-1136 F1 — TRASPASOSCAB de ICG (~7.253 filas). Copia TAMBIÉN los anulados (`ANULADO='T'`) y guarda
 * `ESAJUSTE`, `ESRECUENTO`, `ESAUTOMATICO`, `RECIBIDO`: el filtrado se hace al analizar, no al copiar. Las
 * líneas no existen como tabla (viven en MOVIMENTS, F2).
 */
final class TraspasoCabExtractor extends IcgTablaExtractor implements TraspasoCabExtractorContract
{
    protected static function tabla(): string
    {
        return 'TRASPASOSCAB';
    }

    /** @return list<string> */
    protected static function orderBy(): array
    {
        return ['SERIE', 'CAJA', 'NUMERO'];
    }

    public static function mapRow(array $row): array
    {
        return IcgRowMapper::map(
            $row,
            [
                'serie' => ['SERIE', 'string'],
                'caja' => ['CAJA', 'string'],
                'numero' => ['NUMERO', 'int'],
            ],
            [
                'fecha' => ['FECHA', 'datetime'],
                'codalmacenorigen' => ['CODALMACENORIGEN', 'string'],
                'codalmacendestino' => ['CODALMACENDESTINO', 'string'],
                'seriecompra' => ['SERIECOMPRA', 'string'],
                'numfaccompra' => ['NUMFACCOMPRA', 'int'],
                'contabilizado' => ['CONTABILIZADO', 'string'],
                'total' => ['TOTAL', 'float'],
                'anulado' => ['ANULADO', 'string'],
                'serieanulado' => ['SERIEANULADO', 'string'],
                'cajaanulado' => ['CAJAANULADO', 'string'],
                'numeroanulado' => ['NUMEROANULADO', 'int'],
                'recibido' => ['RECIBIDO', 'string'],
                'fecharecibido' => ['FECHARECIBIDO', 'datetime'],
                'identificador' => ['IDENTIFICADOR', 'string'],
                'recibidoporcodvendedor' => ['RECIBIDOPORCODVENDEDOR', 'int'],
                'descargado' => ['DESCARGADO', 'string'],
                'observaciones' => ['OBSERVACIONES', 'string'],
                'totaldmn' => ['TOTALDMN', 'float'],
                'esautomatico' => ['ESAUTOMATICO', 'string'],
                'esrecuento' => ['ESRECUENTO', 'string'],
                'esajuste' => ['ESAJUSTE', 'string'],
                'cuentagastosexistencias' => ['CUENTAGASTOSEXISTENCIAS', 'string'],
                'cuentagastosexistenciasdmn' => ['CUENTAGASTOSEXISTENCIASDMN', 'string'],
                'idconceptoajuste' => ['IDCONCEPTOAJUSTE', 'int'],
                'idconceptoajustedmn' => ['IDCONCEPTOAJUSTEDMN', 'int'],
                'escontabilizable' => ['ESCONTABILIZABLE', 'string'],
                'fechaanulado' => ['FECHAANULADO', 'datetime'],
                'serieventa' => ['SERIEVENTA', 'string'],
                'numfacventa' => ['NUMFACVENTA', 'int'],
                'fechacreacion' => ['FECHACREACION', 'datetime'],
                'impresiones' => ['IMPRESIONES', 'int'],
                'fechatransporte' => ['FECHATRANSPORTE', 'datetime'],
                'modificable' => ['MODIFICABLE', 'string'],
                'idmotivo' => ['IDMOTIVO', 'int'],
                'descmotivo' => ['DESCMOTIVO', 'string'],
                'estransporte' => ['ESTRANSPORTE', 'string'],
                'vehiculo' => ['VEHICULO', 'string'],
            ],
            self::tabla(),
        );
    }
}
