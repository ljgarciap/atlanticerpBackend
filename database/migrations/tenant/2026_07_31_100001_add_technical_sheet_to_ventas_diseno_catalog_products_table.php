<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-698 (REQ-618, Batch E del Epic CRM) — "Ficha técnica" de la pantalla Catálogo: un
 * ARCHIVO real (PDF/PNG/JPG) subido a S3, "no una tabla de datos dentro del sistema" (cita
 * literal del ticket). Distinto de `technical_spec` (SCRUM-425/426, JSONB de 12 sub-campos
 * estructurados) — ambos conceptos conviven en la misma fila de `catalog_products` porque
 * describen el mismo producto desde 2 ángulos distintos, no se fusionan.
 *
 * Sin versionado múltiple (a diferencia de `project_documents`): un archivo nuevo reemplaza al
 * anterior, `CatalogService::uploadTechnicalSheet()` borra la key vieja de S3 tras persistir la
 * nueva. `technical_sheet_uploaded_at` nullable — null significa "sin ficha", consumido por el
 * badge "✓ Ficha" / "⚠ Sin ficha" (REQ-615 RN4).
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'ventas_diseno';

    public function up(): void
    {
        Schema::table($this->table('catalog_products'), function (Blueprint $table): void {
            $table->string('technical_sheet_key')->nullable()->after('technical_spec');
            $table->string('technical_sheet_filename')->nullable()->after('technical_sheet_key');
            $table->timestampTz('technical_sheet_uploaded_at')->nullable()->after('technical_sheet_filename');
        });
    }

    public function down(): void
    {
        Schema::table($this->table('catalog_products'), function (Blueprint $table): void {
            $table->dropColumn(['technical_sheet_key', 'technical_sheet_filename', 'technical_sheet_uploaded_at']);
        });
    }
};
