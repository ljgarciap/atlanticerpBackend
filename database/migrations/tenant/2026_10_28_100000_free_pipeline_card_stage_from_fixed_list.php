<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 7c (docs/specs/fase7c-etapas-pipeline.md) — `pipeline_cards.stage` deja de ser un ENUM cerrado de 6 valores: la empresa puede
 * intercalar etapas propias (dominio `pipeline_stage` del catálogo). Mismo patrón que `architects.type` en la 7a.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'ventas_diseno';

    public function up(): void
    {
        $cards = $this->table('pipeline_cards');
        DB::statement("ALTER TABLE {$cards} DROP CONSTRAINT IF EXISTS pipeline_cards_stage_check");
        Schema::table($cards, function (Blueprint $table): void {
            $table->string('stage', 60)->default('lead')->change();
        });
    }

    public function down(): void
    {
        // Solo es reversible mientras todas las tarjetas estén en una de las 6 etapas de sistema; si hay una etapa propia en uso, el CHECK
        // falla y hay que mover esas tarjetas antes.
        $cards = $this->table('pipeline_cards');
        Schema::table($cards, function (Blueprint $table): void {
            $table->string('stage', 20)->default('lead')->change();
        });
        DB::statement("ALTER TABLE {$cards} ADD CONSTRAINT pipeline_cards_stage_check CHECK (stage IN ('lead','design','quote','proposal','approved','lost'))");
    }
};
