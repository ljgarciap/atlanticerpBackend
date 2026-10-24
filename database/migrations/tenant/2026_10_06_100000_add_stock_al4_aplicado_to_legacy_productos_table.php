<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-1114 (épica SCRUM-749) — línea base del stock de ICG por artículo para la sincronización
 * por delta (`legacy:sync-incremental-stock`). Es el último valor de AL4 (redondeado a entero, igual
 * que `catalog_products.stock_quantity`) que YA está reflejado en AtlanticERP: `delta = AL4_hoy - base`.
 *
 * NO se reutiliza `stock_al4` (staging): el incremental de productos lo refresca solo cuando cambia
 * el hash del catálogo, así que no sirve como "lo ya reflejado". Esta columna la mueve únicamente el
 * servicio de delta (en la misma transacción que el movimiento de kardex), el loader al crear un
 * producto y `legacy:init-stock-baseline`.
 *
 * `null` = el artículo nunca se cargó (inactivo/descatalogado al momento de la carga inicial) — NO
 * es lo mismo que `0` (se sabe que no tenía stock en AL4). Entero y no decimal a propósito: el
 * delta se calcula sobre enteros redondeados para que las fracciones de ICG no generen deriva.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'legacy';

    public function up(): void
    {
        Schema::table($this->table('productos'), function (Blueprint $table): void {
            $table->integer('stock_al4_aplicado')->nullable()->after('stock_al4');
        });
    }

    public function down(): void
    {
        Schema::table($this->table('productos'), function (Blueprint $table): void {
            $table->dropColumn('stock_al4_aplicado');
        });
    }
};
