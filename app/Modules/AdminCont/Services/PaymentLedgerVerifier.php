<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Services;

use App\Modules\AdminCont\Models\AdminContAbono;
use App\Modules\AdminCont\Models\AdminContCreditNote;
use App\Modules\AdminCont\Models\AdminContInvoice;
use App\Modules\AdminCont\Models\AdminContPaymentMovement;
use Illuminate\Support\Facades\DB;

/**
 * SCRUM-1168 F1 — verificador del libro de movimientos contra el estado real. Es la puerta de salida del modo sombra: las lecturas solo
 * pasan al libro cuando este verificador da CERO diferencias sobre todas las facturas.
 *
 *  1. Por factura: `invoices.saldo_aplicado` debe ser lo aplicado por cobros según el libro (Σ delta_factura) más lo que aportaron las notas
 *     de crédito aplicadas (`monto_aplicado_saldo`; las notas siguen fuera del libro).
 *  2. Por cliente (informativo): el disponible del libro (Σ delta_disponible) debe ser la suma de (monto original − monto usado) de sus abonos
 *     confirmados más lo que el libro dice que sigue sin aplicar de sus cobros del concepto nuevo (ese dinero solo vive en el libro). Un cliente con diferencia indica que algún camino movió un abono sin asentarlo en el libro.
 */
final class PaymentLedgerVerifier
{
    private const TOLERANCIA = 0.01;

    /** @return array{facturas: list<array{invoice_id: int, numero: string, saldo_aplicado: float, libro: float, notas: float, diferencia: float}>, anuladas: list<array{invoice_id: int, numero: string, libro: float}>, clientes: list<array{master_client_id: int, libro: float, abonos: float, diferencia: float}>} */
    public function verify(): array
    {
        $invoices   = (new AdminContInvoice())->getTable();
        $movements  = (new AdminContPaymentMovement())->getTable();
        $notes      = (new AdminContCreditNote())->getTable();
        $abonos     = (new AdminContAbono())->getTable();
        $payments   = (new \App\Modules\AdminCont\Models\AdminContPayment())->getTable();

        $facturas = DB::select(
            "SELECT i.id AS invoice_id, i.numero, i.saldo_aplicado::float AS saldo_aplicado,
                    COALESCE(m.libro, 0)::float AS libro, COALESCE(n.notas, 0)::float AS notas
               FROM {$invoices} i
               LEFT JOIN (SELECT invoice_id, SUM(delta_factura) AS libro FROM {$movements} WHERE invoice_id IS NOT NULL GROUP BY invoice_id) m ON m.invoice_id = i.id
               LEFT JOIN (SELECT factura_origen_id, SUM(monto_aplicado_saldo) AS notas FROM {$notes} WHERE estado = ? GROUP BY factura_origen_id) n ON n.factura_origen_id = i.id
              WHERE ABS(i.saldo_aplicado - (COALESCE(m.libro, 0) + COALESCE(n.notas, 0))) > ?
              ORDER BY i.id",
            [AdminContCreditNote::ESTADO_APLICADA, self::TOLERANCIA],
        );

        // SCRUM-1183 — factura anulada que conserva dinero aplicado por cobros sin que lo respalde una nota de anulación (que decide qué pasa con lo pagado:
        // devolverlo o dejarlo a favor). Una corrección de datos debe haberlo trasladado al reemplazo.
        $anuladas = DB::select(
            "SELECT i.id AS invoice_id, i.numero, m.libro::float AS libro
               FROM {$invoices} i
               JOIN (SELECT invoice_id, SUM(delta_factura) AS libro FROM {$movements} WHERE invoice_id IS NOT NULL GROUP BY invoice_id) m ON m.invoice_id = i.id
              WHERE i.anulada_at IS NOT NULL AND m.libro > ?
                AND NOT EXISTS (SELECT 1 FROM {$notes} n WHERE n.factura_origen_id = i.id AND n.estado = ? AND n.tipo = ? AND COALESCE(n.subtipo_anulacion, '') <> ?)
              ORDER BY i.id",
            [self::TOLERANCIA, AdminContCreditNote::ESTADO_APLICADA, AdminContCreditNote::TIPO_ANULACION_COMPLETA, AdminContCreditNote::SUBTIPO_CORRECCION],
        );

        $clientes = DB::select(
            "SELECT c.master_client_id, COALESCE(l.libro, 0)::float AS libro, (COALESCE(a.abonos, 0) + COALESCE(v.cobros, 0))::float AS abonos
               FROM (SELECT master_client_id FROM {$movements} UNION SELECT master_client_id FROM {$abonos} WHERE estado = ?) c
               LEFT JOIN (SELECT master_client_id, SUM(delta_disponible) AS libro FROM {$movements} GROUP BY master_client_id) l ON l.master_client_id = c.master_client_id
               LEFT JOIN (SELECT master_client_id, SUM(monto_original - monto_usado) AS abonos FROM {$abonos} WHERE estado = ? GROUP BY master_client_id) a ON a.master_client_id = c.master_client_id
               LEFT JOIN (SELECT p.master_client_id, SUM(mm.delta_disponible) AS cobros FROM {$movements} mm JOIN {$payments} p ON p.id = mm.payment_id
                           WHERE p.cobros_v2 = TRUE GROUP BY p.master_client_id) v ON v.master_client_id = c.master_client_id
              WHERE ABS(COALESCE(l.libro, 0) - (COALESCE(a.abonos, 0) + COALESCE(v.cobros, 0))) > ?
              ORDER BY c.master_client_id",
            [AdminContAbono::ESTADO_CONFIRMADO, AdminContAbono::ESTADO_CONFIRMADO, self::TOLERANCIA],
        );

        return [
            'facturas' => array_values(array_map(fn (\stdClass $r): array => [
                'invoice_id' => (int) $r->invoice_id, 'numero' => (string) $r->numero, 'saldo_aplicado' => (float) $r->saldo_aplicado,
                'libro' => (float) $r->libro, 'notas' => (float) $r->notas,
                'diferencia' => round((float) $r->saldo_aplicado - ((float) $r->libro + (float) $r->notas), 2),
            ], $facturas)),
            'anuladas' => array_values(array_map(fn (\stdClass $r): array => [
                'invoice_id' => (int) $r->invoice_id, 'numero' => (string) $r->numero, 'libro' => (float) $r->libro,
            ], $anuladas)),
            'clientes' => array_values(array_map(fn (\stdClass $r): array => [
                'master_client_id' => (int) $r->master_client_id, 'libro' => (float) $r->libro, 'abonos' => (float) $r->abonos,
                'diferencia' => round((float) $r->libro - (float) $r->abonos, 2),
            ], $clientes)),
        ];
    }
}
