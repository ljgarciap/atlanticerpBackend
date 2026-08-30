<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 4 — Servicios, Batch 4 (REQ-248 RN1) — Observaciones de texto libre en "Nuevo ticket".
 * Distinta de `descripcion` (obligatoria, "Breve descripción" del bloque de datos generales, ver
 * StoreTicketRequest) — el mockup (`5A__Servicios_Tickets.html`) las muestra como 2 campos
 * separados. `TicketService::detail()` reusaba `descripcion` como stand-in hasta este batch (ver
 * su propio comentario "hasta que exista una columna `observaciones` dedicada") — corregido acá.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'servicios';

    public function up(): void
    {
        Schema::table($this->table('tickets'), function (Blueprint $table): void {
            $table->text('observaciones')->nullable()->after('requerimientos_especiales');
        });
    }

    public function down(): void
    {
        Schema::table($this->table('tickets'), function (Blueprint $table): void {
            $table->dropColumn('observaciones');
        });
    }
};
