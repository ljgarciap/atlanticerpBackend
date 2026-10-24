<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prep — Facturación electrónica PAC (sin ticket todavía, ver
 * docs/architecture/facturacion-electronica-pac-diseno.md). Campos de salida de la certificación
 * ante el PAC — comunes a MSL y Digifact directo (ver comparativo de esquemas en el doc de
 * arquitectura), nunca se llenan hasta que exista un adapter real conectado.
 *
 * `serie` separado de `numero` (hoy `invoices.numero` es un único string) porque ambos esquemas de
 * proveedor piden serie+numero como campos independientes. `pac_provider_usado` (distinto de
 * `fiscal_settings.pac_provider`, que es la config activa) queda como snapshot de auditoría —
 * mismo criterio que `cliente`/`monto` en esta misma tabla: no se recalcula si la config cambia
 * después de certificado el documento.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'admin_contab';

    public function up(): void
    {
        Schema::table($this->table('invoices'), function (Blueprint $table): void {
            $table->string('serie')->nullable()->after('numero');
            $table->string('fe_estado')->nullable(); // pendiente | certificado | rechazado
            $table->string('cufe')->nullable();
            $table->text('qr_content')->nullable();
            $table->string('xml_firmado_path')->nullable(); // S3 key, disk 's3'
            $table->string('pac_provider_usado')->nullable();
            $table->timestamp('fe_certificado_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table($this->table('invoices'), function (Blueprint $table): void {
            $table->dropColumn([
                'serie', 'fe_estado', 'cufe', 'qr_content', 'xml_firmado_path',
                'pac_provider_usado', 'fe_certificado_at',
            ]);
        });
    }
};
