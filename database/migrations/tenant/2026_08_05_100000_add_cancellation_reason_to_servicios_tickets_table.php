<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 4 — Servicios, Batch 3 (REQ-227 RN6) — motivo de cancelación obligatorio al cancelar un
 * ticket. Nullable a nivel de columna porque solo se captura en el momento de cancelar, no al
 * crear el ticket ni en el resto de los estados.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'servicios';

    public function up(): void
    {
        Schema::table($this->table('tickets'), function (Blueprint $table): void {
            $table->text('cancellation_reason')->nullable()->after('estado');
        });
    }

    public function down(): void
    {
        Schema::table($this->table('tickets'), function (Blueprint $table): void {
            $table->dropColumn('cancellation_reason');
        });
    }
};
