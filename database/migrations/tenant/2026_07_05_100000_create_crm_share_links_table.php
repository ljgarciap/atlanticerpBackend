<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends TenantSchemaMigration
{
    protected string $module = 'crm';

    public function up(): void
    {
        Schema::create($this->table('share_links'), function (Blueprint $table) {
            $table->id();
            // SHA-256 hex of the raw token — same convention as password_reset_tokens.token_hash.
            // The raw token only ever exists in the API response URL, never persisted.
            $table->string('token_hash', 64)->unique();
            $table->foreignId('document_id')->constrained('project_documents')->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('last_accessed_at')->nullable();
            $table->unsignedInteger('access_count')->default(0);
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('share_links'));
    }
};
