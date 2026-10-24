<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-830 — "Imagen del producto" obligatoria en Compras → Inventario. Mismo patrón que
 * `technical_sheet_key`/`technical_sheet_filename`/`technical_sheet_uploaded_at`
 * (2026_07_31_100001_...), pero con un segundo par de keys porque acá SÍ hace falta una miniatura
 * optimizada aparte del archivo grande (RN11/RN12 del ticket) — la ficha técnica nunca necesitó
 * eso, es un documento, no algo que se repita en tarjetas/listados.
 *
 * `photo_url` (columna original, string libre) NO se elimina ni se migra acá — sigue siendo la
 * única fuente para los productos migrados desde ICG (`Legacy\Services\CatalogPhotoLoader`, nunca
 * pisa un `photo_key` real) y el fallback de `CatalogService::photoUrl()`/`photoThumbnailUrl()`
 * para cualquier producto viejo sin imagen subida por este mecanismo nuevo.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'ventas_diseno';

    public function up(): void
    {
        Schema::table($this->table('catalog_products'), function (Blueprint $table): void {
            $table->string('photo_key')->nullable()->after('photo_url');
            $table->string('photo_thumbnail_key')->nullable()->after('photo_key');
            $table->string('photo_filename')->nullable()->after('photo_thumbnail_key');
            $table->timestampTz('photo_uploaded_at')->nullable()->after('photo_filename');
        });
    }

    public function down(): void
    {
        Schema::table($this->table('catalog_products'), function (Blueprint $table): void {
            $table->dropColumn(['photo_key', 'photo_thumbnail_key', 'photo_filename', 'photo_uploaded_at']);
        });
    }
};
