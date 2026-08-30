<?php

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends TenantSchemaMigration
{
    protected string $module = 'crm';

    public function up(): void
    {
        Schema::create($this->table('projects'), function (Blueprint $table) {
            $table->id();
            $table->string('nombre');
            $table->string('contacto')->nullable();
            $table->string('email')->nullable();
            $table->string('celular')->nullable();
            $table->string('encargado')->nullable();
            $table->string('developer')->nullable();
            $table->string('arquitecto')->nullable();
            $table->enum('tipo', ['diseno', 'cotizacion', 'ambos']);
            $table->boolean('dialux')->default(false);
            $table->decimal('area', 10, 2)->nullable();
            $table->string('despacho')->nullable();
            $table->string('ubicacion')->nullable();
            $table->enum('etapa', ['lead', 'diseno', 'cotizacion', 'propuesta', 'cerrado', 'perdido'])->default('lead');
            $table->date('fecha_entrega')->nullable();
            $table->decimal('valor', 12, 2)->nullable();
            $table->text('notas')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('projects'));
    }
};
