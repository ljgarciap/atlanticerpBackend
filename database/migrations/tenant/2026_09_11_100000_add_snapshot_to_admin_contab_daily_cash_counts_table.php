<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pre-QA fusionado SCRUM-878/879 (2026-09-10, docs/pre-qa/scrum878-879-rebote-20260910.md) —
 * hallazgo CRÍTICO en vivo: `DailyCashCountService::forDate()` recalculaba el total de CUALQUIER
 * arqueo (incluidos `cerrado`/`pendiente_aprobacion`/`aprobado`) en cada consulta, sin snapshot.
 * `commissions:backfill-cash-movements` (SCRUM-879, ya corrido en dev) creó un `bank_movement` de
 * comisión fechado 2026-08-25 — el arqueo de esa fecha ya estaba cerrado desde el 29/08, así que su
 * `total_neto` cambió en silencio para Mark, sin que Felix (quien lo cerró) lo haya visto ni exista
 * rastro en `audit_logs`. Decisión de Luis (2026-09-10): congelar el total en el momento de
 * `close()` — cualquier `estado != abierto` lee de acá en vez de recalcular. Protege contra
 * cualquier fuente futura de drift retroactivo (backfills, correcciones de notas de crédito, etc.),
 * no solo el caso puntual de comisiones.
 *
 * `snapshot` guarda el mismo shape que devuelve `DailyCashCountService::forDate()` (cobros,
 * notas_credito, comisiones, total_cobrado, total_notas_credito, total_comisiones, total_neto) tal
 * como existía en el instante de `close()` — nunca se recalcula después. `retenciones` y
 * `observacion_general` quedan FUERA del snapshot a propósito: la constancia de retención se puede
 * subir/reemplazar incluso sobre un arqueo ya cerrado (RN4, Batch 19), así que esas dos siguen
 * leyéndose en vivo.
 *
 * Nullable — los `daily_cash_counts` ya cerrados antes de este deploy no tienen snapshot todavía;
 * `admin-contab:backfill-daily-cash-count-snapshots` los completa reconstruyendo el total tal como
 * existía en `cerrado_at` (filtrando por `created_at` de cada movimiento/pago, no por `fecha` del
 * arqueo), no con el valor ya contaminado de hoy.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'admin_contab';

    public function up(): void
    {
        Schema::table($this->table('daily_cash_counts'), function (Blueprint $table): void {
            $table->jsonb('snapshot')->nullable()->after('aprobado_at');
        });
    }

    public function down(): void
    {
        Schema::table($this->table('daily_cash_counts'), function (Blueprint $table): void {
            $table->dropColumn('snapshot');
        });
    }
};
