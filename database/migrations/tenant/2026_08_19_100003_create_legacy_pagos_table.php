<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-761 (épica SCRUM-749 — Migración ICG→AtlanticERP). Staging de TESORERIA
 * (Illumination/migracion-icg-diseno.md §2/§4/§5) — `legacy.pagos` es el equivalente staged de
 * TESORERIA (144,194 filas §2), con trazabilidad completa vía la llave natural compuesta de ICG.
 *
 * Sin Loader-B NI Matcher — mismo patrón que `legacy.proveedores` (SCRUM-756): catálogo de
 * referencia standalone puro. TESORERIA **no tiene columna de cliente** en el subconjunto
 * documentado (§2) — vincula al documento origen (factura/albarán) por `SERIE+NUMERO`, "⚠️ sin FK
 * declarada" (§2), y ni siquiera esa relación está en la tabla "Estrategia de vinculación" (§5) —
 * a diferencia de Cotizaciones/Pedidos/Albaranes/Facturas, Pagos no aparece ahí. Se guarda como
 * texto/id crudos, sin resolver ningún link, hasta que exista un batch aparte que lo justifique.
 *
 * **Llave natural compuesta de 6 columnas** — corrección deliberada sobre el sketch abreviado de
 * §4 del diseño (que solo listaba `icg_origen/icg_tipodocumento/icg_serie/icg_numero`): el PK real
 * documentado en el discovery de §2 es `ORIGEN+TIPODOCUMENTO+SERIE+NUMERO+N+POSICION` (6 columnas
 * — `POSICION` es necesaria para distinguir múltiples líneas de pago dentro del mismo documento).
 * Omitir `icg_n`/`icg_posicion` del UNIQUE hubiera colisionado filas reales distintas en el upsert
 * de staging — se completa acá con el PK real de 6 columnas, no con el sketch de 4.
 *
 * `icg_n` — mismo criterio conservador que `legacy.facturas`/`legacy.albaranes`: TEXTO, no
 * confirmado directamente contra `TESORERIA` (fuera de la muestra de 6 tablas auditada
 * 2026-08-17), declarado texto por el mismo patrón fuerte de las tablas hermanas — confirmar
 * contra `INFORMATION_SCHEMA` antes de la corrida real.
 *
 * `icg_origen`/`icg_tipodocumento`/`icg_serie` — tipo real sin confirmar contra ICG (§2 no detalla
 * el tipo de columna de ninguna), se declaran texto por ser códigos cortos, mismo criterio
 * defensivo que `icg_numserie` en el resto del módulo — nunca se asume numérico sin confirmar.
 *
 * `estado` — mismo GOTCHA que `PRESUPUESTOSCAB.IDESTADO`/`PEDVENTACAB.IDESTADO` (§13.2 del diseño:
 * "columnas IDESTADO-style son código de estado, no texto interpretable ni booleano") — se guarda
 * crudo sin interpretar, tipo real (¿int o texto?) sin confirmar contra ICG.
 *
 * Con `timestamps()` (created_at/updated_at) — mismo criterio que `legacy.proveedores`, entidad
 * sin Matcher que necesite proteger campos de una segunda corrida.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'legacy';

    public function up(): void
    {
        Schema::create($this->table('pagos'), function (Blueprint $table): void {
            $table->id();

            $table->string('icg_origen', 10);
            $table->string('icg_tipodocumento', 10);
            $table->string('icg_serie', 10);
            $table->unsignedInteger('icg_numero');
            $table->string('icg_n', 10);
            $table->unsignedInteger('icg_posicion');
            $table->unique(
                ['icg_origen', 'icg_tipodocumento', 'icg_serie', 'icg_numero', 'icg_n', 'icg_posicion'],
                'legacy_pagos_natural_key_uq',
            );

            $table->timestampTz('fecha_documento')->nullable();
            $table->timestampTz('fecha_vencimiento')->nullable();
            $table->decimal('importe', 14, 2)->nullable();
            $table->unsignedInteger('icg_codformapago')->nullable();
            $table->string('estado', 20)->nullable();
            $table->timestampTz('fecha_saldado')->nullable();

            $table->timestamps();

            $table->index(['icg_serie', 'icg_numero']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('pagos'));
    }
};
