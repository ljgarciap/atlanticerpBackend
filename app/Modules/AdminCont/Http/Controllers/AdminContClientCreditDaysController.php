<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Http\Controllers;

use App\Modules\AdminCont\Models\AdminContClientBillingProfile;
use App\Modules\AdminCont\Services\CreditTerms;
use App\Modules\VentasDiseno\Models\MasterClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Fase 28 (grupo 3) — días de crédito propios de un cliente. `dias_credito` null = el cliente sigue el
 * valor global de Configuración Fiscal. El cambio afecta el vencimiento y la antigüedad de cartera de sus
 * facturas (se recalculan en vivo); el cambio queda en la auditoría del perfil (`LogsAuditTrail`).
 */
class AdminContClientCreditDaysController
{
    public function update(Request $request, int $masterClientId): JsonResponse
    {
        $data = $request->validate(['dias_credito' => ['present', 'nullable', 'integer', 'min:1', 'max:365']]);

        if (MasterClient::find($masterClientId) === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        AdminContClientBillingProfile::query()->updateOrCreate(
            ['master_client_id' => $masterClientId],
            ['dias_credito' => $data['dias_credito']],
        );

        return response()->json([
            'master_client_id'      => $masterClientId,
            'dias_credito'          => $data['dias_credito'],
            'dias_credito_efectivo' => (new CreditTerms())->diasPara($masterClientId),
        ]);
    }
}
