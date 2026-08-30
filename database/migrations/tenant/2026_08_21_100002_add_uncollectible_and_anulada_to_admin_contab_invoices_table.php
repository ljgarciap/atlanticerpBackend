<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Batch 4 de Facturación (SCRUM-524→528, REQ-447→451). `es_incobrable` (Batch 2) sigue
 * significando "aprobado por Mark" — acá se agrega el sub-estado "propuesto, esperando decisión"
 * (RN3 de REQ-448: sigue en cartera cobrable mientras tanto) y el motivo, que no se borra al
 * rechazar (RN4: queda como registro de auditoría aunque no se muestre en la UI normal).
 *
 * `anulada_at` — REQ-449 RN4: Facturación NUNCA anula una factura por sí misma, el único origen
 * legítimo es una Nota de Crédito de "Anulación completa" (batch muy posterior, no existe hoy).
 * Infraestructura fundacional a propósito, sin ningún escritor real todavía — mismo criterio que
 * `admin_contab.customer_credit_balances` en Batch 3.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'admin_contab';

    public function up(): void
    {
        Schema::table($this->table('invoices'), function (Blueprint $table): void {
            $table->text('motivo_incobrable')->nullable()->after('es_incobrable');
            $table->boolean('incobrable_pendiente')->default(false)->after('motivo_incobrable');
            $table->timestamp('anulada_at')->nullable()->after('incobrable_pendiente');
        });
    }

    public function down(): void
    {
        Schema::table($this->table('invoices'), function (Blueprint $table): void {
            $table->dropColumn(['motivo_incobrable', 'incobrable_pendiente', 'anulada_at']);
        });
    }
};
