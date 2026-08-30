<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * REQ-031: contactos de una cotización — reusa sub_client_contacts (contactos
 * reutilizables del Subcliente, ya construidos en Grupo 2) en vez de duplicar el
 * modelo "contacto propio" de pipeline_card_contacts. Esta tabla es solo el pivot
 * que decide qué contactos de ese Subcliente están agregados a ESTA cotización.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'ventas_diseno';

    public function up(): void
    {
        Schema::create($this->table('quote_contacts'), function (Blueprint $table): void {
            $table->id();
            $table->foreignId('quote_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sub_client_contact_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['quote_id', 'sub_client_contact_id'], 'quote_contacts_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('quote_contacts'));
    }
};
