<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contador atómico de `order_number` para Zona Libre — fila única (id fijo = 1), sin dimensión de
 * año a diferencia de `quote_folio_counters`/`order_folio_counters`: el mockup de REQ-369 RN2 solo
 * pide un número plano incremental, no un folio con prefijo+año (ver
 * `docs/backlog/bloque-b3-desglose-20260724.md`, sección "Puntos abiertos"). Mismo mecanismo de
 * asignación sin colisiones (`ZonaLibreFolioService::next()`: INSERT ... ON CONFLICT DO NOTHING +
 * lockForUpdate() dentro de una transacción externa) que los otros 2 folios del repo.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'compras';

    public function up(): void
    {
        Schema::create($this->table('bodega_zona_libre_folio_counters'), function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('bodega_zona_libre_folio_counters'));
    }
};
