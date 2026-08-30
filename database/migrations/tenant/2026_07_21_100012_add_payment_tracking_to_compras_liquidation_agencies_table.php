<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-254 (REQ-191) — "situación de pago" pedida en el ticket original no tiene RN1→RN3
 * documentadas (ver nota de alcance en LiquidationAgencyController::class) ni existe un modelo de
 * pagos a agencias aprobado (a diferencia de `purchase_order_payments`, que sí tiene RN completas
 * para pagos a proveedores). El mockup de Daniela (2L__Compras_PagosOperativos.html) confirma en
 * su propio comentario que "el monto $ por pagar / $ pagado es un dato NUEVO que no existía antes
 * en ningún lado" — no se deriva de `purchase_orders.total_amount` ni de ninguna otra tabla.
 *
 * Por indicación de Luis (2026-07-18, ver comentario en SCRUM-254): mockear lo mejor posible en
 * vez de dejarlo vacío. Se modela como 4 campos de carga MANUAL por agencia (no un historial de
 * pagos con registro/comprobante como `purchase_order_payments` — eso sería inventar RN que nadie
 * definió). Compras anota a mano lo que la agencia le facturó y lo que ya le pagó; el sistema solo
 * muestra el saldo derivado (`pending - paid`). Si más adelante se define un flujo real de RN,
 * este mock se reemplaza sin romper el shape de la tabla (son columnas nullable adicionales).
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'compras';

    public function up(): void
    {
        Schema::table($this->table('liquidation_agencies'), function (Blueprint $table): void {
            $table->decimal('pending_payment_amount', 12, 2)->default(0)->after('notes');
            $table->decimal('paid_amount', 12, 2)->default(0)->after('pending_payment_amount');
            $table->date('last_payment_date')->nullable()->after('paid_amount');
            $table->date('next_payment_date')->nullable()->after('last_payment_date');
        });
    }

    public function down(): void
    {
        Schema::table($this->table('liquidation_agencies'), function (Blueprint $table): void {
            $table->dropColumn(['pending_payment_amount', 'paid_amount', 'last_payment_date', 'next_payment_date']);
        });
    }
};
