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
            $table->string('model', 60)->nullable()->after('analysis_type');
            $table->unsignedInteger('input_tokens')->nullable()->after('model');
            $table->unsignedInteger('output_tokens')->nullable()->after('input_tokens');
            $table->decimal('estimated_cost_usd', 10, 6)->nullable()->after('output_tokens');
        });
    }

    public function down(): void
    {
        Schema::table($this->table('ai_analyses'), function (Blueprint $table) {
            $table->dropColumn(['model', 'input_tokens', 'output_tokens', 'estimated_cost_usd']);
        });
    }
};
