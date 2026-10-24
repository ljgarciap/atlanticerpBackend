<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-819 — captura de respaldo visual de los datos bancarios del proveedor, adicional a los
 * campos `bank_*` ya existentes (nunca los reemplaza — RN1/RN9 del ticket). Una sola imagen por
 * proveedor (no una tabla de documentos como `purchase_order_documents`): ningún criterio de
 * aceptación pide historial ni múltiples imágenes, y "reemplazar"/"eliminar" son 1:1 con el
 * proveedor. Mismas 4 columnas que describen un archivo en el resto del proyecto (ver
 * `purchase_order_documents`), con prefijo `bank_image_` para no confundir con un futuro
 * documento de otro tipo.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'compras';

    public function up(): void
    {
        Schema::table($this->table('providers'), function (Blueprint $table): void {
            $table->string('bank_image_storage_key')->nullable();
            $table->string('bank_image_original_filename')->nullable();
            $table->unsignedBigInteger('bank_image_size_bytes')->nullable();
            $table->string('bank_image_mime_type')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table($this->table('providers'), function (Blueprint $table): void {
            $table->dropColumn([
                'bank_image_storage_key', 'bank_image_original_filename',
                'bank_image_size_bytes', 'bank_image_mime_type',
            ]);
        });
    }
};
