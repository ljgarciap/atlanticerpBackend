<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Http\Controllers;

use App\Models\User;
use App\Modules\AdminCont\Exceptions\InvoiceStateException;
use App\Modules\AdminCont\Exceptions\PaymentStateException;
use App\Modules\AdminCont\Models\AdminContPayment;
use App\Modules\AdminCont\Models\AdminContPaymentReversalRequest;
use App\Modules\AdminCont\Services\ApplicationReversalService;
use App\Modules\AdminCont\Services\PaymentReversalService;
use App\Modules\VentasDiseno\Models\MasterClient;
use App\Shared\Http\Pagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * SCRUM-1168 F4 — reversos del dinero de los cobros:
 *  - desaplicar una aplicación: sin aprobación, queda en el libro con motivo y autor;
 *  - reversar un cruce (compensación) completo: lo solicita Contabilidad y lo decide el aprobador (Fase 13, 2.3);
 *  - anular un cobro: lo solicitan Contabilidad o Asistente Administrativa y lo decide Gerencia (la ruta de decisión es `primary_approver_only`).
 */
class AdminContReversalController extends Controller
{
    public function __construct(
        private readonly ApplicationReversalService $applications,
        private readonly PaymentReversalService $reversals,
    ) {}

    /** POST /api/admin-contab/movimientos/{id}/desaplicar {motivo} */
    public function desaplicar(int $id, Request $request): JsonResponse
    {
        $actor = $this->resolveUser($request);
        if ($actor === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }
        $data = $request->validate(['motivo' => ['required', 'string', 'max:1000']]);

        try {
            $reverso = $this->applications->desaplicar($id, $data['motivo'], $actor);
        } catch (PaymentStateException|InvoiceStateException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['id' => $reverso->id, 'tipo' => $reverso->tipo, 'monto' => $reverso->monto, 'reverses_movement_id' => $reverso->reverses_movement_id], 201);
    }

