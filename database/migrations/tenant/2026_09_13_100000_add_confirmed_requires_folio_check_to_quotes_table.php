<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Support\Facades\DB;

/**
 * SCRUM-912 — folio obligatorio a nivel de BD, pero SOLO cuando la cotización ya
 * está confirmada ("formal") — un `folio NOT NULL` simple habría roto el ciclo de
 * vida entero (Cotización-A/D siempre nace/vive como Borrador sin folio, ver
 * migración 2026_07_13_100003_create_ventas_diseno_quotes_table.php).
 *
 * `confirmed_at` (no `status`, que quedó sin uso real desde REQ-083 — ver
 * Quote::documentStatus()) es el gate real de "formal" en este modelo:
 * QuoteService::confirm() ya exige folio antes de setear confirmed_at en código de
 * aplicación (lanza QuoteValidationBlockedException si falta) — este CHECK es
 * defensa en profundidad a nivel de BD para ese mismo invariante, no una regla
 * nueva de negocio. Mismo patrón (raw ALTER TABLE ... ADD CONSTRAINT, quotable con
 * $this->table()) que 2026_08_26_100000_add_tbd_to_ventas_diseno_quotes_delivery_type.php.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'ventas_diseno';

    public function up(): void
    {
        $table = $this->table('quotes');

        DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS quotes_confirmed_requires_folio_check");
        DB::statement(
            "ALTER TABLE {$table} ADD CONSTRAINT quotes_confirmed_requires_folio_check "
            . 'CHECK (confirmed_at IS NULL OR folio IS NOT NULL)'
        );
    }

    public function down(): void
    {
        $table = $this->table('quotes');

        DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS quotes_confirmed_requires_folio_check");
    }
};
