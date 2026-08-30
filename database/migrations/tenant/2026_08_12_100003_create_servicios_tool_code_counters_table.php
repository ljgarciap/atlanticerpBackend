<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 4 — Servicios, Batch 13 parte 1 (REQ-271 RN3) — contador atómico del código COD-HER-NNN,
 * fila única (a diferencia de `ticket_folio_counters`/`inspection_report_folio_counters`, acá no
 * hay corte por prefijo/tipo ni por año — el código de herramienta es un consecutivo único de por
 * vida). `id` fijo en 1, mismo mecanismo `INSERT ... ON CONFLICT DO NOTHING` + `lockForUpdate()`
 * que el resto de los folios del módulo — ver `ToolCodeService::next()`.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'servicios';

    public function up(): void
    {
        Schema::create($this->table('tool_code_counters'), function (Blueprint $table): void {
            $table->tinyInteger('id')->primary();
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('tool_code_counters'));
    }
};
