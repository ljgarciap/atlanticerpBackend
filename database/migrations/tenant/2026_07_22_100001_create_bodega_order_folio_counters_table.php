<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-329 Oleada A / Batch A1 (HU-335) — contador atómico del folio PED-AAAA-NNNN de Pedidos,
 * una fila por año calendario. Mismo mecanismo que `quote_folio_counters`/`QuoteFolioService`
 * (ver `App\Modules\Bodega\Services\OrderFolioService`) — INSERT ... ON CONFLICT DO NOTHING +
 * lockForUpdate() para que dos pedidos creados al mismo tiempo nunca choquen de folio.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'bodega';

    public function up(): void
    {
        Schema::create($this->table('order_folio_counters'), function (Blueprint $table): void {
            $table->unsignedInteger('year')->primary();
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('order_folio_counters'));
    }
};
