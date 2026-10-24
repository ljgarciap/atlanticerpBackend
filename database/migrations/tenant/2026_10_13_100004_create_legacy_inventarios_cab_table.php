<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-1136 F1 (épica SCRUM-749). `legacy.inventarios_cab` — staging en crudo de INVENTARIOS (~215 filas, solo cabeceras).
 * Llave natural: fecha, codalmacen. TODAS las columnas copiables de ICG se tipan con el esquema real
 * (nvarchar/nchar -> string(n), ntext -> text, float -> double, int -> integer, smallint -> smallInteger,
 * bit -> boolean, datetime -> timestampTz), en minúscula; solo se excluye VERSION (timestamp). Sin FK
 * hacia `bodega.*` ni `ventas_diseno.*`.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'legacy';

    public function up(): void
    {
        Schema::create($this->table('inventarios_cab'), function (Blueprint $table): void {
            $table->id();
            $table->timestampTz('fecha');
            $table->string('codalmacen', 3);
            $table->unique(['fecha', 'codalmacen'], 'legacy_inventarios_cab_natural_key_uq');
            $table->integer('tipovaloracion')->nullable();
            $table->string('serie', 4)->nullable();
            $table->integer('numero')->nullable();
            $table->integer('codvendedor')->nullable();
            $table->string('completo', 1)->nullable();
            $table->integer('metodo')->nullable();
            $table->string('inicial', 1)->nullable();
            $table->string('bloqueado', 1)->nullable();
            $table->integer('tipovaloraciondmn')->nullable();
            $table->smallInteger('estado')->nullable();
            $table->boolean('escierre')->nullable();
            $table->smallInteger('enlace_ejercicio')->nullable();
            $table->smallInteger('enlace_empresa')->nullable();
            $table->string('enlace_usuario', 10)->nullable();
            $table->integer('enlace_asiento')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('inventarios_cab'));
    }
};
