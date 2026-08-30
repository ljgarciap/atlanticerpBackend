<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-244 (REQ-181) — 5 bodegas fijas del negocio, catálogo cerrado (no hay ningún criterio de
 * aceptación que pida crear/borrar bodegas desde la UI). Sembradas acá mismo, no vía seeder
 * separado — mismo criterio que `LiquidationAgency` no sigue, porque ahí sí hay alta desde UI
 * (REQ-147); acá es una lista fija del negocio, como `PurchaseOrder::MODALITY_*`.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'bodega';

    private const NAMES = ['Illuminations', 'Mermas', 'Préstamo Zona Libre', 'Reserva', 'Llano Bonito'];

    public function up(): void
    {
        Schema::create($this->table('warehouses'), function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });

        $now = now();
        DB::table($this->table('warehouses'))->insert(
            array_map(fn (string $name): array => [
                'name' => $name, 'created_at' => $now, 'updated_at' => $now,
            ], self::NAMES)
        );
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('warehouses'));
    }
};
