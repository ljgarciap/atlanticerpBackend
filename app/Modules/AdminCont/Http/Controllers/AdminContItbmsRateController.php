<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Http\Controllers;

use App\Models\User;
use App\Modules\AdminCont\Http\Requests\StoreItbmsRateRequest;
use App\Modules\AdminCont\Http\Requests\UpdateItbmsRateRequest;
use App\Modules\AdminCont\Models\AdminContItbmsRate;
use App\Modules\VentasDiseno\Models\SubClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** SCRUM-635/636 (REQ-558/559) — catálogo de tasas de ITBMS, gateado por EnsureIsPrimaryApprover. */
class AdminContItbmsRateController
{
    public function index(): JsonResponse
    {
        $rates = AdminContItbmsRate::query()
            ->orderByDesc('es_base')
            ->orderBy('id')
            ->get();

        return response()->json($rates);
    }

    public function store(StoreItbmsRateRequest $request): JsonResponse
    {
        $user = $this->resolveUser($request);

        // RN4 de REQ-558: una tasa nueva siempre se crea desactivada, sin importar qué mande el
        // cliente — `activa` ni siquiera está en las reglas de StoreItbmsRateRequest, así que
        // $request->validated() nunca la trae. `es_base` queda fuera de $fillable a propósito
        // (nunca se crea una tasa base por request), así que se asigna directo, no vía create().
        $rate = new AdminContItbmsRate($request->validated());
        $rate->es_base = false;
        $rate->activa = false;
        $rate->created_by = $user?->id;
        $rate->save();

        return response()->json($rate, 201);
    }

    public function update(UpdateItbmsRateRequest $request, int $id): JsonResponse
    {
        $rate = AdminContItbmsRate::find($id);
        if ($rate === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        // SCRUM-1166 — la tasa base «0% — Exento» no se desactiva mientras haya clientes exentos: sus cotizaciones y facturas la necesitan y, sin ella,
        // cotizar y facturar a esos clientes fallaría. Hay que quitar primero la exención de los clientes (Datos fiscales de clientes).
        if ($rate->es_base && (float) $rate->porcentaje === 0.0 && ! (bool) $request->validated('activa')) {
            $exentos = SubClient::query()->where('itbms_exento', true)->count();
            if ($exentos > 0) {
                $impuesto = \App\Shared\Multitenancy\TenantRegion::taxLabel();

                return response()->json([
                    'message' => "No se puede desactivar la tasa «{$rate->nombre}»: hay {$exentos} cliente(s) exento(s) de {$impuesto} que la usan. Quita primero su exención en Datos fiscales de clientes.",
                ], 422);
            }
        }

        $rate->update($request->validated());

        return response()->json($rate->fresh());
    }

    public function destroy(int $id): JsonResponse
    {
        $rate = AdminContItbmsRate::find($id);
        if ($rate === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        // RN1 de REQ-558: las 3 tasas base nunca se pueden eliminar, solo desactivar.
        if ($rate->es_base) {
            return response()->json(['message' => 'Una tasa base no se puede eliminar, solo desactivar.'], 403);
        }

        $rate->delete();

        return response()->json(null, 204);
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
