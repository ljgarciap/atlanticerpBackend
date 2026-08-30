<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends TenantSchemaMigration
{
    protected string $module = 'auth';

    public function up(): void
    {
        Schema::create($this->table('audit_logs'), function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 100);
            $table->string('entity_type', 100)->nullable();
            $table->string('entity_id', 100)->nullable();
            $table->jsonb('old_values')->nullable();
            $table->jsonb('new_values')->nullable();
            // ip_address stored as VARCHAR(45) — fits both IPv4 and IPv6; cast to INET in views if needed
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->uuid('request_id')->default(DB::raw('gen_random_uuid()'));
            $table->string('http_method', 10)->nullable();
            $table->string('http_path', 500)->nullable();
            $table->smallInteger('http_status')->nullable();
            $table->integer('duration_ms')->nullable();
            $table->jsonb('metadata')->nullable()->default('{}');
            $table->timestampTz('created_at')->useCurrent();
        });

        $logsTable = $this->table('audit_logs');

        // (user_id, created_at DESC) — most common audit query: "what did this user do?"
        DB::statement("CREATE INDEX idx_audit_logs_user_date ON {$logsTable} (user_id, created_at DESC)");

        // (action, created_at DESC) — filter by action type
        DB::statement("CREATE INDEX idx_audit_logs_action_date ON {$logsTable} (action, created_at DESC)");

        // (entity_type, entity_id) — "what happened to this entity?"
        DB::statement("CREATE INDEX idx_audit_logs_entity ON {$logsTable} (entity_type, entity_id)");

        // (created_at DESC) — general time-ordered audit trail
        DB::statement("CREATE INDEX idx_audit_logs_created_at ON {$logsTable} (created_at DESC)");
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('audit_logs'));
    }
};
