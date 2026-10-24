<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-1136 F1 (épica SCRUM-749). `legacy.traspasos_cab` — staging en crudo de TRASPASOSCAB (~7.253 filas), foto completa, INCLUYE anulados; las líneas viven en MOVIMENTS (F2).
 * Llave natural: serie, caja, numero. TODAS las columnas copiables de ICG se tipan con el esquema real
 * (nvarchar/nchar -> string(n), ntext -> text, float -> double, int -> integer, smallint -> smallInteger,
 * bit -> boolean, datetime -> timestampTz), en minúscula; solo se excluye VERSION (timestamp). Sin FK
 * hacia `bodega.*` ni `ventas_diseno.*`.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'legacy';

    public function up(): void
    {
        Schema::create($this->table('traspasos_cab'), function (Blueprint $table): void {
            $table->id();
            $table->string('serie', 4);
            $table->string('caja', 3);
            $table->integer('numero');
            $table->unique(['serie', 'caja', 'numero'], 'legacy_traspasos_cab_natural_key_uq');
            $table->timestampTz('fecha')->nullable();
            $table->string('codalmacenorigen', 3)->nullable();
            $table->string('codalmacendestino', 3)->nullable();
            $table->string('seriecompra', 4)->nullable();
            $table->integer('numfaccompra')->nullable();
            $table->string('contabilizado', 1)->nullable();
            $table->double('total')->nullable();
            $table->string('anulado', 1)->nullable();
            $table->string('serieanulado', 4)->nullable();
            $table->string('cajaanulado', 3)->nullable();
            $table->integer('numeroanulado')->nullable();
            $table->string('recibido', 1)->nullable();
            $table->timestampTz('fecharecibido')->nullable();
            $table->string('identificador', 20)->nullable();
            $table->integer('recibidoporcodvendedor')->nullable();
            $table->string('descargado', 1)->nullable();
            $table->string('observaciones', 100)->nullable();
            $table->double('totaldmn')->nullable();
            $table->string('esautomatico', 1)->nullable();
            $table->string('esrecuento', 1)->nullable();
            $table->string('esajuste', 1)->nullable();
            $table->string('cuentagastosexistencias', 12)->nullable();
            $table->string('cuentagastosexistenciasdmn', 12)->nullable();
            $table->integer('idconceptoajuste')->nullable();
            $table->integer('idconceptoajustedmn')->nullable();
            $table->string('escontabilizable', 1)->nullable();
            $table->timestampTz('fechaanulado')->nullable();
            $table->string('serieventa', 4)->nullable();
            $table->integer('numfacventa')->nullable();
            $table->timestampTz('fechacreacion')->nullable();
            $table->integer('impresiones')->nullable();
            $table->timestampTz('fechatransporte')->nullable();
            $table->string('modificable', 1)->nullable();
            $table->integer('idmotivo')->nullable();
            $table->string('descmotivo', 100)->nullable();
            $table->string('estransporte', 1)->nullable();
            $table->string('vehiculo', 200)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('traspasos_cab'));
    }
};
