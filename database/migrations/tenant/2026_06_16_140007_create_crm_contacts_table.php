<?php

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends TenantSchemaMigration
{
    protected string $module = 'crm';

    public function up(): void
    {
        Schema::create($this->table('contacts'), function (Blueprint $table) {
            $table->id();
            $table->string('nombre');
            $table->enum('tipo', ['developer', 'arquitecto', 'encargado', 'contacto']);
            $table->string('email')->nullable();
            $table->string('telefono')->nullable();
            $table->string('empresa')->nullable();
            $table->text('notas')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('contacts'));
    }
};
