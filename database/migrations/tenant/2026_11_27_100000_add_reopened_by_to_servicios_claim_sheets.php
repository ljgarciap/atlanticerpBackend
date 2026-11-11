<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 32 — una hoja de reclamo reabierta guarda también QUIÉN la reabrió (hasta ahora solo cuándo y por qué).
 * Aditiva y reversible; las hojas ya reabiertas quedan sin autor.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'servicios';

    public function up(): void
    {
        Schema::table($this->table('claim_sheets'), function (Blueprint $table): void {
            $table->unsignedBigInteger('reopened_by_user_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table($this->table('claim_sheets'), function (Blueprint $table): void {
            $table->dropColumn('reopened_by_user_id');
        });
    }
};
