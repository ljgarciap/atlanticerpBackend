<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Modules\VentasDiseno\Models\MasterClient;
use App\Modules\VentasDiseno\Models\PipelineCard;
use App\Modules\VentasDiseno\Models\PipelineCardFile;
use App\Modules\VentasDiseno\Models\Quote;
use App\Modules\VentasDiseno\Models\QuoteItem;
use App\Modules\VentasDiseno\Models\QuotePart;
use App\Modules\VentasDiseno\Models\SalesGoal;
use App\Modules\VentasDiseno\Models\SalesProject;
use App\Modules\VentasDiseno\Models\SubClient;
use App\Modules\VentasDiseno\Models\SubClientContact;
use App\Modules\VentasDiseno\Services\QuoteFolioService;
use App\Shared\Multitenancy\Tenant;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * SCRUM-711 §6 — data de demo comercial (pipeline, metas, cotizaciones) para
 * los 10 vendedor_disenador reales sembrados por BusinessRoleUserSeeder.
 * Absorbe y reemplaza el seed ad-hoc de SCRUM-499 (13 tarjetas + 1 meta sobre
 * un usuario "Designer Demo" genérico) — retirado acá antes de sembrar de
 * nuevo sobre los vendedores reales.
 *
 * NO registrado en DatabaseSeeder::run(): igual que QaTicketsBatch20260725Seeder,
 * este tipo de data de demo está gateado a local/dev/testing en el bloque de
 * DatabaseSeeder (ver AuthUserSeeder/CrmDemoSeeder/VentasDisenoDemoSeeder) y
 * nunca corre en qa/production — pero este ticket pide exactamente esa data en
 * test.atlanticerp.ai (APP_ENV=qa). Se corre a mano por entorno:
 *
 *   php artisan tinker --execute="(new \Database\Seeders\Scrum711SalesForceDemoSeeder)->run();"
 */
class Scrum711SalesForceDemoSeeder extends Seeder
{
    private const MARKER = '[DEMO-711]';

    /** @var list<string> */
    private const VENDOR_EMAILS = [
        'milena.e@grupolafayette.com',
        'neil.quiel@illuminations.com.pa',
        'fefi@illuminations.com.pa',
        'vanessa@illuminations.com.pa',
        'paola@illuminations.com.pa',
        'annie@illuminations.com.pa',
        'maribel@illuminations.com.pa',
        'juanmanuel@illuminations.com.pa',
        'bernardo@illuminations.com.pa',
        'idmar@illuminations.com.pa',
    ];

    /** Primer vendedor de la lista — cumple "3 meses sin superar $20,000" (REQ-075). */
    private const LOW_PERFORMER_EMAIL = 'milena.e@grupolafayette.com';

    /**
     * Plan de tarjetas por vendedor (índice de VENDOR_EMAILS) — [stage, monthsAgo, stagnant].
     * `monthsAgo` solo importa para 'approved' (pobla el historial de Metas); el resto usa
     * offsets cortos en días. `stagnant` fuerza stage_changed_at a >15 días.
     *
     * @var array<int, list<array{stage: string, monthsAgo?: int, stagnant?: bool}>>
     */
    private const PIPELINE_PLAN = [
        // 0 Milena — bajo desempeño: 3 meses consecutivos de Aprobado bajo $20,000.
        0 => [
            ['stage' => 'lead'],
            ['stage' => 'design', 'stagnant' => true],
            ['stage' => 'approved', 'monthsAgo' => 0],
            ['stage' => 'approved', 'monthsAgo' => 1],
            ['stage' => 'approved', 'monthsAgo' => 2],
        ],
        1 => [
            ['stage' => 'lead'], ['stage' => 'design'], ['stage' => 'quote'],
            ['stage' => 'proposal'], ['stage' => 'approved', 'monthsAgo' => 0],
            ['stage' => 'approved', 'monthsAgo' => 3], ['stage' => 'lost'],
        ],
        2 => [
            ['stage' => 'quote'],
            ['stage' => 'proposal', 'stagnant' => true],
            ['stage' => 'approved', 'monthsAgo' => 0],
        ],
        3 => [
            ['stage' => 'lead'], ['stage' => 'lead'], ['stage' => 'design'], ['stage' => 'quote'],
            ['stage' => 'proposal'], ['stage' => 'approved', 'monthsAgo' => 0],
            ['stage' => 'approved', 'monthsAgo' => 2], ['stage' => 'lost'],
        ],
        4 => [
            ['stage' => 'proposal'],
            ['stage' => 'approved', 'monthsAgo' => 0],
        ],
        5 => [
            ['stage' => 'lead'], ['stage' => 'lead'], ['stage' => 'design'], ['stage' => 'design'],
            ['stage' => 'quote'], ['stage' => 'quote'], ['stage' => 'proposal'], ['stage' => 'proposal'],
            ['stage' => 'approved', 'monthsAgo' => 1], ['stage' => 'lost'],
        ],
        6 => [
            ['stage' => 'design', 'stagnant' => true], ['stage' => 'quote'], ['stage' => 'proposal'],
            ['stage' => 'approved', 'monthsAgo' => 0], ['stage' => 'lost'],
        ],
        7 => [
            ['stage' => 'lead'], ['stage' => 'quote'],
            ['stage' => 'approved', 'monthsAgo' => 0], ['stage' => 'lost'],
        ],
        8 => [
            ['stage' => 'lead'], ['stage' => 'design'], ['stage' => 'quote', 'stagnant' => true],
            ['stage' => 'proposal'], ['stage' => 'approved', 'monthsAgo' => 0],
            ['stage' => 'approved', 'monthsAgo' => 4],
        ],
        9 => [
            ['stage' => 'lead'], ['stage' => 'design'], ['stage' => 'quote'],
            ['stage' => 'proposal', 'stagnant' => true], ['stage' => 'approved', 'monthsAgo' => 0],
            ['stage' => 'approved', 'monthsAgo' => 5], ['stage' => 'lost'],
        ],
    ];

