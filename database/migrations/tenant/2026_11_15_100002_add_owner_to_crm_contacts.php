<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 22 (B4) — los contactos del CRM guardan quién los creó y quién los editó por última vez. Nullable: los existentes quedan sin
 * dueño (los edita cualquiera del CRM hasta que alguien con alcance de equipo los reasigne).
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'crm';

    public function up(): void
    {
        Schema::table($this->table('contacts'), function ($table): void {
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table($this->table('contacts'), function ($table): void {
            $table->dropColumn(['created_by', 'updated_by']);
        });
    }
};
