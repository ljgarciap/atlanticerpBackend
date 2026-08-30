<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Batch 17 de Admin&Cont (SCRUM-591→596, REQ-514→519) — Comisiones Externas, continuación. Ver
 * docs/adr/ADR-SCRUM591-596-batch17-comisiones-externas.md.
 *
 * - Comprobante de retención (REQ-514): mismas columnas que `cuenta_cobro_*` (Batch 16), mismo
 *   patrón — la sube Atlantic, no el arquitecto, pero el mecanismo de storage es idéntico.
 * - `bank_account_id` (REQ-517): FK real (mismo schema `admin_contab`, mismo criterio que
 *   `bank_movements.bank_account_id`) — RN1 default "Banco General — Cuenta Corriente" se resuelve
 *   en runtime (mismo query que `PaymentService::defaultBankAccountFor()`), nunca se siembra un
 *   valor fijo acá porque esa cuenta puede no existir todavía en un tenant nuevo.
 * - `porcentaje_aprobado`/`porcentaje_pendiente*` (REQ-516): % base sigue siendo el default de
 *   `fiscal_settings` (Batch 16) hasta que exista una propuesta aprobada — nunca se sobreescribe el
 *   default global, es un override puntual por proyecto.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'admin_contab';

    public function up(): void
    {
        Schema::table($this->table('architect_commissions'), function (Blueprint $table): void {
            $table->string('comprobante_retencion_storage_key')->nullable();
            $table->string('comprobante_retencion_nombre_archivo')->nullable();
            $table->unsignedBigInteger('comprobante_retencion_size_bytes')->nullable();
            $table->string('comprobante_retencion_mime_type')->nullable();
            $table->timestampTz('comprobante_retencion_uploaded_at')->nullable();
            $table->foreignId('comprobante_retencion_uploaded_by')->nullable()->constrained('users')->nullOnDelete();

            $table->foreignId('bank_account_id')->nullable()
                ->constrained($this->table('bank_accounts'))->nullOnDelete();

            $table->decimal('porcentaje_aprobado', 5, 2)->nullable();
            $table->decimal('porcentaje_pendiente', 5, 2)->nullable();
            $table->string('porcentaje_pendiente_motivo')->nullable();
            $table->foreignId('porcentaje_pendiente_propuesto_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('porcentaje_pendiente_propuesto_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table($this->table('architect_commissions'), function (Blueprint $table): void {
            $table->dropConstrainedForeignId('comprobante_retencion_uploaded_by');
            $table->dropConstrainedForeignId('bank_account_id');
            $table->dropConstrainedForeignId('porcentaje_pendiente_propuesto_por');
            $table->dropColumn([
                'comprobante_retencion_storage_key', 'comprobante_retencion_nombre_archivo',
                'comprobante_retencion_size_bytes', 'comprobante_retencion_mime_type',
                'comprobante_retencion_uploaded_at',
                'porcentaje_aprobado', 'porcentaje_pendiente', 'porcentaje_pendiente_motivo',
                'porcentaje_pendiente_propuesto_at',
            ]);
        });
    }
};
