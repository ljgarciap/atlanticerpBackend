<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Http\Controllers;

use App\Modules\VentasDiseno\Http\Requests\ReplaceSalesCommissionTiersRequest;
use App\Modules\VentasDiseno\Models\SalesCommissionTier;
use App\Shared\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

/**
 * SCRUM-944/945 — tramos de comisión escalonada de vendedores (antes hardcodeados en
 * `SalesTotalsService::percentFor()`). Lectura (`index`) + ÚNICO camino de escritura: el reemplazo
 * atómico `replace()` (Pre-QA hallazgo A, Senior Review S1 — los POST/PUT/DELETE individuales se
 * retiraron porque permitían reproducir solapamientos/huecos tramo a tramo). Gateado por
 * `superadmin.all` en routes/ventas-diseno.php, sin pantalla propia (solo `GlobalSettingsPage`).
 */
final class SalesCommissionTierController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(
            SalesCommissionTier::query()->orderBy('orden')->get(),
        );
    }

    /**
     * Pre-QA SCRUM-945 hallazgo A — reemplazo ATÓMICO de la tabla completa: valida cobertura
     * completa (ver ReplaceSalesCommissionTiersRequest/CommissionTierRules) y aplica todo en una
     * transacción. Los eventos de modelo se suspenden a propósito (`withoutEvents`) para dejar UNA
     * sola fila en audit_logs con el antes/después completo, en vez de N filas sueltas.
     */
    public function replace(ReplaceSalesCommissionTiersRequest $request, AuditService $audit): JsonResponse
    {
        /** @var list<array{id?: int|null, monto_minimo: mixed, monto_maximo: mixed, porcentaje: mixed}> $tiers */
        $tiers = $request->validated()['tiers'];
        usort($tiers, static fn (array $a, array $b): int => (float) $a['monto_minimo'] <=> (float) $b['monto_minimo']);

        // S6 — snapshot "antes", escritura, snapshot "después" y audit en UNA transacción: el
        // registro de auditoría nunca describe un estado distinto del que quedó persistido.
        $after = DB::transaction(function () use ($tiers, $audit): array {
            $before = $this->snapshot();

            SalesCommissionTier::withoutEvents(function () use ($tiers): void {
                $keepIds = [];
                foreach ($tiers as $index => $data) {
                    $attrs = [
                        'monto_minimo' => $data['monto_minimo'],
                        'monto_maximo' => $data['monto_maximo'],
                        'porcentaje'   => $data['porcentaje'],
                        'orden'        => $index + 1,
                    ];
                    $tier = isset($data['id']) ? SalesCommissionTier::find((int) $data['id']) : null;
                    if ($tier !== null) {
                        $tier->update($attrs);
                    } else {
                        $tier = SalesCommissionTier::create($attrs);
                    }
                    $keepIds[] = $tier->id;
                }
                SalesCommissionTier::query()->whereNotIn('id', $keepIds)->delete();
            });

            $after = $this->snapshot();
            $audit->log('ventas_diseno.sales_commission_tiers.replaced', 'sales_commission_tiers', null, ['tiers' => $before], ['tiers' => $after]);

            return $after;
        });

        return response()->json($after);
    }

    /** @return list<array<string, mixed>> */
    private function snapshot(): array
    {
        return array_values(SalesCommissionTier::query()->orderBy('orden')->get()->toArray());
    }
}
