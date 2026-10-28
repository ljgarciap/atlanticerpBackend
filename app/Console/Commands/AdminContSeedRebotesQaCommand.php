<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Modules\AdminCont\Models\AdminContInvoice;
use App\Modules\AdminCont\Models\AdminContPayment;
use App\Modules\AdminCont\Models\AdminContPaymentInvoice;
use App\Modules\AdminCont\Models\ArchitectFiscalProfile;
use App\Modules\AdminCont\Services\ArchitectCommissionService;
use App\Modules\Bodega\Models\Order;
use App\Modules\VentasDiseno\Models\Architect;
use App\Modules\VentasDiseno\Models\MasterClient;
use App\Modules\VentasDiseno\Models\PipelineCard;
use App\Modules\VentasDiseno\Models\Quote;
use App\Modules\VentasDiseno\Models\SalesProject;
use App\Modules\VentasDiseno\Models\SubClient;
use App\Shared\Multitenancy\Tenant;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;

/**
 * Rebotes de QA "BLOQUEADO, SIN DATOS DE PRUEBA" (2026-08-27) en SCRUM-583/590/591/592/595/601 —
 * marly no pudo ejercitar ninguno de estos escenarios en dev.atlanticerp.ai porque no existía en ese
 * entorno ningún registro real en el estado que cada ticket necesita. No son bugs de código — ver
 * comentarios de marly en cada ticket. Este comando siembra, vía los modelos y servicios reales
 * del módulo (no SQL directo), un caso por escenario:
 *
 * - SCRUM-583 — un pedido compartido entre 2 vendedores reales (`PipelineCard.compartido_con`).
 * - SCRUM-590 — proyecto de arquitecto en "Pendiente de factura del arquitecto", régimen
 *   con_itbms, datos fiscales completos, SIN cuenta de cobro subida (para probar el flujo de
 *   subida). El mismo registro cubre SCRUM-595 (botón "Recordar", requiere email del arquitecto).
 * - SCRUM-591 — mismo estado que arriba, pero régimen retencion_50, sin comprobante de retención.
 * - SCRUM-592 — proyecto ya con cuenta de cobro subida (vía `ArchitectCommissionService::
 *   uploadCuentaCobro()` real) y sin % pendiente, listo para ejercitar "Marcar como pagada". El
 *   escenario de SCRUM-590 (sin cuenta de cobro) sirve además como caso negativo de este ticket.
 * - SCRUM-601 — un cobro real (`AdminContPayment`, método efectivo — confirmación inmediata) con
 *   fecha de hoy, para que el Arqueo del día tenga contenido.
 *
 * Idempotente por marcador: cada registro fabricado lleva el prefijo `[PREQA-REBOTE-<ticket>]` en
 * su nombre — si ya existe, se omite en vez de duplicar. Nunca corre en producción sin `--force`
 * (mismo criterio que `bodega:seed-uat-projects`) — son datos de prueba, no reales.
 */
final class AdminContSeedRebotesQaCommand extends Command
{
    private const MARKER_583 = '[PREQA-REBOTE-SCRUM583]';

    private const MARKER_590 = '[PREQA-REBOTE-SCRUM590]';

    private const MARKER_591 = '[PREQA-REBOTE-SCRUM591]';

    private const MARKER_592 = '[PREQA-REBOTE-SCRUM592]';

    private const MARKER_601 = '[PREQA-REBOTE-SCRUM601]';

