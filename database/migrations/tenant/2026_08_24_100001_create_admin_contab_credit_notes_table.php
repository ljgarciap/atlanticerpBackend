<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Batch 10 (SCRUM-553→558, REQ-476→481) — apertura de Notas Crédito y Devoluciones, el grupo más
 * grande de la épica Admin&Cont (22 tickets, ver docs/architecture/admin-contab-batches.md). Tabla
 * completa según la entidad NOTA_CREDITO del Modelo de Datos del cliente (Excel adjunto a cada
 * ticket) — infraestructura fundacional a propósito, mismo criterio que
 * `admin_contab.customer_credit_balances` o `invoices.anulada_at`: ningún escritor real todavía,
 * "registrar nota" (el submit real: factura de origen, monto/desglose ITBMS, motivo, comprobante,
 * folio, notificaciones) es Batch 11 (SCRUM-559→564, REQ-482→487). Este batch solo construye el
 * formulario dinámico (selección cliente/tipo, subtipo de anulación, corrección de datos, y el
 * componente de devolución precargado desde Bodega) — la tabla queda vacía hasta entonces.
 *
 * `master_client_id`/`devolucion_bodega_id` son FK "sueltas" (sin constraint) porque cruzan schema
 * de tenant (ventas_diseno / bodega respectivamente) — mismo criterio que
 * `invoices.master_client_id`. `devolucion_bodega_id` en particular no tiene ni tabla origen real
 * todavía: la cola de devoluciones confirmadas por Bodega (REQ-491, "DEVOLUCION_BODEGA" en el
 * Modelo de Datos) es Batch 12, más adelante en el mismo plan.
 *
 * `factura_origen_id`/`factura_nueva_id`/`cuenta_bancaria_salida_id`/`nuevo_tratamiento_itbms_rate_id`
 * SÍ son FK reales — `invoices`, `bank_accounts` e `itbms_rates` viven en el mismo schema
 * `admin_contab` (mismo criterio que `bank_movements.bank_account_id`).
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'admin_contab';

    public function up(): void
    {
        Schema::create($this->table('credit_notes'), function (Blueprint $table): void {
            $table->id();
            $table->string('numero')->unique();
            $table->unsignedBigInteger('master_client_id');
            $table->string('cliente');
            $table->string('tipo'); // descuento_comercial | devolucion_mercancia | anulacion_completa
            $table->string('subtipo_anulacion')->nullable(); // cancelado | correccion — solo si tipo=anulacion_completa

            $table->foreignId('factura_origen_id')->constrained($this->table('invoices'));

            $table->decimal('monto', 12, 2);
            $table->decimal('subtotal', 12, 2);
            $table->decimal('itbms', 12, 2);
            $table->text('motivo');

            $table->string('resultado')->nullable(); // aplicado_saldo | devuelto | saldo_favor
            $table->foreignId('cuenta_bancaria_salida_id')->nullable()
                ->constrained($this->table('bank_accounts'))->nullOnDelete();

            $table->string('estado'); // aplicada | pendiente_aprobacion | rechazada

            // Anulación completa — subtipo "cancelado" (REQ-479).
            $table->boolean('mercancia_regresa_bodega')->nullable();

            // Anulación completa — subtipo "correccion" (REQ-480).
            $table->string('motivo_correccion')->nullable(); // itbms | fecha | ambos
            $table->foreignId('nuevo_tratamiento_itbms_rate_id')->nullable()
                ->constrained($this->table('itbms_rates'))->nullOnDelete();
            $table->date('nueva_fecha_factura')->nullable();
            $table->foreignId('factura_nueva_id')->nullable()
                ->constrained($this->table('invoices'))->nullOnDelete();
            $table->boolean('correccion_pendiente_aprobacion')->default(false);

            $table->string('comprobante_url')->nullable();

            $table->foreignId('registrado_por')->constrained('users');
            $table->date('fecha');

            $table->foreignId('aprobado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->date('fecha_decision')->nullable();
            $table->text('motivo_rechazo')->nullable();

            // Devolución de mercancía — precargada desde la cola de Bodega (REQ-481/REQ-491).
            $table->unsignedBigInteger('devolucion_bodega_id')->nullable();

            $table->timestamps();

            $table->index('master_client_id');
            $table->index('tipo');
            $table->index('estado');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('credit_notes'));
    }
};
