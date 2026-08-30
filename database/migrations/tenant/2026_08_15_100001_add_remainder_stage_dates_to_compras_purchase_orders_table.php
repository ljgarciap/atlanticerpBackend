<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-208 (rediseño 2026-08-15, docs/architecture/scrum208-recepcion-parcial-rediseno.md) —
 * mismo espíritu que `2026_08_05_100001_add_stage_dates...` pero para el REMANENTE
 * (`pending_remainder_status`, REQ-165), que hasta ahora no tenía ningún endpoint que lo avanzara
 * ni ningún registro histórico de cuándo alcanzó cada etapa.
 *
 * `remainder_status_changed_at` mirror de `status_changed_at`. Las 4 columnas de etapa mirror de
 * `PurchaseOrder::STAGE_DATE_COLUMNS`, pero para `PurchaseOrder::REMAINDER_STATUSES` — sin una
 * columna para `REMAINDER_ORDENADO`: el remanente nace directamente en esa etapa al registrarse
 * la recepción parcial (no hay un momento "alcanzó Ordenado" que registrar, ya nace ahí, igual
 * que `ordenado_at` de la orden tampoco se setea retroactivo al crear la orden).
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'compras';

    public function up(): void
    {
        Schema::table($this->table('purchase_orders'), function (Blueprint $table): void {
            $table->timestampTz('remainder_status_changed_at')->nullable()->after('en_transito_local_at');
            $table->timestampTz('remainder_salio_de_origen_at')->nullable()->after('remainder_status_changed_at');
            $table->timestampTz('remainder_en_transito_at')->nullable()->after('remainder_salio_de_origen_at');
            $table->timestampTz('remainder_en_aduana_at')->nullable()->after('remainder_en_transito_at');
            $table->timestampTz('remainder_en_transito_local_at')->nullable()->after('remainder_en_aduana_at');
        });
    }

    public function down(): void
    {
        Schema::table($this->table('purchase_orders'), function (Blueprint $table): void {
            $table->dropColumn([
                'remainder_status_changed_at', 'remainder_salio_de_origen_at', 'remainder_en_transito_at',
                'remainder_en_aduana_at', 'remainder_en_transito_local_at',
            ]);
        });
    }
};
