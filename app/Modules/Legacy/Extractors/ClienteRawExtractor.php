<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Extractors;

use App\Modules\Legacy\Contracts\ClienteRawExtractorContract;
use App\Modules\Legacy\Support\ClienteRawColumnas;
use App\Modules\Legacy\Support\IcgRowMapper;
use App\Modules\Legacy\Support\RowHasher;

/**
 * SCRUM-1137 F1 — CLIENTES de ICG completa (7.796 filas, 110 columnas copiadas). Las columnas excluidas
 * (`ClienteRawColumnas::EXCLUIDAS`: tarjeta, credenciales, foto y binarias) ni siquiera se piden a ICG: se
 * filtran del `SELECT` en `IcgTablaExtractor::selectableColumns()` y, de todos modos, el mapper no las
 * conoce. Mapa generado desde `docs/architecture/icg-clientes-esquema-icg.txt`.
 */
final class ClienteRawExtractor extends IcgTablaExtractor implements ClienteRawExtractorContract
{
    protected static function tabla(): string
    {
        return 'CLIENTES';
    }

    /** @return list<string> */
    protected static function orderBy(): array
    {
        return ['CODCLIENTE'];
    }

    /** @return list<string> */
    protected static function columnasExcluidas(): array
    {
        return ClienteRawColumnas::EXCLUIDAS;
    }

