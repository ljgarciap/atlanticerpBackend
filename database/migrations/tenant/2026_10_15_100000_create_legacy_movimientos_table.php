<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-1136 F2 (épica SCRUM-749). `legacy.movimientos` — staging en crudo de MOVIMENTS de ICG (1.384.677 filas,
 * carga única reanudable; ICG está congelado). Llave natural: `icg_id` (= MOVIMENTS.ID, int, no contiguo hasta
 * 1.830.964); el `id` autoincremental es propio de AtlanticERP. `tipo` (REG/TMP/ENV/MOD) se conserva tal cual: el filtro
 * de TMP es de análisis, no de carga. Las 31 columnas restantes de ICG (todas, ninguna binaria) se tipan con el
 * esquema real (nvarchar/nchar -> string(n), float -> double, int -> integer, datetime -> timestampTz), en
 * minúscula y nullable. `seriedoc`/`numdoc`/`codprocli` son float en ICG y se respetan así. Sin FK hacia
 * `bodega.*` ni `ventas_diseno.*`.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'legacy';

    public function up(): void
    {
        Schema::create($this->table('movimientos'), function (Blueprint $table): void {
            $table->id();
            $table->integer('icg_id')->unique('legacy_movimientos_icg_id_uq');
            $table->string('codalmacenorigen', 3)->nullable();
            $table->string('codalmacendestino', 3)->nullable();
            $table->string('numserie', 25)->nullable();
            $table->integer('codarticulo')->nullable();
            $table->string('talla', 10)->nullable();
            $table->string('color', 10)->nullable();
            $table->double('precio')->nullable();
            $table->timestampTz('fecha')->nullable();
            $table->timestampTz('hora')->nullable();
            $table->double('codprocli')->nullable();
            $table->string('tipo', 3)->nullable();
            $table->double('unidades')->nullable();
            $table->string('seriedoc', 4)->nullable();
            $table->double('numdoc')->nullable();
            $table->string('seriecompra', 4)->nullable();
            $table->integer('numfaccompra')->nullable();
            $table->string('caja', 3)->nullable();
            $table->double('stock')->nullable();
            $table->double('pvp')->nullable();
            $table->integer('codmonedapvp')->nullable();
            $table->string('calcmovpost', 1)->nullable();
            $table->double('udmedida2')->nullable();
            $table->string('zona', 50)->nullable();
            $table->double('pvpdmn')->nullable();
            $table->double('preciodmn')->nullable();
            $table->double('stock2')->nullable();
            $table->double('unid1')->nullable();
            $table->double('unid2')->nullable();
            $table->double('unid3')->nullable();
            $table->double('unid4')->nullable();
            $table->integer('codformato')->nullable();
            $table->timestamps();

            $table->index(['codarticulo', 'fecha'], 'legacy_movimientos_articulo_fecha_idx');
            $table->index(['seriedoc', 'numdoc'], 'legacy_movimientos_doc_idx');
            $table->index('tipo', 'legacy_movimientos_tipo_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('movimientos'));
    }
};
