<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-246 (Lote 4, comentario de Gerencia Test 2026-08-16) — fórmula de "% de similitud" del
 * método "Buscar en catálogo" de Comparación de Referencias, nunca definida antes en el
 * requerimiento original (columna vacía desde el batch 2026-08-15). Defaults = valores literales
 * de la fórmula que Daniela especificó con ejemplo numérico incluido, no números inventados —
 * parametric igual que `replacement_margin_threshold`, nunca const en el servicio.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'compras';

    public function up(): void
    {
        Schema::table($this->table('settings'), function (Blueprint $table): void {
            $table->decimal('similarity_weight_text', 5, 2)->default(50);
            $table->decimal('similarity_weight_price', 5, 2)->default(30);
            $table->decimal('similarity_weight_margin', 5, 2)->default(20);
            $table->decimal('similarity_other_category_penalty', 5, 2)->default(30);
            $table->decimal('similarity_min_threshold', 5, 2)->default(25);
        });
    }

    public function down(): void
    {
        Schema::table($this->table('settings'), function (Blueprint $table): void {
            $table->dropColumn([
                'similarity_weight_text', 'similarity_weight_price', 'similarity_weight_margin',
                'similarity_other_category_penalty', 'similarity_min_threshold',
            ]);
        });
    }
};
