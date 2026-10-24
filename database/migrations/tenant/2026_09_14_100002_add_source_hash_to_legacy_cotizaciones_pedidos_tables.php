<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-838 — `source_hash` en las cabeceras de Cotizaciones/Pedidos, usado por los comandos
 * incrementales nuevos (legacy:sync-incremental-cotizaciones/pedidos). A diferencia de Productos
 * (que hashea cada tabla por separado), acá el hash de la cabecera INCLUYE un fold de sus items
 * (XOR de un hash por línea, orden-independiente — ver
 * App\Modules\Legacy\Services\CotizacionIncrementalStagingLoader::itemContentHash()) — no hace
 * falta una columna de hash separada en cotizacion_items/pedido_items: un cambio en cualquier
 * línea ya cambia el source_hash de su cabecera.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'legacy';

    public function up(): void
    {
        Schema::table($this->table('cotizaciones'), function (Blueprint $table): void {
            $table->string('source_hash', 64)->nullable();
        });

        Schema::table($this->table('pedidos'), function (Blueprint $table): void {
            $table->string('source_hash', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table($this->table('cotizaciones'), function (Blueprint $table): void {
            $table->dropColumn('source_hash');
        });

        Schema::table($this->table('pedidos'), function (Blueprint $table): void {
            $table->dropColumn('source_hash');
        });
    }
};
