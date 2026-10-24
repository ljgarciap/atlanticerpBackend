<?php

declare(strict_types=1);

namespace App\Modules\CRM\Http\Controllers;

use App\Modules\CRM\Http\Requests\CrmSettingsRequest;
use App\Modules\CRM\Services\CrmSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * SCRUM-944/945 — CRUD de los 3 umbrales configurables consumidos por `DashboardService`/
 * `ProjectService` (ver `CrmSettingsService`). Gate de ambas rutas por `superadmin.all` en
 * `routes/crm.php` — a diferencia de Bodega/Fraude, CRM no tiene una pantalla de settings propia
 * todavía: este CRUD solo se consume desde la vista consolidada de superadmin
 * (`GlobalSettingsPage`), no hace falta un permiso de negocio intermedio.
 */
final class CrmSettingsController extends Controller
{
    public function __construct(private readonly CrmSettingsService $settings) {}

    public function show(): JsonResponse
    {
        return response()->json($this->settings->allSettings());
    }

    public function update(CrmSettingsRequest $request): JsonResponse
    {
        $this->settings->updateSettings($request->validated());

        return response()->json($this->settings->allSettings());
    }
}
