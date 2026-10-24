<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\VentasDiseno\Models\PipelineCard;
use App\Modules\VentasDiseno\Models\Quote;
use App\Modules\VentasDiseno\Services\QuoteContactCardSyncService;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Console\Command;

/**
 * SCRUM-1121 — relleno de los contactos de las cotizaciones YA confirmadas hacia el campo «Contacto» de su tarjeta de Pipeline, para las
 * confirmadas antes del fix (las nuevas ya se copian al confirmar, ver `QuoteContactCardSyncService`).
 *
 * Misma regla que el fix: SOLO AGREGA (nunca borra ni modifica un contacto de la tarjeta) y no duplica. Idempotente: una segunda corrida no
 * encuentra nada que agregar.
 */
final class VentasDisenoBackfillCardContactsCommand extends Command
{
    /** @var string */
    protected $signature = 'ventas-diseno:backfill-card-contacts
        {--tenant=atlantic : Slug del tenant a corregir}
        {--dry-run : Solo reporta lo que agregaría, sin escribir}';

    /** @var string */
    protected $description = 'SCRUM-1121 — copia a la tarjeta de Pipeline los contactos de las cotizaciones confirmadas que aún no están (solo agrega, sin duplicar)';

    public function __construct(private readonly QuoteContactCardSyncService $sync)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $tenantOption = $this->option('tenant');
        $tenantSlug   = is_string($tenantOption) ? $tenantOption : 'atlantic';
        $tenant = Tenant::where('slug', $tenantSlug)->first();
        if ($tenant === null) {
            $this->error("Tenant '{$tenantSlug}' no existe.");

            return self::FAILURE;
        }

        Tenant::forgetCurrent();
        $tenant->makeCurrent();

        $dryRun = (bool) $this->option('dry-run');
        $quotesSeen = 0;
        $contactsAdded = 0;

        Quote::query()
            ->whereNotNull('confirmed_at')
            ->whereNotNull('sales_project_id')
            ->with('contacts')
            ->orderBy('id')
            ->chunkById(200, function ($quotes) use ($dryRun, &$quotesSeen, &$contactsAdded): void {
                /** @var Quote $quote */
                foreach ($quotes as $quote) {
                    if ($quote->contacts->isEmpty()) {
                        continue;
                    }
                    $card = PipelineCard::where('sales_project_id', $quote->sales_project_id)->first();
                    if ($card === null) {
                        continue;
                    }
                    $quotesSeen++;

                    $contactsAdded += $dryRun ? count($this->sync->missing($quote, $card)) : $this->sync->sync($quote, $card);
                }
            });

        $this->info(($dryRun ? 'Dry-run — ' : '')."cotizaciones revisadas: {$quotesSeen}; contactos ".($dryRun ? 'que se agregarían' : 'agregados').": {$contactsAdded}.");

        return self::SUCCESS;
    }
}
