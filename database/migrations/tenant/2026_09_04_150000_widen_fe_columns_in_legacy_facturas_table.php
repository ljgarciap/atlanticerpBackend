<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Support\Facades\DB;

/**
 * SCRUM-841 — fix de datos reales, encontrado en la corrida --dry-run contra ICG real en dev
 * (2026-09-04): los `varchar` declarados en la migración original
 * (2026_09_04_100002_add_facturacion_electronica_to_legacy_facturas_table.php) eran más angostos
 * que las columnas reales de FACTURASVENTACAMPOSLIBRES en ICG — `fe_qr` (`varchar(255)` default de
 * `$table->string()`) reventó con un valor real de 471 caracteres (columna ICG: `nvarchar(2000)`).
 * El resto de los `fe_*` que se habían declarado `varchar(30)` también quedan por debajo de lo que
 * ICG permite (`nvarchar(600)`), aunque el dato real observado hoy sea más corto — mismo criterio
 * conservador que `fe_error` (ya `text()` desde el diseño original): ensanchar a `text()` en vez de
 * adivinar un límite que puede volver a quedar corto cuando ICG acumule más historial.
 *
 * No se edita la migración original (ya corrida en dev.atlanticerp.ai) — ver regla del proyecto de nunca
 * modificar una migración ya aplicada, siempre un ALTER de seguimiento.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'legacy';

    /** @var list<string> */
    private const COLUMNS = [
        'fe_qr', 'fe_auth_number', 'fe_serie', 'fe_numero',
        'fe_fecha_emision', 'fe_fecha_recepcion', 'fe_estado',
    ];

    public function up(): void
    {
        // doctrine/dbal no está instalado en este proyecto (confirmado — ningún ->change() existe
        // en el resto de las migraciones), así que Schema::table()->change() no es una opción acá
        // — ALTER TABLE crudo, mismo tipo final (text) que fe_error ya usa desde el diseño original.
        $table = $this->table('facturas');
        foreach (self::COLUMNS as $column) {
            DB::statement("ALTER TABLE {$table} ALTER COLUMN {$column} TYPE text");
        }
    }

    public function down(): void
    {
        $table = $this->table('facturas');
        DB::statement("ALTER TABLE {$table} ALTER COLUMN fe_qr TYPE varchar(255)");
        DB::statement("ALTER TABLE {$table} ALTER COLUMN fe_auth_number TYPE varchar(255)");
        DB::statement("ALTER TABLE {$table} ALTER COLUMN fe_serie TYPE varchar(30)");
        DB::statement("ALTER TABLE {$table} ALTER COLUMN fe_numero TYPE varchar(30)");
        DB::statement("ALTER TABLE {$table} ALTER COLUMN fe_fecha_emision TYPE varchar(30)");
        DB::statement("ALTER TABLE {$table} ALTER COLUMN fe_fecha_recepcion TYPE varchar(30)");
        DB::statement("ALTER TABLE {$table} ALTER COLUMN fe_estado TYPE varchar(30)");
    }
};
