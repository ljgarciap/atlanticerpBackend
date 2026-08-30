<?php

declare(strict_types=1);

use App\Shared\Multitenancy\Tenant;
use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-206 (hallazgo QA 2026-07-16): el endpoint de aprobación de orden aérea/Atlantic
 * (REQ-143) solo chequeaba el permiso genérico `compras.approve` — cualquier usuario con ese
 * permiso podía aprobar (probado en vivo: Daniela, rol Gerencia, aprobó y quedó registrada como
 * `approved_by`, pero la UI mostraba el texto fijo "Aprobada por Mark"). La spec de REQ-143 dice
 * explícitamente "hasta que Mark realmente la haya aprobado" — es una persona puntual, no el rol
 * Gerencia completo. Se guarda como parámetro configurable (mismo patrón que
 * `low_rating_threshold`) en vez de hardcodear el email de Mark en el controller — si la persona
 * cambia, se reasigna desde Configuración sin deploy.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'compras';

    public function up(): void
    {
        Schema::table($this->table('settings'), function (Blueprint $table): void {
            $table->foreignId('mark_approver_user_id')->nullable()->constrained('users')->nullOnDelete();
        });

        // Nombre de tabla calificado explícito (no bare 'users') — TenantSchemaMigration existe
        // justamente para no depender del orden del search_path (ver su docblock); DB::table()
        // no pasa por Schema::/TenantSchemaMigration::table(), así que sin esto puede resolver
        // el schema equivocado según el momento en que corra dentro de tenants:artisan migrate
        // (confirmado en vivo: quedó NULL en dev pese a que Mark ya existía).
        $tenant = Tenant::current();
        $authUsersTable = "{$tenant?->slug}_auth.users";

        $mark = DB::table($authUsersTable)->where('email', 'mbekhar@atlantic.com.pa')->first();
        if ($mark !== null) {
            DB::table($this->table('settings'))->where('id', 1)->update(['mark_approver_user_id' => $mark->id]);
        }
    }

    public function down(): void
    {
        Schema::table($this->table('settings'), function (Blueprint $table): void {
            $table->dropConstrainedForeignId('mark_approver_user_id');
        });
    }
};
