<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-1137 (Fase 2) — `CLIENTES.CIF` trunca el RUC a 12 caracteres; el valor completo vive en
 * `CLIENTES.NIF20`. `nif20` guarda el texto crudo de ICG y `ruc`/`ruc_dv`/`ruc_origen`/`ruc_estado`
 * el resultado de `App\Modules\Legacy\Support\TaxIdNormalizer` (ver su docblock para las reglas).
 * Todas nullable: filas cargadas antes de este cambio quedan en NULL hasta la próxima corrida
 * completa de `legacy:sync-clientes`.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'legacy';

    public function up(): void
    {
        Schema::table($this->table('clientes'), function (Blueprint $table): void {
            $table->string('nif20', 20)->nullable();
            $table->string('ruc')->nullable();
            $table->string('ruc_dv', 4)->nullable();
            $table->string('ruc_origen', 10)->nullable();
            $table->string('ruc_estado', 20)->nullable();
            $table->index('ruc');
        });
    }

    public function down(): void
    {
        Schema::table($this->table('clientes'), function (Blueprint $table): void {
            $table->dropIndex(['ruc']);
            $table->dropColumn(['nif20', 'ruc', 'ruc_dv', 'ruc_origen', 'ruc_estado']);
        });
    }
};
