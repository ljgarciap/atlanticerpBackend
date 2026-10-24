<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-1136 F1 (épica SCRUM-749). `legacy.almacenes` — staging en crudo de ALMACEN (11 filas).
 * Llave natural: codalmacen. TODAS las columnas copiables de ICG se tipan con el esquema real
 * (nvarchar/nchar -> string(n), ntext -> text, float -> double, int -> integer, smallint -> smallInteger,
 * bit -> boolean, datetime -> timestampTz), en minúscula; solo se excluye VERSION (timestamp). Sin FK
 * hacia `bodega.*` ni `ventas_diseno.*`.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'legacy';

    public function up(): void
    {
        Schema::create($this->table('almacenes'), function (Blueprint $table): void {
            $table->id();
            $table->string('codalmacen', 3);
            $table->unique('codalmacen', 'legacy_almacenes_codalmacen_uq');
            $table->string('nombrealmacen', 30)->nullable();
            $table->string('poblacion', 30)->nullable();
            $table->string('provincia', 30)->nullable();
            $table->text('notas')->nullable();
            $table->string('n', 1)->nullable();
            $table->string('centrocoste', 6)->nullable();
            $table->double('stockminimo')->nullable();
            $table->double('stockmaximo')->nullable();
            $table->double('factor')->nullable();
            $table->string('direccion', 30)->nullable();
            $table->string('telefono', 15)->nullable();
            $table->string('fax', 15)->nullable();
            $table->string('serietraspasos', 4)->nullable();
            $table->integer('codempresacontable')->nullable();
            $table->integer('valoraciontraspasos')->nullable();
            $table->integer('valoracioninventario')->nullable();
            $table->integer('pvpetiquetas')->nullable();
            $table->string('poperacional', 15)->nullable();
            $table->integer('codcliente')->nullable();
            $table->timestampTz('fechaultrep')->nullable();
            $table->timestampTz('ultfacturacionprestamos')->nullable();
            $table->timestampTz('fechabloqueo')->nullable();
            $table->integer('valoracionconsumos')->nullable();
            $table->integer('valoraciontraspasosdmn')->nullable();
            $table->integer('valoracioninventariosdmn')->nullable();
            $table->boolean('esmermas')->nullable();
            $table->boolean('esplanificacion')->nullable();
            $table->string('email', 30)->nullable();
            $table->timestampTz('horacambioturno')->nullable();
            $table->string('codpostal', 15)->nullable();
            $table->string('codpais', 2)->nullable();
            $table->string('serietraspasostranporte', 4)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('almacenes'));
    }
};
