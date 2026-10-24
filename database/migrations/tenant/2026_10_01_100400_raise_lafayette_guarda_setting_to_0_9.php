<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Support\Facades\DB;

/**
 * SCRUM-990 (MEDIO-B1, decisión de Luis) — el umbral de la guarda de Lafayette sube de 0,5 a 0,9. Solo toca la fila que SIGUE siendo el 0,5 sembrado
 * por la migración 100300: `updated_by IS NULL` (nadie la editó desde la pantalla) y valor 0,5. Un valor cambiado a mano no se pisa.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'auth';

    public function up(): void
    {
        DB::table($this->table('system_settings'))
            ->where('key', 'lafayette_guarda_min_ratio')
            ->whereNull('updated_by')
            ->whereRaw("value = '0.5'::jsonb")
            ->update(['value' => DB::raw("'0.9'::jsonb")]);
    }

    public function down(): void
    {
        DB::table($this->table('system_settings'))
            ->where('key', 'lafayette_guarda_min_ratio')
            ->whereNull('updated_by')
            ->whereRaw("value = '0.9'::jsonb")
            ->update(['value' => DB::raw("'0.5'::jsonb")]);
    }
};
