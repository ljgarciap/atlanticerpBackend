<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Http\Controllers;

use App\Modules\AdminCont\FacturacionElectronica\Services\PacProviderFactory;
use App\Modules\AdminCont\Http\Requests\UpdateFiscalSettingsRequest;
use App\Modules\AdminCont\Models\AdminContFiscalSettings;
use Illuminate\Http\JsonResponse;

/** SCRUM-632/633/634/637 (REQ-555/556/557/560) — Configuración Fiscal, gateada por EnsureIsPrimaryApprover sobre toda la ruta. */
class AdminContFiscalSettingsController
{
    public function __construct(private readonly PacProviderFactory $pacProviderFactory) {}

    public function show(): JsonResponse
    {
        return response()->json($this->present(AdminContFiscalSettings::current()));
    }

    public function update(UpdateFiscalSettingsRequest $request): JsonResponse
    {
        $settings = AdminContFiscalSettings::current();
        $settings->update($request->validated());

        return response()->json($this->present($settings->fresh() ?? $settings));
    }

    /**
     * SCRUM-943 — `pac_connection_status` deja de ser el valor fijo en BD (REQ-557, sin
     * integración real cuando se creó) y pasa a reflejar si el proveedor/ambiente activo tiene
     * credenciales reales configuradas — ver `PacProviderFactory::tieneCredencialesReales()`.
     *
     * @return array<string, mixed>
     */
    private function present(AdminContFiscalSettings $settings): array
    {
        $data = $settings->toArray();
        $data['pac_connection_status'] = $this->pacProviderFactory->tieneCredencialesReales() ? 'conectado' : 'desconectado';

        return $data;
    }
}
