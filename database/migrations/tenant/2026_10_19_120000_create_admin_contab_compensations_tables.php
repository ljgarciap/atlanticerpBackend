<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-1168 F3 — compensación (cruce): aplicar el dinero disponible de un cliente (cobros a cuenta, abonos históricos, saldo de notas de crédito)
 * a sus facturas pendientes, total o parcial. Cada cruce confirmado es un documento con folio `CMP-nnn`.
 *
 * `compensations` guarda el encabezado; `compensation_lines`, de qué fuente sale cada monto y a qué factura va. El borrador del cruce vive en la
 * pantalla (nada se guarda hasta confirmar): antes de confirmar no cambia nada en las facturas. Sin FK físicas (referencias sueltas del módulo).
 *
 * `estado`: confirmado | reversado (un reverso, F4, deja el cruce marcado y genera sus contra-movimientos en el libro; nunca se borra).
 * `source_type`: cobro | abono | nota_credito.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'admin_contab';

    public function up(): void
    {
        Schema::create($this->table('compensation_folio_counters'), function (Blueprint $table): void {
            $table->unsignedSmallInteger('id')->primary();
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();
        });

        Schema::create($this->table('compensations'), function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('master_client_id')->index();
            $table->string('folio', 20)->unique();
            $table->string('estado', 20)->default('confirmado');
            $table->decimal('monto_total', 12, 2);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('confirmed_at');
            $table->unsignedBigInteger('reversed_by')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->text('motivo_reverso')->nullable();
            $table->timestamps();
        });

        Schema::create($this->table('compensation_lines'), function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('compensation_id')->index();
            $table->string('source_type', 20);
            $table->unsignedBigInteger('source_id');
            $table->unsignedBigInteger('invoice_id')->index();
            $table->decimal('monto', 12, 2);
            $table->timestamp('created_at')->useCurrent();
        });

        DB::statement("ALTER TABLE {$this->table('compensation_lines')} ADD CONSTRAINT compensation_lines_monto_positivo CHECK (monto > 0)");
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('compensation_lines'));
        Schema::dropIfExists($this->table('compensations'));
        Schema::dropIfExists($this->table('compensation_folio_counters'));
    }
};
