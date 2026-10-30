<?php

declare(strict_types=1);

namespace App\Modules\Compras\Services;

use App\Modules\Compras\Models\PurchaseApprovalRule;
use App\Modules\Compras\Models\PurchaseOrder;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\Eloquent\Collection;

/**
 * Fase 14a — evalúa las reglas de aprobación de órdenes de compra: la PRIMERA regla activa (por `sort_order`, luego `id`) cuyas condiciones
 * coinciden decide; si ninguna coincide, la orden no requiere aprobación. Registrada `scoped` (AppServiceProvider): las reglas se leen una
 * vez por petición/job, con la clave del tenant activo; toda escritura de reglas llama a `forget()`.
 */
final class PurchaseApprovalRules
{
    /** @var array<string, Collection<int, PurchaseApprovalRule>> */
    private array $memo = [];

    public function requiresApproval(?string $shippingType, ?string $whoPaysShipping, float $payableTotal): bool
    {
        return $this->decide($shippingType, $whoPaysShipping, $payableTotal)?->requires_approval === true;
    }

    /** La regla que decide (null si ninguna coincide); sirve a la vista previa de la pantalla. */
    public function decide(?string $shippingType, ?string $whoPaysShipping, float $payableTotal): ?PurchaseApprovalRule
    {
        foreach ($this->active() as $rule) {
            if ($rule->matches($shippingType, $whoPaysShipping, $payableTotal)) {
                return $rule;
            }
        }

        return null;
    }

    /**
     * Evalúa las reglas con el total a pagar YA calculado (líneas guardadas) y fija `requires_primary_approval`. Al crear (`$initialStatus`),
     * también el estado inicial: «Por aprobar» si la requiere; si no, queda el estado con el que se creó. Al editar no toca el estado.
     * Único punto de evaluación: lo usan «Nueva orden», los insumos de Servicios y la reposición por familia (Fase 14e).
     */
    public function applyTo(PurchaseOrder $order, bool $initialStatus): void
    {
        $order->refresh();
        $requires = $this->requiresApproval($order->shipping_type, $order->who_pays_shipping, $order->payableTotal());

        $changes = ['requires_primary_approval' => $requires];
        if ($initialStatus && $requires) {
            $changes['status'] = PurchaseOrder::STATUS_POR_APROBAR;
        }
        $order->update($changes);
    }

    /** @return Collection<int, PurchaseApprovalRule> */
    private function active(): Collection
    {
        $key = Tenant::current()->slug ?? '-';

        return $this->memo[$key] ??= PurchaseApprovalRule::query()
            ->where('is_active', true)->orderBy('sort_order')->orderBy('id')->get();
    }

    public function forget(): void
    {
        $this->memo = [];
    }
}
