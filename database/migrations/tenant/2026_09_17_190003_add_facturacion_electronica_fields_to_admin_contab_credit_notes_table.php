<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prep — Facturación electrónica PAC (sin ticket todavía, ver
 * docs/architecture/facturacion-electronica-pac-diseno.md). Mismos campos de salida que
 * `invoices` (ver esa migración) — una Nota de Crédito certificada recibe su propio CUFE, distinto
 * al de `factura_origen_id` (ya existe como FK real en esta tabla, sirve de `referencia` al
 * certificar). Notas de Débito quedan fuera de alcance: AtlanticERP no las modela todavía.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'admin_contab';

    public function up(): void
    {
        Schema::table($this->table('credit_notes'), function (Blueprint $table): void {
            $table->string('serie')->nullable()->after('numero');
            $table->string('fe_estado')->nullable();
            $table->string('cufe')->nullable();
            $table->text('qr_content')->nullable();
            $table->string('xml_firmado_path')->nullable();
            $table->string('pac_provider_usado')->nullable();
            $table->timestamp('fe_certificado_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table($this->table('credit_notes'), function (Blueprint $table): void {
            $table->dropColumn([
                'serie', 'fe_estado', 'cufe', 'qr_content', 'xml_firmado_path',
                'pac_provider_usado', 'fe_certificado_at',
            ]);
        });
    }
};
