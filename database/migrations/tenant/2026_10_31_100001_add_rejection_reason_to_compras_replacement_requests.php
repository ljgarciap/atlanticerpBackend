<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Fase 12a — rechazar una sustitución de producto exige un motivo; queda en la solicitud. */
return new class extends TenantSchemaMigration
{
    protected string $module = 'compras';

    public function up(): void
    {
        Schema::table($this->table('replacement_requests'), function (Blueprint $table): void {
            $table->text('rejection_reason')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table($this->table('replacement_requests'), function (Blueprint $table): void {
            $table->dropColumn('rejection_reason');
        });
    }
};
