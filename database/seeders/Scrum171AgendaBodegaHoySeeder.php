<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Bodega\Models\Order;
use App\Shared\Multitenancy\Tenant;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

/**
 * Siembra datos de agenda de Bodega para SCRUM-171 — QA/UAT en dev.atlanticerp.ai.
 *
 * Problema: `agendas.bodega` en /api/gerencia/home siempre devuelve [] porque no hay
 * ningún pedido con `committed_delivery_date = hoy` en el entorno de dev, lo que impide
 * verificar RN1 (agenda en el panel) y RN3 (clic en fila → /bodega/pedidos?order=<id>)
 * end-to-end. Luis reportó esto 3 sesiones seguidas (v5→v6→v7) como bloqueante.
 *
 * Solución: 2 pedidos con `committed_delivery_date = today` (dinámico — siempre "hoy",
 * nunca una fecha fija que envejezca). Idempotente: no vuelve a insertar si ya corrió.
 *
 * Invocación manual desde tinker en cada entorno (NO registrado en DatabaseSeeder):
 *
 *   (new \Database\Seeders\Scrum171AgendaBodegaHoySeeder)->run();
 */
final class Scrum171AgendaBodegaHoySeeder extends Seeder
{
    /** Marcador para idempotencia — buscar en customer_name */
    private const MARKER = '[SCRUM171]';

    public function run(): void
    {
        $tenant = Tenant::where('slug', 'atlantic')->first();

        if ($tenant === null) {
            $this->command?->warn('Tenant "atlantic" no encontrado — seeder abortado.');
            return;
        }

        $tenant->makeCurrent();

        try {
            $this->seed();
        } finally {
            Tenant::forgetCurrent();
        }
    }

    private function seed(): void
    {
        if (Order::where('customer_name', 'like', self::MARKER . '%')->exists()) {
            $this->command?->info('SCRUM-171: ya sembrado, se omite.');
            return;
        }

        $hoy = Carbon::today()->toDateString();

        // Pedido 1 — Por despachar (aparece en salud Bodega chip "urgentes" + agenda hoy)
        Order::create([
            'order_number'          => 'DEMO-171-A',
            'order_type'            => Order::ORDER_TYPE_PEDIDO,
            'stage'                 => Order::STAGE_POR_DESPACHAR,
            'committed_delivery_date' => $hoy,
            'customer_name'         => self::MARKER . ' Grupo Lafayette',
            'customer_contact'      => 'Gerencia Test 2',
            'delivery_address'      => 'Av. Samuel Lewis, Ciudad de Panamá',
            'invoice_ready'         => false,
        ]);

        // Pedido 2 — Despachado (visible en agenda hoy, stage diferente al anterior)
        Order::create([
            'order_number'          => 'DEMO-171-B',
            'order_type'            => Order::ORDER_TYPE_PEDIDO,
            'stage'                 => Order::STAGE_DESPACHADO,
            'committed_delivery_date' => $hoy,
            'customer_name'         => self::MARKER . ' Atlantic Demo',
            'customer_contact'      => 'Gerencia Test 3',
            'delivery_address'      => 'Punta Pacífica, Torre Global Bank, Piso 12',
            'invoice_ready'         => false,
        ]);

        $this->command?->info('SCRUM-171: 2 pedidos sembrados con committed_delivery_date = ' . $hoy);
    }
}
