<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Batch 3 de Facturación (SCRUM-519→523, REQ-442 RN4) — monto de saldo a favor aplicado al
 *  confirmar esta factura (0 si no se aplicó ninguno). Snapshot inmutable, mismo criterio que
 *  `cliente`/`monto` de esta misma tabla — no se recalcula si el saldo del cliente cambia después. */
return new class extends TenantSchemaMigration
{
    protected string $module = 'admin_contab';

    public function up(): void
    {
        Schema::table($this->table('invoices'), function (Blueprint $table): void {
            $table->decimal('saldo_aplicado', 12, 2)->default(0)->after('monto');
        });
    }

    public function down(): void
    {
        Schema::table($this->table('invoices'), function (Blueprint $table): void {
            $table->dropColumn('saldo_aplicado');
        });
    }
};
