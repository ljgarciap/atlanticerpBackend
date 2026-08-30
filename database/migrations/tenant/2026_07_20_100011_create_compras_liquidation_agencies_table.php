<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-210 (REQ-147, alcance reducido): agencias aduanales/de liquidación para órdenes de
 * modalidad Zona Libre. Campos mínimos — RN1 a RN5 del ticket no están disponibles en el texto
 * (solo "ver Reglas de Negocio del REQ-147", sin detalle), así que se modela solo lo que el
 * criterio de aceptación pide explícitamente: nombre (obligatorio, para poder "registrar una
 * agencia nueva") + datos de contacto básicos, mismo patrón que `providers`.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'compras';

    public function up(): void
    {
        Schema::create($this->table('liquidation_agencies'), function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('contact_name')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('liquidation_agencies'));
    }
};
