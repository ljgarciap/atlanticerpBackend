<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Services;

use App\Modules\VentasDiseno\Models\Quote;
use Illuminate\Support\Facades\DB;

/**
 * SCRUM-912 — limpieza de las cotizaciones huérfanas que el bug de store() dejó
 * persistidas antes del fix (ver QuoteController::hasMeaningfulContent()): filas en
 * `quotes` sin folio (nunca "generadas") Y sin ninguna línea de producto/servicio
 * cargada (ningún QuoteItem en ninguna de sus QuoteParts) — el criterio que pidió el
 * ticket, textual.
 *
 * A propósito NO exige además "sin cliente/descripción/etc" — una cotización real en
 * progreso (cliente elegido, todavía sin ítems) matchea el mismo criterio literal.
 * Por eso `$olderThanHours` (default 24h) es una red de seguridad, no parte del
 * criterio de negocio: un Borrador real recién tocado no debería aparecer como
 * candidato en la misma sesión en la que alguien lo está llenando. Ajustable a 0
 * para auditar TODO lo que matchea el criterio sin importar antigüedad.
 *
 * Nunca borra nada por sí sola — ver OrphanQuoteCleanupCommand para el guard de
 * dry-run/--force (mismo patrón que TestDataCleanupService/TransversalTestDataCleanupService).
 */
final class OrphanQuoteCleanupService
{
    /**
     * Requiere que el tenant correcto ya esté activo (Tenant::makeCurrent()) — no lo
     * resuelve él mismo, esa es responsabilidad del command (mismo patrón que
     * TestDataCleanupService::buildReport()).
     *
     * @return array{
     *   older_than_hours: int,
     *   candidates: list<array{id: int, description: ?string, master_client_id: ?int, sub_client_id: ?int, created_at: string}>,
     * }
     */
    public function buildReport(int $olderThanHours = 24): array
    {
        $cutoff = now()->subHours($olderThanHours);

        $candidates = Quote::query()
            ->whereNull('folio')
            ->whereNull('confirmed_at')
            ->whereDoesntHave('parts.items')
            ->where('created_at', '<=', $cutoff)
            ->orderBy('created_at')
            ->get(['id', 'description', 'master_client_id', 'sub_client_id', 'created_at']);

        return [
            'older_than_hours' => $olderThanHours,
            'candidates' => array_values($candidates->map(fn (Quote $q): array => [
                'id'               => $q->id,
                'description'      => $q->description,
                'master_client_id' => $q->master_client_id,
                'sub_client_id'    => $q->sub_client_id,
                'created_at'       => $q->created_at->toIso8601String(),
            ])->all()),
        ];
    }

    /**
     * Borra exactamente los ids que ya aprobó buildReport() — no vuelve a calcular
     * candidatos (mismo patrón que TestDataCleanupService::execute()). cascadeOnDelete
     * en quote_parts/quote_items/quote_delivery_dates/quote_contacts (ver migraciones
     * de creación de cada tabla) se encarga de los hijos — ninguno tiene datos reales
     * de todas formas, porque el criterio de candidatos ya exige 0 items.
     *
     * @param array{candidates: list<array{id: int}>} $report
     */
    public function execute(array $report): int
    {
        $ids = array_column($report['candidates'], 'id');
        if ($ids === []) {
            return 0;
        }

        return DB::transaction(fn (): int => Quote::query()->whereIn('id', $ids)->delete());
    }
}
