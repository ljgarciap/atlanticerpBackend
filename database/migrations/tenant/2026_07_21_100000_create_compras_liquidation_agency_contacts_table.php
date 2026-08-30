<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-255 (REQ-192) — una agencia de liquidación puede tener varios contactos (nombre,
 * teléfono, correo), a diferencia del contacto único inline que `liquidation_agencies` soporta
 * hoy (SCRUM-210, alcance reducido). RN2 del ticket ("no se puede quedar sin ningún contacto")
 * se aplica en el controller, no acá — a nivel de schema alcanza con la FK.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'compras';

    public function up(): void
    {
        Schema::create($this->table('liquidation_agency_contacts'), function (Blueprint $table): void {
            $table->id();
            $table->foreignId('liquidation_agency_id')
                ->constrained($this->table('liquidation_agencies'), 'id', 'liquidation_agency_contacts_agency_fk')
                ->cascadeOnDelete();
            $table->string('name');
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('liquidation_agency_contacts'));
    }
};
