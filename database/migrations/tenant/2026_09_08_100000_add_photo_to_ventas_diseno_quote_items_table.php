<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-849.6 — foto ad-hoc de un producto escrito directo en la cotización (`is_custom=true`,
 * sin `catalog_product_id` — nunca toca Catálogo/Compras). Mismo patrón de
 * `catalog_products.photo_key`/`photo_filename`/`photo_uploaded_at`
 * (2026_09_03_100000_...), pero un solo tamaño: acá el requerimiento es solo la miniatura de
 * vista previa (RN del ticket), no una imagen de detalle grande aparte.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'ventas_diseno';

    public function up(): void
    {
        Schema::table($this->table('quote_items'), function (Blueprint $table): void {
            $table->string('photo_key')->nullable()->after('discount_percent');
            $table->string('photo_filename')->nullable()->after('photo_key');
            $table->timestampTz('photo_uploaded_at')->nullable()->after('photo_filename');
        });
    }

    public function down(): void
    {
        Schema::table($this->table('quote_items'), function (Blueprint $table): void {
            $table->dropColumn(['photo_key', 'photo_filename', 'photo_uploaded_at']);
        });
    }
};
