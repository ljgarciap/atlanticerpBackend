<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Batch 16 de Admin&Cont (SCRUM-585→590, REQ-508→513) — Comisiones Externas. Ver
 * docs/adr/ADR-SCRUM585-590-batch16-comisiones-externas.md.
 *
 * A diferencia de "pedido comisionable" de Comisiones Internas (100% calculado en vivo, sin tabla
 * propia — ver `CommissionOrderService`), acá SÍ hace falta una fila persistida por proyecto
 * comisionable: la cuenta de cobro adjunta (REQ-513) y la fecha de pago manual (REQ-512 RN3,
 * acción real en Batch 17) no se pueden derivar de ningún otro dato — son estado propio, no un
 * cálculo. La fila NUNCA se crea manualmente por el usuario (RN1 de REQ-508): se
 * crea/actualiza (upsert) como efecto colateral de subir la cuenta de cobro — mientras eso no
 * pase, el proyecto se calcula igual en vivo (mismo join que Internas) simplemente sin fila acá.
 *
 * `architect_id`/`pipeline_card_id` son FK "sueltas" (cross-schema hacia `ventas_diseno`, mismo
 * criterio que el resto del módulo) — `pipeline_card_id` único porque un proyecto aprobado tiene
 * a lo sumo una cuenta de cobro vigente (RN3 de REQ-513: "Reemplazar", no una lista).
 *
 * `fecha_pago` ya se modela en este batch aunque la ACCIÓN de marcar como pagada es REQ-515
 * (Batch 17) — el estado "Pagada" de REQ-512 necesita esta columna para derivarse desde ya.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'admin_contab';

    public function up(): void
    {
        Schema::create($this->table('architect_commissions'), function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('architect_id');
            $table->unsignedBigInteger('pipeline_card_id')->unique();

            $table->string('cuenta_cobro_storage_key')->nullable();
            $table->string('cuenta_cobro_nombre_archivo')->nullable();
            $table->unsignedBigInteger('cuenta_cobro_size_bytes')->nullable();
            $table->string('cuenta_cobro_mime_type')->nullable();
            $table->timestampTz('cuenta_cobro_uploaded_at')->nullable();
            $table->foreignId('cuenta_cobro_uploaded_by')->nullable()->constrained('users')->nullOnDelete();

            $table->date('fecha_pago')->nullable();

            $table->timestamps();

            $table->index('architect_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('architect_commissions'));
    }
};
