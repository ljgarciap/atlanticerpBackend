<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Support\Facades\DB;

/**
 * Fase 4 — Servicios (docs/architecture/servicios-fase4-diseno.md, sección 1). El módulo
 * `App\Modules\Installations` nunca tuvo código real (solo un `.gitkeep`) y sus 4 migraciones del
 * 2026-06-16 (`installations_cases`/`case_assignees`/`case_comments`/`case_photos`) nunca tuvieron
 * datos reales — pero SÍ corrieron en cualquier entorno donde `tenant:create`/`tenants:artisan
 * migrate` ya se ejecutó (dev/test/prod), dejando el schema `{tenant}_installations` con 4 tablas
 * vacías. Borrar esas 4 migraciones del código (ver rename en este mismo commit) no limpia un
 * entorno ya desplegado — esta migración lo hace explícitamente, CASCADE por si alguna FK quedó
 * apuntando ahí (no debería, pero es una tabla vacía sin uso real, sin costo borrarla igual).
 *
 * `Tenant::schemas()` ya no lista `{tenant}_installations` (reemplazado por `{tenant}_servicios`,
 * ver la migración hermana `2026_08_02_100001_create_servicios_tickets_table.php`) — sin este
 * DROP, el schema viejo quedaría huérfano para siempre en cualquier entorno ya creado.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'installations';

    public function up(): void
    {
        DB::statement('DROP SCHEMA IF EXISTS "'.$this->schema().'" CASCADE');
    }

    public function down(): void
    {
        // Irreversible a propósito — recrear el schema/tablas viejas no tiene ningún valor real,
        // eran datos vacíos de un módulo que nunca se usó. Ver docblock de la clase.
    }
};
