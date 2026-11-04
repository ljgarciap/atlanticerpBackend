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

        return response()->json($this->present($rule) + ['warnings' => $this->overlapWarnings($rule)], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $rule = PurchaseApprovalRule::findOrFail($id);
        $rule->update($this->validated($request));
        app(PurchaseApprovalRules::class)->forget();

        $rule->refresh();

        return response()->json($this->present($rule) + ['warnings' => $this->overlapWarnings($rule)]);
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
            'requires_approval' => $rule === null ? app(PurchaseApprovalRules::class)->noRuleRequiresApproval() : $rule->requires_approval === true,
            'rule' => $rule === null ? null : ['id' => $rule->id, 'name' => $rule->name],
        ]);
    }

    /**
     * Fase 20d — rangos de monto que NINGUNA regla activa cubre, por cada combinación de envío (aéreo pagado por la empresa o por el proveedor,
     * marítimo, terrestre). Solo informa: la orden de un hueco se resuelve con `purchase_approval_no_rule`. Montos en centavos para no
     * arrastrar errores de coma flotante; `to = null` es «sin tope».
     */
    public function coverage(): JsonResponse
    {
        $rules = PurchaseApprovalRule::query()->where('is_active', true)->get();
        $combos = [
            [PurchaseOrder::SHIPPING_AEREO, PurchaseOrder::PAYS_ATLANTIC],
            [PurchaseOrder::SHIPPING_AEREO, PurchaseOrder::PAYS_PROVIDER],
            [PurchaseOrder::SHIPPING_MARITIMO, null],
            [PurchaseOrder::SHIPPING_TERRESTRE, null],
        ];

        $out = [];
        foreach ($combos as [$shipping, $payer]) {
            $ranges = [];
            foreach ($rules as $r) {
                if (($r->shipping_type !== null && $r->shipping_type !== $shipping) || ($r->who_pays_shipping !== null && $r->who_pays_shipping !== $payer)) {
                    continue;
                }
                $ranges[] = [$r->min_amount === null ? 0 : (int) round((float) $r->min_amount * 100), $r->max_amount === null ? null : (int) round((float) $r->max_amount * 100)];
            }
            usort($ranges, fn (array $a, array $b): int => $a[0] <=> $b[0]);

            $gaps = [];
            $next = 0; // primer centavo aún sin cubrir
            foreach ($ranges as [$min, $max]) {
                if ($next === null) {
                    break;
                }
                if ($min > $next) {
                    $gaps[] = ['from' => $next / 100, 'to' => ($min - 1) / 100];
                }
                $next = $max === null ? null : max($next, $max + 1);
            }
            if ($next !== null) {
                $gaps[] = ['from' => $next / 100, 'to' => null];
            }

            $out[] = ['shipping_type' => $shipping, 'who_pays_shipping' => $payer, 'gaps' => $gaps];
        }

        return response()->json([
            'no_rule_requires_approval' => app(PurchaseApprovalRules::class)->noRuleRequiresApproval(),
            'has_gaps' => collect($out)->contains(fn (array $c): bool => $c['gaps'] !== []),
            'combos' => $out,
        ]);
    }

    /**
     * Fase 20d — avisos (no bloquean) de una regla activa que se solapa con otra regla activa del MISMO alcance (igual tipo de envío e igual
     * pagador) en un rango de monto: la de menor orden gana y la otra queda sin efecto en esa franja.
     *
     * @return list<array{rule_id: int, name: string, message: string}>
     */
    private function overlapWarnings(PurchaseApprovalRule $rule): array
    {
        if (! $rule->is_active) {
            return [];
        }
        $lo = $rule->min_amount === null ? 0.0 : (float) $rule->min_amount;
        $hi = $rule->max_amount === null ? INF : (float) $rule->max_amount;

        $warnings = [];
        $others = PurchaseApprovalRule::query()->where('is_active', true)->where('id', '!=', $rule->id)
            ->where('shipping_type', $rule->shipping_type)->where('who_pays_shipping', $rule->who_pays_shipping)->get();
        foreach ($others as $o) {
            $olo = $o->min_amount === null ? 0.0 : (float) $o->min_amount;
            $ohi = $o->max_amount === null ? INF : (float) $o->max_amount;
            if ($lo <= $ohi && $olo <= $hi) {
                $warnings[] = [
                    'rule_id' => $o->id,
                    'name' => $o->name,
                    'message' => "Se solapa con la regla «{$o->name}»: en los montos comunes solo aplica la de menor orden.",
                ];
            }
        }

        return $warnings;
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
