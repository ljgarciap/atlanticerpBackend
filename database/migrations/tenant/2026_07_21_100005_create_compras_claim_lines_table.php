<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-260 (REQ-197) — productos afectados de un reclamo. RN dura del ticket: no repetir un
 * producto (unique compuesto) ni exceder la cantidad pedida en `purchase_order_lines.quantity`
 * (validado en el controller, no a nivel de constraint — necesita leer la orden).
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'compras';

    public function up(): void
    {
        Schema::create($this->table('claim_lines'), function (Blueprint $table): void {
            $table->id();
            $table->foreignId('claim_id')
                ->constrained($this->table('claims'), 'id', 'claim_lines_claim_fk')
                ->cascadeOnDelete();
            $table->foreignId('purchase_order_line_id')
                ->constrained($this->table('purchase_order_lines'), 'id', 'claim_lines_order_line_fk')
                ->restrictOnDelete();
            $table->integer('affected_quantity');
            $table->text('description')->nullable();
            $table->timestamps();
            $table->unique(['claim_id', 'purchase_order_line_id'], 'claim_lines_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('claim_lines'));
    }
};
