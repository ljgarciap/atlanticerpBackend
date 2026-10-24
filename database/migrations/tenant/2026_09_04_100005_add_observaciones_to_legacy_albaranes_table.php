<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-842 (épica SCRUM-749 — Migración ICG→AtlanticERP). Agrega OBSERVACIONES de
 * ALBVENTACAMPOSLIBRES (1:1 con ALBVENTACAB por la misma llave natural — NUMSERIE+NUMALBARAN+N),
 * nunca capturado por el import original (SCRUM-759).
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'legacy';

    public function up(): void
    {
        Schema::table($this->table('albaranes'), function (Blueprint $table): void {
            $table->text('observaciones')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table($this->table('albaranes'), function (Blueprint $table): void {
            $table->dropColumn('observaciones');
        });
    }
};
