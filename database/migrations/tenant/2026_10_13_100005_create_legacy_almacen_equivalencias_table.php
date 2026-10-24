<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-1136 F1 (épica SCRUM-749). `legacy.almacen_equivalencias` — mapeo MANUAL almacén ICG → bodega
 * de AtlanticERP (RF5). `warehouse_id` es nullable y SIN FK a `bodega.warehouses` (cero acoplamiento, diseño
 * §1); `estado` = propuesto | confirmado. No se usa para mover stock hasta que el negocio confirme;
 * ningún comando de F1 la escribe ni la siembra.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'legacy';

    public function up(): void
    {
        Schema::create($this->table('almacen_equivalencias'), function (Blueprint $table): void {
            $table->id();
            $table->string('codalmacen_icg', 20)->unique('legacy_almacen_equivalencias_codalmacen_uq');
            $table->unsignedBigInteger('warehouse_id')->nullable();
            $table->string('estado', 20)->default('propuesto');
            $table->text('nota')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('almacen_equivalencias'));
    }
};
