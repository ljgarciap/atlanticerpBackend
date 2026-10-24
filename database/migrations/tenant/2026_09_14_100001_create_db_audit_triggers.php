<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Support\Facades\DB;

/**
 * SCRUM-916 — función PL/pgSQL genérica + triggers AFTER INSERT/UPDATE/DELETE sobre las tablas
 * sensibles de Auth (mismo alcance que SCRUM-915). Detecta la(s) columna(s) de PK vía catálogo de
 * Postgres (pg_index/pg_attribute) en vez de asumir `id` — `user_roles` tiene PK compuesta
 * (user_id, role), el resto tiene `id` simple; la función funciona igual para ambos casos sin
 * necesitar una versión distinta por tabla.
 *
 * `app_user_id` sale de `current_setting('app.current_user_id', true)` — el segundo argumento
 * `true` hace que devuelva NULL en vez de tirar excepción si la variable de sesión nunca se
 * seteó (ej. un cambio hecho por psql/una migración, fuera del flujo de la app). Ver
 * App\Shared\Http\Middleware\AttributeDbChangesToUser, que es quien la setea en cada request.
 *
 * ALCANCE: mismas tablas de SCRUM-915. `global_settings` queda AFUERA a propósito — vive en el
 * schema `public` (landlord, database/migrations/landlord/2026_06_23_000001_...), no en el schema
 * de tenant — necesitaría su propia función/trigger en `public`, fuera del alcance de este ticket.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'auth';

    private const AUDITED_TABLES = [
        'users',
        'security_levels',
        'roles',
        'user_roles',
        'user_additional_roles',
        'security_level_module_permissions',
        'role_module_visibility',
        'user_module_visibility',
        'user_module_menu_visibility',
        'system_settings',
        'departments',
    ];

    public function up(): void
    {
        $schema     = $this->schema();
        $auditTable = $this->table('db_audit_log');
        $function   = "\"{$schema}\".fn_audit_row_change";

        DB::statement(<<<SQL
            CREATE OR REPLACE FUNCTION {$function}() RETURNS trigger AS \$fn\$
            DECLARE
                v_pk_cols   text[];
                v_pk_values text[] := ARRAY[]::text[];
                v_col       text;
                v_row_pk    text;
                v_app_user_id bigint;
            BEGIN
                SELECT array_agg(a.attname ORDER BY a.attnum)
                INTO v_pk_cols
                FROM pg_index i
                JOIN pg_attribute a ON a.attrelid = i.indrelid AND a.attnum = ANY(i.indkey)
                WHERE i.indrelid = TG_RELID AND i.indisprimary;

                IF v_pk_cols IS NOT NULL THEN
                    FOREACH v_col IN ARRAY v_pk_cols LOOP
                        IF TG_OP = 'DELETE' THEN
                            v_pk_values := array_append(v_pk_values, v_col || '=' || (to_jsonb(OLD) ->> v_col));
                        ELSE
                            v_pk_values := array_append(v_pk_values, v_col || '=' || (to_jsonb(NEW) ->> v_col));
                        END IF;
                    END LOOP;
                    v_row_pk := array_to_string(v_pk_values, ',');
                END IF;

                v_app_user_id := NULLIF(current_setting('app.current_user_id', true), '')::bigint;

                INSERT INTO {$auditTable}
                    (table_schema, table_name, operation, row_pk, old_data, new_data, db_role, app_user_id, occurred_at)
                VALUES (
                    TG_TABLE_SCHEMA,
                    TG_TABLE_NAME,
                    TG_OP,
                    v_row_pk,
                    CASE WHEN TG_OP IN ('UPDATE','DELETE') THEN to_jsonb(OLD) ELSE NULL END,
                    CASE WHEN TG_OP IN ('INSERT','UPDATE') THEN to_jsonb(NEW) ELSE NULL END,
                    session_user,
                    v_app_user_id,
                    now()
                );

                RETURN NULL;
            END;
            \$fn\$ LANGUAGE plpgsql;
            SQL);

        foreach (self::AUDITED_TABLES as $table) {
            $qualifiedTable = $this->table($table);
            $triggerName    = "trg_audit_{$table}";

            DB::statement(<<<SQL
                CREATE TRIGGER {$triggerName}
                AFTER INSERT OR UPDATE OR DELETE ON {$qualifiedTable}
                FOR EACH ROW EXECUTE FUNCTION {$function}()
                SQL);
        }
    }

    public function down(): void
    {
        foreach (self::AUDITED_TABLES as $table) {
            $qualifiedTable = $this->table($table);
            $triggerName    = "trg_audit_{$table}";
            DB::statement("DROP TRIGGER IF EXISTS {$triggerName} ON {$qualifiedTable}");
        }

        DB::statement("DROP FUNCTION IF EXISTS \"{$this->schema()}\".fn_audit_row_change()");
    }
};
