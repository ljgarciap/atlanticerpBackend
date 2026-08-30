<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends TenantSchemaMigration
{
    protected string $module = 'ventas_diseno';

    public function up(): void
    {
        Schema::table($this->table('pipeline_cards'), function (Blueprint $table): void {
            $table->text('observations')->nullable()->after('delivery_type');
        });
    }

    public function down(): void
    {
        Schema::table($this->table('pipeline_cards'), function (Blueprint $table): void {
            $table->dropColumn('observations');
        });
    }
};
