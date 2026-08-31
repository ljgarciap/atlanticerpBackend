<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Bodega\Models\Order;
use App\Modules\Bodega\Models\OrderItem;
use App\Modules\Bodega\Models\ProductWarehouseStock;
use App\Modules\Bodega\Models\Warehouse;
use App\Modules\Bodega\Services\OrderFolioService;
use App\Modules\Compras\Models\BodegaZonaLibreRequest;
use App\Modules\Compras\Models\BodegaZonaLibreRequestLine;
use App\Modules\Compras\Models\Provider;
use App\Modules\Compras\Models\PurchaseOrder;
use App\Modules\Compras\Models\PurchaseOrderLine;
use App\Modules\Compras\Services\ZonaLibreFolioService;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Modules\VentasDiseno\Models\MasterClient;
use App\Modules\VentasDiseno\Models\PipelineCard;
use App\Modules\VentasDiseno\Models\PipelineCardContact;
use App\Modules\VentasDiseno\Models\Quote;
use App\Modules\VentasDiseno\Models\QuoteItem;
use App\Modules\VentasDiseno\Models\QuotePart;
use App\Modules\VentasDiseno\Models\SalesGoal;
use App\Modules\VentasDiseno\Models\SalesProject;
use App\Modules\VentasDiseno\Models\SubClient;
use App\Modules\VentasDiseno\Services\PipelineService;
use App\Shared\Multitenancy\Tenant;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Siembra ad-hoc para SCRUM-498/499/501/670 (QA de UI en dev.atlanticerp.ai y test.atlanticerp.ai), pedida
 * por Gerencia Test (498/499) y marly.rangel (501/670) el 2026-07-25. NO se registra en
 * DatabaseSeeder::run() — se invoca a mano vía tinker en cada entorno:
 *
 *   (new \Database\Seeders\QaTicketsBatch20260725Seeder)->run();
 *
 * Idempotente: cada bloque se guarda por un marcador propio y no vuelve a insertar si ya corrió
 * en ese entorno. SCRUM-502 (usuario Mark + permiso Precio Socio) NO se siembra acá — ya existe
 * como dato real Tipo A (CoreUserSeeder + SpecialPermissionSeeder), solo se verifica.
 *
 * SCRUM-406/409 (agregados 2026-07-27/28, devueltos por marly.rangel el 2026-07-26 por falta de
 * datos — ver comentarios de QA en cada ticket): los 12 pedidos de `seedScrum501()` no cubren
 * ninguno de los dos escenarios porque nacieron directo en Bodega, sin pasar por el puente real
 * Ventas & Diseño -> Bodega. `seedScrum406()` y `seedScrum409()` tienen su propio marcador y su
 * propio guard, así que corren aunque `seedScrum501()` ya haya sembrado antes en el entorno.
 */
final class QaTicketsBatch20260725Seeder extends Seeder
{
    private const VD_MARKER = '[QA-VD]';

    private const BOD_MARKER = '[QA-BOD]';

    private const ZL_REF_PREFIX = 'QA-ZL-';

    private const SCRUM406_MARKER = '[QA-BOD-406]';

    private const SCRUM409_MARKER = '[QA-VD-409]';

    public function run(): void
    {
        $tenant = Tenant::where('slug', 'atlantic')->first();

        if ($tenant === null) {
            $this->command?->warn('Tenant "atlantic" no encontrado — batch abortado.');

            return;
        }

        $tenant->makeCurrent();

        try {
            $this->seedScrum498499();
            $this->seedScrum501();
            $this->seedScrum670();
            $this->seedScrum406();
            $this->seedScrum409();
        } finally {
            Tenant::forgetCurrent();
        }
    }

