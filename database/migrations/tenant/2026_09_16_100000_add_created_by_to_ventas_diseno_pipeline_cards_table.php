<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-884 — `created_by` identifica a quien creó originalmente el proyecto, independiente de
 * `owner_id` ("Responsable", reasignable por Gerencia). Nullable porque las tarjetas existentes
 * no tienen este dato hasta correr `ventas_diseno:backfill-pipeline-card-created-by` (mismo
 * patrón de columna nullable + comando de backfill dedicado que `unit_price`, SCRUM-786) —
 * `PipelineService::create()` siempre lo popula para tarjetas nuevas desde este punto en
 * adelante, así que la ventana sin dato se limita a las tarjetas pre-existentes al momento del
 * deploy.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'ventas_diseno';

    public function up(): void
    {
        Schema::table($this->table('pipeline_cards'), function (Blueprint $table): void {
            $table->foreignId('created_by')->nullable()->after('owner_id')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table($this->table('pipeline_cards'), function (Blueprint $table): void {
            $table->dropConstrainedForeignId('created_by');
        });
    }
};
