<?php

declare(strict_types=1);

use App\Shared\Multitenancy\Tenant;
use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-929 (B1b, ver docs/adr/ADR-SCRUM929-facturacion-parcial-o-completa.md) — una factura ahora
 * conoce a qué PEDIDO pertenece (`family_root_id`) y de qué TIPO es (`parcial` | `completo`).
 *
 * Por qué hace falta una columna nueva: hasta SCRUM-876 `order_id` era a la vez "la entrega" y "el
 * pedido" porque toda factura vivía en la tarjeta raíz. Con facturación por entrega, `order_id` de
 * una factura `parcial` pasa a ser la entrega que la originó, y "todas las facturas del pedido" se
 * resuelve por `family_root_id` (ver `AdminContInvoice::forOrder()`).
 *
 * El índice único parcial `invoices_order_id_active_unique (order_id) WHERE anulada_at IS NULL` NO se
 * toca acá: lo reemplaza la migración siguiente, `2026_09_18_110200_scope_invoice_order_id_unique_by_tipo`,
 * por `(order_id, tipo) WHERE anulada_at IS NULL` — a lo sumo UNA `parcial` activa por entrega y UNA
 * `completo` activa por `order_id`, de modo que la raíz pueda tener su propia parcial y además el
 * `completo` por el resto (D-2). Un índice único nuevo sobre `family_root_id` NO se puede crear:
 * dev/test conservan facturas duplicadas huérfanas de antes de SCRUM-876 (decisión C-8) y la
 * migración fallaría. El invariante entre `completo` y las `parcial` de OTRAS tarjetas (suma <= total,
 * tope por ítem, un solo `completo` por pedido) lo garantiza el guard transaccional de
 * `InvoiceService` (ADR §3), no la BD.
 *
 * Backfill: `family_root_id` = raíz de la familia de la tarjeta a la que apunta `order_id` (o el
 * propio `order_id` si la tarjeta ya no existe). Toda factura existente queda `tipo = 'completo'`
 * (default): para esos pedidos desaparecen solas las opciones de facturación parcial. EFECTO
 * COLATERAL A CONOCER: una factura huérfana de antes de SCRUM-876 (colgada de una tarjeta hermana
 * mientras la raíz nunca se facturó) ahora cuenta como "el pedido ya está facturado" — es el
 * comportamiento correcto y elimina un doble cobro posible, pero cambia lo que ve `forOrder()`.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'admin_contab';

    public function up(): void
    {
        Schema::table($this->table('invoices'), function (Blueprint $table): void {
            $table->unsignedBigInteger('family_root_id')->nullable()->after('order_id');
            $table->string('tipo', 10)->default('completo')->after('family_root_id');
        });

        $this->backfillFamilyRootId();

        DB::statement(sprintf('ALTER TABLE %s ALTER COLUMN family_root_id SET NOT NULL', $this->table('invoices')));
        DB::statement(sprintf(
            "ALTER TABLE %s ADD CONSTRAINT invoices_tipo_check CHECK (tipo IN ('parcial', 'completo'))",
            $this->table('invoices'),
        ));

        Schema::table($this->table('invoices'), function (Blueprint $table): void {
            $table->index('family_root_id', 'invoices_family_root_id_idx');
        });
    }

    /**
     * Público a propósito: es la única parte de esta migración que toca datos reales (cross-schema
     * contra `bodega.orders`) y una migración sobre tablas vacías no la ejercita — el test
     * `AdminContInvoiceFamilyRootTest` la invoca directamente contra filas huérfanas.
     */
    public function backfillFamilyRootId(): void
    {
        $slug = (string) Tenant::current()?->slug;

        // Raíz de la familia de la tarjeta de `order_id` (cross-schema: `bodega.orders`).
        DB::statement(sprintf(
            'UPDATE %s i SET family_root_id = COALESCE(o.family_id, o.id) FROM %s_bodega.orders o WHERE o.id = i.order_id',
            $this->table('invoices'),
            $slug,
        ));
        // Tarjeta inexistente (borrada / dato viejo): cae al propio order_id.
        DB::statement(sprintf('UPDATE %s SET family_root_id = order_id WHERE family_root_id IS NULL', $this->table('invoices')));
    }

    public function down(): void
    {
        Schema::table($this->table('invoices'), function (Blueprint $table): void {
            $table->dropIndex('invoices_family_root_id_idx');
        });

        DB::statement(sprintf('ALTER TABLE %s DROP CONSTRAINT IF EXISTS invoices_tipo_check', $this->table('invoices')));

        Schema::table($this->table('invoices'), function (Blueprint $table): void {
            $table->dropColumn(['family_root_id', 'tipo']);
        });
    }
};
