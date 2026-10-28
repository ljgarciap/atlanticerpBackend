<?php

declare(strict_types=1);

namespace App\Modules\Auth\Services;

use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * SCRUM-901 — "Limpieza de datos de prueba" transversal: conservar ÚNICAMENTE la data de UN
 * usuario (`$keepUserEmail`, default Vendedor — flujos completos de principio a fin en todos los
 * módulos) y purgar la de cualquier otro usuario, en cualquier módulo (CRM, Ventas & Diseño,
 * Compras, Bodega, Servicios, Administración & Contabilidad).
 *
 * DISEÑO — deliberadamente distinto de `App\Modules\VentasDiseno\Services\TestDataCleanupService`
 * (SCRUM-735, que identifica candidatos por audit_logs + marcador de texto, acotado a un módulo):
 * acá el criterio es "¿de quién es el dueño?", así que se reusa el ESPÍRITU del mecanismo que
 * tenía `DemoCleanupController` (removido 2026-09-17, hallazgo lateral de SCRUM-927 — feature de
 * uso raro cuyo test de FKs vía `information_schema` era el más pesado de toda la suite) —
 * resolver TODAS las FK de CUALQUIER
 * tabla de CUALQUIER schema del tenant hacia `{tenant}_auth.users(id)`, en vez de una lista
 * hardcodeada de tablas por módulo (un módulo nuevo queda cubierto solo). Invertido respecto a
 * DemoCleanupController: ahí se pasaba una lista chica de usuarios demo a purgar; acá se purga
 * "todos menos uno". A diferencia de DemoCleanupController, acá la resolución de FKs usa
 * `pg_catalog` en vez de `information_schema.constraint_column_usage` — ver `foreignKeysTo()`
 * para el porqué (bug real de privilegios bajo el rol `atlanticerp_app`, encontrado corriendo este
 * comando contra test.atlanticerp.ai; probablemente afecta a DemoCleanupController también, no
 * corregido ahí por estar fuera de alcance de este ticket).
 *
 * DIFERENCIA CLAVE respecto a DemoCleanupController — este comando NUNCA borra usuarios (el
 * roster de AtlanticERP son cuentas reales de staff, no cuentas demo desechables, ver
 * `feedback_testing_uses_real_users_not_demo`/`project_roster_usuarios_reales_atlanticerp` en memoria):
 * solo limpia la DATA que esos usuarios generaron, dejando la cuenta intacta. Por eso `delete_rule`
 * (CASCADE/SET NULL/RESTRICT/NO ACTION) de la FK NO importa acá y nunca se filtra por él — esa
 * distinción solo tendría sentido si el padre (`users`) se fuera a borrar (ahí sí la BD resolvería
 * sola un CASCADE/SET NULL). Como el usuario nunca se borra, la única señal que importa es si la
 * COLUMNA de la fila hija es nullable o no:
 *
 * REGLAS:
 * 1. FK NOT NULL hacia un usuario a purgar → la fila ENTERA es candidata a DELETE (solo existe
 *    porque ese usuario la creó, y esa columna no puede quedar en NULL).
 * 2. FK NULLABLE hacia un usuario a purgar → candidata a poner esa columna en NULL (se conserva
 *    la fila — típicamente quién la tocó/asignó, no quién es su dueño real).
 * 3. GUARD DE REFERENCIA ACTIVA (nuevo respecto a DemoCleanupController, motivado por el hallazgo
 *    de investigación: un `Provider`/`Warehouse`/`CatalogProduct` de prueba puede estar
 *    referenciado por un pedido REAL de Vendedor) — antes de confirmar un DELETE candidato, se
 *    resuelve vía `information_schema` TODA FK que apunte a esa tabla (sin importar el módulo) y
 *    se verifica que ninguna fila SOBREVIVIENTE (no candidata a borrar) todavía la referencie. Si
 *    la referencia existe, el candidato se excluye (`active_reference`) en vez de borrarse — más
 *    conservador que romper la integridad de un flujo real.
 * 4. FUERA DE ALCANCE a propósito (documentado para Luis, no es un olvido): tablas de
 *    catálogo/configuración SIN ninguna FK directa a `users` (`CatalogProduct`, `Provider`,
 *    `Warehouse`, etc.) nunca aparecen como candidatas — este comando no tiene forma de saber si
 *    esas filas son "de prueba" o reales sin una marca explícita (a diferencia de SCRUM-735, que sí
 *    tiene marcadores de texto). Quedan intactas siempre; una limpieza de esas tablas requeriría un
 *    criterio aparte (ej. marcador de texto, o revisión manual) — no se resuelve con este comando.
 * 5. Contadores de folio (`*_folio_counters`, `tool_code_counters`) nunca aparecen como candidatos:
 *    su PK es `year` (o `code`), sin ninguna FK a `users` — confirmado en las migraciones, no
 *    necesitan un guard explícito en código.
 * 6. Tablas SIN columna `id` (pivots de clave compuesta, ej. `crm.project_assignees`,
 *    `auth.user_roles`, `gerencia.regla_aprobacion_aprobadores` — encontradas en vivo corriendo
 *    este comando contra dev: `pluck('id')` sobre esas tablas revienta con "column id does not
 *    exist") van a un bucket aparte (`no_pk_candidates`, solo COUNT, sin ids individuales) y
 *    NUNCA se tocan en `execute()` — no hay forma segura de identificar/filtrar filas
 *    individuales sin una PK propia ni de aplicarles el guard de referencia activa (nada
 *    referencia a una fila de pivot por su "id" porque no tiene). Requieren revisión manual;
 *    el reporte deja explícito cuántas filas hay y en qué tabla, no las esconde.
 *
 * `buildReport()` es SOLO LECTURA. `execute()` recibe el reporte YA construido y borra
 * exactamente eso, dentro de una transacción — ver TransversalCleanupTestDataCommand para el
 * guard de entorno (nunca corre en `production` salvo `--env-override`, y `--force` es la única
 * forma de llegar a `execute()`).
 */
final class TransversalTestDataCleanupService
{
    /**
     * @return array{
     *   tenant: string,
     *   keep_user: array{id: int, email: string},
     *   other_users_count: int,
     *   delete_candidates: list<array{table: string, column: string, count: int, ids: list<int>}>,
     *   null_candidates: list<array{table: string, column: string, count: int, ids: list<int>}>,
     *   no_pk_candidates: list<array{table: string, column: string, count: int, nullable: bool}>,
     *   excluded: list<array{table: string, id: int, reason: string, referenced_by: string}>,
     * }
     */
    public function buildReport(string $tenantSlug, string $keepUserEmail): array
    {
        $tenant = Tenant::where('slug', $tenantSlug)->first();
        if ($tenant === null) {
            throw new RuntimeException("Tenant '{$tenantSlug}' no existe.");
        }

        $authSchema = "{$tenant->slug}_auth";

        $keepUserId = DB::table("{$authSchema}.users")->where('email', $keepUserEmail)->value('id');
        if ($keepUserId === null) {
            throw new RuntimeException("Usuario a conservar '{$keepUserEmail}' no existe en el tenant '{$tenantSlug}'.");
        }
        $keepUserId = (int) $keepUserId;

        $purgeUserIds = DB::table("{$authSchema}.users")
            ->where('id', '!=', $keepUserId)
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        if ($purgeUserIds === []) {
            return [
                'tenant'            => $tenantSlug,
                'keep_user'         => ['id' => $keepUserId, 'email' => $keepUserEmail],
                'other_users_count' => 0,
                'delete_candidates' => [],
                'null_candidates'   => [],
                'no_pk_candidates'  => [],
                'excluded'          => [],
            ];
        }

        $userForeignKeys = $this->foreignKeysTo($authSchema, 'users', $tenant->slug);

        /** @var list<array{table: string, column: string, ids: list<int>}> $rawDeleteCandidates */
        $rawDeleteCandidates = [];
        /** @var list<array{table: string, column: string, count: int, ids: list<int>}> $nullCandidates */
        $nullCandidates = [];
        /** @var list<array{table: string, column: string, count: int, nullable: bool}> $noPkCandidates */
        $noPkCandidates = [];

        foreach ($userForeignKeys as $fk) {
            // `delete_rule` (CASCADE/SET NULL/RESTRICT/NO ACTION) NO se filtra acá a propósito —
            // ver docblock de la clase: solo importaría si el usuario padre se fuera a borrar, y
            // este comando nunca borra usuarios. Lo único relevante es si la columna es nullable.
            $table = "{$fk->table_schema}.{$fk->table_name}";

            // Regla #7 — `{tenant}_auth.audit_logs` es la ÚNICA tabla con UPDATE/DELETE
            // revocado para `atlanticerp_app` a nivel de Postgres (GrantAppRoleCommand::
            // revokeAuditLogsWrite(), REQ-634/SCRUM-810) — inmutable a propósito, es la
            // evidencia real de auditoría. Ni siquiera vale la pena reportarla como candidata:
            // conservar el `user_id` histórico ahí es lo correcto (el usuario nunca se borra),
            // no un pendiente de limpieza. Encontrado en vivo: execute() intentaba el UPDATE,
            // Postgres lo rechazaba con "permission denied", toda la transacción hacía
            // rollback (verificado — ningún otro null/delete de esa corrida quedó a medias),
            // pero igual bloqueaba por completo cualquier corrida real con --force.
            if ($table === "{$authSchema}.audit_logs") {
                continue;
            }

            $query = DB::table($table)->whereIn($fk->column_name, $purgeUserIds);

            // Regla #6 — pivots de clave compuesta (sin "id") no se pueden identificar fila por
            // fila de forma segura; van a un bucket aparte, nunca a execute().
            if (! $this->hasIdColumn($fk->table_schema, $fk->table_name)) {
                $count = $query->count();
                if ($count > 0) {
                    $noPkCandidates[] = ['table' => $table, 'column' => $fk->column_name, 'count' => $count, 'nullable' => $fk->is_nullable === 'YES'];
                }
                continue;
            }

            $ids = array_values($query
                ->pluck('id')
                ->map(fn (mixed $id): int => (int) $id)
                ->all());

            if ($ids === []) {
                continue;
            }

            if ($fk->is_nullable === 'YES') {
                $nullCandidates[] = ['table' => $table, 'column' => $fk->column_name, 'count' => count($ids), 'ids' => $ids];
            } else {
                $rawDeleteCandidates[] = ['table' => $table, 'column' => $fk->column_name, 'ids' => $ids];
            }
        }

        // Guard de referencia activa (regla #3) — se resuelve tabla por tabla porque el universo
        // de "sobrevivientes" de una tabla depende de sus propios candidatos a delete, no de los
        // de otra.
        /** @var list<array{table: string, column: string, count: int, ids: list<int>}> $deleteCandidates */
        $deleteCandidates = [];
        /** @var list<array{table: string, id: int, reason: string, referenced_by: string}> $excluded */
        $excluded = [];

        foreach ($rawDeleteCandidates as $candidate) {
            [$schema, $tableName] = explode('.', $candidate['table'], 2);
            $referencingFks = $this->foreignKeysTo($schema, $tableName, $tenant->slug);

            $survivorIds = $candidate['ids'];
            $blockedIds  = [];

            foreach ($referencingFks as $refFk) {
                // CASCADE/SET NULL/SET DEFAULT ya resuelven solos al borrar el candidato — no es
                // una referencia "activa" en el sentido que este guard le importa, es la relación
                // normal padre-hijo del propio candidato (ej. quote_parts.quote_id CASCADE). Solo
                // RESTRICT/NO ACTION representan un riesgo real de romper una fila sobreviviente.
                if (! in_array($refFk->delete_rule_code, ['a', 'r'], true)) {
                    continue;
                }

                $refTable = "{$refFk->table_schema}.{$refFk->table_name}";

                $referencingRows = DB::table($refTable)
                    ->whereIn($refFk->column_name, $candidate['ids'])
                    ->pluck($refFk->column_name)
                    ->map(fn (mixed $id): int => (int) $id)
                    ->all();

                foreach ($referencingRows as $referencedId) {
                    $blockedIds[$referencedId] = $refTable;
                }
            }

            $survivorIds = array_values(array_diff($candidate['ids'], array_keys($blockedIds)));

            foreach ($blockedIds as $id => $referencingTable) {
                $excluded[] = [
                    'table'         => $candidate['table'],
                    'id'            => $id,
                    'reason'        => 'active_reference',
                    'referenced_by' => $referencingTable,
                ];
            }

            if ($survivorIds !== []) {
                $deleteCandidates[] = [
                    'table'  => $candidate['table'],
                    'column' => $candidate['column'],
                    'count'  => count($survivorIds),
                    'ids'    => $survivorIds,
                ];
            }
        }

        return [
            'tenant'            => $tenantSlug,
            'keep_user'         => ['id' => $keepUserId, 'email' => $keepUserEmail],
            'other_users_count' => count($purgeUserIds),
            'delete_candidates' => $deleteCandidates,
            'null_candidates'   => $nullCandidates,
            'no_pk_candidates'  => $noPkCandidates,
            'excluded'          => $excluded,
        ];
    }

    /**
     * Ejecuta el borrado/null-out real, EXACTAMENTE el reporte ya construido — no vuelve a
     * calcular nada. Null-out primero (nunca puede fallar por FK), delete después.
     *
     * @param array{
     *   delete_candidates: list<array{table: string, column: string, count: int, ids: list<int>}>,
     *   null_candidates: list<array{table: string, column: string, count: int, ids: list<int>}>,
     * } $report
     * @return array{nulled: int, deleted: int}
     */
    public function execute(array $report): array
    {
        $nulled  = 0;
        $deleted = 0;

        DB::transaction(function () use ($report, &$nulled, &$deleted): void {
            foreach ($report['null_candidates'] as $candidate) {
                $nulled += DB::table($candidate['table'])
                    ->whereIn('id', $candidate['ids'])
                    ->update([$candidate['column'] => null]);
            }

            foreach ($report['delete_candidates'] as $candidate) {
                $deleted += DB::table($candidate['table'])
                    ->whereIn('id', $candidate['ids'])
                    ->delete();
            }
        });

        return ['nulled' => $nulled, 'deleted' => $deleted];
    }

    /** Regla #6 — algunos pivots de clave compuesta no tienen columna `id`. */
    private function hasIdColumn(string $schema, string $table): bool
    {
        return DB::table('information_schema.columns')
            ->where('table_schema', $schema)
            ->where('table_name', $table)
            ->where('column_name', 'id')
            ->exists();
    }

    /**
     * Toda FK (de cualquier schema del tenant) que apunte a `{$schema}.{$table}(id)`.
     *
     * DELIBERADAMENTE via `pg_catalog` (`pg_constraint`/`pg_class`/`pg_attribute`/`pg_namespace`),
     * NO `information_schema.constraint_column_usage` (como usa `DemoCleanupController` — mismo
     * bug ahí, no corregido en este batch por estar fuera de alcance de este ticket). Encontrado
     * corriendo este comando contra test.atlanticerp.ai (rol de conexión `atlanticerp_app`, sin superuser
     * desde REQ-634/SCRUM-810): `constraint_column_usage` exige privilegios sobre la tabla
     * REFERENCIADA que un rol de aplicación sin ownership no tiene — bajo `atlanticerp_app` la vista
     * devuelve CERO filas para CUALQUIER FK, sin error, así que el comando reportaba "nada para
     * limpiar" con 194 pipeline_cards y 163 quotes reales de otros usuarios sentados ahí. Bajo un
     * superuser local (dev normal) el bug es invisible — solo se manifestó corriendo el dry-run
     * real contra un entorno desplegado. Las tablas de `pg_catalog` son catálogos del sistema,
     * legibles sin grants especiales — no tienen este problema.
     *
     * Asume FK de una sola columna (`con.conkey[1]`) — cierto para toda FK de este proyecto
     * (siempre `foreignId()->constrained()` de Laravel, nunca compuesta).
     *
     * `delete_rule_code` (`pg_constraint.confdeltype`: a=NO ACTION, r=RESTRICT, c=CASCADE,
     * n=SET NULL, d=SET DEFAULT) — SOLO lo usa el guard de referencia activa (regla #3), para
     * distinguir una FK hija normal que cascadea sola (ej. `quote_parts.quote_id` CASCADE — borrar
     * el quote se lleva sus partes sin romper nada) de una referencia genuinamente externa que sí
     * bloquearía el borrado (RESTRICT/NO ACTION). El resto del método (candidatos NOT NULL/
     * NULLABLE hacia `users`) lo ignora a propósito — ver docblock de la clase.
     *
     * @return list<object{table_schema: string, table_name: string, column_name: string, is_nullable: string, delete_rule_code: string}>
     */
    private function foreignKeysTo(string $referencedSchema, string $referencedTable, string $tenantSlug): array
    {
        return array_values(DB::select(
            <<<'SQL'
                SELECT
                    src_ns.nspname AS table_schema,
                    src_cls.relname AS table_name,
                    src_att.attname AS column_name,
                    CASE WHEN src_att.attnotnull THEN 'NO' ELSE 'YES' END AS is_nullable,
                    con.confdeltype AS delete_rule_code
                FROM pg_constraint con
                JOIN pg_class src_cls ON src_cls.oid = con.conrelid
                JOIN pg_namespace src_ns ON src_ns.oid = src_cls.relnamespace
                JOIN pg_attribute src_att ON src_att.attrelid = con.conrelid AND src_att.attnum = con.conkey[1]
                JOIN pg_class ref_cls ON ref_cls.oid = con.confrelid
                JOIN pg_namespace ref_ns ON ref_ns.oid = ref_cls.relnamespace
                WHERE con.contype = 'f'
                    AND ref_ns.nspname = ?
                    AND ref_cls.relname = ?
                    AND src_ns.nspname LIKE ?
            SQL,
            [$referencedSchema, $referencedTable, "{$tenantSlug}_%"],
        ));
    }
}
