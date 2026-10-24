<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-902 (REQ nuevo, Registrar Abono) — ledger de abonos/saldos a favor por registro, con
 * trazabilidad completa (origen, nivel de asociación, historial de uso). Reemplaza por completo a
 * `customer_credit_balances`/`CreditBalanceService` (ver migración de drop en el mismo batch): ese
 * escalar por cliente no podía representar niveles de asociación (Cliente/Proyecto/Factura) ni
 * consumo parcial trazable, y esta ficha SÍ lo exige (ver ticket, sección 3 "Modelo de datos").
 *
 * `nivel_asociacion` NO es una columna: es un valor calculado (accessor `AdminContAbono::
 * nivelAsociacion()`), derivado de cuáles de `invoice_id`/`sales_project_id` están llenos — el
 * ticket es explícito en que el usuario nunca lo elige directamente, y persistirlo como enum
 * mutable haría fácil desincronizarlo del dato real cuando Facturación re-asocia un abono de nivel
 * Proyecto a la factura recién generada (sección 4.3).
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'admin_contab';

    public function up(): void
    {
        Schema::create($this->table('abonos'), function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('master_client_id');
            // Solo informativo/navegación del formulario — nunca determina el nivel de asociación
            // (ver ticket sección 2.2).
            $table->unsignedBigInteger('sub_client_id')->nullable();
            // "Proyecto" = `ventas_diseno.sales_projects` (mismo vocabulario que
            // `admin_contab.invoices.sales_project_id` y el cascada Cliente→Proyecto ya usado en
            // Estado de Cuenta, `AccountStatementService::projectsForClient()`) — NO
            // `pipeline_cards` (ese es el "pedido" interno de Comisiones/VentasDiseno, un concepto
            // distinto en este módulo).
            $table->unsignedBigInteger('sales_project_id')->nullable();
            $table->unsignedBigInteger('invoice_id')->nullable();

            $table->decimal('monto_original', 12, 2);
            $table->decimal('monto_usado', 12, 2)->default(0);

            $table->string('metodo_pago');
            $table->unsignedBigInteger('cuenta_bancaria_id');
            $table->string('referencia');
            $table->string('comprobante_url')->nullable();

            $table->string('estado')->default('por_confirmar'); // por_confirmar | confirmado

            $table->unsignedBigInteger('registrado_por');
            $table->unsignedBigInteger('confirmado_por')->nullable();
            $table->timestamp('fecha_registro');
            $table->timestamp('fecha_confirmacion')->nullable();

            // Trazabilidad del excedente (ticket sección 4.5): cuando un abono de nivel
            // Factura/Proyecto es mayor a la factura que cubre obligatoriamente, la diferencia
            // genera UN abono nuevo a nivel Cliente master, apuntando acá al abono que lo originó.
            $table->unsignedBigInteger('abono_origen_id')->nullable();

            $table->timestamps();

            $table->index('master_client_id');
            $table->index('sales_project_id');
            $table->index('invoice_id');
            $table->index('estado');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('abonos'));
    }
};
