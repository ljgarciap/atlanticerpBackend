<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 14a — reglas de aprobación de órdenes de compra como datos. Se evalúa la primera regla activa (por `sort_order`) cuyas condiciones
 * coinciden; una condición vacía significa «cualquiera». Se siembra UNA regla equivalente a la que vivía en el código (envío aéreo pagado
 * por la empresa → requiere aprobación), de modo que el comportamiento por defecto no cambia.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'compras';

    public function up(): void
    {
        Schema::create($this->table('purchase_approval_rules'), function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->string('shipping_type', 20)->nullable();
            $table->string('who_pays_shipping', 20)->nullable();
            $table->decimal('min_amount', 14, 2)->nullable();
            $table->decimal('max_amount', 14, 2)->nullable();
            $table->boolean('requires_approval')->default(true);
            $table->timestamps();
        });

        DB::table($this->table('purchase_approval_rules'))->insert([
            'name' => 'Envío aéreo pagado por la empresa',
            'sort_order' => 1,
            'is_active' => true,
            'shipping_type' => 'aereo',
            'who_pays_shipping' => 'atlantic',
            'requires_approval' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('purchase_approval_rules'));
    }
};
