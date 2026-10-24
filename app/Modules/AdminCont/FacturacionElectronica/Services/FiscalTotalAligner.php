<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\FacturacionElectronica\Services;

use App\Modules\AdminCont\Models\AdminContAbono;
use App\Modules\AdminCont\Models\AdminContCreditNote;
use App\Modules\AdminCont\Models\AdminContInvoice;
use App\Modules\AdminCont\Models\AdminContInvoiceLine;
use App\Modules\AdminCont\Models\AdminContPaymentInvoice;
use App\Shared\Services\AuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-1185 (hotfix, decisión de Luis 2026-10-21) — el total del documento fiscal es la ÚNICA fuente de verdad del monto de la factura.
 *
 * Cuando `ItbmsLineBalancer` tiene que mover centavos entre líneas para que la factura pase la validación de totales de la DGI, el total
 * fiscal (neto + ITBMS redondeado por línea = el `dVTot` que firma el PAC) puede diferir en 1 o 2 centavos del `monto` con el que se creó
 * la factura (que sale del ITBMS sobre el total de la cotización). Para que AtlanticERP y el documento fiscal digan lo mismo, ANTES del primer
 * envío se actualizan las líneas de la factura (subtotal, ITBMS, total) y su `monto`, y la diferencia se anota en `ajuste_itbms_delta`:
 * es el mismo mecanismo que usa la «Corrección de datos» (SCRUM-929 B3b Punto 4) para que el pedido no quede con centavos «por facturar».
 *
 * Solo se alinea si, y únicamente si, TODAS estas condiciones se cumplen (si alguna falla, no se toca nada y el documento se envía
 * balanceado igual — `FiscalDocumentItemsBuilder` balancea los mismos netos de forma idéntica — dejando constancia en el log):
 *  - el balanceador movió centavos y dejó la factura dentro de la tolerancia;
 *  - la factura tiene líneas y una tasa en cada una;
 *  - nunca se certificó ni está pendiente de resolver ante el PAC (sin CUFE, sin fecha de certificación);
 *  - no está anulada ni marcada incobrable;
 *  - no tiene cobros aplicados, movimientos de saldo, abonos, compensaciones, saldo aplicado ni notas de crédito: cambiar su monto
 *    obligaría a conciliar pagos que ya se registraron contra el monto anterior.
 *
 * Idempotente: tras alinear, las líneas ya están balanceadas y una segunda pasada no encuentra nada que mover.
 */
final class FiscalTotalAligner
{
    /**
     * Diferencia máxima (centavos) entre el monto guardado y el total fiscal para que se alinee: 5 más 1 por cada 10 líneas (tope 50). El monto
     * viene del ITBMS sobre el total de la cotización y el total fiscal de sumar el de cada línea, así que la diferencia natural crece con las líneas.
     */
    private const MARGEN_BASE_CENTAVOS = 5;
    private const MARGEN_TOPE_CENTAVOS = 50;

    public function __construct(
        private readonly ItbmsLineBalancer $balancer,
        private readonly AuditService $audit,
    ) {
    }

    /**
     * @return array{alineada: bool, motivo: string}
     */
    public function alinear(AdminContInvoice $invoice): array
    {
        $invoice->loadMissing('lines.itbmsRate');

        if ($invoice->lines->isEmpty()) {
            return $this->noAlinea('sin_lineas');
        }
        if ($motivo = $this->motivoPorEstado($invoice)) {
            return $this->noAlinea($motivo);
        }

        $entrada = $this->entrada($invoice);
        if ($entrada === null) {
            return $this->noAlinea('linea_sin_tasa');
        }

        $resultado = $this->balancer->balancear($entrada);
        if (! $resultado->cambiado()) {
            return $this->noAlinea('no_requiere_balanceo');
        }
        if (! $resultado->dentroDelLimite) {
            return $this->noAlinea('no_alcanza_la_tolerancia');
        }
        if (! $this->dentroDelMargen((float) $invoice->monto, $resultado->totalCentavos(), $invoice->lines->count())) {
            Log::warning('Facturación electrónica: el total fiscal se aleja demasiado del monto de la factura; no se alinea', [
                'invoice_id' => $invoice->id, 'monto' => (float) $invoice->monto, 'total_fiscal' => $resultado->totalCentavos() / 100,
            ]);

            return $this->noAlinea('delta_fuera_de_rango');
        }
        if ($motivo = $this->motivoPorMovimientos($invoice)) {
            // El documento se enviará balanceado igual, pero el monto de AtlanticERP no se toca: queda constancia de la diferencia de centavos.
            Log::warning('Facturación electrónica: el total fiscal difiere del monto de la factura y no se alinea', [
                'invoice_id' => $invoice->id, 'motivo' => $motivo, 'monto' => (float) $invoice->monto, 'total_fiscal' => $resultado->totalCentavos() / 100,
            ]);

            return $this->noAlinea($motivo);
        }

        DB::transaction(function () use ($invoice, $resultado): void {
            /** @var AdminContInvoice $bloqueada */
            $bloqueada = AdminContInvoice::query()->lockForUpdate()->findOrFail($invoice->id);
            // Carrera: otro proceso pudo certificar o cobrar la factura entre la lectura y el bloqueo.
            if ($this->motivoPorEstado($bloqueada) !== null || $this->motivoPorMovimientos($bloqueada) !== null) {
                return;
            }

            $montoAnterior = (float) $bloqueada->monto;
            if (! $this->dentroDelMargen($montoAnterior, $resultado->totalCentavos(), $bloqueada->lines()->count())) {
                return;
            }
            $lineas        = $bloqueada->lines()->orderBy('id')->get();
            if ($lineas->count() !== count($resultado->netos)) {
                return;   // las líneas cambiaron entre la lectura y el bloqueo: no se toca nada
            }
            $nuevoMonto    = 0;
            foreach ($lineas->values() as $i => $linea) {
                /** @var AdminContInvoiceLine $linea */
                $neto  = $resultado->netos[$i];
                $itbms = $resultado->itbms[$i];
                $nuevoMonto += $neto + $itbms;

                $linea->forceFill([
                    'subtotal'        => $neto / 100,
                    'itbms'           => $itbms / 100,
                    'total'           => ($neto + $itbms) / 100,
                    'precio_unitario' => $linea->cantidad > 0 ? round(($neto / 100) / (float) $linea->cantidad, 2) : 0.0,
                ])->save();
            }

            $nuevoMonto = $nuevoMonto / 100;
            $bloqueada->forceFill([
                'monto'              => $nuevoMonto,
                'ajuste_itbms_delta' => round((float) $bloqueada->ajuste_itbms_delta + ($nuevoMonto - $montoAnterior), 2),
            ])->save();

            $this->audit->log(
                action: AuditService::ADMIN_CONT_FE_TOTAL_ALINEADO,
                entityType: 'invoice',
                entityId: $bloqueada->id,
                oldValues: ['monto' => $montoAnterior],
                newValues: ['monto' => $nuevoMonto],
                metadata: [
                    'pares'          => $resultado->pares,
                    'lineas_movidas' => count($resultado->modificadas),
                    'delta_antes_ct' => $resultado->deltaInicial,
                    'delta_despues_ct' => $resultado->deltaFinal,
                ],
            );
        });

        $invoice->refresh();
        $invoice->load('lines.itbmsRate');

        return ['alineada' => true, 'motivo' => 'alineada'];
    }

