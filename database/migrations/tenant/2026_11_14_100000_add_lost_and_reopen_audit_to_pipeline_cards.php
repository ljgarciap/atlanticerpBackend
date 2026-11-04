<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 21 (B2) — «Perdido» deja el motivo, quién y cuándo; reactivar una tarjeta perdida (decide el aprobador o un suplente) deja el suyo.
 * Aditiva y nullable: las tarjetas ya perdidas quedan sin motivo (nunca se inventa uno).
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'ventas_diseno';

    public function up(): void
    {
        Schema::table($this->table('pipeline_cards'), function (Blueprint $table): void {
            $table->text('lost_reason')->nullable();
            $table->timestampTz('lost_at')->nullable();
            $table->foreignId('lost_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reopened_reason')->nullable();
            $table->timestampTz('reopened_at')->nullable();
            $table->foreignId('reopened_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table($this->table('pipeline_cards'), function (Blueprint $table): void {
            $table->dropConstrainedForeignId('lost_by');
            $table->dropConstrainedForeignId('reopened_by');
            $table->dropColumn(['lost_reason', 'lost_at', 'reopened_reason', 'reopened_at']);
        });
    }
};
