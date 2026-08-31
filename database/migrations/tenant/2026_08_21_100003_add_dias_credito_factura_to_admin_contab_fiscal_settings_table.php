<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Batch 4 de Facturación (SCRUM-524→528, REQ-450) — "Antigüedad de cartera" necesita una fecha de
 * vencimiento por factura, y el proyecto no tiene ningún concepto de plazo de pago todavía. En vez
 * de hardcodear un umbral (regla dura del proyecto), el plazo de crédito vive acá — mismo patrón
 * `primary_approver_only`/`::current()` que el resto de esta tabla. Vencimiento de una factura = su
 * `created_at` + este valor, calculado en caliente contra la configuración VIGENTE (no se
 * snapshotea por factura): un reporte de antigüedad de cartera refleja la política de crédito
 * actual, no la de cuando se emitió cada factura.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'admin_contab';

    public function up(): void
    {
        Schema::table($this->table('fiscal_settings'), function (Blueprint $table): void {
            $table->unsignedSmallInteger('dias_credito_factura')->default(30)->after('retencion_proveedores_activa');
        });
    }

    public function down(): void
    {
        Schema::table($this->table('fiscal_settings'), function (Blueprint $table): void {
            $table->dropColumn('dias_credito_factura');
        });
    }
};
