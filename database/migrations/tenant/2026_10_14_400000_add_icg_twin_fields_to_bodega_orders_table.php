<?php

declare(strict_types=1);

use App\Shared\Multitenancy\Tenant;
use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pedidos gemelos de ICG (diseño `docs/architecture/icg-pedidos-gemelos-diseno.md`, §3 y §8) — ICG se cerró el
 * 05/10/2026 y los pedidos con saldo pendiente por facturar deben existir en AtlanticERP para facturarse aquí, sin
 * afectar lo ya ocurrido contablemente en ICG.
 *
 * `origin` distingue el pedido nacido de una cotización aprobada (`app`, todas las filas existentes) del gemelo de un
 * pedido de ICG (`icg_legacy`). `icg_serie`/`icg_numero` son la llave natural de ICG (UNIQUE solo entre gemelos).
 * `icg_facturado` es lo ya facturado en ICG (informativo: el pendiente facturable es total − icg_facturado) e
 * `icg_total_neto` el total de cabecera de ICG, con ITBMS.
 *
 * Un gemelo no tiene tarjeta de pipeline, así que `quote_id` (cotización gemela) y `sub_client_id` (cliente/receptor
 * fiscal) viven directo en el pedido: `Order::linkedQuote()` y el receptor fiscal los usan cuando no hay tarjeta.
 * Aditivo: no se modifica ningún dato existente.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'bodega';

    public function up(): void
    {
        $ventasDisenoSchema = Tenant::current()->slug.'_ventas_diseno';
        $orders             = $this->table('orders');

        Schema::table($orders, function (Blueprint $table) use ($ventasDisenoSchema): void {
            $table->string('origin', 20)->default('app');
            $table->string('icg_serie', 10)->nullable();
            $table->unsignedInteger('icg_numero')->nullable();
            $table->decimal('icg_facturado', 14, 2)->nullable();
            $table->decimal('icg_total_neto', 14, 2)->nullable();

            $table->foreignId('quote_id')->nullable()
                ->constrained("{$ventasDisenoSchema}.quotes", 'id', 'orders_quote_fk')
                ->nullOnDelete();
            $table->foreignId('sub_client_id')->nullable()
                ->constrained("{$ventasDisenoSchema}.sub_clients", 'id', 'orders_sub_client_fk')
                ->nullOnDelete();

            $table->index('origin', 'orders_origin_idx');
        });

        DB::statement("ALTER TABLE {$orders} ADD CONSTRAINT orders_origin_check CHECK (origin IN ('app', 'icg_legacy'))");
        // Un gemelo siempre trae su llave de ICG y su cotización gemela; un pedido normal, nada de ICG.
        DB::statement("ALTER TABLE {$orders} ADD CONSTRAINT orders_icg_fields_check CHECK ("
            ."(origin = 'icg_legacy' AND icg_serie IS NOT NULL AND icg_numero IS NOT NULL AND quote_id IS NOT NULL)"
            ." OR (origin = 'app' AND icg_serie IS NULL AND icg_numero IS NULL))");
        DB::statement("CREATE UNIQUE INDEX orders_icg_key_uq ON {$orders} (icg_serie, icg_numero) WHERE origin = 'icg_legacy'");
    }

    public function down(): void
    {
        $orders = $this->table('orders');

        DB::statement('DROP INDEX IF EXISTS '.Tenant::current()->slug.'_bodega.orders_icg_key_uq');
        DB::statement("ALTER TABLE {$orders} DROP CONSTRAINT IF EXISTS orders_icg_fields_check");
        DB::statement("ALTER TABLE {$orders} DROP CONSTRAINT IF EXISTS orders_origin_check");

        Schema::table($orders, function (Blueprint $table): void {
            $table->dropForeign('orders_quote_fk');
            $table->dropForeign('orders_sub_client_fk');
            $table->dropIndex('orders_origin_idx');
            $table->dropColumn(['origin', 'icg_serie', 'icg_numero', 'icg_facturado', 'icg_total_neto', 'quote_id', 'sub_client_id']);
        });
    }
};
