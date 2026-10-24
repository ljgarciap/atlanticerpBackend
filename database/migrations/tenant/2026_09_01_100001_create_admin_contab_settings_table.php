<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * REQ-635 (SCRUM-811) — tabla key/value de umbrales configurables de Admin&Cont, mismo patrón
 * exacto que `bodega_settings` (SCRUM-329). Regla global del proyecto: ningún umbral de negocio
 * se hardcodea como constante en código, siempre en una tabla paramétrica con CRUD/vista de
 * administración (ver CLAUDE.md § "Nunca"). Ver docs/security/THREAT-MODEL-bodega-admincont-fraude.md.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'admin_contab';

    public function up(): void
    {
        Schema::create($this->table('admin_contab_settings'), function (Blueprint $table): void {
            $table->id();
            $table->string('key')->unique('admin_contab_settings_key_uq');
            $table->text('value')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('admin_contab_settings'));
    }
};
