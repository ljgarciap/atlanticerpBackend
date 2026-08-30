<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Batch 19 de Admin&Cont (SCRUM-602, REQ-525) — ver
 * docs/adr/ADR-SCRUM602-606-batch19-arqueo-caja-parte2.md, reconciliación punto 6. Constancia
 * fiscal de retención que el CLIENTE entrega después de un cobro con método "Retención de
 * impuestos" — concepto distinto de `payment_attachments` (comprobante del cobro en sí, capturado
 * al registrar). Mismo shape que `payment_attachments`, pero con `updated_at` real: acá
 * "reemplazar" es un caso de uso explícito del REQ, no solo "subir una vez".
 *
 * Nombres de constraint EXPLÍCITOS y cortos (gotcha ya documentado en `atlanticerp/CLAUDE.md`, checklist
 * de pre-flight): el nombre de tabla+schema por default (`illuminations_admin_contab_payment_
 * retention_attachments_payment_id_...`) supera los 63 bytes que Postgres soporta para un
 * identificador — truncado, el índice único y la FK colisionan en el mismo nombre.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'admin_contab';

    public function up(): void
    {
        Schema::create($this->table('payment_retention_attachments'), function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payment_id')
                ->unique('admin_contab_pmt_retention_attach_payment_uq')
                ->constrained(
                    table: $this->table('payments'),
                    indexName: 'admin_contab_pmt_retention_attach_payment_fk',
                )
                ->cascadeOnDelete();
            $table->string('nombre_archivo');
            $table->string('storage_key');
            $table->unsignedBigInteger('size_bytes');
            $table->string('mime_type', 120);
            $table->unsignedBigInteger('uploaded_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('payment_retention_attachments'));
    }
};
