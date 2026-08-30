<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-610→611 (REQ-533→534) — ledger fundacional de movimientos de dinero por cuenta bancaria.
 * Ningún módulo productor existe todavía (Cobros, Notas Crédito y Devoluciones, Comisiones
 * Internas/Externas son batches futuros del mismo plan, ver docs/architecture/admin-contab-batches.md)
 * — esta tabla es la que esos batches van a insertar directamente vía Eloquent cuando existan.
 * `bank_account_id` nullable: RN2 de REQ-533 dice que comisiones pueden llegar sin cuenta asignada
 * (se completa después, REQ-534) — cobros/devoluciones, en cambio, siempre llegan con cuenta ya
 * asignada desde su pantalla de origen (RN1), pero la columna se deja nullable para los 3 tipos por
 * simplicidad de un único ledger, la regla de negocio se aplica a nivel de aplicación (Controller),
 * no de constraint de base de datos.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'admin_contab';

    public function up(): void
    {
        Schema::create($this->table('bank_movements'), function (Blueprint $table): void {
            $table->id();
            $table->foreignId('bank_account_id')->nullable()
                ->constrained($this->table('bank_accounts'))->nullOnDelete();
            $table->date('fecha');
            $table->string('tipo'); // cobro | devolucion | comision
            $table->string('concepto');
            $table->decimal('monto', 12, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('bank_movements'));
    }
};
