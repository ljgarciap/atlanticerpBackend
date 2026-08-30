<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * REQ-030 (Cotización-A): Arquitecto con "base de datos propia" — a propósito
 * distinta de sub_client_contacts/pipeline_card_contacts (rol architect ahí es un
 * contacto de un cliente puntual; acá es un directorio global de arquitectos,
 * reutilizable entre cotizaciones de cualquier cliente).
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'ventas_diseno';

    public function up(): void
    {
        Schema::create($this->table('architects'), function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('architects'));
    }
};