    /**
     * Monto por tarjeta 'approved', alineado a la tabla escalonada de comisión
     * (SalesTotalsService::percentFor(): <20k=1.5%, <35k=2%, <60k=3%, <80k=4%,
     * <120k=4.5%, resto=5%) — cada vendedor cae en un escalón distinto.
     *
     * @var array<int, list<float>>
     */
    private const APPROVED_AMOUNTS = [
        0 => [8000.0, 12000.0, 15000.0],   // bajo $20k los 3 meses (REQ-075)
        1 => [28000.0, 31000.0],
        2 => [45000.0],
        3 => [72000.0, 90000.0],
        4 => [18500.0],
        5 => [55000.0],
        6 => [95000.0],
        7 => [21000.0],
        8 => [33000.0, 27000.0],
        9 => [110000.0, 60000.0],
    ];

    public function run(): void
    {
        $tenant = Tenant::where('slug', 'illuminations')->first();
        if ($tenant === null) {
            $this->command?->warn('Tenant "illuminations" not found — Scrum711SalesForceDemoSeeder skipped.');
            return;
        }

        $tenant->makeCurrent();
        try {
            $this->seed($tenant->slug);
        } finally {
            Tenant::forgetCurrent();
        }
    }

    private function seed(string $slug): void
    {
        if (MasterClient::where('name', 'like', self::MARKER . '%')->exists()) {
            $this->command?->info('Scrum711SalesForceDemoSeeder ya corrió en este entorno — skip.');
            return;
        }

        $this->retireScrum499DesignerDemoSeed($slug);

        $vendors = DB::table("{$slug}_auth.users")
            ->whereIn('email', self::VENDOR_EMAILS)
            ->select('id', 'email')
            ->get();

        $missing = array_diff(self::VENDOR_EMAILS, $vendors->pluck('email')->all());
        if ($missing !== []) {
            $this->command?->warn('Faltan vendedores (correr BusinessRoleUserSeeder primero): ' . implode(', ', $missing));
            return;
        }

        $catalogIds = CatalogProduct::pluck('id');
        $folioService = app(QuoteFolioService::class);
        $now = Carbon::now();

        foreach ($vendors as $row) {
            $vendorIndex = array_search($row->email, self::VENDOR_EMAILS, true);
            $ownerId     = (int) $row->id;
            $isLowPerformer = $row->email === self::LOW_PERFORMER_EMAIL;
            $slugName    = explode('@', $row->email)[0];

            SalesGoal::create([
                'user_id'          => $ownerId,
                'goal_amount'      => $isLowPerformer ? 15000 : (25000 + $vendorIndex * 2000),
                'goal_plus_amount' => $isLowPerformer ? 20000 : (32000 + $vendorIndex * 2500),
            ]);

            $master = MasterClient::create([
                'name'                => self::MARKER . ' Cliente de ' . ucfirst($slugName),
                'default_price_type'  => 'project',
            ]);
            $sub = SubClient::create([
                'master_client_id' => $master->id,
                'business_name'    => self::MARKER . ' ' . ucfirst($slugName) . ' S.A.',
                'tax_id'           => sprintf('155-%04d-1-2026', 1000 + $vendorIndex),
                'category'         => 'b_architect_designer',
            ]);

            // Sin esto, PipelineCard::validationErrorForTransitionTo() bloquea el botón
            // "avanzar" de TODA tarjeta en Lead con "Falta al menos 1 contacto" (hallazgo
            // real de Pre-QA en SCRUM-716: ninguna tarjeta demo podía moverse de etapa
            // porque el seed nunca cargaba contactos de Subcliente).
            $contact = SubClientContact::create([
                'sub_client_id' => $sub->id,
                'name'          => 'Contacto ' . ucfirst($slugName),
                'role'          => 'client',
                'phone'         => sprintf('+507 6%03d-%04d', 100 + $vendorIndex, 1000 + $vendorIndex),
                'email'         => $slugName . '.contacto@' . $slugName . '-demo.test',
            ]);

            $approvedAmounts = self::APPROVED_AMOUNTS[$vendorIndex];
            $approvedCursor  = 0;

            foreach (self::PIPELINE_PLAN[$vendorIndex] as $i => $plan) {
                $stage = $plan['stage'];

                if ($stage === 'approved') {
                    $amount = $approvedAmounts[$approvedCursor] ?? end($approvedAmounts);
                    $approvedCursor++;
                    $monthsAgo = $plan['monthsAgo'] ?? 0;
                    $stageChangedAt = $now->copy()->subMonths($monthsAgo)->startOfMonth()->addDays(random_int(1, 20));
                } elseif ($stage === 'lost') {
                    $amount = round(random_int(10, 60) * 1000 + random_int(0, 99) * 10, 2);
                    $stageChangedAt = $now->copy()->subDays(random_int(5, 15));
                } else {
                    $amount = null;
                    $stageChangedAt = ($plan['stagnant'] ?? false)
                        ? $now->copy()->subDays(random_int(20, 40))
                        : $now->copy()->subDays(random_int(1, 10));
                }

                // Un SalesProject propio por tarjeta (no compartido entre las 2-10 del
                // vendedor): QuoteListService::list() resuelve la etapa de una cotización
                // haciendo `PipelineCard::whereIn('sales_project_id', ...)->keyBy('sales_project_id')`
                // -- si varias tarjetas comparten sales_project_id, keyBy() colapsa todas
                // menos la última creada, y TODAS las cotizaciones de ese proyecto terminan
                // mostrando la etapa de esa única tarjeta sobreviviente (hallazgo real de
                // Pre-QA 2026-07-28: las 5 cotizaciones de Neil aparecían "Perdido" porque
                // sus 7 tarjetas compartían un solo SalesProject y la última era 'lost').
                $project = SalesProject::create([
                    'sub_client_id' => $sub->id,
                    'name'          => self::MARKER . ' Proyecto ' . ucfirst($slugName) . ' ' . ($i + 1),
                    'tag'           => 'both',
                ]);

                $card = PipelineCard::create([
                    'sales_project_id' => $project->id,
                    'stage'            => $stage,
                    'master_client_id' => $master->id,
                    'sub_client_id'    => $sub->id,
                    'owner_id'         => $ownerId,
                    'amount'           => $amount,
                    'stage_changed_at' => $stageChangedAt,
                ]);

                if ($stage !== 'lost') {
                    $this->satisfyNextStageGate($card, $contact, $ownerId);
                }

                if (in_array($stage, ['quote', 'proposal', 'approved', 'lost'], true)) {
                    $this->makeQuote($card, $ownerId, $folioService, $catalogIds, $i);
                }
            }
        }

        $this->command?->info('Scrum711SalesForceDemoSeeder: sembrados ' . count(self::VENDOR_EMAILS) . ' vendedores.');
    }

