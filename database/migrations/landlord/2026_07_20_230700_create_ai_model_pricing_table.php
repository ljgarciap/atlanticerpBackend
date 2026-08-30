<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_model_pricing', function (Blueprint $table): void {
            $table->string('model')->primary();
            $table->decimal('input_price_per_million_usd', 10, 4);
            $table->decimal('output_price_per_million_usd', 10, 4);
            $table->unsignedInteger('updated_by')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_model_pricing');
    }
};