    // ------------------------------------------------------------------
    // SCRUM-498 (REQ-060 Final Stage) + SCRUM-499 (REQ-072 Panel Metas)
    // ------------------------------------------------------------------
    private function seedScrum498499(): void
    {
        if (SalesProject::where('name', 'like', self::VD_MARKER.'%')->exists()) {
            $this->command?->info('SCRUM-498/499: ya sembrado, se omite.');

            return;
        }

        $ownerId = $this->userIdByRole(['designer']) ?? $this->userIdByRole(['management', 'superadmin']);

        if ($ownerId === null) {
            $this->command?->warn('SCRUM-498/499: no se encontró un usuario designer/management — skip.');

            return;
        }

        $masterA = MasterClient::create(['name' => self::VD_MARKER.' Grupo Constructor Andina', 'default_price_type' => 'project']);
        $masterB = MasterClient::create(['name' => self::VD_MARKER.' Inversiones Punta Pacífica', 'default_price_type' => 'special']);

        $subA = SubClient::create([
            'master_client_id' => $masterA->id,
            'business_name' => self::VD_MARKER.' Andina Residencial S.A.',
            'tax_id' => '155-9001-1-2026',
            'category' => 'b_architect_designer',
        ]);
        $subB = SubClient::create([
            'master_client_id' => $masterB->id,
            'business_name' => self::VD_MARKER.' Punta Pacífica Torres S.A.',
            'tax_id' => '155-9002-1-2026',
            'category' => 'c_bidding',
        ]);

        $proposalNames = [
            'Torre Business Bahía — Fase 2', 'Residencial Costa Verde', 'Hotel Riu Colón — Lobby',
            'Clínica Paitilla — Ala Norte', 'Centro Comercial Metropolis', 'Oficinas Constructora Delta',
            'Residencial Los Faros', 'Restaurante Costa del Este', 'Torre Marina Bahía — Amenidades',
            'Local Comercial Multiplaza', 'Auditorio Universidad del Istmo', 'Condominio Vista Hermosa',
            'Plaza Corporativa Obarrio', 'Residencial Altos del Golf', 'Hospital Punta Pacífica — Ext.',
        ];

        foreach ($proposalNames as $i => $name) {
            $sub = $i % 2 === 0 ? $subA : $subB;
            $master = $i % 2 === 0 ? $masterA : $masterB;

            $project = SalesProject::create([
                'sub_client_id' => $sub->id,
                'name' => self::VD_MARKER.' '.$name,
                'tag' => 'both',
            ]);

            PipelineCard::create([
                'sales_project_id' => $project->id,
                'stage' => 'proposal',
                'master_client_id' => $master->id,
                'sub_client_id' => $sub->id,
                'owner_id' => $ownerId,
                'amount' => random_int(8000, 90000) + random_int(0, 99) / 100,
                'worked_area_m2' => random_int(50, 800),
                'observations' => 'Próximo paso: enviar propuesta final revisada y dar seguimiento telefónico. Cliente evaluando presupuesto.',
                'stage_changed_at' => Carbon::now()->subDays(random_int(0, 14)),
            ]);
        }

        // SCRUM-499: 6 meses de historial (ReportsService::META_HISTORY_MONTHS) con cards "approved".
        $approvedNames = [
            'Edificio Delta Corporate', 'Residencial Brisas del Golf', 'Torre Icónica Punta Pacífica',
            'Restaurante Anthology', 'Clínica Hospital Nacional', 'Oficinas BBVA — Piso 12',
            'Residencial Green Tower', 'Centro Comercial Albrook Mall — Ala C', 'Hotel Sortis — Renovación',
            'Condominio Yacht Club', 'Torre Financial Park', 'Residencial Coral Beach',
            'Plaza Bancaria Vía España', 'Auditorio Ciudad del Saber', 'Torre Aqualina',
        ];

        $nameIdx = 0;
        for ($monthsAgo = 5; $monthsAgo >= 0; $monthsAgo--) {
            $cardsThisMonth = $monthsAgo === 0 ? 3 : 2;

            for ($n = 0; $n < $cardsThisMonth && $nameIdx < count($approvedNames); $n++, $nameIdx++) {
                $sub = $nameIdx % 2 === 0 ? $subA : $subB;
                $master = $nameIdx % 2 === 0 ? $masterA : $masterB;
                $date = Carbon::now()->subMonths($monthsAgo)->startOfMonth()->addDays(random_int(1, 25));

                $project = SalesProject::create([
                    'sub_client_id' => $sub->id,
                    'name' => self::VD_MARKER.' '.$approvedNames[$nameIdx],
                    'tag' => 'both',
                ]);

                PipelineCard::create([
                    'sales_project_id' => $project->id,
                    'stage' => 'approved',
                    'master_client_id' => $master->id,
                    'sub_client_id' => $sub->id,
                    'owner_id' => $ownerId,
                    'amount' => random_int(5000, 60000) + random_int(0, 99) / 100,
                    'worked_area_m2' => random_int(50, 800),
                    'observations' => 'Negocio cerrado — pendiente coordinar fecha de entrega con Bodega.',
                    'stage_changed_at' => $date,
                ]);
            }
        }

        SalesGoal::updateOrCreate(
            ['user_id' => $ownerId],
            ['goal_amount' => 35000.0, 'goal_plus_amount' => 45000.0]
        );

        $this->command?->info('SCRUM-498/499: 15 tarjetas Propuesta + 15 tarjetas Aprobado (6 meses) + meta sembradas.');
    }

