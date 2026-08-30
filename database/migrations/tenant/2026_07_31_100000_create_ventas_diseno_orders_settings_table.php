<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * REQ-625 (Epic CRM Batch F, SCRUM-705) — umbral de puntos porcentuales que separa "Margen bajó"
 * de "Margen mejoró" en Pedidos. Decisión de Luis 2026-07-31: pese a que la spec de Daniela lo da
 * como un número fijo (5pp), va a tabla paramétrica con CRUD superadmin, no hardcodeado — mismo
 * patrón singleton (id=1, sembrada acá) que pricing_settings (REQ-033) y report_settings.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'ventas_diseno';

    public function up(): void
    {
        Schema::create($this->table('orders_settings'), function (Blueprint $table): void {
            $table->id();
            $table->decimal('margin_drop_threshold_pts', 5, 2)->default(5.00);
            $table->timestamps();
        });

        DB::table($this->table('orders_settings'))->insert([
            'id' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('orders_settings'));
    }
};
