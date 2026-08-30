<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-228 (REQ-165) — cuando un ingreso llega incompleto, Compras elige en qué etapa de envío
 * queda el remanente (toda la secuencia, incluso "salio_de_origen", porque el remanente podría no
 * haber salido de origen todavía — distinto del `status` real de la orden, que sigue avanzando
 * por su propia cuenta). Se muestra como el estado visible en Ver Órdenes/Logística hasta el
 * próximo ingreso de esa orden, y se limpia solo cuando todas las líneas quedan completas.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'compras';

    public function up(): void
    {
        Schema::table($this->table('purchase_orders'), function (Blueprint $table): void {
            $table->string('pending_remainder_status')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table($this->table('purchase_orders'), function (Blueprint $table): void {
            $table->dropColumn('pending_remainder_status');
        });
    }
};
