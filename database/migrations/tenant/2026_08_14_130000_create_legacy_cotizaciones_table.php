<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-757 (épica SCRUM-749 — Migración ICG→AtlanticERP). Staging de PRESUPUESTOSCAB
 * (Illumination/migracion-icg-diseno.md §2/§4/§5) — `legacy.cotizaciones` es el equivalente
 * staged de PRESUPUESTOSCAB, con trazabilidad completa vía la llave natural compuesta de ICG
 * (`icg_numserie`+`icg_numpresupuesto`+`icg_n`+`icg_version`, UNIQUE — idempotencia del Loader-A,
 * `App\Modules\Legacy\Services\CotizacionStagingLoader`).
 *
 * A diferencia de `legacy.productos` (que no tiene Matcher), esta entidad hereda el
 * `legacy_cliente_id` de `legacy.clientes` por lookup simple de `icg_codcliente`
 * (`App\Modules\Legacy\Services\CotizacionMatcher`, ver runbook §12.1: "Cotizaciones/Pedidos/
 * Albaranes/Facturas por icg_codcliente → legacy_cliente_id") — SIN re-matchear por CIF/email,
 * ese trabajo ya lo hizo `ClienteMatcher` sobre `legacy.clientes` (SCRUM-755). Si `icg_codcliente`
 * no resuelve a ningún `legacy.clientes.icg_codcliente` conocido, `orphan = true` y
 * `legacy_cliente_id` queda `null` — la cotización se importa igual, nunca se descarta (§5:
 * "nunca se inventa un link... el documento se importa igual con orphan=true").
 *
 * `legacy_cliente_id`/`orphan` NUNCA se incluyen en el `$update` del upsert de staging (mismo
 * criterio que `sub_client_id`/`match_status` en `legacy.clientes`) — una segunda corrida de
 * staging jamás resetea un link ya resuelto por `CotizacionMatcher`; el Matcher es el único que
 * los toca, corriendo siempre después del staging dentro del mismo comando.
 *
 * `icg_codcliente` — GOTCHA §2: FK "por convención" (⚠️→CLIENTES), sin constraint real declarado
 * en ICG — se guarda crudo y nullable, tolerante a que ICG no garantice que siempre esté poblado.
 *
 * `idestado`/`supresupuesto` — GOTCHA de tipo SIN VERIFICAR contra ICG real (mismo patrón que
 * `CLIENTES.BLOQUEADO`, ver docblock de `legacy_clientes`): el ticket no especifica el tipo real de
 * estas 2 columnas en SQL Server (podrían ser código numérico, texto NDBOOLEANF, o un id de
 * agrupación de versiones). Se guardan como texto crudo sin interpretar — ninguna lógica de negocio
 * depende de su valor todavía, así que el riesgo de guardar el tipo equivocado es bajo, pero
 * confirmar contra `INFORMATION_SCHEMA.COLUMNS` antes de la primera corrida real (equivalente a la
 * Tarea 6 de SCRUM-750/755).
 *
 * Sin `updated_at` — a diferencia de `legacy.clientes`/`legacy.productos`, el alcance de este
 * batch (SCRUM-757) no pide rastrear cuándo se actualizó una cabecera ya stageada, solo cuándo se
 * creó. `created_at` se setea una sola vez al insertar (nunca en el `$update` del upsert).
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'legacy';

    public function up(): void
    {
        Schema::create($this->table('cotizaciones'), function (Blueprint $table): void {
            $table->id();

            $table->string('icg_numserie', 10);
            $table->unsignedInteger('icg_numpresupuesto');
            $table->unsignedInteger('icg_n');
            $table->unsignedInteger('icg_version');
            $table->unique(
                ['icg_numserie', 'icg_numpresupuesto', 'icg_n', 'icg_version'],
                'legacy_cotizaciones_natural_key_uq',
            );

            $table->foreignId('legacy_cliente_id')->nullable()
                ->constrained($this->table('clientes'), 'id', 'legacy_cotizaciones_cliente_fk')
                ->nullOnDelete();
            $table->unsignedInteger('icg_codcliente')->nullable();

            $table->timestampTz('fecha')->nullable();
            $table->decimal('total_neto', 14, 2)->nullable();
            $table->decimal('total_bruto', 14, 2)->nullable();
            $table->unsignedInteger('icg_codvendedor')->nullable();
            $table->string('idestado', 20)->nullable();
            $table->string('supresupuesto', 50)->nullable();

            $table->boolean('orphan')->default(false);

            $table->timestampTz('created_at')->nullable();

            $table->index('icg_codcliente');
            $table->index('orphan');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('cotizaciones'));
    }
};
