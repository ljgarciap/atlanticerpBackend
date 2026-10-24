<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Http\Controllers;

use App\Models\User;
use App\Modules\AdminCont\Exceptions\ItbmsExemptionException;
use App\Modules\AdminCont\Services\ItbmsExemptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * SCRUM-1166 — marcar o quitar la exención de ITBMS de un cliente fiscal (sub-cliente). La ruta exige el permiso angosto
 * `admin_contab.edit.client_itbms_exemption`; quien marca debe indicar el documento que la respalda.
 */
class AdminContClientItbmsExemptionController extends Controller
{
    public const PERMISO = 'admin_contab.edit.client_itbms_exemption';

    public function __construct(private readonly ItbmsExemptionService $exemptions) {}

    /** PUT /api/admin-contab/clientes/{subClientId}/exencion-itbms {exento, documento?} */
    public function update(int $subClientId, Request $request): JsonResponse
    {
        $actor = $this->resolveUser($request);
        if ($actor === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $data = $request->validate([
            'exento'    => ['required', 'boolean'],
            'documento' => ['required_if:exento,true', 'nullable', 'string', 'max:255'],
        ]);

        try {
            $result = $data['exento']
                ? $this->exemptions->marcar($subClientId, (string) ($data['documento'] ?? ''), $actor)
                : $this->exemptions->quitar($subClientId, $actor);
        } catch (ItbmsExemptionException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $cliente = $result['sub_client'];

        return response()->json([
            'id'                       => $cliente->id,
            'itbms_exento'             => $cliente->itbms_exento,
            'itbms_exencion_documento' => $cliente->itbms_exencion_documento,
            'itbms_exento_at'          => $cliente->itbms_exento_at?->toIso8601String(),
            'borradores_actualizados'  => $result['borradores_actualizados'],
            'confirmadas_sin_facturar' => $result['confirmadas_sin_facturar'],
        ]);
    }

    private function resolveUser(Request $request): ?User
    {
        /** @var array{sub?: int}|null $payload */
        $payload = $request->attributes->get('jwt');

        return $payload !== null && isset($payload['sub']) ? User::find((int) $payload['sub']) : null;
    }
}
