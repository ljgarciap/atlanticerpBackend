<?php

declare(strict_types=1);

use App\Shared\Multitenancy\Tenant;
use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-755 (épica SCRUM-749 — Migración ICG→AtlanticERP). Staging de CLIENTES
 * (ver Illumination/migracion-icg-diseno.md §2/§4/§5) — `legacy.clientes` es el equivalente staged
 * de CLIENTES, con trazabilidad completa vía `icg_codcliente` (llave natural de ICG, UNIQUE —
 * idempotencia del Loader-A) y `sub_client_id`, seteado por `App\Modules\Legacy\Services\
 * ClienteMatcher` solo cuando resuelve un match único por CIF (fallback por email exacto).
 *
 * A diferencia de `legacy.productos` (SCRUM-750), esta entidad NO tiene Loader-B — nunca escribe
 * en una tabla viva de AtlanticERP, solo LEE `ventas_diseno.sub_clients`/`sub_client_contacts` para
 * resolver el link. El resultado del matching se persiste en las columnas `sub_client_id`/
 * `match_status` de esta misma tabla (ver runbook §12.1: "el resto de las entidades NO tiene
 * Loader-B — quedan exclusivamente en legacy.*").
 *
 * `match_status` arranca en `unmatched` por default — todo cliente recién staged por el Loader-A
 * queda así hasta que `ClienteMatcher` corra sobre él. `sub_client_id` NUNCA se incluye en el
 * `$update` del upsert de staging (`ClienteStagingLoader`) — mismo criterio que
 * `catalog_product_id` en `legacy.productos`: una segunda corrida de staging jamás resetea un link
 * ya resuelto por el Matcher.
 *
 * `bloqueado` — GOTCHA NDBOOLEANF (Illumination/migracion-icg-diseno.md §13.2): el tipo real de
 * `CLIENTES.BLOQUEADO` en SQL Server NO se verificó contra ICG real todavía (fuera de alcance de
 * este batch, ver docblock de `App\Modules\Legacy\Extractors\ClienteExtractor`) — se asume texto
 * NDBOOLEANF `'V'/'F'`, mismo patrón que `ARTICULOS.DESCATALOGADO` (otra tabla maestra "core" de
 * ICG), a confirmar en la corrida real (equivalente a la Tarea 6 de SCRUM-750).
 *
 * Cross-schema FK hacia `ventas_diseno.sub_clients` — mismo patrón ya usado por
 * `legacy_productos_catalog_product_fk`/`servicios_ticket_products`. Constraint nombrado
 * explícitamente — el nombre autogenerado con schema calificado trunca en silencio a 63 bytes.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'legacy';

    public function up(): void
    {
        $ventasDisenoSchema = Tenant::current()->slug.'_ventas_diseno';

        Schema::create($this->table('clientes'), function (Blueprint $table) use ($ventasDisenoSchema): void {
            $table->id();
            $table->unsignedInteger('icg_codcliente')->unique('legacy_clientes_codcliente_uq');

            $table->string('nombre_comercial')->nullable();
            $table->string('razon_social');
            $table->string('cif')->nullable();
            $table->string('direccion')->nullable();
            $table->string('poblacion')->nullable();
            $table->string('provincia')->nullable();
            $table->string('pais')->nullable();
            $table->string('telefono1')->nullable();
            $table->string('telefono2')->nullable();
            $table->string('mobil')->nullable();
            $table->string('email')->nullable();
            $table->unsignedInteger('icg_codvendedor')->nullable();

            $table->foreignId('sub_client_id')->nullable()
                ->constrained("{$ventasDisenoSchema}.sub_clients", 'id', 'legacy_clientes_sub_client_fk')
                ->nullOnDelete();
            $table->string('match_status', 20)->default('unmatched'); // matched | unmatched | ambiguous

            $table->boolean('bloqueado')->default(false);
            $table->timestampTz('fecha_modificado_icg')->nullable();

            $table->timestamps();

            $table->index('match_status');
            $table->index('cif');
            $table->index('email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('clientes'));
    }
};
