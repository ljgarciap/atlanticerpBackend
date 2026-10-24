<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-1127 — PDF de la orden de compra en inglés, a pedido del usuario, orden por orden.
 *
 * - `purchase_order_translations`: la versión en inglés GUARDADA de una orden (una fila vigente por orden e idioma). El PDF en inglés se
 *   renderiza desde acá: descargarlo después no llama a la IA y sale idéntico. `source_hash` es el hash de las descripciones de origen al
 *   generar; si cambian las líneas, deja de coincidir y la versión queda "desactualizada".
 * - `translation_cache`: traducción por texto de origen, compartida entre órdenes, para no pagar dos veces la misma descripción. Solo guarda
 *   texto de producto: nunca datos de la orden ni del proveedor.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'compras';

    public function up(): void
    {
        Schema::create($this->table('purchase_order_translations'), function (Blueprint $table): void {
            $table->id();
            $table->foreignId('purchase_order_id')
                ->constrained($this->table('purchase_orders'), 'id', 'purchase_order_translations_order_fk')
                ->cascadeOnDelete();
            $table->string('lang', 5);
            // { "<purchase_order_line_id>": "descripción traducida" }
            $table->jsonb('lines');
            $table->string('source_hash', 64);
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('generated_at');
            $table->timestampsTz();

            $table->unique(['purchase_order_id', 'lang'], 'purchase_order_translations_order_lang_unique');
        });

        Schema::create($this->table('translation_cache'), function (Blueprint $table): void {
            $table->id();
            $table->string('source_hash', 64);
            $table->string('lang', 5);
            $table->text('translated');
            $table->timestampsTz();

            $table->unique(['source_hash', 'lang'], 'translation_cache_source_lang_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('translation_cache'));
        Schema::dropIfExists($this->table('purchase_order_translations'));
    }
};
