<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-746 — Sesión expira por inactividad consecutiva (no absoluta desde login).
 *
 * `last_activity_at` — actualizado atómicamente en cada `POST /auth/refresh` exitoso
 * (ver `AuthController::refresh()`), nunca en `AuthenticateJwt` (Threat Model
 * `docs/security/THREAT-MODEL-scrum746-session-inactivity.md`, Hallazgo 1/4). El gate de
 * inactividad compara `now() - last_activity_at` contra `session_inactivity_hours`
 * (`auth.system_settings`, default 10h).
 *
 * `login_at` — momento del login ORIGINAL, propagado (copiado, no `now()`) a través de cada
 * rotación de refresh token en `issueRefreshToken()`. `issueRefreshToken()` crea una fila NUEVA
 * en cada rotación (`expires_at` es una ventana rodante) — si el techo absoluto de 30 días se
 * calculara contra `created_at` de la fila vigente, se resetearía en cada rotación y dejaría de
 * ser un techo real. `login_at` es lo que le da al techo absoluto (fijo en código, 30 días,
 * `PreferencesService::SESSION_ABSOLUTE_CEILING_DAYS`) un ancla que sobrevive la rotación.
 *
 * Backfill: filas existentes (emitidas antes de este batch) no tienen ninguna señal real de
 * actividad — se usa `created_at` como mejor proxy disponible, para no invalidar de golpe todo
 * refresh token vigente en el momento del deploy.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'auth';

    public function up(): void
    {
        Schema::table($this->table('refresh_tokens'), function (Blueprint $table): void {
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamp('login_at')->nullable();
        });

        DB::table($this->table('refresh_tokens'))
            ->whereNull('last_activity_at')
            ->update([
                'last_activity_at' => DB::raw('created_at'),
                'login_at'         => DB::raw('created_at'),
            ]);
    }

    public function down(): void
    {
        Schema::table($this->table('refresh_tokens'), function (Blueprint $table): void {
            $table->dropColumn(['last_activity_at', 'login_at']);
        });
    }
};
