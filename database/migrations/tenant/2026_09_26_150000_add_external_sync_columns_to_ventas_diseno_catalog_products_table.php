<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-970 (épica SCRUM-969 — integración Lafayette Lighting, Bodega Zona Libre). Trazabilidad de
 * origen para productos poblados desde una fuente externa (hoy solo Lafayette, `external_source`
 * queda genérico por si se agrega otro proveedor con el mismo mecanismo más adelante).
 *
 * `external_synced_at` mapea `cache_updated_at` de la fuente externa — NO es
 * `updated_at`/`created_at` de AtlanticERP, es el timestamp de la fuente, para poder distinguir "cuándo
 * cambió el dato en Lafayette" de "cuándo corrió nuestro job de sync".
 *
 * Ambas nullable — productos que no vienen de una fuente externa (la inmensa mayoría hoy) nunca
 * las tienen pobladas, no se backfillea con datos falsos.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'ventas_diseno';

    public function up(): void
    {
        Schema::table($this->table('catalog_products'), function (Blueprint $blueprint): void {
            $blueprint->string('external_source')->nullable()->after('provider_id');
            $blueprint->timestamp('external_synced_at')->nullable()->after('external_source');
        });
    }

    public function down(): void
    {
        Schema::table($this->table('catalog_products'), function (Blueprint $blueprint): void {
            $blueprint->dropColumn(['external_source', 'external_synced_at']);
        });
    }
};
