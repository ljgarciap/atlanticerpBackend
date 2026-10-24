<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-1114 — parámetros de `legacy:sync-incremental-stock`. Fila única (id=1), mismo patrón que
 * `compras.settings`: ningún umbral vive como constante en el código (regla global), el superadmin
 * los ajusta sin deploy.
 *
 * - `activo`: interruptor de las corridas programadas (nace en false en TODOS los entornos; una
 *   corrida manual supervisada con `--operador` corre igual). También sirve para el cutover.
 * - `baseline_inicializada_at`: la pone `legacy:init-stock-baseline --apply`. Sin ella el comando se
 *   niega a correr: con la base sin inicializar, el delta sería el stock completo de ICG otra vez.
 * - Umbrales del circuit breaker (valores iniciales PROVISORIOS, se afinan con el dry-run real):
 *   % de productos elegibles con delta, unidades por producto y unidades absolutas totales.
 * - `min_filas_icg_pct`: guard contra una respuesta PARCIAL de ICG (no vacía): si las filas de AL4 leídas son
 *   menos que este % de las de la última corrida buena, la corrida queda retenida en vez de tomar lo que falta
 *   como stock 0 (revisión del Senior Reviewer, S1).
 * - `horas_aviso_apagado`: horas que el sync puede estar apagado en producción, tras su primera corrida, antes
 *   de que el health-check avise (nada hardcodeado).
 * - `correos_alerta`: destinatarios adicionales (además de los superadmins) de los avisos del sync.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'legacy';

    public function up(): void
    {
        Schema::create($this->table('stock_sync_settings'), function (Blueprint $table): void {
            $table->id();
            $table->boolean('activo')->default(false);
            $table->timestampTz('baseline_inicializada_at')->nullable();
            $table->decimal('max_productos_con_delta_pct', 5, 2)->default(30);
            $table->unsignedInteger('max_unidades_abs_por_producto')->default(2000);
            $table->unsignedInteger('max_unidades_abs_total')->default(30000);
            $table->decimal('min_filas_icg_pct', 5, 2)->default(80);
            $table->unsignedInteger('horas_aviso_apagado')->default(48);
            $table->jsonb('correos_alerta')->default(DB::raw("'[]'::jsonb"));
            $table->timestamps();
        });

        DB::table($this->table('stock_sync_settings'))->insert([
            'id' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('stock_sync_settings'));
    }
};
