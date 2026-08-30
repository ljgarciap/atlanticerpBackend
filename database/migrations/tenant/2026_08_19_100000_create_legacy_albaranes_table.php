<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-759 (épica SCRUM-749 — Migración ICG→AtlanticERP). Staging de ALBVENTACAB
 * (Illumination/migracion-icg-diseno.md §2/§4/§5) — `legacy.albaranes` es el equivalente staged de
 * ALBVENTACAB, con trazabilidad completa vía la llave natural compuesta de ICG
 * (`icg_numserie`+`icg_numalbaran`+`icg_n`, UNIQUE — idempotencia del Loader-A,
 * `App\Modules\Legacy\Services\AlbaranStagingLoader`). Mismo patrón exacto que `legacy.pedidos`
 * (SCRUM-758) — 3 columnas de llave, sin VERSION (los albaranes, igual que los pedidos, no
 * re-versionan como una cotización en borrador).
 *
 * `icg_n` — **TEXTO, no entero** (§13.2 del diseño, confirmado 2026-08-17 contra ICG real:
 * `nchar(1)`, valor constante `'B'` en el 100% de las filas de las 6 tablas
 * PRESUPUESTOSCAB/LIN+PEDVENTACAB/LIN+ALBVENTACAB/LIN). `legacy.cotizaciones`/`legacy.pedidos`
 * quedaron con `unsignedInteger` por error (ya desplegados, deuda pendiente de ALTER TABLE sin
 * urgencia real porque el valor es constante) — Albaranes es la primera entidad de este batch que
 * se implementa DESPUÉS de confirmar el gotcha, así que declara el tipo correcto desde el diseño
 * inicial, sin repetir el mismo casteo.
 *
 * Igual que `legacy.pedidos` (runbook §12.1: "Cotizaciones/Pedidos/Albaranes/Facturas por
 * icg_codcliente → legacy_cliente_id"), esta entidad hereda el `legacy_cliente_id` de
 * `legacy.clientes` por lookup simple de `icg_codcliente` (`App\Modules\Legacy\Services\
 * AlbaranMatcher`) — SIN re-matchear por CIF/email, ese trabajo ya lo hizo `ClienteMatcher` sobre
 * `legacy.clientes` (SCRUM-755). Si `icg_codcliente` no resuelve a ningún
 * `legacy.clientes.icg_codcliente` conocido, `orphan = true` y `legacy_cliente_id` queda `null` —
 * el albarán se importa igual, nunca se descarta (§5: "nunca se inventa un link"). A diferencia de
 * `icg_codcliente` en Pedidos (⚠️ por convención, sin constraint real), `ALBVENTACAB.CODCLIENTE`
 * SÍ declara FK real hacia `CLIENTES` en ICG (§2: "✅ declarada") — igual se guarda nullable acá,
 * tolerante a que el lookup contra `legacy.clientes` (staged por separado) no siempre resuelva.
 *
 * `legacy_cliente_id`/`orphan` NUNCA se incluyen en el `$update` del upsert de staging (mismo
 * criterio que `legacy.pedidos`) — una segunda corrida de staging jamás resetea un link ya
 * resuelto por `AlbaranMatcher`; el Matcher es el único que los toca, corriendo siempre después del
 * staging dentro del mismo comando.
 *
 * `facturado` — NDBOOLEANF confirmado 2026-08-17 contra ICG real: `ALBVENTACAB.FACTURADO` es
 * `nchar(1)`, `'T'`/`'F'` (60,690 `'T'` / 295 `'F'`), mismo literal que `CLIENTES.BLOQUEADO` — NO
 * el mismo que `ARTICULOS.DESCATALOGADO` (`'V'`). Ver `AlbaranExtractor::mapAlbaranRow()`.
 *
 * `icg_numseriefac`/`icg_numfac` — link "por convención" (⚠️→FACTURASVENTA, §2) a la factura del
 * albarán. Queda CRUDO y sin resolver en este batch — mismo criterio que
 * `icg_seriealbaran`/`icg_numeroalbaran` en `legacy.pedidos`, se resuelve cuando exista el batch de
 * Facturas (SCRUM-760, fuera de alcance acá).
 *
 * `icg_codcliente`/`icg_codvendedor` — `icg_codvendedor` es ⚠️ por convención (sin constraint real,
 * §2), se guarda crudo y nullable igual que en Pedidos/Cotizaciones.
 *
 * Sin `updated_at` — mismo alcance que `legacy.pedidos`/`legacy.cotizaciones`, no se pide rastrear
 * cuándo se actualizó una cabecera ya stageada, solo cuándo se creó.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'legacy';

    public function up(): void
    {
        Schema::create($this->table('albaranes'), function (Blueprint $table): void {
            $table->id();

            $table->string('icg_numserie', 10);
            $table->unsignedInteger('icg_numalbaran');
            $table->string('icg_n', 10);
            $table->unique(
                ['icg_numserie', 'icg_numalbaran', 'icg_n'],
                'legacy_albaranes_natural_key_uq',
            );

            $table->foreignId('legacy_cliente_id')->nullable()
                ->constrained($this->table('clientes'), 'id', 'legacy_albaranes_cliente_fk')
                ->nullOnDelete();
            $table->unsignedInteger('icg_codcliente')->nullable();

            $table->timestampTz('fecha')->nullable();
            $table->decimal('totalneto', 14, 2)->nullable();
            $table->boolean('facturado')->default(false);
            $table->unsignedInteger('icg_codvendedor')->nullable();

            // Link crudo a la factura (FACTURASVENTA) — sin resolver en este batch, ver docblock.
            $table->string('icg_numseriefac', 10)->nullable();
            $table->unsignedInteger('icg_numfac')->nullable();

            $table->boolean('orphan')->default(false);

            $table->timestampTz('created_at')->nullable();

            $table->index('icg_codcliente');
            $table->index('orphan');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('albaranes'));
    }
};
