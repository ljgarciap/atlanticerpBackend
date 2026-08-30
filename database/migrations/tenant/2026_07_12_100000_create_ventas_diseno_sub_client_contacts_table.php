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
        // REQ-066: contactos propios del Subcliente (distintos de pipeline_card_contacts,
        // que son propios de una tarjeta/proyecto puntual) — reutilizables entre proyectos
        // del mismo subcliente y, a futuro, desde el buscador de contactos de Cotización
        // (fuera de alcance de este grupo, ver memory/project_backlog_ventas_diseno_req.md).
        Schema::create($this->table('sub_client_contacts'), function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sub_client_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->enum('role', ['client', 'architect', 'other']);
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('sub_client_contacts'));
    }
};
