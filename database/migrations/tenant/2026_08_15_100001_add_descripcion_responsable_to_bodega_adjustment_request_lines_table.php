<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-428 (REQ-358, rebote de Daniela Amaya 2026-08-13) — el modal "Solicitar ajuste" agrega 2
 * campos opcionales nuevos al lado del Motivo (que pasa de texto libre a un desplegable de 6
 * opciones fijas, ver `AdjustmentRequestLine::MOTIVOS` — sin cambio de columna, sigue siendo
 * `text`, la restricción de vocabulario se valida en el Request): `descripcion` (texto libre,
 * también usado para detallar cuando `motivo = OTRO_ESPECIFICAR`) y `responsable` (texto libre,
 * nombre de quien detectó/reporta la diferencia — no es un FK a `users`, el mockup lo pide como
 * campo de texto simple, puede ser alguien sin cuenta en AtlanticERP).
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'bodega';

    public function up(): void
    {
        Schema::table($this->table('adjustment_request_lines'), function (Blueprint $table): void {
            $table->text('descripcion')->nullable()->after('motivo');
            $table->string('responsable')->nullable()->after('descripcion');
        });
    }

    public function down(): void
    {
        Schema::table($this->table('adjustment_request_lines'), function (Blueprint $table): void {
            $table->dropColumn(['descripcion', 'responsable']);
        });
    }
};
