<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-902 §4.2/4.3 — cuando una línea de cobro cubre una factura con un Abono de nivel
 * Factura/Proyecto ya re-asociado, la aplicación es obligatoria y bloqueada: `abono_id` +
 * `monto_abono_aplicado` guardan qué abono cubrió esta línea y cuánto, calculado una sola vez en
 * `PaymentService::register()` bajo lock y reusado tal cual en la aplicación diferida
 * (`PaymentService::confirm()`), para que el desglose mostrado al registrar el cobro (monto
 * original, monto de abono aplicado, monto neto) sea siempre el mismo número que el que
 * efectivamente se aplicó — nunca un recálculo aparte que pueda desincronizarse.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'admin_contab';

    public function up(): void
    {
        Schema::table($this->table('payment_invoices'), function (Blueprint $table): void {
            $table->unsignedBigInteger('abono_id')->nullable()->after('invoice_id');
            $table->decimal('monto_abono_aplicado', 12, 2)->default(0)->after('monto_aplicado');
        });
    }

    public function down(): void
    {
        Schema::table($this->table('payment_invoices'), function (Blueprint $table): void {
            $table->dropColumn(['abono_id', 'monto_abono_aplicado']);
        });
    }
};
