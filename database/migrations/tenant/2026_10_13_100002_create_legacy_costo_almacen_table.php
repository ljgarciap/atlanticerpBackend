<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-1136 F1 (épica SCRUM-749). `legacy.costo_almacen` — staging en crudo de COSTESPORALMACEN (~11.031 filas), foto completa.
 * Llave natural: codarticulo, talla, color, codalmacen. TODAS las columnas copiables de ICG se tipan con el esquema real
 * (nvarchar/nchar -> string(n), ntext -> text, float -> double, int -> integer, smallint -> smallInteger,
 * bit -> boolean, datetime -> timestampTz), en minúscula; solo se excluye VERSION (timestamp). Sin FK
 * hacia `bodega.*` ni `ventas_diseno.*`.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'legacy';

    public function up(): void
    {
        Schema::create($this->table('costo_almacen'), function (Blueprint $table): void {
            $table->id();
            $table->integer('codarticulo');
            $table->string('talla', 10);
            $table->string('color', 10);
            $table->string('codalmacen', 3);
            $table->unique(['codarticulo', 'talla', 'color', 'codalmacen'], 'legacy_costo_almacen_natural_key_uq');
            $table->double('costemedio')->nullable();
            $table->double('costestock')->nullable();
            $table->double('ultimocoste')->nullable();
            $table->double('precioultcompra')->nullable();
            $table->double('ultdesccomercial')->nullable();
            $table->double('unidadescompradas')->nullable();
            $table->timestampTz('fechaultcompra')->nullable();
            $table->double('ultdtocomercial')->nullable();
            $table->double('preciocomprareal')->nullable();
            $table->integer('codmoneda')->nullable();
            $table->double('costemediodmn')->nullable();
            $table->double('costestockdmn')->nullable();
            $table->double('ultimocostedmn')->nullable();
            $table->double('precioultcompradmn')->nullable();
            $table->double('preciocomprarealdmn')->nullable();
            $table->double('ultdesccomercialdmn')->nullable();
            $table->double('ultdtocomercialdmn')->nullable();
            $table->integer('codmonedadmn')->nullable();
            $table->double('unidadescompradasdmn')->nullable();
            $table->double('importecargo1')->nullable();
            $table->double('importecargo2')->nullable();
            $table->double('importecargo1dmn')->nullable();
            $table->double('importecargo2dmn')->nullable();
            $table->timestamps();
            $table->index('codalmacen');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('costo_almacen'));
    }
};
