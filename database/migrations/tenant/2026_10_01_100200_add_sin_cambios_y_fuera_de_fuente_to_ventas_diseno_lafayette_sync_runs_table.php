<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-990 — el sincronizador de Lafayette escribe ahora SOLO en `bodega.lafayette_productos` (upsert que solo toca
 * las filas que cambiaron y marca las que desaparecen de la fuente). Dos contadores nuevos para el historial de
 * corridas: `sin_cambios` (filas que ya estaban idénticas, no se escriben) y `fuera_de_fuente` (marcadas en esta corrida).
 *
 * `vinculados`, `coexistentes` y `excluidos` quedan en 0 hacia adelante; NO se eliminan ahora para no destruir el
 * historial (se retiran en el Release E, junto con las columnas `external_*` del catálogo).
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'ventas_diseno';

    public function up(): void
    {
        Schema::table($this->table('lafayette_sync_runs'), function (Blueprint $table): void {
            $table->unsignedInteger('sin_cambios')->default(0)->after('actualizados');
            $table->unsignedInteger('fuera_de_fuente')->default(0)->after('sin_cambios');
        });
    }

    public function down(): void
    {
        Schema::table($this->table('lafayette_sync_runs'), function (Blueprint $table): void {
            $table->dropColumn(['sin_cambios', 'fuera_de_fuente']);
        });
    }
};
