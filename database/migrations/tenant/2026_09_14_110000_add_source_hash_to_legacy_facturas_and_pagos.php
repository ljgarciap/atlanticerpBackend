<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-838 (épica SCRUM-749 — Migración ICG→AtlanticERP). `source_hash` (SHA-256 hex, 64 chars) para
 * los comandos incrementales nuevos de Facturas y Pagos — mismo patrón que
 * `2026_09_14_100000_add_source_hash_to_legacy_producto_tables.php`. Nullable — solo lo pueblan
 * los comandos incrementales, nunca los full-sync existentes (legacy:sync-facturas/pagos).
 *
 * Para Facturas, el hash incluye los campos fe_* (SCRUM-841) — una factura puede autorizarse ante
 * la DGI días/semanas después de emitida, sin que cambie ningún otro campo de la cabecera; el hash
 * tiene que reflejar ESE cambio para que el comando incremental no lo ignore (ver docblock de
 * FacturaIncrementalStagingLoader).
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'legacy';

    public function up(): void
    {
        Schema::table($this->table('facturas'), function (Blueprint $table): void {
            $table->string('source_hash', 64)->nullable()->after('fe_estado');
        });

        Schema::table($this->table('pagos'), function (Blueprint $table): void {
            $table->string('source_hash', 64)->nullable()->after('fecha_saldado');
        });
    }

    public function down(): void
    {
        Schema::table($this->table('facturas'), function (Blueprint $table): void {
            $table->dropColumn('source_hash');
        });

        Schema::table($this->table('pagos'), function (Blueprint $table): void {
            $table->dropColumn('source_hash');
        });
    }
};
