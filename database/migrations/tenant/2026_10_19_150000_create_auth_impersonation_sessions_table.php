<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-1158 («Ver como usuario», ADR-010) — una fila por cada sesión de impersonación de solo lectura: un superadmin (`actor_id`) ve la
 * aplicación con la identidad de otra persona (`target_id`). Es la evidencia de quién miró a quién, cuándo y por qué, y lo que permite
 * revocar una sesión al instante (AuthenticateJwt la valida en cada request que lleve el claim `act`).
 *
 * Las FK a `users` NO llevan cascada de borrado: la evidencia debe sobrevivir. Condición C4 del threat model: el rol de la app
 * (`atlanticerp_app`) no puede hacer DELETE y su UPDATE se limita a las columnas de cierre (ver `tenant:grant-app-role`).
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'auth';

    public function up(): void
    {
        Schema::create($this->table('impersonation_sessions'), function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('actor_id')->constrained('users');
            $table->foreignId('target_id')->constrained('users');
            $table->text('reason');
            $table->timestampTz('started_at')->useCurrent();
            $table->timestampTz('expires_at');
            $table->timestampTz('ended_at')->nullable();
            $table->string('ended_reason', 20)->nullable(); // manual | expired | revoked | target_blocked
            $table->foreignId('ended_by')->nullable()->constrained('users');
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('request_id', 64)->nullable();
        });

        $sessions = $this->table('impersonation_sessions');

        DB::statement("CREATE INDEX idx_impersonation_sessions_actor ON {$sessions} (actor_id, started_at DESC)");
        DB::statement("CREATE INDEX idx_impersonation_sessions_target ON {$sessions} (target_id, started_at DESC)");
        // Consulta del guard y de la revocación: solo las sesiones todavía abiertas.
        DB::statement("CREATE INDEX idx_impersonation_sessions_open ON {$sessions} (actor_id) WHERE ended_at IS NULL");
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('impersonation_sessions'));
    }
};
