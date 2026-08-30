<?php

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends TenantSchemaMigration
{
    protected string $module = 'auth';

    public function up(): void
    {
        Schema::create($this->table('password_reset_tokens'), function (Blueprint $table) {
            $table->id();
            $table->string('email', 255);
            $table->string('token_hash', 64)->unique();  // SHA-256 hex of raw token
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('password_reset_tokens'));
    }
};
