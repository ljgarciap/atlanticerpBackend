<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-842 (épica SCRUM-749 — Migración ICG→AtlanticERP). Agrega PROYECTO/PROMOCION de
 * `PRESUPUESTOSCAMPOSLIBRES` (1:1 con `PRESUPUESTOSCAB` por la misma llave natural de 4 columnas —
 * NUMSERIE+NUMPRESUPUESTO+N+VERSION), nunca capturados por el import original (SCRUM-757).
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'legacy';

    public function up(): void
    {
        Schema::table($this->table('cotizaciones'), function (Blueprint $table): void {
            $table->string('proyecto')->nullable();
            $table->string('promocion')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table($this->table('cotizaciones'), function (Blueprint $table): void {
            $table->dropColumn(['proyecto', 'promocion']);
        });
    }
};
