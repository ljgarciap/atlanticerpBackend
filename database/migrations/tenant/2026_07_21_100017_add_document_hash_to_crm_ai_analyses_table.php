<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use App\Shared\Multitenancy\TenantSchemaMigration;

return new class extends TenantSchemaMigration
{
    protected string $module = 'crm';

    public function up(): void
    {
        Schema::table($this->table('ai_analyses'), function (Blueprint $table) {
            $table->string('document_hash', 64)->nullable()->after('entity_id');
            $table->index('document_hash', 'ai_analyses_document_hash_idx');
        });
    }

    public function down(): void
    {
        Schema::table($this->table('ai_analyses'), function (Blueprint $table) {
            $table->dropIndex('ai_analyses_document_hash_idx');
            $table->dropColumn('document_hash');
        });
    }
};
