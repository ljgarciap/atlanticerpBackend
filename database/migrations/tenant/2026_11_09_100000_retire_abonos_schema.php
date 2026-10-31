<?php

declare(strict_types=1);

use App\Shared\Multitenancy\Tenant;
use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Support\Facades\DB;

/**
 * Fase 15a-2 (docs/specs/fase15a-un-solo-concepto-de-cobros.md) — retira el esquema del modelo anterior de cobros, ya sin código que lo lea o escriba.
 *
 * Borra: las tablas `abonos` y `abono_folio_counters`; las columnas `payment_invoices.abono_id`, `payment_invoices.monto_abono_aplicado`,
 * `payment_movements.abono_id` y `payments.cobros_v2`; las líneas de compensación y solicitudes de devolución cuyo origen era un abono; y la clave
 * `cobros_v2_activo` de `admin_contab_settings`.
 *
 * Seguridad de datos (IRREVERSIBLE: `down()` no restaura nada). Fuera de local/dev/testing aborta, sin borrar nada, si existe algún abono, algún cobro
 * del modelo anterior (`cobros_v2 = false`) o alguna línea de compensación o devolución que venga de un abono: ese entorno debe convertirse ANTES con
 * la versión anterior (backfill + verificador) o exportarse, y el respaldo previo es obligatorio.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'admin_contab';

    /** Entornos donde los datos de estas tablas son de demostración y se pueden descartar. */
    private const ENTORNOS_DESCARTABLES = ['local', 'dev', 'testing'];

    public function up(): void
    {
        $schema = "{$this->schemaName()}";

        if (! app()->environment(self::ENTORNOS_DESCARTABLES)) {
            $this->verificarSinDatosAnteriores($schema);
        }

        DB::transaction(function () use ($schema): void {
            if ($this->tieneTabla($schema, 'compensation_lines')) {
                DB::statement("DELETE FROM {$schema}.compensation_lines WHERE source_type = 'abono'");
            }
            if ($this->tieneTabla($schema, 'payment_reversal_requests')) {
                DB::statement("DELETE FROM {$schema}.payment_reversal_requests WHERE source_type = 'abono'");
            }

            DB::statement("DROP TABLE IF EXISTS {$schema}.abonos CASCADE");
            DB::statement("DROP TABLE IF EXISTS {$schema}.abono_folio_counters CASCADE");
            DB::statement("ALTER TABLE {$schema}.payment_invoices DROP COLUMN IF EXISTS abono_id, DROP COLUMN IF EXISTS monto_abono_aplicado");
            DB::statement("ALTER TABLE {$schema}.payment_movements DROP COLUMN IF EXISTS abono_id");
            DB::statement("ALTER TABLE {$schema}.payments DROP COLUMN IF EXISTS cobros_v2");
            DB::statement("DELETE FROM {$schema}.admin_contab_settings WHERE key = 'cobros_v2_activo'");
        });
    }

    public function down(): void
    {
        throw new \RuntimeException('Fase 15a-2 es irreversible: los abonos y el modelo anterior de cobros no se restauran. Restaura el respaldo previo.');
    }

    private function schemaName(): string
    {
        return (Tenant::current()?->slug ?? throw new \RuntimeException('No hay tenant activo.')).'_admin_contab';
    }

    private function tieneTabla(string $schema, string $tabla): bool
    {
        return DB::table('information_schema.tables')->where('table_schema', $schema)->where('table_name', $tabla)->exists();
    }

    private function tieneColumna(string $schema, string $tabla, string $columna): bool
    {
        return DB::table('information_schema.columns')->where('table_schema', $schema)->where('table_name', $tabla)->where('column_name', $columna)->exists();
    }

    private function verificarSinDatosAnteriores(string $schema): void
    {
        if ($this->tieneTabla($schema, 'abonos') && DB::table("{$schema}.abonos")->exists()) {
            throw new \RuntimeException('Hay abonos registrados: conviértelos con la versión anterior (backfill y verificador) o expórtalos, y haz un respaldo, antes de migrar este entorno.');
        }
        if ($this->tieneColumna($schema, 'payments', 'cobros_v2') && DB::table("{$schema}.payments")->where('cobros_v2', false)->exists()) {
            throw new \RuntimeException('Hay cobros del modelo anterior (cobros_v2 = false): conviértelos con la versión anterior o expórtalos, y haz un respaldo, antes de migrar este entorno.');
        }
        if ($this->tieneTabla($schema, 'compensation_lines') && DB::table("{$schema}.compensation_lines")->where('source_type', 'abono')->exists()) {
            throw new \RuntimeException('Hay compensaciones cuyo origen era un abono: exporta ese historial y haz un respaldo antes de migrar este entorno.');
        }
    }
};
