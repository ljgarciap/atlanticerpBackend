<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 1 del aislamiento del núcleo (docs/specs/fase1-limpieza-nucleo.md, bloque A) — CotizaYA sale del producto.
 *
 * Retira: las tablas `cotizaya_settings` y `cotizaya_folio_counters`, las columnas `cotizaya_customer_name/phone` de
 * `quotes`, el valor `cotizaya` del CHECK de `quotes.origin` (la columna `origin` se queda: Legacy usa `icg_legacy`),
 * y el Cliente Master / Subcliente fijos «CotizaYA» que sembraba `CotizaYaClientSeeder`.
 *
 * Seguridad de datos: NO borra cotizaciones. Si existe alguna con `origin = 'cotizaya'`, o si el Cliente Master/Subcliente
 * «CotizaYA» está referenciado por una cotización o un proyecto, la migración aborta con un mensaje claro: esos datos
 * hay que exportarlos o resolverlos a mano antes. IRREVERSIBLE en datos: `down()` recrea las estructuras vacías.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'ventas_diseno';

    public function up(): void
    {
        $quotes = $this->table('quotes');

        $conOrigen = (int) DB::table($quotes)->where('origin', 'cotizaya')->count();
        if ($conOrigen > 0) {
            throw new RuntimeException("Hay {$conOrigen} cotización(es) con origin = 'cotizaya': exportarlas o resolverlas antes de retirar CotizaYA (esta migración no borra cotizaciones).");
        }

        if (Schema::hasTable($this->table('cotizaya_settings'))) {
            foreach (DB::table($this->table('cotizaya_settings'))->get(['master_client_id', 'sub_client_id']) as $row) {
                $referenciado = DB::table($quotes)->where('master_client_id', $row->master_client_id)->orWhere('sub_client_id', $row->sub_client_id)->exists()
                    || DB::table($this->table('sales_projects'))->where('sub_client_id', $row->sub_client_id)->exists();
                if ($referenciado) {
                    throw new RuntimeException('El Cliente Master/Subcliente «CotizaYA» está referenciado por cotizaciones o proyectos: resolverlo antes de retirar CotizaYA.');
                }
                DB::table($this->table('sub_clients'))->where('id', $row->sub_client_id)->delete();
                DB::table($this->table('master_clients'))->where('id', $row->master_client_id)->delete();
            }
        }

        DB::statement("ALTER TABLE {$quotes} DROP CONSTRAINT IF EXISTS quotes_origin_check");
        DB::statement("ALTER TABLE {$quotes} ADD CONSTRAINT quotes_origin_check CHECK (origin IN ('comercial', 'icg_legacy'))");

        Schema::table($this->table('quotes'), function (Blueprint $table): void {
            $table->dropColumn(['cotizaya_customer_name', 'cotizaya_customer_phone']);
        });

        Schema::dropIfExists($this->table('cotizaya_folio_counters'));
        Schema::dropIfExists($this->table('cotizaya_settings'));
    }

    public function down(): void
    {
        Schema::create($this->table('cotizaya_settings'), function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('master_client_id');
            $table->unsignedBigInteger('sub_client_id');
            $table->timestamps();
        });
        Schema::create($this->table('cotizaya_folio_counters'), function (Blueprint $table): void {
            $table->smallInteger('year')->primary();
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();
        });

        Schema::table($this->table('quotes'), function (Blueprint $table): void {
            $table->string('cotizaya_customer_name', 255)->nullable();
            $table->string('cotizaya_customer_phone', 50)->nullable();
        });

        $quotes = $this->table('quotes');
        DB::statement("ALTER TABLE {$quotes} DROP CONSTRAINT IF EXISTS quotes_origin_check");
        DB::statement("ALTER TABLE {$quotes} ADD CONSTRAINT quotes_origin_check CHECK (origin IN ('comercial', 'cotizaya', 'icg_legacy'))");
    }
};
