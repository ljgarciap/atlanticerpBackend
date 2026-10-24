<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pedidos gemelos de ICG (diseño `docs/architecture/icg-pedidos-gemelos-diseno.md`, §11, Opción L1) — ICG guarda por
 * línea de pedido las unidades pendientes y las ya servidas (`PEDVENTALIN.UNIDADESPEN`/`UNIDADESREC`). La factura de
 * saldo de un gemelo lleva solo las líneas pendientes por cantidad, así que el staging las conserva. Nullable: las
 * filas cargadas antes de este cambio quedan en NULL hasta la próxima corrida de `legacy:sync-pedidos`.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'legacy';

    public function up(): void
    {
        Schema::table($this->table('pedido_items'), function (Blueprint $table): void {
            $table->decimal('unidades_pendientes', 12, 2)->nullable();
            $table->decimal('unidades_recibidas', 12, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table($this->table('pedido_items'), function (Blueprint $table): void {
            $table->dropColumn(['unidades_pendientes', 'unidades_recibidas']);
        });
    }
};
