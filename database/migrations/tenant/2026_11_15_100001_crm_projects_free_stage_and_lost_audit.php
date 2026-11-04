<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 22 (B3 y B5) — `projects.etapa` deja de ser un ENUM (las etapas son un catálogo por empresa) y se guardan el motivo y la
 * auditoría de perder y de reactivar un proyecto. Columnas nullable: los ya perdidos quedan sin motivo (nunca se inventa uno).
 * `down()` restaura el ENUM y solo es seguro sin etapas propias en uso.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'crm';

    public function up(): void
    {
        $t = $this->table('projects');
        $check = DB::selectOne("select conname from pg_constraint where conrelid = ?::regclass and contype = 'c' and pg_get_constraintdef(oid) ilike '%etapa%'", [$t]);
        if ($check !== null) {
            DB::statement("ALTER TABLE {$t} DROP CONSTRAINT \"{$check->conname}\"");
        }
        DB::statement("ALTER TABLE {$t} ALTER COLUMN etapa TYPE varchar(50)");

        Schema::table($t, function ($table): void {
            $table->text('lost_reason')->nullable();
            $table->timestamp('lost_at')->nullable();
            $table->unsignedBigInteger('lost_by')->nullable();
            $table->text('reopened_reason')->nullable();
            $table->timestamp('reopened_at')->nullable();
            $table->unsignedBigInteger('reopened_by')->nullable();
        });
    }

    public function down(): void
    {
        $t = $this->table('projects');
        Schema::table($t, function ($table): void {
            $table->dropColumn(['lost_reason', 'lost_at', 'lost_by', 'reopened_reason', 'reopened_at', 'reopened_by']);
        });
        DB::statement("ALTER TABLE {$t} ADD CONSTRAINT projects_etapa_check CHECK (etapa in ('lead','diseno','cotizacion','propuesta','cerrado','perdido'))");
    }
};
