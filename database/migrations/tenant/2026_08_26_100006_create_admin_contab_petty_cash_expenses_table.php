<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Batch 20 de Admin&Cont (SCRUM-612→617, REQ-535→537) — línea individual de gasto de caja chica.
 * `report_id` nulo = todavía en "Pendientes"; se setea al incluirse en un reporte (REQ-538) y
 * nunca vuelve a null en este batch (el flujo de rechazo que sí lo libera es Batch 21).
 *
 * `intentos_rechazo` viaja desde ya (tinyInteger, default 0) aunque ningún endpoint de este batch
 * lo modifique — REQ-537 RN3 exige mostrar el estado "Rechazado (1/2)"/"Reabierto" de una línea, y
 * esa fórmula (ver `AdminContPettyCashExpense::estadoLabel()`) necesita esta columna para existir
 * desde ahora. Batch 21 solo va a tener que escribirla, no volver a tocar esta migración ni la
 * fórmula de estado.
 *
 * `solicitante_id` es cualquier empleado activo (Usuario), sin acotar por rol — el mockup usa una
 * lista cerrada hardcodeada marcada "REQ pendiente" en su propio comentario; acá se resuelve con
 * el directorio real de usuarios (mismo endpoint `/autocomplete/users` que ya usa el resto del
 * sistema para selects de personas), no con una lista propia de esta pantalla.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'admin_contab';

    public function up(): void
    {
        Schema::create($this->table('petty_cash_expenses'), function (Blueprint $table): void {
            $table->id();
            $table->date('fecha');

            $table->foreignId('solicitante_id')->nullable()
                ->constrained('users', 'id', 'admin_contab_petty_cash_expenses_solicitante_fk')
                ->nullOnDelete();

            $table->string('proveedor');
            $table->text('descripcion');
            $table->decimal('monto_bruto', 12, 2);
            $table->decimal('itbms', 12, 2); // RN1 — nunca nullable/default: se exige escribir 0 explícito

            $table->foreignId('report_id')->nullable()
                ->constrained($this->table('petty_cash_reports'), 'id', 'admin_contab_petty_cash_expenses_report_fk')
                ->nullOnDelete();

            $table->unsignedTinyInteger('intentos_rechazo')->default(0); // ver docblock — reservado para Batch 21

            $table->foreignId('created_by')->nullable()
                ->constrained('users', 'id', 'admin_contab_petty_cash_expenses_created_by_fk')
                ->nullOnDelete();

            $table->timestamps();

            $table->index('solicitante_id');
            $table->index('report_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('petty_cash_expenses'));
    }
};
