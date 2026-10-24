<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vista de validación de pedidos gemelos (spec `docs/specs/icg-pedidos-gemelos-vista-validacion.md`): hoja del Excel de
 * Atlantic de la que salió el gemelo («PARCIAL 2025», «PARCIAL 2026», «COMPLETO 2026»). La etapa de Bodega no la
 * reemplaza: cambia cuando Bodega despacha un completo y no separa los parciales de 2025 de los de 2026. Aditivo y nulable
 * `icg_vendedor` es el nombre del vendedor del Excel (el pedido gemelo no tiene tarjeta ni vendedor de AtlanticERP). Ambas nulables (nulas en pedidos normales y en gemelos cargados antes de esta columna).
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'bodega';

    public function up(): void
    {
        Schema::table($this->table('orders'), function (Blueprint $table): void {
            $table->string('icg_hoja', 40)->nullable();
            $table->string('icg_vendedor', 120)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table($this->table('orders'), function (Blueprint $table): void {
            $table->dropColumn(['icg_hoja', 'icg_vendedor']);
        });
    }
};
