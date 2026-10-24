<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-857 (REQ-458) — el Estado de Cuenta es un documento que recibe el CLIENTE para poder pagar
 * por transferencia: necesita el número de cuenta completo, no el enmascarado (`ultimos_4_digitos`)
 * que ya usa el resto del sistema. Decisión de Luis 2026-09-08: columna nueva, opcional, usada
 * SOLO en documentos de cobro (Estado de Cuenta, Facturas vía `InvoiceService::paymentBlock()`) —
 * la regla RN4 original de REQ-530 ("nunca se captura el número completo") sigue vigente para
 * cualquier otro propósito (listados internos, selector de cuenta en Cobros/Comisiones, etc.),
 * que siguen usando `ultimos_4_digitos` sin cambios.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'admin_contab';

    public function up(): void
    {
        Schema::table($this->table('bank_accounts'), function (Blueprint $table): void {
            $table->string('numero_cuenta_completo')->nullable()->after('ultimos_4_digitos');
        });
    }

    public function down(): void
    {
        Schema::table($this->table('bank_accounts'), function (Blueprint $table): void {
            $table->dropColumn('numero_cuenta_completo');
        });
    }
};
