<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-1120 — CotizaYA (cotizaciones de venta chica) comparte la tabla `quotes` y todo el motor de partidas con
 * las cotizaciones comerciales, pero vive aparte: `origin` distingue ambas y un global scope de Eloquent
 * (`CotizaYaOriginScope`) excluye `cotizaya` de TODA consulta salvo las del módulo CotizaYA.
 *
 * Las filas existentes quedan como `comercial` (default) — no se modifica ningún dato. `cotizaya_customer_name` y
 * `cotizaya_customer_phone` son texto libre del formulario de CotizaYA (el Cliente Master es siempre el fijo).
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'ventas_diseno';

    public function up(): void
    {
        Schema::table($this->table('quotes'), function (Blueprint $table): void {
            $table->string('origin', 20)->default('comercial')->after('id');
            $table->string('cotizaya_customer_name', 255)->nullable();
            $table->string('cotizaya_customer_phone', 50)->nullable();
            $table->index('origin');
        });

        $quotes = $this->table('quotes');
        DB::statement("ALTER TABLE {$quotes} DROP CONSTRAINT IF EXISTS quotes_origin_check");
        DB::statement("ALTER TABLE {$quotes} ADD CONSTRAINT quotes_origin_check CHECK (origin IN ('comercial', 'cotizaya'))");
    }

    public function down(): void
    {
        $quotes = $this->table('quotes');
        DB::statement("ALTER TABLE {$quotes} DROP CONSTRAINT IF EXISTS quotes_origin_check");

        Schema::table($this->table('quotes'), function (Blueprint $table): void {
            $table->dropIndex(['origin']);
            $table->dropColumn(['origin', 'cotizaya_customer_name', 'cotizaya_customer_phone']);
        });
    }
};
