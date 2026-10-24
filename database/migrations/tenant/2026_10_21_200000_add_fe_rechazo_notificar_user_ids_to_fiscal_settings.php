<?php

declare(strict_types=1);

use App\Shared\Multitenancy\Tenant;
use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-1186 (hotfix) — destinatarios del correo «la DGI rechazó una factura»: lista de usuarios en Configuración Fiscal (se cambia
 * sin deploy por la misma API de la configuración fiscal), en vez de escribir correos en el código.
 *
 * Se siembra con Gerencia Test 4 y Luis García (decisión de Luis, 2026-10-21) resueltos por su email. Si alguno no existe en el tenant
 * (dev/test con otros usuarios) simplemente no entra a la lista: la migración nunca falla por eso. Idempotente: solo siembra si la lista
 * está vacía, así que repetirla no pisa cambios hechos después.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'admin_contab';

    /** @var list<string> */
    private const EMAILS = ['superadmin2@test.com', 'gerencia4@test.com'];

    public function up(): void
    {
        if (! Schema::hasColumn($this->table('fiscal_settings'), 'fe_rechazo_notificar_user_ids')) {
            Schema::table($this->table('fiscal_settings'), function (Blueprint $table): void {
                $table->jsonb('fe_rechazo_notificar_user_ids')->default('[]');
            });
        }

        // Nombre de tabla calificado explícito (ver 2026_07_20_100009_add_mark_approver_to_compras_settings_table).
        $tenant = Tenant::current();
        $ids    = DB::table("{$tenant?->slug}_auth.users")
            ->whereIn(DB::raw('lower(email)'), self::EMAILS)
            ->where('is_active', true)
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        if ($ids !== []) {
            DB::table($this->table('fiscal_settings'))
                ->whereRaw("fe_rechazo_notificar_user_ids = '[]'::jsonb")
                ->update(['fe_rechazo_notificar_user_ids' => json_encode($ids)]);
        }
    }

    public function down(): void
    {
        Schema::table($this->table('fiscal_settings'), function (Blueprint $table): void {
            $table->dropColumn('fe_rechazo_notificar_user_ids');
        });
    }
};
