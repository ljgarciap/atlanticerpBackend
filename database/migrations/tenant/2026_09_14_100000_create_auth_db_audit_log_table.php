<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-916 (Epic SCRUM-788) — auditoría a nivel de base de datos, backstop de LogsAuditTrail
 * (que solo ve cambios que pasan por Eloquent). Tabla separada de `audit_logs` a propósito: son
 * mecanismos distintos (ORM vs trigger de Postgres) con garantías distintas — mezclarlas complica
 * leer cualquiera de las dos después. `db_role` siempre está poblado (session_user de Postgres);
 * `app_user_id` solo si el cambio vino de la app (ver AttributeDbChangesToUser middleware) — NULL
 * ahí es en sí mismo una señal: "este cambio no pasó por el flujo normal de la aplicación".
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'auth';

    public function up(): void
    {
        Schema::create($this->table('db_audit_log'), function (Blueprint $table): void {
            $table->id();
            $table->string('table_schema', 100);
            $table->string('table_name', 100);
            $table->string('operation', 10); // INSERT | UPDATE | DELETE
            $table->text('row_pk'); // texto: cubre PK simples y compuestas por igual
            $table->jsonb('old_data')->nullable();
            $table->jsonb('new_data')->nullable();
            $table->string('db_role', 100);
            $table->unsignedBigInteger('app_user_id')->nullable();
            $table->timestampTz('occurred_at')->useCurrent();
        });

        $logTable = $this->table('db_audit_log');

        DB::statement("CREATE INDEX idx_db_audit_log_table_date ON {$logTable} (table_schema, table_name, occurred_at DESC)");
        DB::statement("CREATE INDEX idx_db_audit_log_app_user ON {$logTable} (app_user_id, occurred_at DESC)");
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('db_audit_log'));
    }
};
