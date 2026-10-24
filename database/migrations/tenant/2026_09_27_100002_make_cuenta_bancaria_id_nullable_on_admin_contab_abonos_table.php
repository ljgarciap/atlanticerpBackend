<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-955 punto 3 — bug real encontrado: `cuenta_bancaria_id` en `abonos` era NOT NULL sin
 * excepción, a diferencia de `payments.bank_account_id` (nullable desde su creación) — hacía
 * imposible registrar un Abono con método `retencion_impuestos`/`ajuste_cuenta`
 * (`AdminContPayment::METODOS_SIN_CUENTA_BANCARIA`), que nunca representan un movimiento bancario
 * real (mismo RN4/REQ-465-466 ya aplicado a Cobros). Se corrige acá para que
 * `StoreAbonoRequest`/`AbonoService` puedan persistir `null` para esos 2 métodos.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'admin_contab';

    public function up(): void
    {
        Schema::table($this->table('abonos'), function (Blueprint $table): void {
            $table->unsignedBigInteger('cuenta_bancaria_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table($this->table('abonos'), function (Blueprint $table): void {
            $table->unsignedBigInteger('cuenta_bancaria_id')->nullable(false)->change();
        });
    }
};
