<?php

declare(strict_types=1);

use App\Shared\Multitenancy\Tenant;
use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-329 Oleada A / Batch A1 (REQ-305 a 341, entidad PEDIDO) — pedido de Bodega, unidad
 * central del tablero Kanban "Pedidos". Nace al aprobar una cotización en Ventas & Diseño (REQ-308); desde
 * SCRUM-1128 lo crea la aprobación explícita "Pasar a Pedido" — ver `PipelineService::passToOrder()`.
 *
 * `stage` — una de las 7 etapas fijas del flujo (REQ-302/311, confirmadas contra Jira, NO el
 * placeholder "terminado" de la spec original): asignado, picking_pendiente, en_picking,
 * packing, por_despachar, despachado, entregado. Ver `App\Modules\Bodega\Models\Order::STAGE_ORDER`.
 *
 * `family_id`/`sequence_in_family`/`total_in_family` (REQ-319, HU-335) — un pedido dividido por
 * entrega parcial genera varias filas `orders` que comparten `order_number` mas no `id`; la fila
 * raíz tiene `family_id = null`, las hijas apuntan a ella.
 *
 * `assistant_id` se resuelve en la creación vía `WarehouseAssistantMapping` (REQ-307) — NULLABLE
 * (decisión de Luis, 2026-07-22, revisando el batch): si falta el mapeo para el vendedor, el
 * pedido se crea IGUAL en "Asignado" sin `assistant_id`, en vez de perderse — "no perder ningún
 * pedido" (REQ-308, valor de negocio) pesa más que "siempre asignado". Ver
 * `OrderService::createFromApprovedQuote()`, que además notifica al Jefe de Bodega para
 * triage manual cuando esto pasa.
 *
 * `customer_name`/`customer_contact`/`delivery_address` — snapshot heredado de la cotización
 * aprobada (REQ-308 RN1), copiado una sola vez al crear el pedido (no se recalcula en vivo desde
 * Ventas & Diseño, mismo criterio que `QuoteItem.unit_price`).
 *
 * `invoice_ready` (REQ-327/328) — stub aprobado por Luis: no existe módulo de
 * Administración/Contabilidad todavía, así que el flujo real de "factura lista" no puede
 * implementarse end-to-end en este batch. Columna booleana simple para no bloquear Batch A2/A3.
 *
 * Índice único parcial `orders_pipeline_card_root_uq` (Blocker 2 de Senior Review, 2026-07-22) —
 * defensa adicional además del lock en `PipelineService::changeStage()`: evita dos pedidos raíz
 * (`family_id IS NULL`) para la misma tarjeta de Pipeline incluso si el lock se pierde en algún
 * camino no contemplado. Acotado a `family_id IS NULL` a propósito — REQ-319 (entregas
 * parciales, Batch A2) va a crear varias filas `orders` con el mismo `pipeline_card_id` para
 * tarjetas hijas de una misma familia, eso es válido y no debe chocar con este índice. NULLs de
 * `pipeline_card_id` no compiten entre sí en un índice único de Postgres, así que pedidos sin
 * tarjeta de origen (futuro, ver columna nullable arriba) tampoco chocan. Mismo patrón que
 * `warehouse_assistant_mapping_active_seller_uq` (migración `..._100006_...`).
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'bodega';

    public function up(): void
    {
        $ventasDisenoSchema = Tenant::current()->slug.'_ventas_diseno';
        $authSchema = Tenant::current()->slug.'_auth';
        $table = $this->table('orders');

        Schema::create($table, function (Blueprint $table) use ($ventasDisenoSchema, $authSchema): void {
            $table->id();
            $table->string('order_number')->unique('orders_order_number_uq');

            $table->foreignId('pipeline_card_id')->nullable()
                ->constrained("{$ventasDisenoSchema}.pipeline_cards", 'id', 'orders_pipeline_card_fk')
                ->nullOnDelete();

            $table->string('order_type')->default('pedido'); // pedido|revision_inventario|faltante
            $table->string('stage')->default('asignado');

            $table->foreignId('family_id')->nullable()
                ->constrained($this->table('orders'), 'id', 'orders_family_fk')
                ->nullOnDelete();
            $table->unsignedInteger('sequence_in_family')->nullable();
            $table->unsignedInteger('total_in_family')->nullable();

            $table->foreignId('assistant_id')->nullable()
                ->constrained("{$authSchema}.users", 'id', 'orders_assistant_fk')
                ->nullOnDelete();
            $table->foreignId('picker_id')->nullable()
                ->constrained("{$authSchema}.users", 'id', 'orders_picker_fk')
                ->nullOnDelete();
            $table->foreignId('courier_id')->nullable()
                ->constrained("{$authSchema}.users", 'id', 'orders_courier_fk')
                ->nullOnDelete();

            $table->date('committed_delivery_date')->nullable();

            $table->string('customer_name')->nullable();
            $table->string('customer_contact')->nullable();
            $table->text('delivery_address')->nullable();

            $table->boolean('invoice_ready')->default(false);

            $table->timestamps();

            $table->index('stage', 'orders_stage_idx');
            $table->index('assistant_id', 'orders_assistant_idx');
            $table->index('family_id', 'orders_family_idx');
            $table->index('committed_delivery_date', 'orders_committed_delivery_date_idx');
        });

        // Blueprint no soporta índices únicos parciales (WHERE) directamente — ver docblock de
        // la clase para el porqué de acotarlo a family_id IS NULL.
        DB::statement("CREATE UNIQUE INDEX orders_pipeline_card_root_uq ON {$table} (pipeline_card_id) WHERE family_id IS NULL");
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('orders'));
    }
};
