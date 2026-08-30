<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 4 — Servicios, Batch 12 (REQ-237, SCRUM-300) — Condiciones del servicio configurables por
 * Gerencia. La migración original de `service_quotes` (Batch 11,
 * `2026_08_11_100011_create_servicios_service_quotes_table`) documentó a propósito NO agregar esta
 * columna antes de tiempo — este es ese "después".
 *
 * `conditions` es SIEMPRE una copia congelada del texto vigente en
 * `ServiciosSettingsService::conditionsCotizacionServicios()` al momento de
 * `ServiceQuoteController::send()` (nunca una referencia viva) — permanece `null` mientras la
 * cotización está en Borrador; el frontend muestra el valor vigente como preview antes de eso, sin
 * persistirlo.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'servicios';

    public function up(): void
    {
        Schema::table($this->table('service_quotes'), function (Blueprint $table): void {
            $table->text('conditions')->nullable()->after('observations');
        });
    }

    public function down(): void
    {
        Schema::table($this->table('service_quotes'), function (Blueprint $table): void {
            $table->dropColumn('conditions');
        });
    }
};
