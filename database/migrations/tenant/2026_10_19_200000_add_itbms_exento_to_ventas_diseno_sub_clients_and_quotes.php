<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-1166 — clientes exentos de ITBMS. La exención se marca en el cliente fiscal (el sub-cliente: el que tiene RUC, DV y tipo de receptor) y la
 * deciden solo quienes administran (permiso `admin_contab.edit.client_itbms_exemption`), con el documento que la respalda (resolución o ley).
 *
 * `sub_clients`: la marca, el documento, y cuándo y quién la puso. `quotes`: una marca propia que sigue al cliente mientras la cotización no esté
 * confirmada y queda fija al confirmarla, así una cotización confirmada o una factura emitida nunca cambia si después se marca al cliente.
 *
 * Aditiva y sin backfill: todo queda en `false`, el comportamiento actual no cambia para nadie hasta que Administración marque a un cliente.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'ventas_diseno';

    public function up(): void
    {
        Schema::table($this->table('sub_clients'), function (Blueprint $table): void {
            $table->boolean('itbms_exento')->default(false);
            $table->string('itbms_exencion_documento')->nullable();
            $table->timestamp('itbms_exento_at')->nullable();
            $table->unsignedBigInteger('itbms_exento_by')->nullable();
        });

        Schema::table($this->table('quotes'), function (Blueprint $table): void {
            $table->boolean('itbms_exento')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table($this->table('quotes'), function (Blueprint $table): void {
            $table->dropColumn('itbms_exento');
        });

        Schema::table($this->table('sub_clients'), function (Blueprint $table): void {
            $table->dropColumn(['itbms_exento', 'itbms_exencion_documento', 'itbms_exento_at', 'itbms_exento_by']);
        });
    }
};