    public static function mapRow(array $row): array
    {
        $mapped = IcgRowMapper::map(
            $row,
            [
                'codcliente' => ['CODCLIENTE', 'int'],
            ],
            [
                'codcontable'            => ['CODCONTABLE', 'string'],
                'nombrecliente'          => ['NOMBRECLIENTE', 'string'],
                'nombrecomercial'        => ['NOMBRECOMERCIAL', 'string'],
                'cif'                    => ['CIF', 'string'],
                'alias'                  => ['ALIAS', 'string'],
                'direccion1'             => ['DIRECCION1', 'string'],
                'codpostal'              => ['CODPOSTAL', 'string'],
                'poblacion'              => ['POBLACION', 'string'],
                'provincia'              => ['PROVINCIA', 'string'],
                'pais'                   => ['PAIS', 'string'],
                'personacontacto'        => ['PERSONACONTACTO', 'string'],
                'telefono1'              => ['TELEFONO1', 'string'],
                'telefono2'              => ['TELEFONO2', 'string'],
                'fax'                    => ['FAX', 'string'],
                'faxpedidos'             => ['FAXPEDIDOS', 'string'],
                'telex'                  => ['TELEX', 'string'],
                'e_mail'                 => ['E_MAIL', 'string'],
                'codclisuyo'             => ['CODCLISUYO', 'string'],
                'numcuenta'              => ['NUMCUENTA', 'string'],
                'codbanco'               => ['CODBANCO', 'string'],
                'numsucursal'            => ['NUMSUCURSAL', 'string'],
                'digcontrolbanco'        => ['DIGCONTROLBANCO', 'string'],
                'codpostalbanco'         => ['CODPOSTALBANCO', 'string'],
                'codswift'               => ['CODSWIFT', 'string'],
                'nombrebanco'            => ['NOMBREBANCO', 'string'],
                'direccionbanco'         => ['DIRECCIONBANCO', 'string'],
                'poblacionbanco'         => ['POBLACIONBANCO', 'string'],
                'enviopor'               => ['ENVIOPOR', 'string'],
                'enviodirecion'          => ['ENVIODIRECION', 'string'],
                'enviocodpostal'         => ['ENVIOCODPOSTAL', 'string'],
                'enviopoblacion'         => ['ENVIOPOBLACION', 'string'],
                'envioprovincia'         => ['ENVIOPROVINCIA', 'string'],
                'enviopais'              => ['ENVIOPAIS', 'string'],
                'cantportespag'          => ['CANTPORTESPAG', 'float'],
                'tipoportes'             => ['TIPOPORTES', 'string'],
                'numdiasentrega'         => ['NUMDIASENTREGA', 'int'],
                'riesgoconcedido'        => ['RIESGOCONCEDIDO', 'float'],
                'tipo'                   => ['TIPO', 'int'],
                'recargo'                => ['RECARGO', 'string'],
                'zona'                   => ['ZONA', 'string'],
                'codvendedor'            => ['CODVENDEDOR', 'int'],
                'diapago1'               => ['DIAPAGO1', 'int'],
                'diapago2'               => ['DIAPAGO2', 'int'],
                'observaciones'          => ['OBSERVACIONES', 'text'],
                'facturarsinimpuestos'   => ['FACTURARSINIMPUESTOS', 'string'],
                'apdocorreos'            => ['APDOCORREOS', 'string'],
                'dtocomercial'           => ['DTOCOMERCIAL', 'float'],
                'fechamodificado'        => ['FECHAMODIFICADO', 'datetime'],
                'regimfact'              => ['REGIMFACT', 'string'],
                'codmoneda'              => ['CODMONEDA', 'int'],
                'direccion2'             => ['DIRECCION2', 'string'],
                'compradoredi'           => ['COMPRADOREDI', 'string'],
                'receptoredi'            => ['RECEPTOREDI', 'string'],
                'clienteedi'             => ['CLIENTEEDI', 'string'],
                'pagadoredi'             => ['PAGADOREDI', 'string'],
                'tipodoc'                => ['TIPODOC', 'int'],
                'fechanacimiento'        => ['FECHANACIMIENTO', 'datetime'],
                'sexo'                   => ['SEXO', 'string'],
                'nif20'                  => ['NIF20', 'string'],
                'descatalogado'          => ['DESCATALOGADO', 'string'],
                'transporte'             => ['TRANSPORTE', 'int'],
                'mesvacaciones'          => ['MESVACACIONES', 'int'],
                'grupoimpresion'         => ['GRUPOIMPRESION', 'int'],
                'numcopiasfactura'       => ['NUMCOPIASFACTURA', 'int'],
                'tipocliente'            => ['TIPOCLIENTE', 'int'],
                'condentregaedi'         => ['CONDENTREGAEDI', 'string'],
                'condentrega'            => ['CONDENTREGA', 'string'],
                'codidioma'              => ['CODIDIOMA', 'int'],
                'serie'                  => ['SERIE', 'string'],
                'almacen'                => ['ALMACEN', 'string'],
                'local_remota'           => ['LOCAL_REMOTA', 'string'],
                'empresa'                => ['EMPRESA', 'int'],
                'codentrega'             => ['CODENTREGA', 'string'],
                'procedencia'            => ['PROCEDENCIA', 'string'],
                'codigoprocedencia'      => ['CODIGOPROCEDENCIA', 'int'],
                'idsucursal'             => ['IDSUCURSAL', 'int'],
                'codvisible'             => ['CODVISIBLE', 'int'],
                'codpais'                => ['CODPAIS', 'string'],
                'b2b_idmapping'          => ['B2B_IDMAPPING', 'int'],
                'facturarconimpuesto'    => ['FACTURARCONIMPUESTO', 'int'],
                'cargosfijosa'           => ['CARGOSFIJOSA', 'int'],
                'codcontabledmn'         => ['CODCONTABLEDMN', 'string'],
                'disenyo_camposlibres'   => ['DISENYO_CAMPOSLIBRES', 'int'],
                'mobil'                  => ['MOBIL', 'string'],
                'nocalcularcargo1artic'  => ['NOCALCULARCARGO1ARTIC', 'bool'],
                'nocalcularcargo2artic'  => ['NOCALCULARCARGO2ARTIC', 'bool'],
                'esclientedelgrupo'      => ['ESCLIENTEDELGRUPO', 'bool'],
                'tiporeserva'            => ['TIPORESERVA', 'int'],
                'regimret'               => ['REGIMRET', 'string'],
                'tiporet'                => ['TIPORET', 'int'],
                'ret_tiporetencioniva'   => ['RET_TIPORETENCIONIVA', 'int'],
                'ret_porcexclusion'      => ['RET_PORCEXCLUSION', 'float'],
                'ret_fechainiexclusion'  => ['RET_FECHAINIEXCLUSION', 'datetime'],
                'ret_fechafinexclusion'  => ['RET_FECHAFINEXCLUSION', 'datetime'],
                'camposlibrestotalizar'  => ['CAMPOSLIBRESTOTALIZAR', 'bool'],
                'codcliasoc'             => ['CODCLIASOC', 'int'],
                'cargosextrasa'          => ['CARGOSEXTRASA', 'int'],
                'comision'               => ['COMISION', 'float'],
                'proveedorcomision'      => ['PROVEEDORCOMISION', 'int'],
                'comisionesfacturables'  => ['COMISIONESFACTURABLES', 'bool'],
                'localizadorobligatorio' => ['LOCALIZADOROBLIGATORIO', 'bool'],
                'recc'                   => ['RECC', 'bool'],
                'bloqueado'              => ['BLOQUEADO', 'string'],
                'ordenadeudo'            => ['ORDENADEUDO', 'string'],
                'subnorma'               => ['SUBNORMA', 'int'],
                'secuenciaadeudo'        => ['SECUENCIAADEUDO', 'int'],
                'codigoiban'             => ['CODIGOIBAN', 'string'],
                'fechafirmaordenadeudo'  => ['FECHAFIRMAORDENADEUDO', 'datetime'],
                'tipodocident'           => ['TIPODOCIDENT', 'int'],
            ],
            self::tabla(),
        );

        // Hash de las columnas copiadas (sin source_hash): el loader no reescribe filas que no cambiaron.
        $mapped['source_hash'] = RowHasher::hash($mapped);

        return $mapped;
    }
}
