<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Http\Controllers;

use App\Models\User;
use App\Modules\AdminCont\Exceptions\InvoiceStateException;
use App\Modules\AdminCont\Exceptions\PaymentStateException;
use App\Modules\AdminCont\Services\CobrosConfigService;
use App\Modules\AdminCont\Services\CompensationPdfService;
use App\Modules\AdminCont\Services\CompensationService;
use App\Shared\Http\Pagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

/**
 * SCRUM-1168 F3 — Compensaciones: vista aparte, por cliente, para cruzar el dinero disponible (cobros a cuenta, abonos históricos, saldo de notas)
 * contra sus facturas pendientes. Solo con el concepto nuevo de cobros activo (`cobros_v2_activo`).
 */
class AdminContCompensacionController extends Controller
{
    public function __construct(
        private readonly CompensationService $compensations,
        private readonly CompensationPdfService $pdf,
        private readonly CobrosConfigService $config,
    ) {}

    private function inactivo(): ?JsonResponse
    {
        return $this->config->v2Activo() ? null : response()->json(['message' => 'Las compensaciones todavía no están activas.'], 422);
    }

    /** GET /api/admin-contab/compensaciones/clientes?search=&signo=positivo|negativo&page=&per_page= */
    public function clientes(Request $request): JsonResponse
    {
        if (($off = $this->inactivo()) !== null) {
            return $off;
        }

        $result     = $this->compensations->clientes([
            'search' => $request->query('search'),
            'signo'  => in_array($request->query('signo'), ['positivo', 'negativo'], true) ? $request->query('signo') : null,
        ]);
        $pagination = Pagination::params($request, [], 'saldo_a_favor', 'desc');
        $paginator  = Pagination::ofArray($result['rows'], $pagination['per_page'], $pagination['page']);

        return response()->json([
            'indicadores' => $result['indicadores'],
            'rows'        => $paginator->items(),
            'total'       => count($result['rows']),
            'meta'        => Pagination::meta($paginator),
        ]);
    }

    /** GET /api/admin-contab/compensaciones/clientes/{masterClientId} */
    public function cliente(int $masterClientId): JsonResponse
    {
        if (($off = $this->inactivo()) !== null) {
            return $off;
        }

        return response()->json($this->compensations->detalle($masterClientId));
    }

    /** POST /api/admin-contab/compensaciones/preview — cómo quedaría el cruce, sin cambiar nada. */
    public function preview(Request $request): JsonResponse
    {
        if (($off = $this->inactivo()) !== null) {
            return $off;
        }
        [$masterClientId, $lines] = $this->parse($request);

        try {
            return response()->json($this->compensations->preview($masterClientId, $lines));
        } catch (PaymentStateException|InvoiceStateException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /** POST /api/admin-contab/compensaciones — confirma el cruce (folio CMP-XXX). */
    public function store(Request $request): JsonResponse
    {
        if (($off = $this->inactivo()) !== null) {
            return $off;
        }
        $actor = $this->resolveUser($request);
        if ($actor === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }
        [$masterClientId, $lines] = $this->parse($request);

        try {
            $compensation = $this->compensations->confirm($masterClientId, $lines, $actor);
        } catch (PaymentStateException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($this->compensations->presentar($compensation), 201);
    }

    /** GET /api/admin-contab/compensaciones/{id} */
    public function show(int $id): JsonResponse
    {
        $compensation = $this->compensations->find($id);
        if ($compensation === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        return response()->json($this->compensations->presentar($compensation));
    }

    /** GET /api/admin-contab/compensaciones/{id}/pdf — documento con folio. */
    public function pdfDocument(int $id): \Illuminate\Http\Response|JsonResponse
    {
        $compensation = $this->compensations->find($id);
        if ($compensation === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        return $this->pdf->generate($this->compensations->presentar($compensation))->stream("compensacion-{$compensation->folio}.pdf");
    }

    /** @return array{0: int, 1: list<array{source_type: string, source_id: int, invoice_id: int, monto: float}>} */
    private function parse(Request $request): array
    {
        $data = $request->validate([
            'master_client_id'      => ['required', 'integer'],
            'lines'                 => ['required', 'array', 'min:1', 'max:300'],
            'lines.*.source_type'   => ['required', Rule::in(['cobro', 'abono', 'nota_credito'])],
            'lines.*.source_id'     => ['required', 'integer'],
            'lines.*.invoice_id'    => ['required', 'integer'],
            'lines.*.monto'         => ['required', 'numeric', 'gt:0', 'max:99999999.99'],
        ]);

        $lines = array_values(array_map(fn (array $l): array => [
            'source_type' => (string) $l['source_type'], 'source_id' => (int) $l['source_id'],
            'invoice_id' => (int) $l['invoice_id'], 'monto' => round((float) $l['monto'], 2),
        ], $data['lines']));

        return [(int) $data['master_client_id'], $lines];
    }

    private function resolveUser(Request $request): ?User
    {
        /** @var array{sub?: int}|null $payload */
        $payload = $request->attributes->get('jwt');

        return $payload !== null && isset($payload['sub']) ? User::find((int) $payload['sub']) : null;
    }
}
