<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-257 (REQ-194) — umbral de días "En revisión" antes de marcar la bandera automática
 * "⚠️ Atención". Default 7: valor literal del escenario de aceptación del ticket ("Given que el
 * reclamo R-2001 lleva 7 días 'En revisión'"), no un número inventado — parametric igual que
 * `low_rating_threshold`.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'compras';

    public function up(): void
    {
        Schema::table($this->table('settings'), function (Blueprint $table): void {
            $table->integer('claim_attention_days')->default(7);
        });
    }

    public function down(): void
    {
        Schema::table($this->table('settings'), function (Blueprint $table): void {
            $table->dropColumn('claim_attention_days');
        });
    }
};
