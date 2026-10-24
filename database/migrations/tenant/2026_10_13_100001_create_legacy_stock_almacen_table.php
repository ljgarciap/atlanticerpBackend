<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-1136 F1 (épica SCRUM-749). `legacy.stock_almacen` — staging en crudo de STOCKS (~181.727 filas), foto completa. `stock_corregido` = max(stock, 0), calculado al cargar. Un `codalmacen` sin fila en `almacenes` (caso A1) se carga igual: no hay FK.
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
        Schema::create($this->table('stock_almacen'), function (Blueprint $table): void {
            $table->id();
            $table->integer('codarticulo');
            $table->string('talla', 10);
            $table->string('color', 10);
            $table->string('codalmacen', 3);
            $table->unique(['codarticulo', 'talla', 'color', 'codalmacen'], 'legacy_stock_almacen_natural_key_uq');
            $table->double('stock')->nullable();
            $table->double('pedido')->nullable();
            $table->double('aservir')->nullable();
            $table->double('prestado')->nullable();
            $table->double('deposito')->nullable();
            $table->double('fabricacion')->nullable();
            $table->double('minimo')->nullable();
            $table->timestampTz('fechamodificado')->nullable();
            $table->double('maximo')->nullable();
            $table->string('ubicacion', 25)->nullable();
            $table->timestampTz('fecharegul')->nullable();
            $table->double('stockregul')->nullable();
            $table->double('enreparacion')->nullable();
            $table->double('entransito')->nullable();
            $table->double('merma')->nullable();
            $table->double('stockcontable')->nullable();
            $table->double('stock2')->nullable();
            $table->double('stockregul2')->nullable();
            $table->double('stock_corregido')->nullable();
            $table->timestamps();
            $table->index('codalmacen');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('stock_almacen'));
    }
};
