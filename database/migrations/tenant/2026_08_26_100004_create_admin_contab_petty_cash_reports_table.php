<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Batch 20 de Admin&Cont (SCRUM-612→617, REQ-535→540) — Caja Chica. Un reporte agrupa los gastos
 * pendientes seleccionados en el flujo de 2 pasos de REQ-538 (selección + forma de pago) y arranca
 * en `pendiente_aprobacion` hasta que Mark lo aprueba (REQ-540), pasando a `finalizado` de forma
 * permanente. El rechazo total/parcial de un reporte (REQ-541/542, Batch 21) no está construido
 * acá — solo los 2 estados que este batch necesita.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'admin_contab';

    public function up(): void
    {
        Schema::create($this->table('petty_cash_reports'), function (Blueprint $table): void {
            $table->id();
            $table->string('numero')->unique('admin_contab_petty_cash_reports_numero_uq'); // folio NNNN-AAAA, ver AdminContPettyCashReportFolioService

            $table->string('forma_pago', 20); // transferencia|efectivo|cheque|yappy
            $table->string('estado', 30)->default('pendiente_aprobacion'); // pendiente_aprobacion|finalizado

            $table->foreignId('realizado_por')->nullable()
                ->constrained('users', 'id', 'admin_contab_petty_cash_reports_realizado_por_fk')
                ->nullOnDelete();
            $table->foreignId('aprobado_por')->nullable()
                ->constrained('users', 'id', 'admin_contab_petty_cash_reports_aprobado_por_fk')
                ->nullOnDelete();
            $table->timestampTz('fecha_aprobacion')->nullable();

            $table->timestamps();

            $table->index('estado');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('petty_cash_reports'));
    }
};