    // ------------------------------------------------------------------
    // SCRUM-501 — Pedidos de Bodega (Kanban 3A) en cada etapa + picking parcial + back-allocation
    // ------------------------------------------------------------------
    private function seedScrum501(): void
    {
        if (Order::where('customer_name', 'like', self::BOD_MARKER.'%')->exists()) {
            $this->command?->info('SCRUM-501: ya sembrado, se omite.');

            return;
        }

        $esteban = $this->resolveUser('liderbodega@test.com', 'lider_bodega');
        $osvaldo = $this->resolveUser('asistentebodega@test.com', 'asistente_bodega');
        $mariano = $this->resolveUser('asistentebodega2@test.com', 'asistente_bodega');
        $apolonio = $this->resolveUser('ayudantegeneralbodega@test.com', 'ayudante_general_bodega');
        $gary = $this->resolveUser('transporte@test.com', 'transporte');

        if ($osvaldo === null || $mariano === null || $apolonio === null || $gary === null) {
            $this->command?->warn('SCRUM-501: falta algún usuario de Bodega (Osvaldo/Mariano/Apolonio/Gary) — skip.');

            return;
        }

        $products = CatalogProduct::where('is_active', true)->inRandomOrder()->limit(10)->get();
        if ($products->count() < 2) {
            $this->command?->warn('SCRUM-501: no hay suficientes CatalogProduct activos para armar los pedidos — skip.');

            return;
        }
        $products = $products->values();
        // Ciclo por si el catálogo local tiene menos productos que los que usa el batch.
        $p = fn (int $i) => $products[$i % $products->count()];

        $folio = app(OrderFolioService::class);

        // 1-2: Asignado, con y sin picker (SCRUM-383 validación de picker obligatorio)
        $this->makeOrder($folio->next(), Order::STAGE_ASIGNADO, $osvaldo, $apolonio, null,
            self::BOD_MARKER.' Residencial Las Brisas — Fam. Rodríguez', [$p(0), $p(1)]);
        $this->makeOrder($folio->next(), Order::STAGE_ASIGNADO, $mariano, null, null,
            self::BOD_MARKER.' Oficinas Business Tower — Constructora Delta', [$p(1), $p(2)]);

        // 3: Picking pendiente, con picker ya asignado
        $this->makeOrder($folio->next(), Order::STAGE_PICKING_PENDIENTE, $osvaldo, $apolonio, null,
            self::BOD_MARKER.' Casa Punta Pacífica — Ing. Herrera', [$p(2), $p(3)]);

        // 4: En picking — picking PARCIAL con las 3 ramas (hay / no hay / mixto)
        $orderD = $this->makeOrder($folio->next(), Order::STAGE_EN_PICKING, $mariano, $apolonio, null,
            self::BOD_MARKER.' Torre Marina Bahía — Depto 12B', []);
        OrderItem::create([
            'order_id' => $orderD->id, 'catalog_product_id' => $p(0)->id,
            'reference_snapshot' => $p(0)->reference, 'description_snapshot' => $p(0)->description,
            'qty_requested' => 10, 'qty_reserved' => 10, 'qty_picked' => 10, 'qty_delivered' => 0,
            'found_at_location' => true,
        ]);
        OrderItem::create([
            'order_id' => $orderD->id, 'catalog_product_id' => $p(3)->id,
            'reference_snapshot' => $p(3)->reference, 'description_snapshot' => $p(3)->description,
            'qty_requested' => 5, 'qty_reserved' => 5, 'qty_picked' => 0, 'qty_delivered' => 0,
            'found_at_location' => false, 'shortage_reason' => OrderItem::SHORTAGE_SIN_STOCK,
        ]);
        OrderItem::create([
            'order_id' => $orderD->id, 'catalog_product_id' => $p(4)->id,
            'reference_snapshot' => $p(4)->reference, 'description_snapshot' => $p(4)->description,
            'qty_requested' => 10, 'qty_reserved' => 10, 'qty_picked' => 6, 'qty_delivered' => 0,
            'found_at_location' => true, 'shortage_reason' => OrderItem::SHORTAGE_SIN_STOCK,
        ]);

        // 5: Packing (completamente alistado)
        $this->makeOrder($folio->next(), Order::STAGE_PACKING, $osvaldo, $apolonio, null,
            self::BOD_MARKER.' Residencial Vista Hermosa — Sra. Gómez', [$p(4), $p(5)], fullyPicked: true);

        // 6-7: Por despachar, con y sin repartidor+factura (SCRUM-398 bloqueo)
        $this->makeOrder($folio->next(), Order::STAGE_POR_DESPACHAR, $osvaldo, $apolonio, $gary,
            self::BOD_MARKER.' Local Comercial Multiplaza — Tienda Luz', [$p(5), $p(6)], fullyPicked: true, invoiceReady: true);
        $this->makeOrder($folio->next(), Order::STAGE_POR_DESPACHAR, $mariano, $apolonio, null,
            self::BOD_MARKER.' Clínica Paitilla — Consultorio 305', [$p(6), $p(7)], fullyPicked: true, invoiceReady: false);

        // 8: Despachado (para subir guía firmada, SCRUM-399)
        $this->makeOrder($folio->next(), Order::STAGE_DESPACHADO, $osvaldo, $apolonio, $gary,
            self::BOD_MARKER.' Restaurante Costa del Este', [$p(7), $p(8)], fullyPicked: true, invoiceReady: true);

        // Familia de entregas — mismo order_number, dos filas (raíz despachada + hija en picking)
        $familyFolio = $folio->next();
        $root = $this->makeOrder($familyFolio, Order::STAGE_DESPACHADO, $osvaldo, $apolonio, $gary,
            self::BOD_MARKER.' Proyecto Hotel Riu — Entrega 1 de 2', [$p(8)], fullyPicked: true, invoiceReady: true,
            orderNumberOverride: $familyFolio, sequenceInFamily: 1, totalInFamily: 2);
        $this->makeOrder($familyFolio, Order::STAGE_EN_PICKING, $mariano, $apolonio, null,
            self::BOD_MARKER.' Proyecto Hotel Riu — Entrega 2 de 2', [$p(9)],
            orderNumberOverride: $familyFolio, familyId: $root->id, sequenceInFamily: 2, totalInFamily: 2);

        // Back-allocation: un producto dedicado con stock insuficiente para 2 pedidos activos
        $sharedProduct = CatalogProduct::firstOrCreate(
            ['reference' => 'QA-BOD-SHARED-001'],
            [
                'name' => self::BOD_MARKER.' Reflector LED 150W (compartido back-allocation)',
                'price_full' => 120.0, 'cost' => 70.0, 'stock_quantity' => 8, 'is_active' => true, 'rotation' => 'media',
            ]
        );
        $warehouse = Warehouse::first();
        if ($warehouse !== null) {
            ProductWarehouseStock::updateOrCreate(
                ['catalog_product_id' => $sharedProduct->id, 'warehouse_id' => $warehouse->id],
                ['quantity' => 8]
            );

            $orderK = $this->makeOrder($folio->next(), Order::STAGE_EN_PICKING, $osvaldo, $apolonio, null,
                self::BOD_MARKER.' Back-allocation — Pedido prioritario (entrega +2 días)', [],
                committedDeliveryDate: Carbon::now()->addDays(2));
            OrderItem::create([
                'order_id' => $orderK->id, 'catalog_product_id' => $sharedProduct->id,
                'reference_snapshot' => $sharedProduct->reference, 'description_snapshot' => $sharedProduct->description,
                'qty_requested' => 6, 'qty_reserved' => 6, 'qty_picked' => 0, 'qty_delivered' => 0,
            ]);

            $orderL = $this->makeOrder($folio->next(), Order::STAGE_PICKING_PENDIENTE, $mariano, $apolonio, null,
                self::BOD_MARKER.' Back-allocation — Pedido con menor prioridad (entrega +5 días)', [],
                committedDeliveryDate: Carbon::now()->addDays(5));
            OrderItem::create([
                'order_id' => $orderL->id, 'catalog_product_id' => $sharedProduct->id,
                'reference_snapshot' => $sharedProduct->reference, 'description_snapshot' => $sharedProduct->description,
                'qty_requested' => 6, 'qty_reserved' => 2, 'qty_picked' => 0, 'qty_delivered' => 0,
            ]);
        }

        $this->command?->info('SCRUM-501: pedidos de Bodega sembrados en todas las etapas + familia + back-allocation.');
    }

