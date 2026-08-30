<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** SCRUM-210 (REQ-147, alcance reducido) — agencia de liquidación asignada a la orden. */
return new class extends TenantSchemaMigration
{
    protected string $module = 'compras';

    public function up(): void
    {
        Schema::table($this->table('purchase_orders'), function (Blueprint $table): void {
            $table->foreignId('liquidation_agency_id')->nullable()
                ->constrained($this->table('liquidation_agencies'))->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table($this->table('purchase_orders'), function (Blueprint $table): void {
            $table->dropConstrainedForeignId('liquidation_agency_id');
        });
    }
};
