<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-220→236 (REQ-157→173) — Ingreso de Mercancía. `import_cost_total`/`freight_total`/
 * `handling_total`/`other_costs_total` son RN de REQ-164 (proveedor NO local): 4 montos
 * capturados UNA VEZ por ingreso (no por línea) y prorrateados proporcionalmente al valor de
 * cada línea al calcularse — por eso viven acá en el header, no en `purchase_order_receipt_lines`.
 * Para proveedor local no se usan (ITBMS va por línea, ver esa tabla).
 *
 * Sin `corrected_from_id`/versionado: REQ-167 ("corregir un ingreso ya guardado") edita el MISMO
 * registro in-place — RN dice "se precargan... editables desde ahí" y "se eliminan los
 * movimientos de stock del ingreso ORIGINAL antes de registrar los corregidos", no que se cree un
 * ingreso nuevo. `updated_at` ya registra la corrección.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'compras';

    public function up(): void
    {
        Schema::create($this->table('purchase_order_receipts'), function (Blueprint $table): void {
            $table->id();
            $table->foreignId('purchase_order_id')
                ->constrained($this->table('purchase_orders'), 'id', 'purchase_order_receipts_order_fk')
                ->cascadeOnDelete();
            $table->string('invoice_storage_key')->nullable();
            $table->string('invoice_original_filename')->nullable();
            $table->decimal('import_cost_total', 12, 2)->nullable();
            $table->decimal('freight_total', 12, 2)->nullable();
            $table->decimal('handling_total', 12, 2)->nullable();
            $table->decimal('other_costs_total', 12, 2)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('purchase_order_receipts'));
    }
};
