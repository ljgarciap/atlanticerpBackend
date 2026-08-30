<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Batch 12 de Notas Crédito y Devoluciones (SCRUM-565→570, REQ-488→490) — "Corrección de datos"
 * con submit real: anula la factura original (`anulada_at`, Batch 4) y genera una factura NUEVA
 * para el MISMO `Order` (mismos productos, ITBMS o fecha corregidos) — `credit_notes.factura_nueva_id`
 * ya anticipaba esto desde su propia migración de Batch 10 (FK a una fila DISTINTA de `invoices`).
 *
 * Choca con el invariante original de Batch 2 (`unique(order_id)`, "un Order tiene a lo sumo una
 * factura") — nunca reconciliado hasta ahora porque Batch 12 (quien primero necesita 2 filas para
 * el mismo Order) no existía todavía cuando se escribió esa migración. Se relaja a un índice único
 * PARCIAL (`WHERE anulada_at IS NULL`) — preserva el invariante real que sí importa ("a lo sumo
 * UNA factura ACTIVA por Order"), permite que una fila anulada conviva con su reemplazo.
 *
 * Todo call site de `AdminContInvoice::where('order_id', ...)` que asumía una sola fila se
 * actualizó en el mismo commit para filtrar `whereNull('anulada_at')` — ver
 * `InvoiceService`/`CreditNoteService`. Pendiente de confirmación explícita del Arquitecto en
 * Senior Review — cambia un invariante de schema de un batch anterior, no solo agrega algo nuevo.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'admin_contab';

    public function up(): void
    {
        Schema::table($this->table('invoices'), function (Blueprint $table): void {
            $table->dropUnique(['order_id']);
        });

        DB::statement(sprintf(
            'CREATE UNIQUE INDEX invoices_order_id_active_unique ON %s (order_id) WHERE anulada_at IS NULL',
            $this->table('invoices'),
        ));
    }

    public function down(): void
    {
        DB::statement(sprintf('DROP INDEX IF EXISTS %s.invoices_order_id_active_unique', $this->schema()));

        Schema::table($this->table('invoices'), function (Blueprint $table): void {
            $table->unique('order_id');
        });
    }
};
