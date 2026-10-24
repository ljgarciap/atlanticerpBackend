<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Support\Facades\DB;

/**
 * Pre-QA 2026-09-14 (batch SCRUM-838/839/915/916) — CRÍTICO encontrado en `fn_audit_row_change()`
 * (2026_09_14_100001_create_db_audit_triggers.php): `to_jsonb(OLD)`/`to_jsonb(NEW)` vuelcan la fila
 * COMPLETA de `atlantic_auth.users` a `db_audit_log.old_data`/`new_data`, incluyendo
 * `password_hash` — exactamente lo que SCRUM-915 (`User::auditableAttributes()`) tuvo cuidado de
 * excluir del audit trail a nivel de ORM, pero el trigger de BD (que audita por fuera de
 * Eloquent, incluyendo cambios que bypasean el ORM) lo vuelve a exponer sin ningún filtro.
 *
 * Fix: restar las claves sensibles del jsonb antes de insertar (operador `-` de jsonb, no-op si
 * la clave no existe en la tabla — seguro de aplicar a las 11 tablas auditadas por igual, no solo
 * `users`). `remember_token` se incluye defensivamente aunque la columna no existe hoy en
 * `users` (ver App\Models\User::auditableAttributes(), mismo criterio ya aplicado ahí).
 *
 * CREATE OR REPLACE FUNCTION reemplaza la función ya creada por la migración anterior sin tocar
 * los triggers (que apuntan a la función por nombre, no por definición) — se puede aplicar tanto
 * en un ambiente que corre las migraciones desde cero como en uno donde
 * 2026_09_14_100001_create_db_audit_triggers ya corrió (dev.atlanticerp.ai).
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'auth';

    private const SENSITIVE_KEYS = ['password_hash', 'remember_token'];

    public function up(): void
    {
        $schema   = $this->schema();
        $function = "\"{$schema}\".fn_audit_row_change";

        $stripSensitiveOld = $this->stripKeysSql('OLD');
        $stripSensitiveNew = $this->stripKeysSql('NEW');

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

                INSERT INTO {$this->table('db_audit_log')}
                    (table_schema, table_name, operation, row_pk, old_data, new_data, db_role, app_user_id, occurred_at)
                VALUES (
                    TG_TABLE_SCHEMA,
                    TG_TABLE_NAME,
                    TG_OP,
                    v_row_pk,
                    CASE WHEN TG_OP IN ('UPDATE','DELETE') THEN {$stripSensitiveOld} ELSE NULL END,
                    CASE WHEN TG_OP IN ('INSERT','UPDATE') THEN {$stripSensitiveNew} ELSE NULL END,
                    session_user,
                    v_app_user_id,
                    now()
                );

                RETURN NULL;
            END;
            \$fn\$ LANGUAGE plpgsql;
            SQL);
    }

    public function down(): void
    {
        // No-op a propósito: revertir recrearía la función insegura (con password_hash expuesto).
        // Si alguna vez hace falta un rollback real de este archivo, restaurar manualmente desde
        // 2026_09_14_100001_create_db_audit_triggers::up().
    }

    private function stripKeysSql(string $record): string
    {
        $expr = "to_jsonb({$record})";
        foreach (self::SENSITIVE_KEYS as $key) {
            $expr .= " - '{$key}'";
        }

        return $expr;
    }
};
