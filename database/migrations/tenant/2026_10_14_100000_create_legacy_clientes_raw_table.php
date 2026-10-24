<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-1137 F1. `legacy.clientes_raw` — copia cruda de CLIENTES de ICG (7.796 filas). Llave natural:
 * codcliente. Columnas y tipos generados desde el esquema REAL (`docs/architecture/icg-clientes-esquema-icg.txt`):
 * nvarchar/nchar -> string(n), ntext -> text, float -> double, int -> integer, smallint -> smallInteger,
 * bit -> boolean, datetime -> timestampTz, en minúscula y todas nullable salvo la llave.
 *
 * EXCLUIDAS a propósito (REVIEW-icg-clientes-staging-f1.md, condición 3): CVC, PASS, PASSWORDCOMMERCE,
 * NUMTARJETA, FOTOCLIENTE y toda columna binaria/timestamp (VERSION). Sin FK hacia `ventas_diseno.*` ni hacia
 * `legacy.clientes`. `source_hash` = hash de las columnas copiadas (para no reescribir filas sin cambios).
 * Acceso solo por base de datos y comandos Artisan: ningún endpoint la expone (condición 1).
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'legacy';

    public function up(): void
    {
        Schema::create($this->table('clientes_raw'), function (Blueprint $table): void {
            $table->id();
            $table->integer('codcliente');
            $table->string('codcontable', 12)->nullable();
            $table->string('nombrecliente', 255)->nullable();
            $table->string('nombrecomercial', 255)->nullable();
            $table->string('cif', 12)->nullable();
            $table->string('alias', 255)->nullable();
            $table->string('direccion1', 255)->nullable();
            $table->string('codpostal', 8)->nullable();
            $table->string('poblacion', 100)->nullable();
            $table->string('provincia', 100)->nullable();
            $table->string('pais', 100)->nullable();
            $table->string('personacontacto', 255)->nullable();
            $table->string('telefono1', 15)->nullable();
            $table->string('telefono2', 15)->nullable();
            $table->string('fax', 15)->nullable();
            $table->string('faxpedidos', 15)->nullable();
            $table->string('telex', 15)->nullable();
            $table->string('e_mail', 255)->nullable();
            $table->string('codclisuyo', 12)->nullable();
            $table->string('numcuenta', 10)->nullable();
            $table->string('codbanco', 4)->nullable();
            $table->string('numsucursal', 4)->nullable();
            $table->string('digcontrolbanco', 2)->nullable();
            $table->string('codpostalbanco', 8)->nullable();
            $table->string('codswift', 15)->nullable();
            $table->string('nombrebanco', 255)->nullable();
            $table->string('direccionbanco', 255)->nullable();
            $table->string('poblacionbanco', 100)->nullable();
            $table->string('enviopor', 20)->nullable();
            $table->string('enviodirecion', 255)->nullable();
            $table->string('enviocodpostal', 8)->nullable();
            $table->string('enviopoblacion', 100)->nullable();
            $table->string('envioprovincia', 100)->nullable();
            $table->string('enviopais', 100)->nullable();
            $table->double('cantportespag')->nullable();
            $table->string('tipoportes', 1)->nullable();
            $table->integer('numdiasentrega')->nullable();
            $table->double('riesgoconcedido')->nullable();
            $table->smallInteger('tipo')->nullable();
            $table->string('recargo', 1)->nullable();
            $table->string('zona', 2)->nullable();
            $table->integer('codvendedor')->nullable();
            $table->smallInteger('diapago1')->nullable();
            $table->smallInteger('diapago2')->nullable();
            $table->text('observaciones')->nullable();
            $table->string('facturarsinimpuestos', 1)->nullable();
            $table->string('apdocorreos', 12)->nullable();
            $table->double('dtocomercial')->nullable();
            $table->timestampTz('fechamodificado')->nullable();
            $table->string('regimfact', 1)->nullable();
            $table->integer('codmoneda')->nullable();
            $table->string('direccion2', 255)->nullable();
            $table->string('compradoredi', 13)->nullable();
            $table->string('receptoredi', 13)->nullable();
            $table->string('clienteedi', 13)->nullable();
            $table->string('pagadoredi', 31)->nullable();
            $table->integer('tipodoc')->nullable();
            $table->timestampTz('fechanacimiento')->nullable();
            $table->string('sexo', 1)->nullable();
            $table->string('nif20', 20)->nullable();
            $table->string('descatalogado', 1)->nullable();
            $table->integer('transporte')->nullable();
            $table->integer('mesvacaciones')->nullable();
            $table->integer('grupoimpresion')->nullable();
            $table->integer('numcopiasfactura')->nullable();
            $table->integer('tipocliente')->nullable();
            $table->string('condentregaedi', 3)->nullable();
            $table->string('condentrega', 3)->nullable();
            $table->integer('codidioma')->nullable();
            $table->string('serie', 4)->nullable();
            $table->string('almacen', 3)->nullable();
            $table->string('local_remota', 1)->nullable();
            $table->integer('empresa')->nullable();
            $table->string('codentrega', 3)->nullable();
            $table->string('procedencia', 30)->nullable();
            $table->integer('codigoprocedencia')->nullable();
            $table->integer('idsucursal')->nullable();
            $table->integer('codvisible')->nullable();
            $table->string('codpais', 2)->nullable();
            $table->integer('b2b_idmapping')->nullable();
            $table->integer('facturarconimpuesto')->nullable();
            $table->integer('cargosfijosa')->nullable();
            $table->string('codcontabledmn', 12)->nullable();
            $table->integer('disenyo_camposlibres')->nullable();
            $table->string('mobil', 15)->nullable();
            $table->boolean('nocalcularcargo1artic')->nullable();
            $table->boolean('nocalcularcargo2artic')->nullable();
            $table->boolean('esclientedelgrupo')->nullable();
            $table->integer('tiporeserva')->nullable();
            $table->string('regimret', 1)->nullable();
            $table->integer('tiporet')->nullable();
            $table->integer('ret_tiporetencioniva')->nullable();
            $table->double('ret_porcexclusion')->nullable();
            $table->timestampTz('ret_fechainiexclusion')->nullable();
            $table->timestampTz('ret_fechafinexclusion')->nullable();
            $table->boolean('camposlibrestotalizar')->nullable();
            $table->integer('codcliasoc')->nullable();
            $table->integer('cargosextrasa')->nullable();
            $table->double('comision')->nullable();
            $table->integer('proveedorcomision')->nullable();
            $table->boolean('comisionesfacturables')->nullable();
            $table->boolean('localizadorobligatorio')->nullable();
            $table->boolean('recc')->nullable();
            $table->string('bloqueado', 1)->nullable();
            $table->string('ordenadeudo', 35)->nullable();
            $table->integer('subnorma')->nullable();
            $table->integer('secuenciaadeudo')->nullable();
            $table->string('codigoiban', 100)->nullable();
            $table->timestampTz('fechafirmaordenadeudo')->nullable();
            $table->integer('tipodocident')->nullable();
            $table->string('source_hash', 64);
            $table->timestamps();

            $table->unique('codcliente', 'legacy_clientes_raw_codcliente_uq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('clientes_raw'));
    }
};