    /** @var string */
    protected $signature = 'admincont:seed-rebotes-qa
        {--tenant=atlantic : Slug del tenant a sembrar}
        {--force : Requerido para correr en app()->environment(\'production\') — nunca por accidente}';

    /** @var string */
    protected $description = 'Siembra escenarios de prueba para desbloquear QA de SCRUM-583/590/591/592/595/601 (rebotes por falta de datos, no bugs de código)';

    public function handle(ArchitectCommissionService $commissions): int
    {
        if (app()->environment('production') && ! $this->option('force')) {
            $this->error('Este comando siembra datos de prueba — nunca corre en production sin --force explícito.');

            return self::FAILURE;
        }

        $tenant = Tenant::where('slug', $this->option('tenant'))->first();
        if ($tenant === null) {
            $this->error("Tenant '{$this->option('tenant')}' no encontrado.");

            return self::FAILURE;
        }
        Tenant::forgetCurrent();
        $tenant->makeCurrent();

        $felixId = (int) User::where('email', 'contabilidad@test.com')->value('id');
        $neilId = (int) User::where('email', 'vendedor2@test.com')->value('id');
        $fefiId = (int) User::where('email', 'vendedor3@test.com')->value('id');

        if ($felixId === 0 || $neilId === 0 || $fefiId === 0) {
            $this->error('Faltan usuarios base del roster real (Contabilidad/Vendedor) — abortando.');

            return self::FAILURE;
        }

        $this->seedSharedProject($neilId, $fefiId);
        $this->seedArchitectPendingSinArchivo(self::MARKER_590, ArchitectFiscalProfile::REGIMEN_CON_ITBMS, $felixId);
        $this->seedArchitectPendingSinArchivo(self::MARKER_591, ArchitectFiscalProfile::REGIMEN_RETENCION_50, $felixId);
        $this->seedArchitectReadyToPay($felixId, $commissions);
        $this->seedDailyCashCountPayment($felixId);

        $this->info('Listo — verificar en dev.atlanticerp.ai (Comisiones Internas/Externas, Arqueo del día).');

        return self::SUCCESS;
    }

    /** SCRUM-583 — pedido compartido entre 2 vendedores reales. */
    private function seedSharedProject(int $ownerId, int $sharedWithId): void
    {
        if (MasterClient::where('name', 'like', self::MARKER_583.'%')->exists()) {
            $this->line('SCRUM-583 — ya sembrado, omitido.');

            return;
        }

        $master = MasterClient::create(['name' => self::MARKER_583.' Cliente Compartido']);
        $sub = SubClient::create(['master_client_id' => $master->id, 'business_name' => $master->name, 'tax_id' => '155-0000-9-'.random_int(1000, 9999)]);
        $project = SalesProject::create(['sub_client_id' => $sub->id, 'name' => self::MARKER_583.' Proyecto Compartido']);

        $amount = 6000.0;
        $card = PipelineCard::create([
            'sales_project_id' => $project->id, 'stage' => 'approved', 'master_client_id' => $master->id,
            'sub_client_id' => $sub->id, 'owner_id' => $ownerId, 'amount' => $amount,
            'stage_changed_at' => Carbon::now(), 'compartido_con' => [$sharedWithId],
        ]);

        $order = Order::create([
            'order_number' => 'PED-COM-'.random_int(1000, 999999), 'pipeline_card_id' => $card->id,
            'order_type' => Order::ORDER_TYPE_PEDIDO, 'stage' => Order::STAGE_POR_DESPACHAR,
            'customer_name' => $sub->business_name,
        ]);

        AdminContInvoice::create([
            'numero' => 'F-COM-'.random_int(1000, 999999), 'order_id' => $order->id, 'cliente' => $sub->business_name,
            'master_client_id' => $master->id, 'monto' => $amount, 'saldo_aplicado' => $amount,
        ]);

        $this->info("SCRUM-583 — sembrado: pedido compartido owner={$ownerId} + compartido_con=[{$sharedWithId}], monto USD {$amount} (100% cobrado).");
    }

    /**
     * SCRUM-590/591/595 — proyecto de arquitecto en "Pendiente de factura del arquitecto" (100%
     * cobrado, sin `fecha_pago`), datos fiscales completos, SIN cuenta de cobro/comprobante de
     * retención subidos todavía — para ejercitar el flujo de subida (590/591) y el botón
     * "Recordar" (595, requiere `Architect.email`, ya seteado acá).
     */
    private function seedArchitectPendingSinArchivo(string $marker, string $regimenFiscal, int $ownerId): void
    {
        if (Architect::where('name', 'like', $marker.'%')->exists()) {
            $this->line("{$marker} — ya sembrado, omitido.");

            return;
        }

        $card = $this->makeArchitectProject($marker, $regimenFiscal, $ownerId, amount: 5000.0, totalCobrado: 5000.0);

        $this->info("{$marker} — sembrado: proyecto de arquitecto (régimen {$regimenFiscal}) en Pendiente de factura, sin archivo subido. PipelineCard #{$card->id}.");
    }

    /**
     * SCRUM-592 — proyecto listo para "Marcar como pagada": 100% cobrado, datos fiscales
     * completos, cuenta de cobro YA subida (vía el servicio real, no un insert directo), sin %
     * pendiente de aprobación. El escenario SCRUM-590 (sin archivo) sirve de caso negativo.
     */
    private function seedArchitectReadyToPay(int $ownerId, ArchitectCommissionService $commissions): void
    {
        if (Architect::where('name', 'like', self::MARKER_592.'%')->exists()) {
            $this->line('SCRUM-592 — ya sembrado, omitido.');

            return;
        }

        $card = $this->makeArchitectProject(self::MARKER_592, ArchitectFiscalProfile::REGIMEN_CON_ITBMS, $ownerId, amount: 4200.0, totalCobrado: 4200.0);
        $architectId = (int) Quote::where('sales_project_id', $card->sales_project_id)->value('architect_id');

        $tmpPath = sys_get_temp_dir().'/preqa-cuenta-cobro-'.uniqid().'.pdf';
        file_put_contents($tmpPath, "%PDF-1.4\n% PREQA-REBOTE-SCRUM592 — cuenta de cobro de prueba\n%%EOF");
        $file = new UploadedFile($tmpPath, 'cuenta-cobro-preqa-scrum592.pdf', 'application/pdf', null, true);

        $commissions->uploadCuentaCobro($card->id, $architectId, $file, $ownerId);
        @unlink($tmpPath);

        $this->info("SCRUM-592 — sembrado: proyecto de arquitecto con cuenta de cobro ya subida, listo para marcar pagada. PipelineCard #{$card->id}.");
    }

    /** Encadena SalesProject → PipelineCard(approved) → Quote(architect_id) → Order →
     *  AdminContInvoice → ArchitectFiscalProfile — mismo patrón que
     *  AdminContArchitectCommissionControllerTest::makeArchitectProject()/makeArchitect(). */
    private function makeArchitectProject(string $marker, string $regimenFiscal, int $ownerId, float $amount, float $totalCobrado): PipelineCard
    {
        $architect = Architect::create(['name' => $marker.' Arq. '.uniqid(), 'email' => 'preqa+'.uniqid().'@atlantic.test']);
        ArchitectFiscalProfile::create([
            'architect_id' => $architect->id, 'empresa' => $marker.' Empresa Arq', 'ruc' => '155-'.random_int(1000, 9999).'-1-'.random_int(1000, 9999),
            'regimen_fiscal' => $regimenFiscal,
        ]);

        $master = MasterClient::create(['name' => $marker.' Cliente Arq']);
        $sub = SubClient::create(['master_client_id' => $master->id, 'business_name' => $master->name, 'tax_id' => '155-0000-8-'.random_int(1000, 9999)]);
        $project = SalesProject::create(['sub_client_id' => $sub->id, 'name' => $marker.' Proyecto Arq']);
        $card = PipelineCard::create([
            'sales_project_id' => $project->id, 'stage' => 'approved', 'master_client_id' => $master->id,
            'sub_client_id' => $sub->id, 'owner_id' => $ownerId, 'amount' => $amount,
            'stage_changed_at' => Carbon::now(),
        ]);

        Quote::create([
            'owner_id' => $ownerId, 'sales_project_id' => $project->id,
            'architect_id' => $architect->id, 'status' => 'approved',
        ]);

        $order = Order::create([
            'order_number' => 'PED-ARQ-'.random_int(1000, 999999), 'pipeline_card_id' => $card->id,
            'order_type' => Order::ORDER_TYPE_PEDIDO, 'stage' => Order::STAGE_POR_DESPACHAR,
            'customer_name' => $sub->business_name,
        ]);

        AdminContInvoice::create([
            'numero' => 'F-ARQ-'.random_int(1000, 999999), 'order_id' => $order->id, 'cliente' => $sub->business_name,
            'master_client_id' => $master->id, 'monto' => $amount, 'saldo_aplicado' => $totalCobrado,
        ]);

        return $card;
    }

    /** SCRUM-601 — al menos 1 cobro confirmado hoy, para que el Arqueo del día tenga contenido. */
    private function seedDailyCashCountPayment(int $createdBy): void
    {
        if (MasterClient::where('name', 'like', self::MARKER_601.'%')->exists()) {
            $this->line('SCRUM-601 — ya sembrado, omitido.');

            return;
        }

        $master = MasterClient::create(['name' => self::MARKER_601.' Cliente Arqueo']);
        $sub = SubClient::create(['master_client_id' => $master->id, 'business_name' => $master->name, 'tax_id' => '155-0000-7-'.random_int(1000, 9999)]);

        $invoice = AdminContInvoice::create([
            'numero' => 'F-ARQ-DIA-'.random_int(100000, 999999), 'order_id' => random_int(1000000, 9999999),
            'master_client_id' => $master->id, 'cliente' => $sub->business_name, 'monto' => 350.0, 'saldo_aplicado' => 0,
        ]);

        $payment = AdminContPayment::create([
            'numero_recibo' => 'REC-PREQA-'.random_int(100000, 999999), 'master_client_id' => $master->id,
            'monto_recibido' => 350.0, 'saldo_favor_aplicado' => 0, 'metodo_pago' => AdminContPayment::METODO_EFECTIVO,
            'estado' => AdminContPayment::ESTADO_CONFIRMADO, 'created_by' => $createdBy,
        ]);
        AdminContPaymentInvoice::create(['payment_id' => $payment->id, 'invoice_id' => $invoice->id, 'monto_aplicado' => 350.0]);

        $this->info("SCRUM-601 — sembrado: cobro confirmado (efectivo) de USD 350.00 con fecha de hoy. Payment #{$payment->id}.");
    }
}
