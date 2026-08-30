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
        Schema::create($this->table('ai_analyses'), function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('entity_type', 50);            // project | monthly_report
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->string('analysis_type', 50);          // summarize | suggest_actions | draft_message | monthly_analysis
            $table->string('status', 20)->default('pending'); // pending | running | completed | failed
            $table->text('result')->nullable();
            $table->text('error')->nullable();
            $table->unsignedBigInteger('requested_by')->nullable();
            $table->jsonb('extra')->default('{}');
            $table->timestamps();

            $table->index(['entity_type', 'entity_id', 'analysis_type'], 'ai_analyses_entity_idx');
            $table->index('status', 'ai_analyses_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('ai_analyses'));
    }
};
