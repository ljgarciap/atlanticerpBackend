<?php

declare(strict_types=1);

namespace Tests\Feature\VentasDiseno;

use App\Models\User;
use App\Modules\VentasDiseno\Models\Quote;
use App\Modules\VentasDiseno\Models\QuotePart;
use App\Modules\VentasDiseno\Services\OrphanQuoteCleanupService;
use App\Shared\Multitenancy\Tenant;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-912 (criterio 4) — VentasDisenoCleanupOrphanQuotesCommand delega toda la lógica de
 * negocio en este servicio (el command solo agrega el guard de dry-run/--force, ver su
 * docblock) — se testea el servicio directo, sin pasar por Artisan.
 */
class OrphanQuoteCleanupServiceTest extends FeatureTestCase
{
    private int $ownerId;

    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $this->ownerId = (int) User::where('email', 'designer@atlantic.test')->value('id');
    }

    private function backdate(Quote $quote, int $hoursAgo): void
    {
        // created_at no es fillable (ver Quote::$fillable) — se ajusta post-creación,
        // desactivando timestamps para que save() no lo pise con now().
        $quote->timestamps = false;
        $quote->created_at = now()->subHours($hoursAgo);
        $quote->save();
    }

    public function test_build_report_identifica_solo_huerfanas_viejas_sin_folio_y_sin_items(): void
    {
        // Candidata real: sin folio, sin confirmar, sin items, con más de 24h de antigüedad.
        $orphanOld = Quote::create(['owner_id' => $this->ownerId, 'description' => 'Huérfana vieja']);
        $this->backdate($orphanOld, 48);

        // No candidata: misma forma, pero recién creada (red de seguridad older_than_hours).
        $orphanRecent = Quote::create(['owner_id' => $this->ownerId, 'description' => 'Huérfana reciente']);

        // No candidata: ya confirmada (tiene folio).
        $confirmed = Quote::create(['owner_id' => $this->ownerId, 'description' => 'Confirmada', 'folio' => 'COT-2026-0099', 'confirmed_at' => now()]);
        $this->backdate($confirmed, 48);

        // No candidata: sin folio, pero con al menos un ítem cargado (borrador real en progreso).
        $withItems = Quote::create(['owner_id' => $this->ownerId, 'description' => 'Borrador real con items']);
        $this->backdate($withItems, 48);
        $part = QuotePart::create(['quote_id' => $withItems->id, 'name' => 'Partida 1', 'position' => 1]);
        $part->items()->create([
            'catalog_product_id' => null, 'is_custom' => true, 'reference' => 'CUSTOM-1',
            'name' => 'Ítem de prueba', 'description' => 'Ítem de prueba', 'quantity' => 1, 'unit_price' => 10,
        ]);

        $service = app(OrphanQuoteCleanupService::class);
        $report  = $service->buildReport(olderThanHours: 24);

        $candidateIds = array_column($report['candidates'], 'id');
        $this->assertSame([$orphanOld->id], $candidateIds);
        $this->assertSame(24, $report['older_than_hours']);
    }

    public function test_execute_borra_unicamente_los_ids_del_reporte(): void
    {
        $orphanOld = Quote::create(['owner_id' => $this->ownerId, 'description' => 'Huérfana vieja']);
        $this->backdate($orphanOld, 48);

        $confirmed = Quote::create(['owner_id' => $this->ownerId, 'description' => 'Confirmada', 'folio' => 'COT-2026-0100', 'confirmed_at' => now()]);
        $this->backdate($confirmed, 48);

        $service = app(OrphanQuoteCleanupService::class);
        $report  = $service->buildReport(olderThanHours: 24);
        $deleted = $service->execute($report);

        $this->assertSame(1, $deleted);
        $this->assertNull(Quote::find($orphanOld->id));
        $this->assertNotNull(Quote::find($confirmed->id));
    }

    public function test_execute_no_borra_nada_si_el_reporte_viene_vacio(): void
    {
        $confirmed = Quote::create(['owner_id' => $this->ownerId, 'description' => 'Confirmada', 'folio' => 'COT-2026-0101', 'confirmed_at' => now()]);
        $this->backdate($confirmed, 48);

        $service = app(OrphanQuoteCleanupService::class);
        $deleted = $service->execute(['candidates' => []]);

        $this->assertSame(0, $deleted);
        $this->assertNotNull(Quote::find($confirmed->id));
    }
}