    /** @param list<CatalogProduct> $products */
    private function makeOrder(
        string $orderNumber,
        string $stage,
        ?User $assistant,
        ?User $picker,
        ?User $courier,
        string $customerName,
        array $products,
        bool $fullyPicked = false,
        bool $invoiceReady = false,
        ?string $orderNumberOverride = null,
        ?int $familyId = null,
        ?int $sequenceInFamily = null,
        ?int $totalInFamily = null,
        ?Carbon $committedDeliveryDate = null,
    ): Order {
        $order = Order::create([
            'order_number' => $orderNumberOverride ?? $orderNumber,
            'order_type' => Order::ORDER_TYPE_PEDIDO,
            'stage' => $stage,
            'family_id' => $familyId,
            'sequence_in_family' => $sequenceInFamily,
            'total_in_family' => $totalInFamily,
            'assistant_id' => $assistant?->id,
            'picker_id' => $picker?->id,
            'courier_id' => $courier?->id,
            'committed_delivery_date' => $committedDeliveryDate ?? Carbon::now()->addDays(random_int(1, 10)),
            'customer_name' => $customerName,
            'customer_contact' => '+507 6'.random_int(100, 999).'-'.random_int(1000, 9999),
            'delivery_address' => 'Ciudad de Panamá',
            'invoice_ready' => $invoiceReady,
        ]);

        foreach ($products as $product) {
            $qty = random_int(2, 8);
            OrderItem::create([
                'order_id' => $order->id,
                'catalog_product_id' => $product->id,
                'reference_snapshot' => $product->reference,
                'description_snapshot' => $product->description,
                'qty_requested' => $qty,
                'qty_reserved' => $qty,
                'qty_picked' => $fullyPicked ? $qty : 0,
                'qty_delivered' => 0,
                'found_at_location' => $fullyPicked ? true : null,
            ]);
        }

        return $order;
    }

