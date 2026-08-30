<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * REQ-141 (SCRUM-204) — el ciclo de estados de una orden salta pasos de tránsito/aduana cuando
 * el proveedor es local. Campo nuevo, DISTINTO de `providers.category` (texto libre, ya
 * existente — categorización de negocio tipo "Iluminación", sin relación con esto). Mezclar
 * ambos conceptos en un solo campo habría sido frágil: la lógica de salto de estados necesita
 * comparar contra un valor exacto, no contra texto libre que Yirena podría escribir distinto
 * cada vez (decisión de Luis, daily sprint2 2026-07-14).
 *
 * Default 'internacional' — los proveedores ya sembrados en tests/demo no tienen este dato,
 * y la mayoría de los ejemplos reales de los tickets (LightCorp, Iluminex) son internacionales.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'compras';

    public function up(): void
    {
        Schema::table($this->table('providers'), function (Blueprint $table): void {
            $table->string('origin')->default('internacional')->after('category');
        });
    }

    public function down(): void
    {
        Schema::table($this->table('providers'), function (Blueprint $table): void {
            $table->dropColumn('origin');
        });
    }
};
