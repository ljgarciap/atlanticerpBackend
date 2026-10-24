<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Support\Facades\DB;

/**
 * SCRUM-929 (B2, ver docs/adr/ADR-SCRUM929-facturacion-parcial-o-completa.md) — el índice único
 * parcial `invoices_order_id_active_unique (order_id) WHERE anulada_at IS NULL` (creado en
 * `2026_08_24_100002_relax_order_id_unique_on_admin_contab_invoices_table`) se REEMPLAZA por
 * `invoices_order_id_tipo_active_unique (order_id, tipo) WHERE anulada_at IS NULL`.
 *
 * Por qué: una factura `completo` cuelga de la tarjeta RAÍZ del pedido (`order_id` = raíz), y la raíz
 * es también la 1ª entrega — la que casi siempre se factura primero como `parcial` (`order_id` = raíz).
 * D-2 exige poder emitir después "pedido completo" por lo que falta; con el índice viejo (una sola
 * factura activa por `order_id`, sea del tipo que sea) esa segunda factura reventaba en la BD.
 *
 * Qué sigue garantizando la BD: a lo sumo UNA `parcial` activa por entrega y UNA `completo` activa
 * por `order_id`. Qué NO puede expresar un índice y sigue siendo del guard transaccional de
 * `InvoiceService`/`InvoiceBillingPlanner` (ADR §3): que no coexistan un `completo` y parciales que
 * sumen más que el total, que un pedido no tenga dos `completo` colgadas de tarjetas distintas, y el
 * tope por ítem.
 *
 * Seguro sobre datos existentes: es MÁS DÉBIL que el índice que reemplaza (toda tupla que era única
 * por `order_id` también lo es por `(order_id, tipo)`), así que no puede fallar al crearse, ni
 * siquiera con las facturas huérfanas de dev/test (todas `completo` tras el backfill de `110000`).
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'admin_contab';

    public function up(): void
    {
        DB::statement(sprintf('DROP INDEX IF EXISTS %s.invoices_order_id_active_unique', $this->schema()));
        DB::statement(sprintf(
            'CREATE UNIQUE INDEX invoices_order_id_tipo_active_unique ON %s (order_id, tipo) WHERE anulada_at IS NULL',
            $this->table('invoices'),
        ));
    }

    /**
     * ADVERTENCIA: el `down()` puede FALLAR si ya existen una factura `parcial` y una `completo`
     * activas con el mismo `order_id` (el caso que esta migración habilita): el índice viejo no las
     * admite. En ese caso hay que anular o reasignar una de las dos antes de revertir.
     */
    public function down(): void
    {
        DB::statement(sprintf('DROP INDEX IF EXISTS %s.invoices_order_id_tipo_active_unique', $this->schema()));
        DB::statement(sprintf(
            'CREATE UNIQUE INDEX invoices_order_id_active_unique ON %s (order_id) WHERE anulada_at IS NULL',
            $this->table('invoices'),
        ));
    }
};
