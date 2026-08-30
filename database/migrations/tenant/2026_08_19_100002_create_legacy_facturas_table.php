<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-760 (épica SCRUM-749 — Migración ICG→AtlanticERP). Staging de FACTURASVENTA
 * (Illumination/migracion-icg-diseno.md §2/§4/§5) — `legacy.facturas` es el equivalente staged de
 * FACTURASVENTA (60,215 filas §2), con trazabilidad completa vía la llave natural compuesta de
 * ICG (`icg_numserie`+`icg_numfactura`+`icg_n`, UNIQUE — idempotencia del Loader-A,
 * `App\Modules\Legacy\Services\FacturaStagingLoader`).
 *
 * A diferencia de `legacy.pedidos`/`legacy.albaranes` (header+líneas, 2 tablas cada una),
 * `FACTURASVENTA` **no tiene una tabla de líneas documentada** en el discovery (§2) — es una única
 * tabla plana, mismo criterio de alcance que `legacy.proveedores` (sin tabla de líneas), pero A
 * DIFERENCIA de esa entidad, Facturas SÍ necesita matching de cliente (`legacy_cliente_id`/
 * `orphan`, ver abajo) — es un híbrido: staging de una sola tabla (como Proveedores) + Matcher
 * (como Pedidos/Albaranes).
 *
 * `icg_n` — **TEXTO, no entero**, mismo criterio conservador que `legacy.albaranes` (§13.2 del
 * diseño): la columna `N` fue confirmada `nchar(1)` de texto constante `'B'` en las 6 tablas
 * PRESUPUESTOSCAB/LIN+PEDVENTACAB/LIN+ALBVENTACAB/LIN el 2026-08-17, pero **`FACTURASVENTA.N`
 * específicamente no formó parte de esa muestra** — se declara texto por el mismo patrón fuerte
 * observado en las 6 tablas hermanas, pero confirmar contra `INFORMATION_SCHEMA`+`GROUP BY` antes
 * de la primera corrida real (Tarea 6), no asumir ciego solo por la semejanza de nombre de tabla.
 *
 * Igual que `legacy.pedidos`/`legacy.albaranes` (runbook §12.1: "Cotizaciones/Pedidos/Albaranes/
 * Facturas por icg_codcliente → legacy_cliente_id"), esta entidad hereda el `legacy_cliente_id` de
 * `legacy.clientes` por lookup simple de `icg_codcliente` (`App\Modules\Legacy\Services\
 * FacturaMatcher`) — SIN re-matchear por CIF/email. Si `icg_codcliente` no resuelve, `orphan =
 * true` y `legacy_cliente_id` queda `null` — la factura se importa igual, nunca se descarta (§5).
 * `FACTURASVENTA.CODCLIENTE→CLIENTES` es ⚠️ "por convención" (§2, sin FK real declarada en ICG, a
 * diferencia de `ALBVENTACAB.CODCLIENTE` que sí la declara) — se guarda nullable, tolerante a que
 * ICG no garantice que siempre esté poblado.
 *
 * `sufactura` — nombre de columna engañoso, mismo patrón que `PRESUPUESTOSCAB.SUPRESUPUESTO`
 * (§13.2 del diseño: "tampoco es booleano pese al nombre... sugiere una referencia a otro
 * presupuesto/cliente, no un flag"). Se guarda como texto crudo sin interpretar, semántica real sin
 * confirmar — nunca se asume booleano solo por el prefijo "SU".
 *
 * `legacy_cliente_id`/`orphan` NUNCA se incluyen en el `$update` del upsert de staging (mismo
 * criterio que `legacy.pedidos`/`legacy.albaranes`) — una segunda corrida de staging jamás resetea
 * un link ya resuelto por `FacturaMatcher`.
 *
 * Sin `updated_at` — mismo alcance que `legacy.pedidos`/`legacy.albaranes`.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'legacy';

    public function up(): void
    {
        Schema::create($this->table('facturas'), function (Blueprint $table): void {
            $table->id();

            $table->string('icg_numserie', 10);
            $table->unsignedInteger('icg_numfactura');
            $table->string('icg_n', 10);
            $table->unique(
                ['icg_numserie', 'icg_numfactura', 'icg_n'],
                'legacy_facturas_natural_key_uq',
            );

            $table->foreignId('legacy_cliente_id')->nullable()
                ->constrained($this->table('clientes'), 'id', 'legacy_facturas_cliente_fk')
                ->nullOnDelete();
            $table->unsignedInteger('icg_codcliente')->nullable();
            $table->unsignedInteger('icg_codvendedor')->nullable();

            $table->timestampTz('fecha')->nullable();
            $table->decimal('total_neto', 14, 2)->nullable();
            $table->decimal('total_impuestos', 14, 2)->nullable();
            $table->decimal('total_bruto', 14, 2)->nullable();
            $table->string('sufactura', 30)->nullable();

            $table->boolean('orphan')->default(false);

            $table->timestampTz('created_at')->nullable();

            $table->index('icg_codcliente');
            $table->index('orphan');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('facturas'));
    }
};
