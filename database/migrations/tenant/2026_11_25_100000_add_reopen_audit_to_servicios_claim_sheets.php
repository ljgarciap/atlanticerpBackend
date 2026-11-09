<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 30 (grupo 3) — una hoja de reclamo completada se puede reabrir con motivo. Guarda cuándo y por qué.
 * Aditiva y reversible; las hojas existentes quedan sin reapertura.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'servicios';

    public function up(): void
    {
        Schema::table($this->table('claim_sheets'), function (Blueprint $table): void {
            $table->timestamp('reopened_at')->nullable();
            $table->text('reopened_reason')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table($this->table('claim_sheets'), function (Blueprint $table): void {
            $table->dropColumn(['reopened_at', 'reopened_reason']);
        });
    }
};
