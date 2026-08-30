<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Bodega\Models\Order;
use App\Modules\Bodega\Services\OrderFolioService;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * SCRUM-171 (REQ-109 RN1/RN3, 2026-08-26) — 3 sesiones consecutivas de Pre-QA (v5→v6→v7) no
 * pudieron verificar el panel "Agenda de Bodega — hoy" de Gerencia end-to-end porque
 * `dev.atlanticerp.ai` nunca tuvo un `Order` activo con `committed_delivery_date` = hoy. Este comando
 * garantiza que exista al menos uno, con fecha DINÁMICA (siempre "hoy" al momento de correr, no
 * una fecha fija que vuelva a quedar vieja al día siguiente) — pensado para correr una vez por
 * despliegue/verificación (no forma parte de `DatabaseSeeder`).
 *
 * Marcador `[SEED-RUTA-HOY]` en `customer_name` (mismo criterio que `[UAT-BODEGA]`/`[DEMO]` en
 * otros seeders del proyecto). Idempotente: si YA hay algún `Order` activo (no `STAGE_ENTREGADO`)
 * con `committed_delivery_date` = hoy — propio o de datos reales — no crea uno nuevo, solo lo
 * reporta. Si el marcado de una corrida anterior quedó con fecha de AYER (el caso típico al
 * correr esto un día después de la corrida previa), lo actualiza a hoy en vez de crear uno
 * adicional cada vez — evita acumular pedidos de prueba sin límite a través de múltiples corridas.
 */
final class BodegaSeedTodaysRouteCommand extends Command
{
    private const MARKER = '[SEED-RUTA-HOY]';

    /** @var string */
    protected $signature = 'bodega:seed-todays-route
        {--tenant=illuminations : Slug del tenant a sembrar}
        {--force : Requerido para correr en app()->environment(\'production\') — nunca por accidente}';

    /** @var string */
    protected $description = 'SCRUM-171 — garantiza al menos 1 Order activo con committed_delivery_date=hoy, para que el panel Agenda de Bodega de Gerencia tenga datos que ejercitar';

    public function __construct(private readonly OrderFolioService $folioService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        if (app()->environment('production') && ! (bool) $this->option('force')) {
            $this->error('Este comando siembra data de prueba — requiere --force explícito para correr en production.');

            return self::FAILURE;
        }

        $tenantSlug = (string) $this->option('tenant');
        $tenant     = Tenant::where('slug', $tenantSlug)->first();
        if ($tenant === null) {
            $this->error("Tenant '{$tenantSlug}' no existe.");

            return self::FAILURE;
        }

        $tenant->makeCurrent();

        $today = now()->toDateString();

        $existingReal = Order::whereDate('committed_delivery_date', $today)
            ->whereNotIn('stage', [Order::STAGE_ENTREGADO])
            ->where('customer_name', 'not like', self::MARKER.'%')
            ->exists();

        if ($existingReal) {
            $this->info("Ya hay al menos 1 Order real activo con entrega comprometida hoy ({$today}) — nada que sembrar.");

            return self::SUCCESS;
        }

        $marked = Order::where('customer_name', 'like', self::MARKER.'%')
            ->whereNotIn('stage', [Order::STAGE_ENTREGADO])
            ->first();

        if ($marked !== null) {
            if ($marked->committed_delivery_date?->toDateString() === $today) {
                $this->info("Ya hay un Order marcado {$marked->order_number} con fecha de hoy — nada que hacer.");

                return self::SUCCESS;
            }

            $marked->update(['committed_delivery_date' => $today]);
            $this->info("Order marcado existente {$marked->order_number} actualizado a fecha de hoy ({$today}) en vez de crear uno nuevo.");

            return self::SUCCESS;
        }

        $order = DB::transaction(function () use ($today): Order {
            return Order::create([
                'order_number'             => $this->folioService->next(),
                'pipeline_card_id'         => null,
                'order_type'               => Order::ORDER_TYPE_PEDIDO,
                'stage'                    => Order::STAGE_ASIGNADO,
                'committed_delivery_date'  => $today,
                'customer_name'            => self::MARKER.' Cliente de prueba (verificación Agenda de Bodega)',
                'customer_contact'         => '6000-0000',
                'delivery_address'         => 'Calle 50, Ciudad de Panamá (dirección de prueba)',
                'invoice_ready'            => false,
            ]);
        });

        $this->info("Order {$order->order_number} creado con committed_delivery_date=hoy ({$today}) para ejercitar el panel Agenda de Bodega.");

        return self::SUCCESS;
    }
}