    /**
     * El balanceo solo mueve centavos: si el monto guardado y el total fiscal difieren más que unos pocos centavos, algo más está pasando
     * (monto inconsistente con sus líneas) y alinear escribiría esa diferencia en `ajuste_itbms_delta` sin que nadie la vea.
     */
    private function dentroDelMargen(float $monto, int $totalFiscalCentavos, int $lineas): bool
    {
        return abs($totalFiscalCentavos - (int) round($monto * 100)) <= self::margenCentavos($lineas);
    }

    /** Diferencia natural máxima (centavos) entre el monto de AtlanticERP y el total fiscal de una factura con ese número de líneas. */
    public static function margenCentavos(int $lineas): int
    {
        return min(self::MARGEN_TOPE_CENTAVOS, self::MARGEN_BASE_CENTAVOS + intdiv($lineas, 10));
    }

    /** @return list<array{neto: int, bp: int}>|null null si alguna línea no tiene tasa */
    private function entrada(AdminContInvoice $invoice): ?array
    {
        $entrada = [];
        foreach ($invoice->lines->sortBy('id')->values() as $linea) {
            $tasa = $linea->itbmsRate;
            if ($tasa === null) {
                return null;
            }
            $entrada[] = ['neto' => (int) round((float) $linea->subtotal * 100), 'bp' => (int) round($tasa->porcentaje * 100)];
        }

        return $entrada;
    }

    /** Motivo por el que NO se debe alinear según el estado de la factura, o null si el estado lo permite. */
    private function motivoPorEstado(AdminContInvoice $invoice): ?string
    {
        if ($invoice->cufe !== null || $invoice->fe_certificado_at !== null || in_array($invoice->fe_estado, ['certificado', 'pendiente'], true)) {
            return 'ya_certificada_o_pendiente';
        }
        if ($invoice->anulada_at !== null || (bool) $invoice->es_incobrable) {
            return 'anulada_o_incobrable';
        }

        return null;
    }

    /** Motivo por el que NO se debe alinear porque hay movimientos de dinero o documentos contra el monto actual, o null si no hay ninguno. */
    private function motivoPorMovimientos(AdminContInvoice $invoice): ?string
    {
        if ((float) $invoice->saldo_aplicado > 0) {
            return 'tiene_saldo_aplicado';
        }

        $hay = AdminContPaymentInvoice::query()->where('invoice_id', $invoice->id)->exists()
            || AdminContAbono::query()->where('invoice_id', $invoice->id)->exists()
            || AdminContCreditNote::query()->where('factura_origen_id', $invoice->id)->exists();

        // Movimientos de saldo y compensaciones del concepto nuevo de Cobros (SCRUM-1168): esas tablas solo existen en las ramas que ya lo incluyen.
        // Se consultan por nombre de tabla si existen, para que ESTE mismo archivo sirva en master (hotfix) y en dev/test al alinear hacia atrás.
        $hay = $hay || $this->hayEnTablasOpcionales($invoice->id);

        return $hay ? 'tiene_cobros_o_notas' : null;
    }

    private function hayEnTablasOpcionales(int $invoiceId): bool
    {
        $esquema = explode('.', (new AdminContInvoice())->getTable())[0];
        foreach (['payment_movements', 'compensation_lines'] as $tabla) {
            $completa = "{$esquema}.{$tabla}";
            if (Schema::hasTable($completa) && DB::table($completa)->where('invoice_id', $invoiceId)->exists()) {
                return true;
            }
        }

        return false;
    }

    /** @return array{alineada: bool, motivo: string} */
    private function noAlinea(string $motivo): array
    {
        return ['alineada' => false, 'motivo' => $motivo];
    }
}
