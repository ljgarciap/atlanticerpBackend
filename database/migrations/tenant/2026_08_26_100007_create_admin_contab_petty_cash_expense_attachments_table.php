<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Batch 20 (REQ-535 RN2) — foto(s) del recibo de un gasto de caja chica. Mismo patrón que
 * `servicios.ticket_attachments` (siempre privado, sin versionado) — a diferencia de
 * `admin_contab.payment_attachments`, sin `unique()` en `expense_id`: RN2 exige al menos 1 foto al
 * crear, pero el mockup permite agregar soporte adicional después.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'admin_contab';

    public function up(): void
    {
        Schema::create($this->table('petty_cash_expense_attachments'), function (Blueprint $table): void {
            $table->id();
            $table->foreignId('expense_id')
                ->constrained($this->table('petty_cash_expenses'), 'id', 'admin_contab_petty_cash_expense_attachments_expense_fk')
                ->cascadeOnDelete();

            $table->string('nombre_archivo');
            $table->string('storage_key');
            $table->unsignedBigInteger('size_bytes');
            $table->string('mime_type', 120);

            $table->foreignId('uploaded_by')->nullable()
                ->constrained('users', 'id', 'admin_contab_petty_cash_expense_attachments_uploader_fk')
                ->nullOnDelete();

            $table->timestamp('created_at')->nullable();

            $table->index('expense_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('petty_cash_expense_attachments'));
    }
};
