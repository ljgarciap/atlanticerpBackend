<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-842 (épica SCRUM-749 — Migración ICG→AtlanticERP). Agrega COMISION de PEDVENTACAMPOSLIBRES
 * (1:1 con PEDVENTACAB por la misma llave natural — NUMSERIE+NUMPEDIDO+N), nunca capturada por el
 * import original (SCRUM-758).
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'legacy';

    public function up(): void
    {
        Schema::table($this->table('pedidos'), function (Blueprint $table): void {
            $table->decimal('comision', 14, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table($this->table('pedidos'), function (Blueprint $table): void {
            $table->dropColumn('comision');
        });
    }
};
