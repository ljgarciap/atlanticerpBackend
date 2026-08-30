<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 4 — Servicios, Batch 15 (REQ-211 RN3/RN4) — Panel "Indicadores del mes" de Inicio necesita
 * 2 señales reales que el esquema no tenía:
 *
 * - `visit_count`: cuántas veces el ticket entró a estado "on_site" (TicketService::changeStage()
 *   lo incrementa). "Resuelto en 1ra visita" (RN3) = ticket cerrado/resuelto con visit_count <= 1.
 *   Antes de este batch, `InternalTechnicianService::list()` y `CommissionCalculationService`
 *   dejaban esta fórmula como placeholder documentado ("depende de REQ-211, que no existe
 *   todavía") — este es el batch que la define. No se reusa ningún campo existente porque no
 *   había ninguno que contara revisitas.
 * - `closed_at`: momento en que el ticket llegó por primera vez a resolved/closed (seteado una
 *   sola vez, nunca sobreescrito en corridas posteriores). Necesario para "instalaciones
 *   completadas este mes" (RN2) y "tiempo prom. resolución" (RN4) — `updated_at` no sirve como
 *   proxy porque cualquier edición posterior al cierre lo correría.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'servicios';

    public function up(): void
    {
        Schema::table($this->table('tickets'), function (Blueprint $table): void {
            $table->unsignedInteger('visit_count')->default(0)->after('scheduled_ends_at');
            $table->timestampTz('closed_at')->nullable()->after('visit_count');
        });
    }

    public function down(): void
    {
        Schema::table($this->table('tickets'), function (Blueprint $table): void {
            $table->dropColumn(['visit_count', 'closed_at']);
        });
    }
};
