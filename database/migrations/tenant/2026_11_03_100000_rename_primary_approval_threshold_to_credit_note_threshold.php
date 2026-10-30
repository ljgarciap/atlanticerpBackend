<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 14d (3.4) — el umbral de Configuración Fiscal solo lo lee `CreditNoteService` (una nota de crédito por encima pasa por el aprobador);
 * Compras NO lo usa (sus reglas son `purchase_approval_rules`, Fase 14a). La clave decía «aprobación primaria» y sugería que gobernaba todo lo
 * que aprueba el aprobador: se renombra a lo que de verdad hace. Solo cambia el nombre de la columna; el valor se conserva.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'admin_contab';

    public function up(): void
    {
        Schema::table($this->table('fiscal_settings'), function (Blueprint $table): void {
            $table->renameColumn('primary_approval_threshold', 'credit_note_approval_threshold');
        });
    }

    public function down(): void
    {
        Schema::table($this->table('fiscal_settings'), function (Blueprint $table): void {
            $table->renameColumn('credit_note_approval_threshold', 'primary_approval_threshold');
        });
    }
};
