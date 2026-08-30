<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends TenantSchemaMigration
{
    protected string $module = 'crm';

    public function up(): void
    {
        $table = $this->table('project_documents');

        Schema::table($table, function (Blueprint $blueprint) {
            $blueprint->string('storage_zone', 10)->notNull()->default('private')->after('mime_type');
            $blueprint->text('public_url')->nullable()->after('storage_zone');
            $blueprint->integer('version')->notNull()->default(1)->after('public_url');
            $blueprint->unsignedBigInteger('parent_document_id')->nullable()->after('version');
            $blueprint->boolean('is_current')->notNull()->default(true)->after('parent_document_id');
        });

        // CHECK constraint on storage_zone
        DB::statement(
            "ALTER TABLE {$table}
             ADD CONSTRAINT project_documents_storage_zone_check
             CHECK (storage_zone IN ('public','private'))"
        );

        // Self-referential FK: parent_document_id → id ON DELETE SET NULL
        DB::statement(
            "ALTER TABLE {$table}
             ADD CONSTRAINT fk_project_documents_parent
             FOREIGN KEY (parent_document_id)
             REFERENCES {$table}(id)
             ON DELETE SET NULL"
        );

        // Composite index: (project_id, is_current) — speeds up "current documents" queries
        DB::statement("CREATE INDEX idx_project_documents_project_current ON {$table} (project_id, is_current)");

        // Index on parent_document_id — speeds up version tree traversal
        DB::statement("CREATE INDEX idx_project_documents_parent ON {$table} (parent_document_id)");

        // Index on (uploaded_by, created_at) — used by audit-style queries
        DB::statement("CREATE INDEX idx_project_documents_uploader_date ON {$table} (uploaded_by, created_at)");
    }

    public function down(): void
    {
        $table = $this->table('project_documents');

        DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS project_documents_storage_zone_check");
        DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS fk_project_documents_parent");
        DB::statement("DROP INDEX IF EXISTS idx_project_documents_project_current");
        DB::statement("DROP INDEX IF EXISTS idx_project_documents_parent");
        DB::statement("DROP INDEX IF EXISTS idx_project_documents_uploader_date");

        Schema::table($table, function (Blueprint $blueprint) {
            $blueprint->dropColumn(['storage_zone', 'public_url', 'version', 'parent_document_id', 'is_current']);
        });
    }
};
