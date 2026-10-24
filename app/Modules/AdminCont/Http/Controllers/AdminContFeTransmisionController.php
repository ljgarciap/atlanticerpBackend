<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Http\Controllers;

use App\Modules\AdminCont\Models\AdminContCreditNote;
use App\Modules\AdminCont\Models\AdminContFeTransmision;
use App\Modules\AdminCont\Services\InvoiceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * SCRUM-987 — historial de transmisiones al PAC de una factura o nota de crédito (payload enviado +
 * respuesta recibida). Las rutas viven bajo el middleware `primary_approver_only` (routes/admin-contab.php):
 * el payload lleva datos del receptor (RUC, nombre, correo) y la respuesta cruda del proveedor, así
 * que es deny-by-default para cualquier actor que no sea Gerencia/superadmin.
 */
class AdminContFeTransmisionController
{
    private const LIMITE = 50;

    public function __construct(private readonly InvoiceService $invoices)
    {
    }

    /** GET /api/admin-contab/invoices/{order}/fe-transmisiones?invoice_id= */
    public function factura(int $order, Request $request): JsonResponse
    {
        $request->validate(['invoice_id' => ['sometimes', 'nullable', 'integer', 'min:1']]);

        $invoice = $this->invoices->resolveInvoice($order, $request->filled('invoice_id') ? $request->integer('invoice_id') : null);
        if ($invoice === null) {
            return response()->json(['message' => 'No hay una factura generada para esta entrega.'], 404);
        }

        return response()->json(['data' => $this->historial('factura', $invoice->id)]);
    }

    /** GET /api/admin-contab/notas-credito/{creditNote}/fe-transmisiones */
    public function notaCredito(int $creditNote): JsonResponse
    {
        if (AdminContCreditNote::query()->whereKey($creditNote)->doesntExist()) {
            return response()->json(['message' => 'Nota de crédito no encontrada.'], 404);
        }

        return response()->json(['data' => $this->historial('nota_credito', $creditNote)]);
    }

    /** @return list<array<string, mixed>> */
    private function historial(string $tipo, int $documentoId): array
    {
        return array_values(AdminContFeTransmision::query()
            ->where('documento_tipo', $tipo)
            ->where('documento_id', $documentoId)
            ->orderByDesc('id')
            ->limit(self::LIMITE)
            ->get()
            ->map(static fn (AdminContFeTransmision $t): array => [
                'id'              => $t->id,
                'created_at'      => $t->created_at->toIso8601String(),
                'provider'        => $t->provider,
                'ambiente'        => $t->ambiente,
                'operacion'       => $t->operacion,
                'origen'          => $t->origen,
                'endpoint'        => $t->endpoint,
                'serie'           => $t->serie,
                'numero_fiscal'   => $t->numero_fiscal,
                'resultado'       => $t->resultado,
                'http_status'     => $t->http_status,
                'duracion_ms'     => $t->duracion_ms,
                'request_id'      => $t->request_id,
                'request_payload' => $t->request_payload,
                'response_body'   => $t->response_body,
            ])
            ->all());
    }
}
