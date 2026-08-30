<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 4 — Servicios, Batch 5 (REQ-263→267) — técnicos externos (empresas/personas
 * subcontratadas). Ver docs/architecture/servicios-fase4-diseno.md sección "schema: servicios".
 * `email` se agrega acá pese a no estar en el diseño original (REQ-264 RN1 la lista como campo
 * opcional del formulario de alta) — el diseño quedó desactualizado en ese detalle.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'servicios';

    public function up(): void
    {
        Schema::create($this->table('external_technicians'), function (Blueprint $table): void {
            $table->id();
            $table->string('nombre');
            $table->string('empresa');
            $table->string('especialidad')->nullable();
            $table->string('telefono')->nullable();
            $table->string('email')->nullable();

            $table->decimal('tarifa_dia', 10, 2);
            $table->boolean('tarifa_visible')->default(true);
            $table->string('estado')->default('active');

            $table->timestamps();

            $table->index('estado');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('external_technicians'));
    }
};
