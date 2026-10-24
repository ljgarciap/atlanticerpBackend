<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-816 — detalle fila-por-fila de una corrida de `legacy.sync_runs`, solo para filas que NO
 * terminaron `matched`/`cargado` limpio (excluded/orphan/ambiguous/error) — el motivo real por el
 * que cada fila quedó fuera, hoy descartado apenas termina el comando de sync (`renderReport()`
 * solo lo imprime en consola, nunca se persistía en ningún lado — confirmado leyendo
 * `LegacySyncProductosCommand`/`FacturaMatcher`/`ClienteMatcher` antes de este batch).
 *
 * Solo cubre corridas REALES a partir de este batch — no hay forma de reconstruir el detalle de
 * corridas ya ejecutadas (esa información nunca se guardó).
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'legacy';

    public function up(): void
    {
        Schema::create($this->table('sync_run_details'), function (Blueprint $table): void {
            $table->id();

            $table->unsignedBigInteger('sync_run_id');
            $table->foreign('sync_run_id', 'legacy_sync_run_details_run_fk')
                ->references('id')->on($this->table('sync_runs'))
                ->cascadeOnDelete();

            // Identificador legible de la fila de origen — icg_codarticulo, icg_codcliente,
            // serie+numero, etc., varía por entidad, no hay una PK natural común entre las 7.
            $table->string('referencia');
            $table->string('status', 20); // excluded | orphan | ambiguous | error
            $table->text('motivo')->nullable();

            $table->timestamp('created_at')->nullable();

            $table->index(['sync_run_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('sync_run_details'));
    }
};
