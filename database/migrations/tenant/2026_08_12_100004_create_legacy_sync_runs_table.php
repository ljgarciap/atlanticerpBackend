<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-750 (épica SCRUM-749). Una fila por corrida REAL de `legacy:sync-productos` (no por
 * `--dry-run`, ver docblock de `App\Console\Commands\LegacySyncProductosCommand`) — vacío de
 * monitoreo #5 de Illumination/migracion-icg-diseno.md §11 (base para la alerta Telegram de
 * "más de 10 días sin corrida exitosa", todavía sin implementar — solo el registro en esta tabla
 * es parte de este batch).
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'legacy';

    public function up(): void
    {
        Schema::create($this->table('sync_runs'), function (Blueprint $table): void {
            $table->id();
            $table->string('entidad'); // 'productos' en este batch — otras entidades futuras reusan la tabla
            $table->unsignedInteger('extraidos')->default(0);
            $table->unsignedInteger('staged')->default(0);
            $table->unsignedInteger('mapeados')->default(0);
            $table->unsignedInteger('cargados')->default(0);
            $table->unsignedInteger('excluidos')->default(0);
            $table->unsignedInteger('errores')->default(0);
            $table->unsignedInteger('duracion_ms')->default(0);
            $table->string('status', 20); // success | partial | failed
            $table->timestampTz('started_at');
            $table->timestampTz('finished_at');

            $table->timestamp('created_at')->nullable();

            $table->index(['entidad', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('sync_runs'));
    }
};
