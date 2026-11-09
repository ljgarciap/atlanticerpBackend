<?php

declare(strict_types=1);

namespace App\Modules\Servicios\Http\Controllers;

use App\Models\User;
use App\Modules\Servicios\Models\InsumoSetting;
use App\Modules\Servicios\Services\InsumoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Fase 4 — Servicios, Grupo D parte 2 (REQ-273→275, SCRUM-343/344/345). Ver docblock de
 * `InsumoService`. REQ-275 (buscador de productos para agregar un insumo nuevo) NO tiene endpoint
 * propio acá — reusa `GET /api/servicios/lookup/products` (`ProductLookupController`, ya expone
 * `exclude` para filtrar del lado del servidor los productos ya trackeados).
 */
final class InsumoController extends Controller
{
    public function __construct(private readonly InsumoService $insumos) {}

    /** GET /api/servicios/insumos — REQ-273. */
    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->insumos->list()]);
    }

    /** POST /api/servicios/insumos — REQ-275. RN5 (rol): validado en la ruta. */
    public function store(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        /** @var array{catalog_product_id: int, minimo: int, cantidad_inicial: int, existencia_inicial?: ?int} $validated */
        $validated = $request->validate([
            'catalog_product_id' => ['required', 'integer', 'exists:catalog_products,id'],
            'minimo'              => ['required', 'integer', 'min:0'],
            'cantidad_inicial'    => ['required', 'integer', 'min:0'],
            'existencia_inicial'  => ['nullable', 'integer', 'min:0'],
        ]);

        try {
            $result = $this->insumos->addInsumo(
                (int) $validated['catalog_product_id'],
                (int) $validated['minimo'],
                (int) $validated['cantidad_inicial'],
                $user,
                (int) ($validated['existencia_inicial'] ?? 0),
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($result, 201);
    }

    /** POST /api/servicios/insumos/{id}/solicitar — REQ-274. RN5 (rol): validado en la ruta. */
    /** PATCH /api/servicios/insumos/{id} — Fase 30, corrige el mínimo. */
    public function update(Request $request, int $id): JsonResponse
    {
        $setting = InsumoSetting::where('is_active', true)->find($id);
        if ($setting === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $validated = $request->validate(['minimo' => ['required', 'integer', 'min:0']]);
        $this->insumos->updateMinimo($setting, (int) $validated['minimo']);

        $row = collect($this->insumos->list())->firstWhere('id', $setting->id);

        return response()->json($row);
    }

    /** DELETE /api/servicios/insumos/{id} — Fase 30, baja (desactiva; el historial se conserva). */
    public function destroy(int $id): JsonResponse
    {
        $setting = InsumoSetting::where('is_active', true)->find($id);
        if ($setting === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $this->insumos->deactivate($setting);

        return response()->json(['message' => 'Insumo dado de baja.']);
    }

    public function storePurchaseRequest(Request $request, int $id): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $setting = InsumoSetting::find($id);
        if ($setting === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        /** @var array{cantidad: int} $validated */
        $validated = $request->validate(['cantidad' => ['required', 'integer', 'min:1']]);

        try {
            $purchaseRequest = $this->insumos->requestPurchase($setting, (int) $validated['cantidad'], $user);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($this->insumos->presentPurchaseRequest($purchaseRequest), 201);
    }

    private function resolveUser(Request $request): ?User
    {
        /** @var array{sub?: int}|null $payload */
        $payload = $request->attributes->get('jwt');

        if ($payload === null || ! isset($payload['sub'])) {
            return null;
        }

        return User::find((int) $payload['sub']);
    }
}
