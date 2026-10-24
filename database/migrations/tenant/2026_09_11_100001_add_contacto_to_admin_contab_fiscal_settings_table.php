<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-550 (rebote de Daniela 2026-09-10) — el recibo de cobro ("Ver recibo") debe mostrar un
 * dato de contacto de la empresa (teléfono y/o correo), igual que ya muestra RUC/razón
 * social/dirección desde esta misma tabla. No existía ninguna columna de contacto en
 * `fiscal_settings` ni en `company_profile` — se agregan acá, mismo patrón que el resto de esta
 * tabla (string, default '', Mark las carga desde la pantalla de Configuración Fiscal).
 *
 * Mientras sigan vacías (mismo estado hoy de `nombre_comercial`/`direccion_fiscal` en dev/test/
 * prod, ver docblock de `PdfFooterText`), `PaymentReceiptPdfService` usa un fallback público
 * constante — no se bloquea el recibo por falta de este dato.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'admin_contab';

    public function up(): void
    {
        Schema::table($this->table('fiscal_settings'), function (Blueprint $table): void {
            $table->string('telefono_contacto')->default('')->after('direccion_fiscal');
            $table->string('correo_contacto')->default('')->after('telefono_contacto');
        });
    }

    public function down(): void
    {
        Schema::table($this->table('fiscal_settings'), function (Blueprint $table): void {
            $table->dropColumn(['telefono_contacto', 'correo_contacto']);
        });
    }
};