    /**
     * SCRUM-499 sembró 13 tarjetas 'approved' + 1 SalesGoal sobre el usuario legado
     * "designer@illuminations.test" (rol 'designer', ver QaTicketsBatch20260725Seeder,
     * que no está commiteado en esta rama — corrió a mano vía tinker). Se retira acá
     * porque Daniela (QA) no podía ver Ventas&Diseño con ese usuario y el propio
     * ticket 711 §6.1 pide la misma data sobre los vendedores reales.
     */
    private function retireScrum499DesignerDemoSeed(string $slug): void
    {
        $designerId = DB::table("{$slug}_auth.users")
            ->where('email', 'designer@illuminations.test')
            ->value('id');

        if ($designerId === null) {
            return;
        }

        $removedCards = PipelineCard::where('owner_id', $designerId)->delete();
        $removedGoals = SalesGoal::where('user_id', $designerId)->delete();

        if ($removedCards > 0 || $removedGoals > 0) {
            $this->command?->info("Retirado seed SCRUM-499 de designer@illuminations.test: {$removedCards} tarjetas, {$removedGoals} metas.");
        }
    }

    /**
     * SCRUM-716 — sin esto, el botón "avanzar" de PipelineCardModal quedaba
     * bloqueado en TODA tarjeta demo: PipelineCard::validationErrorForTransitionTo()
     * exige, según la etapa actual, contacto (Lead), superficie trabajada + archivo
     * de diseño (Diseño), superficie + cotización firmada (Cotización), o fecha de
     * entrega + comprobante de aprobación (Propuesta) — el seed nunca cargaba nada
     * de esto. `lost`/`approved` no tienen siguiente etapa con gate (ver STAGE_FLOW),
     * así que no necesitan nada acá.
     */
    private function satisfyNextStageGate(PipelineCard $card, SubClientContact $contact, int $ownerId): void
    {
        switch ($card->stage) {
            case 'lead':
                $card->contacts()->create([
                    'name'  => $contact->name,
                    'role'  => $contact->role,
                    'phone' => $contact->phone,
                    'email' => $contact->email,
                ]);
                break;

            case 'design':
                $card->update(['worked_area_m2' => random_int(20, 150)]);
                $this->seedFile($card, 'design', $ownerId);
                break;

            case 'quote':
                $card->update(['worked_area_m2' => random_int(20, 150)]);
                $this->seedFile($card, 'signed_quote', $ownerId);
                break;

            case 'proposal':
                $card->deliveryDates()->create(['date' => $card->stage_changed_at->copy()->addDays(15)]);
                $this->seedFile($card, 'approval_proof', $ownerId);
                break;
        }
    }

