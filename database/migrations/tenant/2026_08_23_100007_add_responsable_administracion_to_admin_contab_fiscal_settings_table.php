<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Batch 9 — Estado de Cuenta (SCRUM-534→538, REQ-458 RN2/RN3). El bloque de datos de pago exige
 * un "Responsable" idéntico entre Facturación y Estado de Cuenta. Hasta este batch,
 * `InvoiceService::pagoBlock()` usaba el `created_by` de CADA factura puntual — no hay problema
 * mostrando una factura sola, pero Estado de Cuenta agrega muchas facturas de un cliente,
 * potencialmente creadas por personas distintas, así que ese campo no puede ser "el mismo en las
 * 2 pantallas" tal cual. Decisión de Luis (2026-08-23): un único responsable a nivel EMPRESA (no
 * por cliente ni por factura) en `fiscal_settings` — la misma tabla singleton que ya guarda "Pago
 * a"/RUC/etc. — y `InvoiceService::pagoBlock()` se corrige para leer de acá también (arregla de
 * paso la inconsistencia latente que ya existía en Facturación, aunque nadie la había notado).
 * Nullable, sin default — el bloque de pago simplemente muestra "—" hasta que alguien lo
 * configure (por ahora sin pantalla de edición propia, mismo criterio que otros datos de setup
 * inicial de este módulo, ej. `ultimos_4_digitos` de cuentas bancarias).
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'admin_contab';

    public function up(): void
    {
        Schema::table($this->table('fiscal_settings'), function (Blueprint $table): void {
            $table->unsignedBigInteger('responsable_administracion_user_id')->nullable()->after('dias_credito_factura');
        });
    }

    public function down(): void
    {
        Schema::table($this->table('fiscal_settings'), function (Blueprint $table): void {
            $table->dropColumn('responsable_administracion_user_id');
        });
    }
};