    // ------------------------------------------------------------------
    // SCRUM-670 — Productos + órdenes de Zona Libre de Colón
    // ------------------------------------------------------------------
    private function seedScrum670(): void
    {
        if (CatalogProduct::where('reference', 'like', self::ZL_REF_PREFIX.'%')->exists()) {
            $this->command?->info('SCRUM-670: ya sembrado, se omite.');

            return;
        }

        $provider = Provider::where('name', 'Zona Libre de Colón')->first();
        if ($provider === null) {
            $this->command?->warn('SCRUM-670: proveedor "Zona Libre de Colón" no existe en este entorno (¿ZonaLibreProviderSeeder no corrió?) — skip.');

            return;
        }

        $osvaldo = $this->resolveUser('asistentebodega@test.com', 'asistente_bodega');
        $mariano = $this->resolveUser('asistentebodega2@test.com', 'asistente_bodega');
        $yirena = $this->resolveUser('lidercompras@test.com', 'lider_compras');

        if ($osvaldo === null || $mariano === null || $yirena === null) {
            $this->command?->warn('SCRUM-670: falta Osvaldo/Mariano/Yirena — skip.');

            return;
        }

        $warehouse = Warehouse::first();

        $catalog = [
            ['ref' => self::ZL_REF_PREFIX.'001', 'desc' => self::VD_MARKER.' Riel LED 2m Importado', 'cost' => 45.0, 'price' => 89.0, 'stock' => 40],
            ['ref' => self::ZL_REF_PREFIX.'002', 'desc' => self::VD_MARKER.' Panel LED 60x60 Importado', 'cost' => 22.0, 'price' => 48.0, 'stock' => 60],
            ['ref' => self::ZL_REF_PREFIX.'003', 'desc' => self::VD_MARKER.' Reflector LED 200W Importado', 'cost' => 38.0, 'price' => 79.0, 'stock' => 25],
            ['ref' => self::ZL_REF_PREFIX.'004', 'desc' => self::VD_MARKER.' Lámpara Colgante Decorativa Importada', 'cost' => 15.0, 'price' => 35.0, 'stock' => 50],
        ];

        $createdProducts = [];
        foreach ($catalog as $row) {
            $product = CatalogProduct::create([
                'reference' => $row['ref'],
                // SCRUM-237/240 — `name` NOT NULL (ver migración 2026_08_15_100000_...).
                'name' => $row['desc'],
                'price_full' => $row['price'],
                'cost' => $row['cost'],
                'stock_quantity' => $row['stock'],
                'provider_id' => $provider->id,
                'is_active' => true,
                'rotation' => CatalogProduct::ROTATION_MEDIA,
            ]);
            $createdProducts[] = $product;

            if ($warehouse !== null) {
                ProductWarehouseStock::updateOrCreate(
                    ['catalog_product_id' => $product->id, 'warehouse_id' => $warehouse->id],
                    ['quantity' => $row['stock']]
                );
            }
        }

        $folio = app(ZonaLibreFolioService::class);

        // 1: Por aprobar
        $req1 = BodegaZonaLibreRequest::create([
            'order_number' => $folio->next(), 'status' => BodegaZonaLibreRequest::STATUS_PENDIENTE,
            'requested_by' => $osvaldo->id, 'shipping_type' => BodegaZonaLibreRequest::SHIPPING_TERRESTRE,
            'estimated_arrival_date' => Carbon::now()->addDays(10), 'notes' => 'Reposición trimestral de riel y panel LED.',
        ]);
        BodegaZonaLibreRequestLine::create([
            'bodega_zona_libre_request_id' => $req1->id, 'catalog_product_id' => $createdProducts[0]->id,
            'quantity' => 20, 'unit_cost_snapshot' => $createdProducts[0]->cost,
        ]);
        BodegaZonaLibreRequestLine::create([
            'bodega_zona_libre_request_id' => $req1->id, 'catalog_product_id' => $createdProducts[1]->id,
            'quantity' => 30, 'unit_cost_snapshot' => $createdProducts[1]->cost,
        ]);

        // 2: Aprobada
        $req2 = BodegaZonaLibreRequest::create([
            'order_number' => $folio->next(), 'status' => BodegaZonaLibreRequest::STATUS_APROBADA,
            'requested_by' => $mariano->id, 'approved_by' => $yirena->id,
            'shipping_type' => BodegaZonaLibreRequest::SHIPPING_TERRESTRE,
            'estimated_arrival_date' => Carbon::now()->addDays(7), 'notes' => 'Aprobada para surtir reflectores.',
        ]);
        BodegaZonaLibreRequestLine::create([
            'bodega_zona_libre_request_id' => $req2->id, 'catalog_product_id' => $createdProducts[2]->id,
            'quantity' => 15, 'unit_cost_snapshot' => $createdProducts[2]->cost,
        ]);

        // 3: Rechazada
        $req3 = BodegaZonaLibreRequest::create([
            'order_number' => $folio->next(), 'status' => BodegaZonaLibreRequest::STATUS_RECHAZADA,
            'requested_by' => $osvaldo->id, 'rejected_by' => $yirena->id,
            'rejection_reason' => 'Presupuesto de Zona Libre excedido este mes — reintentar el próximo ciclo.',
            'shipping_type' => BodegaZonaLibreRequest::SHIPPING_TERRESTRE, 'notes' => 'Lámparas decorativas, no urgente.',
        ]);
        BodegaZonaLibreRequestLine::create([
            'bodega_zona_libre_request_id' => $req3->id, 'catalog_product_id' => $createdProducts[3]->id,
            'quantity' => 25, 'unit_cost_snapshot' => $createdProducts[3]->cost,
        ]);

        $this->command?->info('SCRUM-670: 4 productos Zona Libre + 3 solicitudes (pendiente/aprobada/rechazada) sembradas.');
    }

