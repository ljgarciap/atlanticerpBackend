<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-1168 F1 — libro de movimientos del dinero de los cobros: la traza. SOLO inserción (nunca se edita ni se borra: el modelo lo impide y
 * `GrantAppRoleCommand` revoca UPDATE/DELETE al rol de la app, mismo patrón que `fe_transmisiones`). Un reverso es OTRO movimiento que apunta
 * al original (`reverses_movement_id`, único: un movimiento se reversa una sola vez), nunca un borrado.
 *
 * `monto` es siempre positivo (la magnitud); el efecto va firmado en dos columnas, para que los invariantes sean sumas simples:
 *   `delta_disponible` — cuánto cambia el dinero disponible (saldo a favor) del cliente;
 *   `delta_factura`    — cuánto cambia lo aplicado a `factura` por cobros (no incluye notas de crédito, que siguen en su tabla).
 * Sin FK físicas (mismo criterio de referencias sueltas del módulo).
 *
 * `payment_invoices.fecha_aplicacion`: fecha en que el dinero se aplicó a esa factura (decisión de Luis, 2026-10-19: la fecha de aplicación
 * manda para «la factura se cobró»). NULL en lo histórico: los lectores usan `COALESCE(fecha_aplicacion, payment.created_at)`, sin cambio para el pasado.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'admin_contab';

    public function up(): void
    {
        Schema::create($this->table('payment_movements'), function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('master_client_id');
            // ingreso | aplicacion | desaplicacion | anulacion | devolucion | historico | reclasificacion
            $table->string('tipo', 30);
            $table->unsignedBigInteger('payment_id')->nullable();
            $table->unsignedBigInteger('invoice_id')->nullable();
            $table->unsignedBigInteger('abono_id')->nullable();
            $table->decimal('monto', 12, 2);
            $table->decimal('delta_disponible', 12, 2)->default(0);
            $table->decimal('delta_factura', 12, 2)->default(0);
            // directa | cruce | migracion | sistema
            $table->string('origen', 20);
            $table->unsignedBigInteger('compensation_id')->nullable();
            $table->unsignedBigInteger('reverses_movement_id')->nullable()->unique();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->text('motivo')->nullable();
            $table->timestamp('fecha_movimiento');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['master_client_id', 'fecha_movimiento'], 'payment_movements_cliente_fecha_idx');
            $table->index('payment_id', 'payment_movements_payment_idx');
            $table->index('invoice_id', 'payment_movements_invoice_idx');
        });

        DB::statement("ALTER TABLE {$this->table('payment_movements')} ADD CONSTRAINT payment_movements_monto_positivo CHECK (monto > 0)");

        Schema::table($this->table('payment_invoices'), function (Blueprint $table): void {
            $table->timestamp('fecha_aplicacion')->nullable()->after('monto_abono_aplicado');
        });
    }

    public function down(): void
    {
        Schema::table($this->table('payment_invoices'), function (Blueprint $table): void {
            $table->dropColumn('fecha_aplicacion');
        });
        Schema::dropIfExists($this->table('payment_movements'));
    }
};
