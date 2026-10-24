<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-1137 (Fase 3) — registro de reversa de `legacy:fix-clientes-ruc`. Una fila por `sub_clients` corregido y por corrida (`run_id`), con el
 * `tax_id`/`tax_id_dv` anterior y el nuevo, para revertir una corrida exacta sin reconstruirla desde `audit_logs`. Sin FK a `ventas_diseno.*`
 * (mismo criterio que el resto del staging: el módulo Legacy nunca ata su esquema al de destino). `revertida_at` marca lo ya revertido (idempotencia).
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'legacy';

    public function up(): void
    {
        Schema::create($this->table('cliente_ruc_correcciones'), function (Blueprint $table): void {
            $table->id();
            $table->uuid('run_id');
            $table->unsignedBigInteger('sub_client_id');
            $table->integer('icg_codcliente');
            $table->string('tax_id_anterior');
            $table->string('tax_id_dv_anterior', 4)->nullable();
            $table->string('tax_id_nuevo');
            $table->string('tax_id_dv_nuevo', 4)->nullable();
            $table->string('operador');
            $table->timestampTz('aplicada_at');
            $table->timestampTz('revertida_at')->nullable();
            $table->timestamps();

            $table->index('run_id', 'legacy_cliente_ruc_correcciones_run_idx');
            $table->index('sub_client_id', 'legacy_cliente_ruc_correcciones_sub_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('cliente_ruc_correcciones'));
    }
};
