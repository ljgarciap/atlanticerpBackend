<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-758 (épica SCRUM-749 — Migración ICG→AtlanticERP). Staging de PEDVENTACAB
 * (Illumination/migracion-icg-diseno.md §2/§4/§5) — `legacy.pedidos` es el equivalente staged de
 * PEDVENTACAB, con trazabilidad completa vía la llave natural compuesta de ICG
 * (`icg_numserie`+`icg_numpedido`+`icg_n`, UNIQUE — idempotencia del Loader-A,
 * `App\Modules\Legacy\Services\PedidoStagingLoader`).
 *
 * A diferencia de `legacy.cotizaciones` (PK compuesta de 4 columnas, incluye VERSION),
 * PEDVENTACAB solo declara 3 (§2: "PK compuesta: NUMSERIE+NUMPEDIDO+N") — Pedidos no tiene
 * versión, un pedido confirmado no se re-versiona como una cotización en borrador.
 *
 * Igual que `legacy.cotizaciones` (SCRUM-757, ver runbook §12.1: "Cotizaciones/Pedidos/Albaranes/
 * Facturas por icg_codcliente → legacy_cliente_id"), esta entidad hereda el `legacy_cliente_id` de
 * `legacy.clientes` por lookup simple de `icg_codcliente` (`App\Modules\Legacy\Services\
 * PedidoMatcher`) — SIN re-matchear por CIF/email, ese trabajo ya lo hizo `ClienteMatcher` sobre
 * `legacy.clientes` (SCRUM-755). Si `icg_codcliente` no resuelve a ningún
 * `legacy.clientes.icg_codcliente` conocido, `orphan = true` y `legacy_cliente_id` queda `null` —
 * el pedido se importa igual, nunca se descarta (§5: "nunca se inventa un link... el documento se
 * importa igual con orphan=true").
 *
 * `legacy_cliente_id`/`orphan` NUNCA se incluyen en el `$update` del upsert de staging (mismo
 * criterio que `legacy.cotizaciones`) — una segunda corrida de staging jamás resetea un link ya
 * resuelto por `PedidoMatcher`; el Matcher es el único que los toca, corriendo siempre después del
 * staging dentro del mismo comando.
 *
 * `icg_codcliente`/`icg_codvendedor` — GOTCHA §2: FKs "por convención" (⚠️), sin constraint real
 * declarado en ICG — se guardan crudos y nullable, tolerante a que ICG no garantice que siempre
 * estén poblados.
 *
 * `icg_seriealbaran`/`icg_numeroalbaran` — link "por convención" (⚠️→ALBVENTACAB, §2) a la entrega
 * del pedido. Queda CRUDO y sin resolver en este batch — se guarda tal como viene de ICG, sin
 * intentar un FK ni una resolución de id interno, hasta que exista el batch de Albaranes (fuera de
 * alcance de SCRUM-758).
 *
 * `idestado` — mismo GOTCHA de tipo sin verificar contra ICG real que `legacy.cotizaciones.
 * idestado`: no se confirmó el tipo real en SQL Server (podría ser código numérico o NDBOOLEANF).
 * Se guarda como texto crudo sin interpretar — confirmar contra `INFORMATION_SCHEMA.COLUMNS` antes
 * de la primera corrida real (equivalente a la Tarea 6 de SCRUM-750/755/757).
 *
 * `fechaentrega` nullable — a diferencia de `fechapedido` (siempre presente en un pedido real), la
 * fecha de entrega puede no estar definida todavía en ICG.
 *
 * Sin `updated_at` — mismo alcance que `legacy.cotizaciones`, no se pide rastrear cuándo se
 * actualizó una cabecera ya stageada, solo cuándo se creó.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'legacy';

    public function up(): void
    {
        Schema::create($this->table('pedidos'), function (Blueprint $table): void {
            $table->id();

            $table->string('icg_numserie', 10);
            $table->unsignedInteger('icg_numpedido');
            $table->unsignedInteger('icg_n');
            $table->unique(
                ['icg_numserie', 'icg_numpedido', 'icg_n'],
                'legacy_pedidos_natural_key_uq',
            );

            $table->foreignId('legacy_cliente_id')->nullable()
                ->constrained($this->table('clientes'), 'id', 'legacy_pedidos_cliente_fk')
                ->nullOnDelete();
            $table->unsignedInteger('icg_codcliente')->nullable();

            $table->timestampTz('fechapedido')->nullable();
            $table->timestampTz('fechaentrega')->nullable();
            $table->decimal('totneto', 14, 2)->nullable();
            $table->unsignedInteger('icg_codvendedor')->nullable();
            $table->string('idestado', 20)->nullable();

            // Link crudo a la entrega (ALBVENTACAB) — sin resolver en este batch, ver docblock.
            $table->string('icg_seriealbaran', 10)->nullable();
            $table->unsignedInteger('icg_numeroalbaran')->nullable();

            $table->boolean('orphan')->default(false);

            $table->timestampTz('created_at')->nullable();

            $table->index('icg_codcliente');
            $table->index('orphan');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('pedidos'));
    }
};
