<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Support\Facades\DB;

/**
 * Pedidos gemelos de ICG (diseño `docs/architecture/icg-pedidos-gemelos-diseno.md`, Opción A): la cotización gemela de
 * cada pedido lleva `origin = 'icg_legacy'`. Solo se amplía el CHECK de `origin` (comercial | cotizaya | icg_legacy);
 * ninguna fila existente cambia.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'ventas_diseno';

    public function up(): void
    {
        $quotes = $this->table('quotes');
        DB::statement("ALTER TABLE {$quotes} DROP CONSTRAINT IF EXISTS quotes_origin_check");
        DB::statement("ALTER TABLE {$quotes} ADD CONSTRAINT quotes_origin_check CHECK (origin IN ('comercial', 'cotizaya', 'icg_legacy'))");
    }

    public function down(): void
    {
        $quotes = $this->table('quotes');
        // Si ya existen cotizaciones gemelas, volver al CHECK viejo falla a propósito: nunca se borran datos al revertir.
        DB::statement("ALTER TABLE {$quotes} DROP CONSTRAINT IF EXISTS quotes_origin_check");
        DB::statement("ALTER TABLE {$quotes} ADD CONSTRAINT quotes_origin_check CHECK (origin IN ('comercial', 'cotizaya'))");
    }
};