    // ------------------------------------------------------------------
    // SCRUM-406 (REQ-336) — tarjeta "sin stock" con PO real en tránsito y ETA futura, para poder
    // verificar el Escenario 1 (ETA visible en el tablero sin abrir el detalle). Devuelto por
    // marly.rangel el 2026-07-26: los 12 pedidos de seedScrum501() son todos order_type=pedido,
    // ninguno faltante/sin-stock.
    // ------------------------------------------------------------------
    private function seedScrum406(): void
    {
        if (Order::where('customer_name', 'like', self::SCRUM406_MARKER.'%')->exists()) {
            $this->command?->info('SCRUM-406: ya sembrado, se omite.');

            return;
        }

        $osvaldo = $this->resolveUser('asistentebodega@test.com', 'asistente_bodega');
        $apolonio = $this->resolveUser('ayudantegeneralbodega@test.com', 'ayudante_general_bodega');
        $yirena = $this->resolveUser('lidercompras@test.com', 'lider_compras');

        if ($osvaldo === null || $apolonio === null || $yirena === null) {
            $this->command?->warn('SCRUM-406: falta algún usuario (Osvaldo/Apolonio/Yirena) — skip.');

            return;
        }

        $provider = Provider::firstOrCreate(
            ['name' => self::SCRUM406_MARKER.' Proveedor Importado Demo'],
            ['origin' => Provider::ORIGIN_INTERNACIONAL, 'currency' => 'USD', 'is_active' => true, 'created_by' => $yirena->id]
        );

        $product = CatalogProduct::firstOrCreate(
            ['reference' => 'QA-BOD-406-001'],
            [
                'name' => self::SCRUM406_MARKER.' Perfil LED Empotrable 2m (demo sin stock)',
                'price_full' => 95.0, 'cost' => 52.0, 'stock_quantity' => 0,
                'provider_id' => $provider->id, 'is_active' => true, 'rotation' => CatalogProduct::ROTATION_MEDIA,
            ]
        );

        $warehouse = Warehouse::first();
        if ($warehouse !== null) {
            ProductWarehouseStock::updateOrCreate(
                ['catalog_product_id' => $product->id, 'warehouse_id' => $warehouse->id],
                ['quantity' => 0]
            );
        }

        // RN1 (SCRUM-406): la ETA jala de una PurchaseOrder REAL asociada, no capturada a mano —
        // status distinto de "recibido" + estimated_arrival_date futura, con una línea real sobre
        // el mismo catalog_product_id que el ítem "sin stock" del pedido (ver
        // OrderBoardService::supplierEta(), que hace exactamente ese join).
        $purchaseOrder = PurchaseOrder::create([
            'provider_id' => $provider->id,
            'status' => PurchaseOrder::STATUS_EN_TRANSITO,
            'status_changed_at' => Carbon::now()->subDays(2),
            'shipping_type' => PurchaseOrder::SHIPPING_MARITIMO,
            'who_pays_shipping' => PurchaseOrder::PAYS_PROVIDER,
            'modality' => PurchaseOrder::MODALITY_DIRECTO,
            'estimated_arrival_date' => Carbon::now()->addDays(9),
            'requires_primary_approval' => false,
            'currency' => 'USD',
            'total_amount' => 20 * $product->cost,
            'notes' => self::SCRUM406_MARKER.' Orden de reposición en tránsito — fixture QA para SCRUM-406.',
            'created_by' => $yirena->id,
        ]);

        PurchaseOrderLine::create([
            'purchase_order_id' => $purchaseOrder->id,
            'catalog_product_id' => $product->id,
            'quantity' => 20,
            'unit_cost' => $product->cost,
            'subtotal' => 20 * $product->cost,
        ]);

        $folio = app(OrderFolioService::class);

        $order = Order::create([
            'order_number' => $folio->next(),
            'order_type' => Order::ORDER_TYPE_FALTANTE,
            'stage' => Order::STAGE_ASIGNADO,
            'assistant_id' => $osvaldo->id,
            'picker_id' => $apolonio->id,
            'committed_delivery_date' => Carbon::now()->addDays(9),
            'customer_name' => self::SCRUM406_MARKER.' Residencial Punta Chame — Fam. Vega',
            'customer_contact' => '+507 6'.random_int(100, 999).'-'.random_int(1000, 9999),
            'delivery_address' => 'Ciudad de Panamá',
            'invoice_ready' => false,
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'catalog_product_id' => $product->id,
            'reference_snapshot' => $product->reference,
            'description_snapshot' => $product->description,
            'qty_requested' => 5,
            'qty_reserved' => 0,
            'qty_picked' => 0,
            'qty_delivered' => 0,
            'found_at_location' => false,
            'shortage_reason' => OrderItem::SHORTAGE_SIN_STOCK,
        ]);

        $this->command?->info('SCRUM-406: pedido "sin stock" (faltante/asignado) + PO real en tránsito con ETA futura sembrados.');
    }

