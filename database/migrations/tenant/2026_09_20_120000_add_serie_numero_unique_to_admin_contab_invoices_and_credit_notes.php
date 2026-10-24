<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Support\Facades\DB;

/**
 * Threat model de Facturación Electrónica MSL (docs/security/THREAT-MODEL-facturacion-electronica-msl.md,
 * hallazgo #2, 2026-09-20). GUIA_ILUMINATION.md §5 exige que `serie`+`numero` sea único por
 * documento ante MSL — AtlanticERP no reforzaba esa unicidad a nivel de BD para el par específico que se
 * envía al PAC.
 *
 * `invoices.numero`/`credit_notes.numero` YA tienen `unique()` global desde su creación (Batch 2 /
 * Batch 10) — eso ya hace que (serie, numero) sea unico de hecho hoy, sin importar el valor de
 * `serie`. Este índice compuesto es una capa explícita adicional, no una corrección de un gap
 * activo: si algún día se relaja el `unique(numero)` general (mismo tipo de cambio que
 * `2026_08_24_100002_relax_order_id_unique_on_admin_contab_invoices_table.php` ya hizo para
 * `order_id`), esta constraint sigue protegiendo específicamente el par que Facturación
 * Electrónica le manda al PAC, sin depender de que quien haga ese cambio futuro recuerde esta
 * dependencia.
 *
 * Índice parcial (`WHERE serie IS NOT NULL`) — `serie` es nullable y solo se puebla para
 * documentos certificados vía FE; no tiene sentido indexar filas que nunca participan de esa
 * unicidad. Mismo patrón que el índice parcial de `order_id` ya usado en el módulo.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'admin_contab';

    public function up(): void
    {
        DB::statement(sprintf(
            'CREATE UNIQUE INDEX invoices_fe_serie_numero_unique ON %s (serie, numero) WHERE serie IS NOT NULL',
            $this->table('invoices'),
        ));

        DB::statement(sprintf(
            'CREATE UNIQUE INDEX credit_notes_fe_serie_numero_unique ON %s (serie, numero) WHERE serie IS NOT NULL',
            $this->table('credit_notes'),
        ));
    }

    public function down(): void
    {
        DB::statement(sprintf('DROP INDEX IF EXISTS %s.invoices_fe_serie_numero_unique', $this->schema()));
        DB::statement(sprintf('DROP INDEX IF EXISTS %s.credit_notes_fe_serie_numero_unique', $this->schema()));
    }
};
