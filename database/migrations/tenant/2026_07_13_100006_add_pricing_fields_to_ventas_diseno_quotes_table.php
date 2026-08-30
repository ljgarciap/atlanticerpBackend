<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cotización-B (REQ-033/041): tipo de precio general de la cotización + modo de
 * descuento (por línea / global, excluyentes — REQ-041). "Público" es el default al
 * abrir el formulario (REQ-033); "line" es el default de descuento, sin ninguno
 * aplicado todavía.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'ventas_diseno';

    public function up(): void
    {
        Schema::table($this->table('quotes'), function (Blueprint $table): void {
            $table->enum('price_type', ['public', 'project', 'special', 'partner', 'premium'])
                ->default('public')->after('architect_id');
            $table->enum('discount_mode', ['line', 'global'])->default('line')->after('price_type');
            $table->decimal('global_discount_percent', 5, 2)->default(0)->after('discount_mode');
        });
    }

    public function down(): void
    {
        Schema::table($this->table('quotes'), function (Blueprint $table): void {
            $table->dropColumn(['price_type', 'discount_mode', 'global_discount_percent']);
        });
    }
};
