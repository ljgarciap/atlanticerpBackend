<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Controllers;

use App\Models\User;
use App\Modules\CRM\Models\Project;
use App\Modules\VentasDiseno\Models\Quote;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DemoCleanupController
{
    private const DEMO_EMAIL_DOMAIN = '@illuminations.test';
    private const DEMO_PROJECT_PREFIX = '[DEMO]';

    public function __invoke(Request $request): JsonResponse
    {
        $tenant = Tenant::current();

        if ($tenant === null) {
            return response()->json(['message' => 'No active tenant.'], 400);
        }

        $authSchema     = "{$tenant->slug}_auth";
        $userRolesTable = "{$authSchema}.user_roles";

        $deletedProjects     = 0;
        $deletedUsers        = 0;
        $deletedQuotes       = 0;
        $deletedRelatedRows  = 0;
        $nulledReferences    = 0;

        DB::transaction(function () use (
            $tenant,
            $authSchema,
            $userRolesTable,
            &$deletedProjects,
            &$deletedUsers,
            &$deletedQuotes,
            &$deletedRelatedRows,
            &$nulledReferences,
        ): void {
            // 1. Delete [DEMO] projects — cascade removes activities, reminders, assignees, documents
            $deletedProjects = Project::query()
                ->where('nombre', 'like', self::DEMO_PROJECT_PREFIX . '%')
                ->delete();

            // 2. Delete orphaned quote drafts — never generated a folio AND never got a
            // single real item. Found 2026-07-27 (SCRUM-105): QA/Pre-QA sessions leave
            // behind abandoned drafts (started "+ Nueva cotización", never finished) that
            // accumulate on dev/test and get mistaken for product bugs (empty $0 quotes,
            // quotes sitting in a stage before "Cotización"). A draft with real items but
            // no folio is left untouched — it may be in-progress work.
            //
            // Runs BEFORE demo user deletion on purpose: quotes.owner_id is ON DELETE
            // RESTRICT, and orphan drafts are almost always owned by a demo user — leaving
            // them in place would make every demo user who ever opened "+ Nueva cotización"
            // undeletable below.
            $deletedQuotes = Quote::query()
                ->whereNull('folio')
                ->whereDoesntHave('parts.items')
                ->delete();

            // 3. Demo users not owning a real (foliated) quote — quotes.owner_id is ON
            // DELETE RESTRICT, so a demo user with a real quote is skipped, not deleted.
            $demoUserIds = User::query()
                ->where('email', 'like', '%' . self::DEMO_EMAIL_DOMAIN)
                ->whereNotIn('id', Quote::query()->select('owner_id'))
                ->pluck('id');

            if ($demoUserIds->isEmpty()) {
                return;
            }

            // 4. Resolve every FK across every tenant schema that points at
            // {authSchema}.users(id) via information_schema instead of a hardcoded table
            // list. Found 2026-07-30: 11 Bodega/Compras tables added after this controller
            // was first written (customer_returns, adjustment_requests, relocation_requests,
            // general_count_requests, warehouse_assistant_mapping, order_stage_history, ...)
            // were never added here — the hardcoded-table approach silently rots with every
            // new module until a demo-owned row hits it and crashes the whole transaction.
            // Reading the DB's own FK graph means a module added tomorrow is covered without
            // touching this file again.
            $foreignKeys = DB::select(
                <<<'SQL'
                    SELECT
                        tc.table_schema,
                        tc.table_name,
                        kcu.column_name,
                        rc.delete_rule,
                        col.is_nullable
                    FROM information_schema.table_constraints tc
                    JOIN information_schema.key_column_usage kcu
                        ON tc.constraint_name = kcu.constraint_name AND tc.table_schema = kcu.table_schema
                    JOIN information_schema.referential_constraints rc
                        ON tc.constraint_name = rc.constraint_name AND tc.constraint_schema = rc.constraint_schema
                    JOIN information_schema.constraint_column_usage ccu
                        ON rc.unique_constraint_name = ccu.constraint_name AND rc.unique_constraint_schema = ccu.constraint_schema
                    JOIN information_schema.columns col
                        ON col.table_schema = tc.table_schema AND col.table_name = tc.table_name AND col.column_name = kcu.column_name
                    WHERE tc.constraint_type = 'FOREIGN KEY'
                        AND ccu.table_schema = ?
                        AND ccu.table_name = 'users'
                        AND tc.table_schema LIKE ?
                SQL,
                [$authSchema, "{$tenant->slug}_%"],
            );

            foreach ($foreignKeys as $fk) {
                // DB already handles it (SET NULL / CASCADE onDelete in the migration).
                if (! in_array($fk->delete_rule, ['NO ACTION', 'RESTRICT'], true)) {
                    continue;
                }

                $table = "{$fk->table_schema}.{$fk->table_name}";

                if ($fk->is_nullable === 'YES') {
                    // Who touched/assigned it — safe to clear without losing the row.
                    $nulledReferences += DB::table($table)
                        ->whereIn($fk->column_name, $demoUserIds)
                        ->update([$fk->column_name => null]);
                } else {
                    // NOT NULL owner — the row only exists because a demo user created it,
                    // so the whole row is demo data.
                    $deletedRelatedRows += DB::table($table)
                        ->whereIn($fk->column_name, $demoUserIds)
                        ->delete();
                }
            }

            // 5. Delete demo users and their legacy roles.
            DB::table($userRolesTable)->whereIn('user_id', $demoUserIds)->delete();
            $deletedUsers = User::query()->whereIn('id', $demoUserIds)->delete();
        });

        return response()->json([
            'message'              => 'Demo data removed successfully.',
            'deleted_projects'     => $deletedProjects,
            'deleted_users'        => $deletedUsers,
            'deleted_quotes'       => $deletedQuotes,
            'deleted_related_rows' => $deletedRelatedRows,
            'nulled_references'    => $nulledReferences,
        ]);
    }
}
