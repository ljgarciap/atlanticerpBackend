<?php

declare(strict_types=1);

use App\Shared\Multitenancy\Tenant;
use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-388 (REQ-318, corrección de Daniela Amaya 2026-08-11) — `InventoryReviewService::
 * resolve()` sobrescribía `location_snapshot` con la ubicación confirmada durante la Revisión de
 * Inventario, perdiendo la ubicación ORIGINAL registrada del producto. `location_snapshot` ahora
 * queda intocable desde ese flujo — la nueva ubicación confirmada vive en columnas propias:
 *
 * - `found_location`: ubicación nueva/real confirmada por el asistente al marcar "Sí hay".
 * - `found_confirmed_by_id`: quién confirmó (FK a auth.users, mismo patrón cross-schema que
 *   `orders.assistant_id`/`picker_id`/`courier_id` — "FK suelta" con constraint real).
 * - `found_confirmed_at`: cuándo se confirmó — permite construir la nota "Nueva ubicación: X —
 *   Confirmado por Y" server-side (`OrderItem::foundNote()`) sin depender de `updated_at`, que
 *   también cambia por otras ediciones ajenas a la revisión (ej. `qty_picked`/`picking_notes`).
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'bodega';

    public function up(): void
    {
        $authSchema = Tenant::current()->slug.'_auth';

        Schema::table($this->table('order_items'), function (Blueprint $table) use ($authSchema): void {
            $table->string('found_location')->nullable()->after('found_at_location');
            $table->foreignId('found_confirmed_by_id')->nullable()->after('found_location')
                ->constrained("{$authSchema}.users", 'id', 'order_items_found_confirmed_by_fk')
                ->nullOnDelete();
            $table->timestamp('found_confirmed_at')->nullable()->after('found_confirmed_by_id');
        });
    }

    public function down(): void
    {
        Schema::table($this->table('order_items'), function (Blueprint $table): void {
            $table->dropConstrainedForeignId('found_confirmed_by_id');
            $table->dropColumn(['found_location', 'found_confirmed_at']);
        });
    }
};
