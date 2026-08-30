<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Support\Facades\DB;

/**
 * SCRUM-88 (REQ-019) Pre-QA CRÍTICO: el fix agregó 'photo' al Rule::in() del backend y al
 * PipelineFileType del frontend, pero nadie migró el check constraint real de Postgres —
 * cualquier upload de Foto (extensión válida) fallaba 100% del tiempo con
 * "pipeline_card_files_type_check" violado, exponiendo el error SQL crudo en la UI.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'ventas_diseno';

    public function up(): void
    {
        $table = $this->table('pipeline_card_files');

        DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS pipeline_card_files_type_check");
        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT pipeline_card_files_type_check CHECK (type IN ('design','proposal','signed_quote','approval_proof','photo'))");
    }

    public function down(): void
    {
        $table = $this->table('pipeline_card_files');

        DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS pipeline_card_files_type_check");
        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT pipeline_card_files_type_check CHECK (type IN ('design','proposal','signed_quote','approval_proof'))");
    }
};
