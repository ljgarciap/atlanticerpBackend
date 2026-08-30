<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Support\Facades\DB;

/**
 * Hallazgo MAYOR de Visual Review/Pre-QA + Senior Review (2026-08-19) sobre el batch Configuración
 * Fiscal (SCRUM-635/REQ-558): RN3 del ticket es textual — "tanto el porcentaje como la descripción
 * de uso son obligatorios" al agregar una tasa personalizada, `nombre` no es parte del ticket ni
 * del mockup adjunto (solo lo necesitan las 3 tasas base sembradas por la migración anterior,
 * ej. "7% — General"). Sin doctrine/dbal en el proyecto, se altera la constraint directo con SQL
 * en vez de Blueprint::change() (mismo criterio que
 * 2026_08_19_100001_make_service_quotes_numero_nullable.php).
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'admin_contab';

    public function up(): void
    {
        $table = $this->table('itbms_rates');
        DB::statement("ALTER TABLE {$table} ALTER COLUMN nombre DROP NOT NULL");
    }

    public function down(): void
    {
        $table = $this->table('itbms_rates');
        DB::statement("ALTER TABLE {$table} ALTER COLUMN nombre SET NOT NULL");
    }
};
