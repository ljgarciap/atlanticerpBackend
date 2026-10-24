<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Services;

use App\Modules\AdminCont\Exceptions\InvoiceStateException;
use App\Modules\AdminCont\Models\AdminContInvoice;
use Illuminate\Support\Facades\DB;

/**
 * SCRUM-1168 F1 — ÚNICO escritor de `invoices.saldo_aplicado`. Hoy de ese acumulador leen la factura «cobrada», el aging, el Estado de Cuenta, las
 * comisiones, los reportes y la facturación electrónica; si cualquier camino lo escribe por fuera, todos esos lectores quedan mal a la vez.
 * Centralizarlo permite además verificar el invariante (`cobros:verificar-libro`): saldo_aplicado = lo aplicado por cobros (libro) + notas de crédito.
 *
 * Debe llamarse dentro de una transacción con la factura ya bloqueada (`lockForUpdate`).
 */
final class InvoiceBalanceWriter
{
    /**
     * @param bool $permitirExceso solo para las notas de crédito, que conservan su regla propia (valor acreditable) tal cual estaba; los cobros
     *        nunca pueden dejar la factura con más aplicado que su valor ni con menos de cero.
     * @throws InvoiceStateException si el movimiento dejaría el saldo fuera de [0, monto].
     */
    public function apply(AdminContInvoice $invoice, float $delta, bool $permitirExceso = false): void
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('InvoiceBalanceWriter::apply() debe correr dentro de una transacción con la factura bloqueada.');
        }

        $nuevo = round((float) $invoice->saldo_aplicado + $delta, 2);

        if ($nuevo < -0.005 || (! $permitirExceso && $nuevo > (float) $invoice->monto + 0.005)) {
            throw new InvoiceStateException(
                "El movimiento dejaría la factura {$invoice->numero} con un saldo aplicado de \${$nuevo}, fuera de lo permitido (0 a ".number_format((float) $invoice->monto, 2).').'
            );
        }

        $invoice->update(['saldo_aplicado' => max(0.0, $nuevo)]);
    }
}