    private function seedFile(PipelineCard $card, string $type, int $ownerId): void
    {
        PipelineCardFile::create([
            'pipeline_card_id' => $card->id,
            'type'             => $type,
            'file_name'        => self::MARKER . " {$type}.pdf",
            'storage_key'      => "ventas-diseno/demo-711/{$card->id}/{$type}.pdf",
            'size_bytes'       => 102400,
            'mime_type'        => 'application/pdf',
            'uploaded_by'      => $ownerId,
        ]);
    }

    /** @param \Illuminate\Support\Collection<int, int> $catalogIds */
    private function makeQuote(
        PipelineCard $card,
        int $ownerId,
        QuoteFolioService $folioService,
        \Illuminate\Support\Collection $catalogIds,
        int $seedOffset,
    ): void {
        $status = in_array($card->stage, ['proposal', 'approved'], true) ? 'approved' : 'sent';

        $quote = Quote::create([
            'master_client_id' => $card->master_client_id,
            'sub_client_id'    => $card->sub_client_id,
            'sales_project_id' => $card->sales_project_id,
            'description'      => self::MARKER . ' Cotización ' . ucfirst($card->stage),
            'owner_id'         => $ownerId,
            'delivery_type'    => 'single',
            'status'           => $status,
            'folio'            => $folioService->next(),
            'generated_at'     => $card->stage_changed_at,
            'price_type'       => 'project',
        ]);

        $part = QuotePart::create([
            'quote_id' => $quote->id,
            'name'     => 'General',
            'position' => 1,
        ]);

        // 1-3 ítems tomados del catálogo real (CatalogProductSeeder / SCRUM-715) —
        // costo/precio/referencia/descripción copiados del producto, mismo
        // comportamiento que QuoteItemController al seleccionar un producto existente.
        $itemCount = $catalogIds->isEmpty() ? 0 : min(3, max(1, ($seedOffset % 3) + 1));
        for ($i = 0; $i < $itemCount; $i++) {
            $product = CatalogProduct::find($catalogIds[($seedOffset + $i) % $catalogIds->count()]);
            if ($product === null) {
                continue;
            }

            QuoteItem::create([
                'quote_part_id'      => $part->id,
                'catalog_product_id' => $product->id,
                'is_custom'          => false,
                'reference'          => $product->reference,
                'description'        => $product->description,
                'quantity'           => random_int(1, 8),
                'unit_price'         => $product->price_full,
                'cost'               => $product->cost,
            ]);
        }
    }
}
