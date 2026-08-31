<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-433 (ADR-SCRUM433-445 sección 2) — el proveedor fijo "Zona Libre de Colón" nunca se
 * hardcodea (ni en frontend ni en backend): se guarda como parámetro configurable, mismo patrón
 * que `primary_approver_user_id` en la misma tabla (2026_07_20_100009). El registro real del
 * `Provider` lo crea `database/seeders/ZonaLibreProviderSeeder`, que también setea este campo.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'compras';

    public function up(): void
    {
        Schema::table($this->table('settings'), function (Blueprint $table): void {
            $table->foreignId('zona_libre_provider_id')->nullable()->constrained('providers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table($this->table('settings'), function (Blueprint $table): void {
            $table->dropConstrainedForeignId('zona_libre_provider_id');
        });
    }
};
