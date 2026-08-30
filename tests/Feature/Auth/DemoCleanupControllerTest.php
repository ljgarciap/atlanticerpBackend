<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Modules\VentasDiseno\Models\Quote;
use App\Modules\VentasDiseno\Models\QuoteItem;
use App\Modules\VentasDiseno\Models\QuotePart;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-105 (2026-07-27) — el endpoint de limpieza de demo ahora también barre borradores
 * de cotización huérfanos (nunca generaron folio, nunca tuvieron un ítem real). Ver
 * memory/project_sesion_20260727_analisis_tiempos_calidad.md.
 */
class DemoCleanupControllerTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    private function userIdByEmail(string $email): int
    {
        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');

        return (int) DB::table("{$slug}_auth.users")->where('email', $email)->value('id');
    }

    public function test_deletes_orphan_quote_drafts_without_folio_or_items(): void
    {
        $ownerId = $this->userIdByEmail('designer@atlantic.test');

        $orphan = Quote::create(['owner_id' => $ownerId]);

        $token    = $this->loginAs('superadmin@atlantic.test', 'Password123!', 'superadmin');
        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/admin/demo/cleanup');

        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $response->assertOk();
        $response->assertJsonPath('deleted_quotes', 1);
        $this->assertNull(Quote::find($orphan->id));
        // no real quote left referencing designer@atlantic.test — safe to delete too
        $this->assertSame(0, $this->userIdByEmail('designer@atlantic.test'));
    }

    /**
     * quotes.owner_id is ON DELETE RESTRICT — before this change, a demo user owning a
     * real (foliated) quote would crash the whole cleanup transaction. Confirms the guard
     * added alongside the orphan-draft cleanup: the user is skipped, not deleted.
     */
    public function test_keeps_quotes_with_a_folio_and_their_owner(): void
    {
        $ownerId = $this->userIdByEmail('designer@atlantic.test');

        $generated = Quote::create(['owner_id' => $ownerId, 'folio' => 'COT-2026-TEST1']);

        $token    = $this->loginAs('superadmin@atlantic.test', 'Password123!', 'superadmin');
        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/admin/demo/cleanup');

        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $response->assertOk();
        $response->assertJsonPath('deleted_quotes', 0);
        $this->assertNotNull(Quote::find($generated->id));
        $this->assertSame($ownerId, $this->userIdByEmail('designer@atlantic.test'));
    }

    /**
     * Regression for 2026-07-30: DemoCleanupController hardcoded which tables to sweep
     * before deleting demo users. 11 Bodega/Compras tables added since (customer_returns,
     * adjustment_requests, warehouse_assistant_mapping, ...) were never added to that list —
     * a demo user owning any row in one of them crashed the whole transaction with a FK
     * violation the first time it was actually hit. The fix walks information_schema for
     * every FK pointing at users(id) instead of a fixed table list. This table is the
     * simplest of the 11 to set up (no other required FKs besides the two user columns,
     * both NOT NULL, no nullOnDelete) — enough to prove the generic sweep works end to end.
     */
    public function test_deletes_rows_owned_by_a_demo_user_in_tables_added_after_the_hardcoded_list(): void
    {
        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');
        $sellerId = $this->userIdByEmail('designer@atlantic.test');
        $assistantId = $this->userIdByEmail('electrician@atlantic.test');

        $mappingId = DB::table("{$slug}_bodega.warehouse_assistant_mapping")->insertGetId([
            'seller_user_id'    => $sellerId,
            'assistant_user_id' => $assistantId,
            'is_active'         => true,
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);

        $token    = $this->loginAs('superadmin@atlantic.test', 'Password123!', 'superadmin');
        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/admin/demo/cleanup');

        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $response->assertOk();
        $this->assertNull(DB::table("{$slug}_bodega.warehouse_assistant_mapping")->find($mappingId));
        $this->assertSame(0, $this->userIdByEmail('designer@atlantic.test'));
        $this->assertSame(0, $this->userIdByEmail('electrician@atlantic.test'));
    }

    public function test_keeps_folioless_drafts_that_have_a_real_item_and_their_owner(): void
    {
        $ownerId = $this->userIdByEmail('designer@atlantic.test');

        $inProgress = Quote::create(['owner_id' => $ownerId]);
        $part       = QuotePart::create(['quote_id' => $inProgress->id, 'name' => 'Partida 1', 'position' => 1]);
        QuoteItem::create([
            'quote_part_id' => $part->id,
            'is_custom'     => true,
            'reference'     => 'REF-1',
            'description'   => 'Item de prueba',
            'quantity'      => 1,
            'unit_price'    => 100,
        ]);

        $token    = $this->loginAs('superadmin@atlantic.test', 'Password123!', 'superadmin');
        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/admin/demo/cleanup');

        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $response->assertOk();
        $response->assertJsonPath('deleted_quotes', 0);
        $this->assertNotNull(Quote::find($inProgress->id));
        $this->assertSame($ownerId, $this->userIdByEmail('designer@atlantic.test'));
    }
}
