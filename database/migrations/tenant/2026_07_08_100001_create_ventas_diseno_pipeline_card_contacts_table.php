<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends TenantSchemaMigration
{
    protected string $module = 'ventas_diseno';

    public function up(): void
    {
        // Contactos por tarjeta (REQ-021) — a diferencia de CONTACTO en el modelo de
        // datos del cliente (que también permite contactos reutilizables a nivel de
        // subcliente para Cotizaciones), en Grupo 1b solo se implementa el caso
        // "contacto propio de la tarjeta" — el reuso a nivel de subcliente pertenece a
        // la pantalla de Cotización (fuera de alcance, ver memory/
        // project_backlog_ventas_diseno_req.md).
        Schema::create($this->table('pipeline_card_contacts'), function (Blueprint $table): void {
            $table->id();
            $table->foreignId('pipeline_card_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->enum('role', ['client', 'architect', 'other']);
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('pipeline_card_contacts'));
    }
};
