<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 12a (docs/specs/fase12-seguridad-y-separacion-de-funciones.md) — un ticket cerrado o cancelado es final; se reabre solo con un permiso y un
 * motivo, que quedan en el propio ticket (la auditoría registra además el cambio de estado).
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'servicios';

    public function up(): void
    {
        Schema::table($this->table('tickets'), function (Blueprint $table): void {
            $table->timestamp('reopened_at')->nullable();
            $table->text('reopened_reason')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table($this->table('tickets'), function (Blueprint $table): void {
            $table->dropColumn(['reopened_at', 'reopened_reason']);
        });
    }
};
