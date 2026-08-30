<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-473→489 (REQ-403→419, Bloque B6 "Devoluciones") — ver
 * ADR-SCRUM473-489-devoluciones.md. Contador atómico del folio DEV-AAAA-NNNN de Devoluciones,
 * una fila por año calendario — mismo mecanismo exacto que `order_folio_counters`/
 * `OrderFolioService` (INSERT ... ON CONFLICT DO NOTHING + lockForUpdate()), tabla separada
 * porque el prefijo (DEV vs PED) y el ciclo de vida de Devoluciones son independientes de Pedidos.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'bodega';

    public function up(): void
    {
        Schema::create($this->table('return_folio_counters'), function (Blueprint $table): void {
            $table->unsignedInteger('year')->primary();
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('return_folio_counters'));
    }
};
