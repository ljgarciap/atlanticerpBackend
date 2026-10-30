<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\AdminCont\Models\AdminContInvoice;
use App\Shared\Multitenancy\Tenant;

/**
 * Fase 15a — el registro de cobros anterior (`POST /payments`, ligado a facturas) se retiró: el único concepto es el nuevo (`POST /payments/cobro`).
 * Muchos tests genéricos de cobros (recibo en PDF, historial, confirmación, adjuntos…) crean su cobro con la CARGA anterior; este adaptador la traduce
 * a la nueva (`monto_recibido` + `aplicaciones` por factura) para que sigan cubriendo el comportamiento vigente sin reescribir cada llamada.
 *
 * Reglas de la traducción: el monto recibido es el estimado; cada factura recibe lo que le falta (`monto − saldo_aplicado`), en orden, hasta agotar
 * lo recibido, y la retención y el ajuste de cuenta (que deben aplicarse completos) reciben todo lo pendiente.
 */
trait RegistraCobrosConCargaAnterior
{
    /**
     * @param  string  $uri
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $headers
     * @param  int  $options
     */
    public function postJson($uri, array $data = [], array $headers = [], $options = 0)
    {
        if ($uri === '/api/admin-contab/payments' && array_key_exists('invoice_ids', $data)) {
            $uri = '/api/admin-contab/payments/cobro';
            $data = $this->cargaNueva($data);
        }

        return parent::postJson($uri, $data, $headers, $options);
    }

    /**
     * @param  string  $uri
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $headers
     */
    public function post($uri, array $data = [], array $headers = [])
    {
        if ($uri === '/api/admin-contab/payments' && array_key_exists('invoice_ids', $data)) {
            $uri = '/api/admin-contab/payments/cobro';
            $data = $this->cargaNueva($data);
        }

        return parent::post($uri, $data, $headers);
    }

    /**
     * @param  array<string, mixed>  $anterior
     * @return array<string, mixed>
     */
    private function cargaNueva(array $anterior): array
    {
        Tenant::where('slug', 'atlantic')->firstOrFail()->makeCurrent(); // el HTTP previo (login) deja el tenant olvidado

        $recibido = (float) ($anterior['monto_recibido_estimado'] ?? 0);
        $completo = in_array($anterior['metodo_pago'] ?? '', ['retencion_impuestos', 'ajuste_cuenta'], true);
        $restante = $recibido;
        $aplicaciones = [];
        foreach (AdminContInvoice::query()->whereIn('id', $anterior['invoice_ids'])->orderBy('id')->get() as $factura) {
            $pendiente = round((float) $factura->monto - (float) $factura->saldo_aplicado, 2);
            $monto = $completo ? $pendiente : min($pendiente, $restante);
            if ($pendiente <= 0.004) {
                $aplicaciones[] = ['invoice_id' => $factura->id, 'monto' => 0.01]; // una factura ya saldada: el registro nuevo debe rechazarlo
            } elseif ($monto > 0.004) {
                $aplicaciones[] = ['invoice_id' => $factura->id, 'monto' => $monto];
                $restante = round($restante - $monto, 2);
            }
        }

        $nueva = array_filter([
            'master_client_id' => $anterior['master_client_id'] ?? null,
            'monto_recibido' => $completo ? array_sum(array_column($aplicaciones, 'monto')) : $recibido,
            'metodo_pago' => $anterior['metodo_pago'] ?? null,
            'bank_account_id' => $anterior['bank_account_id'] ?? null,
            'numero_documento_retencion' => $anterior['numero_documento_retencion'] ?? null,
            'comentario_ajuste' => $anterior['comentario_ajuste'] ?? null,
            'referencia' => $anterior['referencia'] ?? null,
            'comprobante' => $anterior['comprobante'] ?? null,
        ], fn ($v) => $v !== null);
        $nueva['aplicaciones'] = $aplicaciones;

        return $nueva;
    }
}
