<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 30 (grupo 4) — insumos y herramientas se dan de baja (se desactivan, nunca se borran: tienen historial) y la
 * recepción de una herramienta guarda la referencia de compra que la respalda. Todo lo existente queda activo y sin
 * referencia: nada cambia al desplegar. Aditiva y reversible.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'servicios';

    public function up(): void
    {
        Schema::table($this->table('insumo_settings'), function (Blueprint $table): void {
            $table->boolean('is_active')->default(true);
        });
        Schema::table($this->table('tools'), function (Blueprint $table): void {
            $table->boolean('is_active')->default(true);
        });
        Schema::table($this->table('tool_purchase_requests'), function (Blueprint $table): void {
            $table->string('received_reference', 255)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table($this->table('tool_purchase_requests'), function (Blueprint $table): void {
            $table->dropColumn('received_reference');
        });
        Schema::table($this->table('tools'), function (Blueprint $table): void {
            $table->dropColumn('is_active');
        });
        Schema::table($this->table('insumo_settings'), function (Blueprint $table): void {
            $table->dropColumn('is_active');
        });
    }
};
