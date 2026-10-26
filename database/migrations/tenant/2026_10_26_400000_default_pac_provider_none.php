<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Support\Facades\DB;

/**
 * Fase 6b — el proveedor fiscal de un tenant NUEVO pasa a ser `none` (sin facturación electrónica) hasta que Gerencia u operaciones elija uno
 * (`admin-contab:set-pac-provider`). El default histórico era `digifact`, de cuando aún no había ninguna integración; con `digifact` ya
 * cableado, un tenant recién creado habría intentado certificar contra un PAC sin credenciales. La fila de configuración fiscal ya la
 * inserta una migración anterior con el default viejo, así que además de cambiar el default de la columna se pasan a `none` las filas que
 * quedaron en `digifact`: ese proveedor nunca estuvo operativo (la fábrica lo rechazaba hasta la Fase 6b), de modo que nadie lo eligió a
 * propósito. Los tenants con `msl` (los que sí configuraron su proveedor) no se tocan. `down()` solo restaura el default de la columna.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'admin_contab';

    public function up(): void
    {
        DB::statement("ALTER TABLE {$this->table('fiscal_settings')} ALTER COLUMN pac_provider SET DEFAULT 'none'");
        DB::table($this->table('fiscal_settings'))->where('pac_provider', 'digifact')->update(['pac_provider' => 'none']);
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE {$this->table('fiscal_settings')} ALTER COLUMN pac_provider SET DEFAULT 'digifact'");
    }
};
