<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-1125 — el catálogo de Zona Libre muestra `precio_6` de `vista_productos_lighting` (antes `precio_5`).
 * Columna nueva, nullable: queda NULL hasta la próxima corrida del sincronizador (`lafayette:sync-productos`),
 * que la llena. `precio_5` se conserva para que el cambio sea reversible.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'bodega';

    public function up(): void
    {
        Schema::table($this->table('lafayette_productos'), function (Blueprint $table): void {
            $table->decimal('precio_6', 15, 4)->nullable()->after('precio_5');
        });
    }

    public function down(): void
    {
        Schema::table($this->table('lafayette_productos'), function (Blueprint $table): void {
            $table->dropColumn('precio_6');
        });
    }
};
