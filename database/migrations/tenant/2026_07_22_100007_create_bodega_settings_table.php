<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-329 Oleada A / Batch A1 — tabla key/value de umbrales configurables de Bodega (ej. días
 * para "urgente"/"atrasado" — REQ-300/310). Regla global del proyecto: ningún umbral de negocio
 * se hardcodea como constante en código, siempre en una tabla paramétrica con CRUD/vista de
 * administración (ver CLAUDE.md § "Nunca"). Sin filas sembradas en este batch — los tickets de
 * Home/Reportes que los consuman (Oleada posterior) siembran/leen su propia key.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'bodega';

    public function up(): void
    {
        Schema::create($this->table('bodega_settings'), function (Blueprint $table): void {
            $table->id();
            $table->string('key')->unique('bodega_settings_key_uq');
            $table->text('value')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('bodega_settings'));
    }
};
