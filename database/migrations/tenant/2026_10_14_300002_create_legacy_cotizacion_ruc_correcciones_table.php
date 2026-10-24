<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-1137 (Fase 3, cotizaciones) — registro de reversa de `legacy:fix-clientes-ruc --cotizaciones`. Una fila por cotización (`ventas_diseno.quotes`)
 * cuyo `ruc` se ajustó al RUC corregido de su sub_client, y por corrida (`run_id`), con el valor anterior y el nuevo. Sin FK a `ventas_diseno.*` (mismo
 * criterio que `cliente_ruc_correcciones`). `revertida_at` marca lo ya revertido (idempotencia).
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'legacy';

    public function up(): void
    {
        Schema::create($this->table('cotizacion_ruc_correcciones'), function (Blueprint $table): void {
            $table->id();
            $table->uuid('run_id');
            $table->unsignedBigInteger('quote_id');
            $table->unsignedBigInteger('sub_client_id');
            $table->string('ruc_anterior');
            $table->string('ruc_nuevo');
            $table->string('operador');
            $table->timestampTz('aplicada_at');
            $table->timestampTz('revertida_at')->nullable();
            $table->timestamps();

            $table->index('run_id', 'legacy_cotizacion_ruc_correcciones_run_idx');
            $table->index('quote_id', 'legacy_cotizacion_ruc_correcciones_quote_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('cotizacion_ruc_correcciones'));
    }
};