    /** POST /api/admin-contab/compensaciones/{id}/reverse {motivo} — Fase 13 (2.3): solicita el reverso; lo decide el aprobador o un suplente. */
    public function reversarCompensacion(int $id, Request $request): JsonResponse
    {
        $actor = $this->resolveUser($request);
        if ($actor === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }
        $data = $request->validate(['motivo' => ['required', 'string', 'max:1000']]);

        try {
            $solicitud = $this->reversals->solicitarReversoCompensacion($id, $data['motivo'], $actor);
        } catch (PaymentStateException|InvoiceStateException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($this->present($solicitud), 201);
    }

    /** POST /api/admin-contab/payments/{id}/anular {motivo} — solicita la anulación (la decide Gerencia). */
    public function solicitarAnulacion(int $id, Request $request): JsonResponse
    {
        $actor = $this->resolveUser($request);
        if ($actor === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }
        $data = $request->validate(['motivo' => ['required', 'string', 'max:1000']]);

        try {
            $solicitud = $this->reversals->solicitarAnulacion($id, $data['motivo'], $actor);
        } catch (PaymentStateException|InvoiceStateException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($this->present($solicitud), 201);
    }

    /**
     * POST /api/admin-contab/clientes/{masterClientId}/devolucion (multipart) — solicita devolver dinero del saldo a favor del cliente; la decide Gerencia.
     * Campos: source_type (cobro|abono|nota_credito), source_id, monto, bank_account_id, referencia, motivo, comprobante (archivo, obligatorio).
     */
    public function solicitarDevolucion(int $masterClientId, Request $request): JsonResponse
    {
        $actor = $this->resolveUser($request);
        if ($actor === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }
        $data = $request->validate([
            'source_type'     => ['required', 'in:cobro,abono,nota_credito'],
            'source_id'       => ['required', 'integer', 'min:1'],
            'monto'           => ['required', 'numeric', 'gt:0'],
            'bank_account_id' => ['required', 'integer', 'min:1'],
            'referencia'      => ['required', 'string', 'max:255'],
            'motivo'          => ['required', 'string', 'max:1000'],
            'comprobante'     => ['required', 'file', 'max:10240', 'extensions:pdf,jpg,jpeg,png,webp'],
        ]);

        try {
            $solicitud = $this->reversals->solicitarDevolucion(
                $masterClientId, $data['source_type'], (int) $data['source_id'], (float) $data['monto'], (int) $data['bank_account_id'],
                $data['referencia'], $request->file('comprobante'), $data['motivo'], $actor,
            );
        } catch (PaymentStateException|InvoiceStateException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($this->present($solicitud), 201);
    }

    /** GET /api/admin-contab/reversal-requests?estado=solicitada|ejecutada|rechazada&tipo=anulacion|devolucion|reverso_compensacion */
    public function index(Request $request): JsonResponse
    {
        $query = AdminContPaymentReversalRequest::query()->orderByDesc('id');
        if (in_array($request->query('estado'), ['solicitada', 'ejecutada', 'rechazada'], true)) {
            $query->where('estado', $request->query('estado'));
        }
        if (in_array($request->query('tipo'), ['anulacion', 'devolucion', 'reverso_compensacion'], true)) {
            $query->where('tipo', $request->query('tipo'));
        }
        $rows       = $query->get();
        $pagination = Pagination::params($request, [], 'id', 'desc');
        $paginator  = Pagination::ofArray($this->presentMany(array_values($rows->all())), $pagination['per_page'], $pagination['page']);

        return response()->json(['rows' => $paginator->items(), 'total' => $rows->count(), 'meta' => Pagination::meta($paginator)]);
    }

    /** PUT /api/admin-contab/reversal-requests/{id}/decision {aprobar, motivo_rechazo?} — exclusivo de Gerencia. */
    public function decision(int $id, Request $request): JsonResponse
    {
        $mark = $this->resolveUser($request);
        if ($mark === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }
        $data = $request->validate(['aprobar' => ['required', 'boolean'], 'motivo_rechazo' => ['nullable', 'string', 'max:1000']]);

        try {
            $result = $this->reversals->decidir($id, (bool) $data['aprobar'], $data['motivo_rechazo'] ?? null, $mark);
        } catch (PaymentStateException|InvoiceStateException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($this->present($result['request']) + ['ajustes_comision' => $result['ajustes_comision']]);
    }

    /**
     * @param list<AdminContPaymentReversalRequest> $rows
     * @return list<array<string, mixed>>
     */
    private function presentMany(array $rows): array
    {
        $payments = AdminContPayment::query()->whereIn('id', array_filter(array_map(fn ($r) => $r->payment_id, $rows)))->pluck('numero_recibo', 'id');
        $clients  = MasterClient::query()->whereIn('id', array_map(fn ($r) => $r->master_client_id, $rows))->pluck('name', 'id');
        $users    = User::query()->whereIn('id', array_filter(array_merge(array_map(fn ($r) => $r->requested_by, $rows), array_map(fn ($r) => $r->decided_by, $rows))))->get()->keyBy('id');
        $name     = function (?int $id) use ($users): ?string {
            $u = $id !== null ? $users->get($id) : null;

            return $u !== null ? trim($u->first_name.' '.$u->last_name) : null;
        };

        return array_map(fn (AdminContPaymentReversalRequest $r): array => $this->present($r) + [
            'numero_recibo' => $r->payment_id !== null ? $payments->get($r->payment_id) : null,
            'cliente'       => (string) ($clients->get($r->master_client_id) ?? ''),
            'comprobante_url' => $r->tipo === AdminContPaymentReversalRequest::TIPO_DEVOLUCION ? $this->reversals->comprobanteUrl($r) : null,
            'solicitada_por' => $name($r->requested_by),
            'decidida_por'  => $name($r->decided_by),
        ], $rows);
    }

    /** @return array<string, mixed> */
    private function present(AdminContPaymentReversalRequest $r): array
    {
        return [
            'id' => $r->id, 'tipo' => $r->tipo, 'estado' => $r->estado, 'master_client_id' => $r->master_client_id, 'payment_id' => $r->payment_id,
            'source_type' => $r->source_type, 'source_id' => $r->source_id, 'monto' => $r->monto, 'motivo' => $r->motivo, 'motivo_rechazo' => $r->motivo_rechazo,
            'bank_account_id' => $r->bank_account_id, 'referencia' => $r->referencia, 'tiene_comprobante' => $r->comprobante_key !== null,
            'created_at' => $r->created_at->toIso8601String(), 'decided_at' => $r->decided_at?->toIso8601String(),
        ];
    }

    private function resolveUser(Request $request): ?User
    {
        /** @var array{sub?: int}|null $payload */
        $payload = $request->attributes->get('jwt');

        return $payload !== null && isset($payload['sub']) ? User::find((int) $payload['sub']) : null;
    }
}
