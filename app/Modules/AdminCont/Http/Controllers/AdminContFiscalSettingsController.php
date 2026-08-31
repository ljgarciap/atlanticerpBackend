<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Http\Controllers;

use App\Modules\AdminCont\Http\Requests\UpdateFiscalSettingsRequest;
use App\Modules\AdminCont\Models\AdminContFiscalSettings;
use Illuminate\Http\JsonResponse;

/** SCRUM-632/633/634/637 (REQ-555/556/557/560) — Configuración Fiscal, gateada por EnsureIsPrimaryApprover sobre toda la ruta. */
class AdminContFiscalSettingsController
{
    public function show(): JsonResponse
    {
        return response()->json(AdminContFiscalSettings::current());
    }

    public function update(UpdateFiscalSettingsRequest $request): JsonResponse
    {
        $settings = AdminContFiscalSettings::current();
        $settings->update($request->validated());

        return response()->json($settings->fresh());
    }
}
