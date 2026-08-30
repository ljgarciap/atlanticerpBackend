<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-640 (REQ-563) — ubicaciones puramente administrativas (tipo `oficina`/`otra`). Las 7
 * bodegas/showrooms oficiales NO se duplican acá — RN1 exige que nunca haya un nombre alternativo
 * al de `App\Modules\Bodega\Models\Warehouse`, así que esta tabla solo guarda lo que Bodega no
 * tiene. `AdminContLocationController::index()` combina ambas fuentes en un único listado.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'admin_contab';

    public function up(): void
    {
        Schema::create($this->table('locations'), function (Blueprint $table): void {
            $table->id();
            $table->string('nombre');
            $table->string('tipo'); // oficina | otra
            $table->string('direccion', 500)->nullable();
            $table->boolean('activa')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('locations'));
    }
};
