<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Support\Facades\DB;

/**
 * SCRUM-990 (P2) — el umbral de la guarda del sincronizador de Lafayette pasa de constante de código a parámetro en `auth.system_settings`
 * (regla del workspace: ningún umbral de negocio hardcodeado). Se siembra con 0,9 (decisión de Luis, ver la migración 100400 para tenants que ya tenían el 0,5 inicial); `insertOrIgnore` no pisa un valor ya ajustado.
 * Ver `App\Modules\Integrations\Lafayette\Services\LafayetteSettings` para el riesgo de este umbral.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'auth';

    public function up(): void
    {
        DB::table($this->table('system_settings'))->insertOrIgnore([
            'key'        => 'lafayette_guarda_min_ratio',
            'value'      => DB::raw("'0.9'::jsonb"),
            'updated_by' => null,
            'updated_at' => null,
        ]);
    }

    public function down(): void
    {
        DB::table($this->table('system_settings'))->where('key', 'lafayette_guarda_min_ratio')->delete();
    }
};
