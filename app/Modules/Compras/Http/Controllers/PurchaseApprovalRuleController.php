<?php

declare(strict_types=1);

namespace App\Modules\Compras\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Compras\Models\PurchaseApprovalRule;
use App\Modules\Compras\Models\PurchaseOrder;
use App\Modules\Compras\Services\PurchaseApprovalRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Fase 14a — administración de las reglas de aprobación de órdenes de compra (solo superadmin: rutas bajo `permission:superadmin.all`, sin
 * permiso nuevo). Cada alta, edición y baja queda en la auditoría por `LogsAuditTrail` del modelo. Quién aprueba NO se decide aquí
 * (aprobador primario o suplente, Fase 12b): las reglas solo deciden CUÁNDO una orden requiere aprobación.
 */
class PurchaseApprovalRuleController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => PurchaseApprovalRule::query()->orderBy('sort_order')->orderBy('id')->get()->map(fn (PurchaseApprovalRule $r): array => $this->present($r))->all(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $rule = PurchaseApprovalRule::create($this->validated($request));
        app(PurchaseApprovalRules::class)->forget();

        return response()->json($this->present($rule), 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $rule = PurchaseApprovalRule::findOrFail($id);
        $rule->update($this->validated($request));
        app(PurchaseApprovalRules::class)->forget();

        return response()->json($this->present($rule->refresh()));
    }

    public function destroy(int $id): JsonResponse
    {
        PurchaseApprovalRule::findOrFail($id)->delete();
        app(PurchaseApprovalRules::class)->forget();

        return response()->json(['message' => 'Regla eliminada.']);
    }

    /** Vista previa: con las reglas ACTIVAS de hoy, ¿una orden así requiere aprobación y qué regla decide? No guarda nada. */
    public function preview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'shipping_type' => ['nullable', Rule::in([PurchaseOrder::SHIPPING_AEREO, PurchaseOrder::SHIPPING_MARITIMO, PurchaseOrder::SHIPPING_TERRESTRE])],
            'who_pays_shipping' => ['nullable', Rule::in([PurchaseOrder::PAYS_ATLANTIC, PurchaseOrder::PAYS_PROVIDER])],
            'amount' => ['required', 'numeric', 'min:0'],
        ]);

        $rule = app(PurchaseApprovalRules::class)->decide($data['shipping_type'] ?? null, $data['who_pays_shipping'] ?? null, (float) $data['amount']);

        return response()->json([
            'requires_approval' => $rule?->requires_approval === true,
            'rule' => $rule === null ? null : ['id' => $rule->id, 'name' => $rule->name],
        ]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:100000'],
            'is_active' => ['required', 'boolean'],
            'shipping_type' => ['nullable', Rule::in([PurchaseOrder::SHIPPING_AEREO, PurchaseOrder::SHIPPING_MARITIMO, PurchaseOrder::SHIPPING_TERRESTRE])],
            'who_pays_shipping' => ['nullable', Rule::in([PurchaseOrder::PAYS_ATLANTIC, PurchaseOrder::PAYS_PROVIDER])],
            'min_amount' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'max_amount' => ['nullable', 'numeric', 'min:0', 'max:999999999999', 'gte:min_amount'],
            'requires_approval' => ['required', 'boolean'],
        ]);

        // El pagador del envío solo existe en envío aéreo (PurchaseOrder::normalizeShippingFields): una regla con otro tipo y pagador no coincidiría nunca.
        if (($data['who_pays_shipping'] ?? null) !== null && ($data['shipping_type'] ?? PurchaseOrder::SHIPPING_AEREO) !== PurchaseOrder::SHIPPING_AEREO) {
            abort(response()->json(['message' => 'El pagador del envío solo aplica al envío aéreo.', 'errors' => ['who_pays_shipping' => ['El pagador del envío solo aplica al envío aéreo.']]], 422));
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private function present(PurchaseApprovalRule $r): array
    {
        return [
            'id' => $r->id,
            'name' => $r->name,
            'sort_order' => $r->sort_order,
            'is_active' => $r->is_active,
            'shipping_type' => $r->shipping_type,
            'who_pays_shipping' => $r->who_pays_shipping,
            'min_amount' => $r->min_amount === null ? null : (float) $r->min_amount,
            'max_amount' => $r->max_amount === null ? null : (float) $r->max_amount,
            'requires_approval' => $r->requires_approval,
        ];
    }
}
