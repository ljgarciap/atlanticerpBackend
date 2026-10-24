<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Support\Facades\DB;

/**
 * SCRUM-1120 — el cliente no debe ver la marca «CotizaYA»: el folio de la serie propia pasa de `CotizaYA-AAAA-NNNN` a
 * `COTY-AAAA-NNNN` (decisión de Luis/David). Renombra los folios ya generados; el PDF se arma al vuelo desde `quotes.folio`,
 * así que los PDF que se descarguen desde ahora salen con el folio nuevo (los ya descargados o enviados no cambian).
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'ventas_diseno';

    public function up(): void
    {
        $quotes = $this->table('quotes');
        DB::statement("UPDATE {$quotes} SET folio = 'COTY-' || substr(folio, 10) WHERE folio LIKE 'CotizaYA-%'");

        // El Cliente Master y el Subcliente fijos también salen en documentos (p. ej. factura a consumidor final): pasan a
        // «Cotización». Se ubican por id en `cotizaya_settings` y solo si siguen llamándose «CotizaYA» (no pisa un nombre ajustado).
        $settings = $this->table('cotizaya_settings');
        $masters  = $this->table('master_clients');
        $subs     = $this->table('sub_clients');
        DB::statement("UPDATE {$masters} SET name = 'Cotización' WHERE lower(name) = 'cotizaya' AND id IN (SELECT master_client_id FROM {$settings})");
        DB::statement("UPDATE {$subs} SET business_name = 'Cotización' WHERE lower(business_name) = 'cotizaya' AND id IN (SELECT sub_client_id FROM {$settings})");
    }

    public function down(): void
    {
        $quotes = $this->table('quotes');
        DB::statement("UPDATE {$quotes} SET folio = 'CotizaYA-' || substr(folio, 6) WHERE folio LIKE 'COTY-%'");

        $settings = $this->table('cotizaya_settings');
        DB::statement("UPDATE {$this->table('master_clients')} SET name = 'CotizaYA' WHERE name = 'Cotización' AND id IN (SELECT master_client_id FROM {$settings})");
        DB::statement("UPDATE {$this->table('sub_clients')} SET business_name = 'CotizaYA' WHERE business_name = 'Cotización' AND id IN (SELECT sub_client_id FROM {$settings})");
    }
};