    // ------------------------------------------------------------------
    // SCRUM-409 (REQ-339) — pedido vinculado a una cotización real de Ventas & Diseño, con
    // Proyecto/Diseñador/Cliente/Contacto/Teléfono poblados (RN1). Devuelto por marly.rangel el
    // 2026-07-26: los 12 pedidos de seedScrum501() tienen pipeline_card_id null.
    //
    // A propósito NO se crea la PipelineCard directo en stage="approved" (ese es el patrón de
    // VentasDisenoDemoSeeder, que sirve para el tablero de Pipeline pero NUNCA dispara el evento
    // de dominio) — acá se crea en "proposal" y se avanza con PipelineService::changeStage(),
    // igual que en producción, para que el listener real CreateOrderFromApprovedQuote cree el
    // Order y lo deje vinculado vía pipeline_card_id.
    // ------------------------------------------------------------------
    private function seedScrum409(): void
    {
        if (SalesProject::where('name', 'like', self::SCRUM409_MARKER.'%')->exists()) {
            $this->command?->info('SCRUM-409: ya sembrado, se omite.');

            return;
        }

        // Vendedor Disenador Test 10 (vendedor_disenador) — vendedora real con WarehouseAssistantMapping
        // real ya sembrado (-> Asistente Bodega Test, ver WarehouseAssistantMappingSeeder), para que el
        // Order nazca con assistant_id asignado sin disparar la notificación de "sin asistente".
        $owner = User::where('email', 'vendedordisenador10@test.com')->first();

        if ($owner === null) {
            $ownerId = $this->userIdByRole(['designer']) ?? $this->userIdByRole(['management', 'superadmin']);
            $owner = $ownerId !== null ? User::find($ownerId) : null;
        }

        if ($owner === null) {
            $this->command?->warn('SCRUM-409: no se encontró un usuario vendedor/diseñador — skip.');

            return;
        }

        $master = MasterClient::create([
            'name' => self::SCRUM409_MARKER.' Grupo Torre Bahía',
            'default_price_type' => 'project',
        ]);
        $sub = SubClient::create([
            'master_client_id' => $master->id,
            'business_name' => self::SCRUM409_MARKER.' Torre Bahía Residencial S.A.',
            'tax_id' => '155-4090-1-2026',
            'category' => 'b_architect_designer',
            'delivery_address' => 'Costa del Este, Ciudad de Panamá',
        ]);
        $project = SalesProject::create([
            'sub_client_id' => $sub->id,
            'name' => self::SCRUM409_MARKER.' Torre Bahía — Lobby Principal',
            'tag' => 'both',
        ]);

        $card = PipelineCard::create([
            'sales_project_id' => $project->id,
            'stage' => 'proposal',
            'master_client_id' => $master->id,
            'sub_client_id' => $sub->id,
            'owner_id' => $owner->id,
            'amount' => 28500.0,
            'worked_area_m2' => 320,
            'observations' => 'Propuesta final aceptada por el cliente — pendiente coordinar entrega con Bodega.',
            'stage_changed_at' => Carbon::now()->subDays(2),
        ]);

        PipelineCardContact::create([
            'pipeline_card_id' => $card->id,
            'name' => 'Arq. Fernanda Ríos',
            'role' => 'architect',
            'phone' => '+507 6234-5678',
            'email' => 'fernanda.rios@example.com',
        ]);

        $product = CatalogProduct::where('is_active', true)->inRandomOrder()->first();

        $quote = Quote::create([
            'master_client_id' => $master->id,
            'sub_client_id' => $sub->id,
            'sales_project_id' => $project->id,
            'owner_id' => $owner->id,
            'status' => 'approved',
            'folio' => 'COT-QA409-0001',
            'generated_at' => Carbon::now()->subDays(3),
            'price_type' => 'project',
        ]);

        $part = QuotePart::create(['quote_id' => $quote->id, 'name' => 'Iluminación General', 'position' => 1]);

        QuoteItem::create([
            'quote_part_id' => $part->id,
            'catalog_product_id' => $product?->id,
            'is_custom' => $product === null,
            'reference' => $product?->reference ?? 'QA-409-CUSTOM',
            'description' => $product?->name ?? self::SCRUM409_MARKER.' Luminaria personalizada — lobby',
            'quantity' => 12,
            'unit_price' => 145.0,
            'cost' => 82.0,
        ]);

        // Dispara el flujo real: PipelineService::changeStage() -> evento QuoteApproved ->
        // CreateOrderFromApprovedQuote -> OrderService::createFromApprovedQuote(). NO
        // PipelineCard::create(['stage' => 'approved']) directo, que no dispara nada.
        app(PipelineService::class)->changeStage($card, 'approved');

        $this->command?->info('SCRUM-409: cotización aprobada sembrada — Order de Bodega creado vía evento real QuoteApproved, con pipeline_card_id vinculado.');
    }

    /** @param list<string> $roles */
    private function userIdByRole(array $roles): ?int
    {
        $slug = Tenant::current()?->slug;
        $table = "{$slug}_auth.user_roles";

        foreach ($roles as $role) {
            $userId = DB::table($table)->where('role', $role)->value('user_id');
            if ($userId !== null) {
                return (int) $userId;
            }
        }

        return null;
    }

    private function resolveUser(string $email, string $roleKey): ?User
    {
        return User::where('email', $email)->first()
            ?? User::whereHas('role', fn ($q) => $q->where('key', $roleKey))->first();
    }
}
