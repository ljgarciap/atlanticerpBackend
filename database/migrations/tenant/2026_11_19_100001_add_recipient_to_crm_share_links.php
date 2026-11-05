<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Fase 25 — a quién se compartió un enlace público (texto libre opcional, para el seguimiento de aperturas). Aditiva. */
return new class extends TenantSchemaMigration
{
    protected string $module = 'crm';

    public function up(): void
    {
        Schema::table($this->table('share_links'), function (Blueprint $table): void {
            $table->string('destinatario', 120)->nullable()->after('created_by');
        });
    }

    public function down(): void
    {
        Schema::table($this->table('share_links'), function (Blueprint $table): void {
            $table->dropColumn('destinatario');
        });
    }
};
