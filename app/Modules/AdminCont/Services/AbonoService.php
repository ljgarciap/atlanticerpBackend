<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Services;

use App\Models\User;
use App\Modules\AdminCont\Exceptions\AbonoStateException;
use App\Modules\AdminCont\Models\AdminContAbono;
use App\Modules\AdminCont\Models\AdminContCreditNote;
use App\Modules\AdminCont\Models\AdminContInvoice;
use App\Modules\AdminCont\Models\AdminContPayment;
use App\Modules\Bodega\Models\Order as BodegaOrder;
use App\Modules\VentasDiseno\Models\MasterClient;
use App\Modules\VentasDiseno\Models\SalesProject;
use App\Modules\VentasDiseno\Models\SubClient;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * SCRUM-902 (Registrar Abono) + SCRUM-903 (Saldo a Favor de Clientes). Reemplaza a
 * `CreditBalanceService` (retirado) como fuente de verdad de saldo a favor — ver docblock de la
 * migración de drop de `customer_credit_balances` para el porqué del reemplazo completo.
 *
 * Lógica de integración (ticket 902 sección 4, LEER ANTES de tocar este archivo):
 *  - Un abono `por_confirmar` nunca es visible/usable en ningún lado — todo método de consulta
 *    filtra `estado=confirmado` salvo `find()`/`update()`/`confirm()` (que operan sobre un abono
 *    puntual por id, antes o después de confirmarse).
 *  - Nivel Factura/Proyecto: aplicación OBLIGATORIA y bloqueada en Cobros (`abonoObligatorioParaFactura()`).
 *  - Nivel Cliente: aplicación OPCIONAL con tope, consumida FIFO junto con Notas de Crédito
 *    `resultado=saldo_favor` (`saldoClienteDisponible()`/`aplicarSaldoCliente()`).
 *  - Excedente (4.5): cuando un abono obligatorio es mayor a la factura que cubre, la diferencia
 *    se extrae como un abono nuevo, ya confirmado, a nivel Cliente master (`registrarUsoObligatorio()`).
 */
final class AbonoService
{
    public function __construct(
        // SCRUM-1168 F1 — libro de movimientos (modo sombra).
        private readonly PaymentLedger $ledger,
        private readonly ClientBalanceService $clientBalance,
    ) {}

    /**
     * §4.3 — cuando Facturación genera la factura de un proyecto, cualquier abono confirmado de
     * nivel Proyecto sobre ese `sales_project_id` (sin factura todavía) se re-asocia a la factura
     * recién creada. La factura NO se modifica en este paso (ver `InvoiceService::confirm()`) —
     * desde acá en adelante ese abono se comporta como nivel Factura.
     */
    public function reasociarAlFacturar(int $salesProjectId, AdminContInvoice $invoice): void
    {
        DB::transaction(function () use ($salesProjectId, $invoice): void {
            AdminContAbono::query()
                ->where('sales_project_id', $salesProjectId)
                ->whereNull('invoice_id')
                ->where('estado', AdminContAbono::ESTADO_CONFIRMADO)
                ->lockForUpdate()
                ->get()
                ->each(fn (AdminContAbono $a) => $a->update(['invoice_id' => $invoice->id]));
        });
    }

    /**
     * SCRUM-1168 F0 — lo que un cobro confirmado no pudo aplicar a sus facturas (ya no tenían lugar) queda como saldo a favor del
     * cliente: un abono nuevo, ya `confirmado`, a nivel Cliente master, con el método, cuenta y registrador del cobro y su recibo como
     * referencia. Mismo vehículo que el excedente de §4.5, para que lo vea Saldo a Favor y lo use el siguiente cobro o la compensación.
     */
    public function registrarExcedenteDeCobro(AdminContPayment $payment, float $excedente): AdminContAbono
    {
        $abono = AdminContAbono::create([
            'master_client_id'   => $payment->master_client_id,
            'sub_client_id'      => null,
            'sales_project_id'   => null,
            'invoice_id'         => null,
            'monto_original'     => round($excedente, 2),
            'monto_usado'        => 0,
            'metodo_pago'        => $payment->metodo_pago,
            'cuenta_bancaria_id' => $payment->bank_account_id,
            'referencia'         => "Excedente del cobro {$payment->numero_recibo}",
            'estado'             => AdminContAbono::ESTADO_CONFIRMADO,
            'registrado_por'     => $payment->created_by,
            'confirmado_por'     => $payment->created_by,
            'fecha_registro'     => now(),
            'fecha_confirmacion' => now(),
        ]);

        // SCRUM-1168 F1 — el dinero ya está en el libro (el ingreso del cobro menos lo aplicado): solo se deja la traza (efecto cero).
        $this->ledger->shadow(fn () => $this->ledger->reclasificacion(
            $payment->master_client_id, $excedente, "Excedente del cobro {$payment->numero_recibo} que no cupo en su factura", $payment->id, $abono->id,
        ));

        return $abono;
    }

    /**
     * SCRUM-1168 F4 — al anular un cobro del flujo anterior, el saldo a favor de nivel cliente que había consumido (FIFO, sin rastro de qué abono o nota)
     * vuelve al cliente como un abono nuevo ya confirmado a nivel Cliente, con el recibo del cobro anulado como referencia. El libro ya recoge ese dinero
     * en los contra-movimientos de la anulación: solo se deja la traza (efecto cero).
     */
    public function registrarRestitucionPorAnulacion(AdminContPayment $payment, float $monto): AdminContAbono
    {
        $abono = AdminContAbono::create([
            'master_client_id'   => $payment->master_client_id,
            'sub_client_id'      => null,
            'sales_project_id'   => null,
            'invoice_id'         => null,
            'monto_original'     => round($monto, 2),
            'monto_usado'        => 0,
            'metodo_pago'        => $payment->metodo_pago,
            'cuenta_bancaria_id' => $payment->bank_account_id,
            'referencia'         => "Restitución por anulación del cobro {$payment->numero_recibo}",
            'estado'             => AdminContAbono::ESTADO_CONFIRMADO,
            'registrado_por'     => $payment->created_by,
            'confirmado_por'     => $payment->created_by,
            'fecha_registro'     => now(),
            'fecha_confirmacion' => now(),
        ]);

        $this->ledger->shadow(fn () => $this->ledger->reclasificacion(
            $payment->master_client_id, $monto, "Restitución de saldo por la anulación del cobro {$payment->numero_recibo}", $payment->id, $abono->id,
        ));

        return $abono;
    }

    /**
     * §4.2/4.3 — abono(s) obligatorio(s) ya re-asociados a esta factura, con disponible > 0.
     * Consumidos FIFO por `fecha_registro` si hubiera más de uno (caso raro, no impedido por el
     * ticket). Read-only — no muta nada, usado tanto para el desglose en Cobros como, calculado de
     * nuevo bajo lock, en `PaymentService::register()`/`confirm()`.
     *
     * @return array<int, array{abono_id:int, disponible:float}>
     */
    public function abonosObligatoriosParaFactura(int $invoiceId): array
    {
        return AdminContAbono::query()
            ->where('invoice_id', $invoiceId)
            ->where('estado', AdminContAbono::ESTADO_CONFIRMADO)
            ->orderBy('fecha_registro')
            ->get()
            ->map(fn (AdminContAbono $a) => ['abono_id' => $a->id, 'disponible' => $a->montoDisponible()])
            ->filter(fn (array $r) => $r['disponible'] > 0)
            ->values()
            ->all();
    }

    /**
     * §4.5 — aplica `$montoAplicado` (ya decidido, <= disponible del abono en ese momento) contra
     * la factura, y si el abono tenía más disponible del que hacía falta, extrae la diferencia
     * como un abono NUEVO, ya `confirmado`, a nivel Cliente master (RN: "el abono original queda
     * con monto_usado = monto_original"). Llamado desde el punto donde `PaymentService` de verdad
     * concreta el cobro (inmediato o `confirm()` diferido) — nunca desde el preview.
     */
    public function registrarUsoObligatorio(int $abonoId, float $montoAplicado): void
    {
        DB::transaction(function () use ($abonoId, $montoAplicado): void {
            /** @var AdminContAbono $abono */
            $abono = AdminContAbono::query()->lockForUpdate()->findOrFail($abonoId);
            $disponibleAntes = $abono->montoDisponible();
            // SCRUM-1168 F0 — el abono obligatorio se fijó al registrar el cobro; si entre tanto otro cobro lo consumió, no se
            // vuelve a consumir lo que ya no existe.
            if ($montoAplicado > $disponibleAntes + 0.005) {
                throw new AbonoStateException(
                    "El abono {$abono->numero_recibo} ya no tiene saldo suficiente para este cobro (disponible \$".number_format($disponibleAntes, 2).').'
                );
            }
            $excedente       = round($disponibleAntes - $montoAplicado, 2);

            $abono->update(['monto_usado' => round($abono->monto_usado + $disponibleAntes, 2)]);

            if ($excedente > 0) {
                $nuevo = AdminContAbono::create([
                    'master_client_id'   => $abono->master_client_id,
                    'sub_client_id'      => null,
                    'sales_project_id'   => null,
                    'invoice_id'         => null,
                    'monto_original'     => $excedente,
                    'monto_usado'        => 0,
                    'metodo_pago'        => $abono->metodo_pago,
                    'cuenta_bancaria_id' => $abono->cuenta_bancaria_id,
                    'referencia'         => $abono->referencia,
                    'estado'             => AdminContAbono::ESTADO_CONFIRMADO,
                    'registrado_por'     => $abono->registrado_por,
                    'confirmado_por'     => $abono->confirmado_por,
                    'fecha_registro'     => now(),
                    'fecha_confirmacion' => now(),
                    'abono_origen_id'    => $abono->id,
                ]);

                // SCRUM-1168 F1 — mismo dinero que el abono de origen, ya en el libro: solo se deja la traza (efecto cero).
                $this->ledger->shadow(fn () => $this->ledger->reclasificacion(
                    $nuevo->master_client_id, $excedente, "Excedente del abono {$abono->numero_recibo} al aplicarse a su factura", null, $nuevo->id,
                ));
            }
        });
    }

    /**
     * §4.4/4.6 — saldo disponible a nivel Cliente master: abonos nivel Cliente confirmados +
     * Notas de Crédito `resultado=saldo_favor` `estado=aplicada` (ver `AdminContCreditNote::
     * montoDisponibleSaldoFavor()`). Misma fuente única que consume `Cobros` y muestra `Saldo a
     * Favor de Clientes` — nunca una copia/recálculo aparte (903 §4.1).
     */
    public function saldoClienteDisponible(int $masterClientId, bool $bloquear = false): float
    {
        // SCRUM-1168 F0 — `$bloquear` (solo dentro de una transacción) toma `FOR UPDATE` sobre las filas que forman el saldo,
        // para que quien lo valida y luego lo consume no compita con otro cobro del mismo cliente.
        $abonosQuery = AdminContAbono::query()
            ->where('master_client_id', $masterClientId)
            ->whereNull('sales_project_id')
            ->whereNull('invoice_id')
            ->where('estado', AdminContAbono::ESTADO_CONFIRMADO);
        if ($bloquear) {
            $abonosQuery->orderBy('id')->lockForUpdate();
        }
        $abonos = $abonosQuery
            ->get()
            ->sum(fn (AdminContAbono $a) => $a->montoDisponible());

        $notasQuery = AdminContCreditNote::query()
            ->where('master_client_id', $masterClientId)
            ->where('resultado', AdminContCreditNote::RESULTADO_SALDO_FAVOR)
            ->where('estado', AdminContCreditNote::ESTADO_APLICADA);
        if ($bloquear) {
            $notasQuery->orderBy('id')->lockForUpdate();
        }
        $notas = $notasQuery
            ->get()
            ->sum(fn (AdminContCreditNote $n) => $n->montoDisponibleSaldoFavor());

        return round((float) $abonos + (float) $notas, 2);
    }

    /**
     * Fase 13 (2.2) — DESGLOSE del saldo a favor de un cliente por nivel, la misma cuenta que la pantalla «Saldo a favor» y la compensación:
     *  - `aplicable`: abonos nivel Cliente + notas de crédito `saldo_favor` (`saldoClienteDisponible()`, lo que consume un cobro);
     *  - `a_cuenta`: cobros a cuenta del concepto nuevo, sin aplicar (libro de movimientos);
     *  - `reservado`: abonos ligados a un proyecto o a una factura (solo sirven para ese destino);
     *  - `total`: la suma de los tres, es decir, la cifra de la pantalla «Saldo a favor» para ese cliente.
     * Antes cada pantalla sumaba orígenes distintos y daba cifras que no cuadraban entre sí.
     *
     * @return array{aplicable: float, a_cuenta: float, reservado: float, total: float}
     */
    public function desgloseSaldoCliente(int $masterClientId): array
    {
        $aplicable = $this->saldoClienteDisponible($masterClientId);

        $reservado = (float) AdminContAbono::query()
            ->where('master_client_id', $masterClientId)
            ->where('estado', AdminContAbono::ESTADO_CONFIRMADO)
            ->where(fn ($q) => $q->whereNotNull('sales_project_id')->orWhereNotNull('invoice_id'))
            ->get()
            ->sum(fn (AdminContAbono $a) => $a->montoDisponible());

        $aCuenta = (float) ($this->clientBalance->remanentePorCliente([$masterClientId])[$masterClientId] ?? 0.0);

        return [
            'aplicable' => round($aplicable, 2),
            'a_cuenta'  => round($aCuenta, 2),
            'reservado' => round($reservado, 2),
            'total'     => round($aplicable + $aCuenta + $reservado, 2),
        ];
    }

    /**
     * SCRUM-1147 — saldo a favor que se podría aplicar a cada factura, para mostrarlo (informativo)
     * junto al total en Facturación. UN solo valor por factura: el del nivel MÁS ESPECÍFICO que
     * tenga saldo disponible — factura (abonos re-asociados a esa factura), si no proyecto (abonos
     * nivel Proyecto sin factura), si no cliente (`saldoClienteDisponible()`: abonos nivel Cliente +
     * Notas de Crédito `saldo_favor`) — así nunca se suman niveles ni se muestran cifras que no
     * corresponden. Todo en 3-4 consultas en lote, nunca una por fila (el listado puede traer
     * cientos de facturas).
     *
     * @param list<array{invoice_id: int, sales_project_id: int|null, master_client_id: int|null}> $targets
     * @return array<int, float> invoice_id => saldo disponible (> 0); las facturas sin saldo no aparecen
     */
    public function saldoFavorPorFactura(array $targets): array
    {
        if ($targets === []) {
            return [];
        }

        $invoiceIds = array_values(array_unique(array_column($targets, 'invoice_id')));
        $projectIds = array_values(array_unique(array_filter(array_column($targets, 'sales_project_id'))));
        $clientIds  = array_values(array_unique(array_filter(array_column($targets, 'master_client_id'))));

        /** @var array<int, float> $porFactura */
        $porFactura = [];
        foreach (AdminContAbono::query()->whereIn('invoice_id', $invoiceIds)->where('estado', AdminContAbono::ESTADO_CONFIRMADO)->get() as $a) {
            $porFactura[(int) $a->invoice_id] = ($porFactura[(int) $a->invoice_id] ?? 0.0) + $a->montoDisponible();
        }

        /** @var array<int, float> $porProyecto */
        $porProyecto = [];
        if ($projectIds !== []) {
            foreach (AdminContAbono::query()->whereIn('sales_project_id', $projectIds)->whereNull('invoice_id')->where('estado', AdminContAbono::ESTADO_CONFIRMADO)->get() as $a) {
                $porProyecto[(int) $a->sales_project_id] = ($porProyecto[(int) $a->sales_project_id] ?? 0.0) + $a->montoDisponible();
            }
        }

        /** @var array<int, float> $porCliente */
        $porCliente = [];
        if ($clientIds !== []) {
            $abonos = AdminContAbono::query()->whereIn('master_client_id', $clientIds)->whereNull('sales_project_id')->whereNull('invoice_id')
                ->where('estado', AdminContAbono::ESTADO_CONFIRMADO)->get();
            foreach ($abonos as $a) {
                $porCliente[(int) $a->master_client_id] = ($porCliente[(int) $a->master_client_id] ?? 0.0) + $a->montoDisponible();
            }
            $notas = AdminContCreditNote::query()->whereIn('master_client_id', $clientIds)
                ->where('resultado', AdminContCreditNote::RESULTADO_SALDO_FAVOR)->where('estado', AdminContCreditNote::ESTADO_APLICADA)->get();
            foreach ($notas as $n) {
                $porCliente[(int) $n->master_client_id] = ($porCliente[(int) $n->master_client_id] ?? 0.0) + $n->montoDisponibleSaldoFavor();
            }
            // SCRUM-1168 F2 — saldo sin aplicar de los cobros del concepto nuevo (libro de movimientos).
            foreach ($this->clientBalance->remanentePorCliente($clientIds) as $clientId => $remanente) {
                $porCliente[$clientId] = ($porCliente[$clientId] ?? 0.0) + $remanente;
            }
        }

        $result = [];
        foreach ($targets as $t) {
            $saldo = round($porFactura[$t['invoice_id']] ?? 0.0, 2);
            if ($saldo <= 0 && $t['sales_project_id'] !== null) {
                $saldo = round($porProyecto[$t['sales_project_id']] ?? 0.0, 2);
            }
            if ($saldo <= 0 && $t['master_client_id'] !== null) {
                $saldo = round($porCliente[$t['master_client_id']] ?? 0.0, 2);
            }
            if ($saldo > 0) {
                $result[$t['invoice_id']] = $saldo;
            }
        }

        return $result;
    }

    /**
     * §4.4 — consume `$monto` (ya validado <= `saldoClienteDisponible()`) en orden FIFO por fecha
     * entre abonos nivel Cliente y Notas de Crédito `saldo_favor` disponibles de este cliente.
     * Transparente al usuario, que solo ve un total (ticket §4.4: "si hay varios registros
     * disponibles, consumirlos en orden, transparente al usuario").
     *
     * @return array{abonos: float, notas: float} cuánto salió de abonos y cuánto de notas de crédito (SCRUM-1168 F1: las notas siguen
     *         fuera del libro de movimientos, el cobro necesita saber esa parte).
     */
    public function aplicarSaldoCliente(int $masterClientId, float $monto): array
    {
        if ($monto <= 0) {
            return ['abonos' => 0.0, 'notas' => 0.0];
        }

        return DB::transaction(function () use ($masterClientId, $monto): array {
            /** @var \Illuminate\Support\Collection<int, AdminContAbono> $abonos */
            $abonos = AdminContAbono::query()
                ->where('master_client_id', $masterClientId)
                ->whereNull('sales_project_id')
                ->whereNull('invoice_id')
                ->where('estado', AdminContAbono::ESTADO_CONFIRMADO)
                ->lockForUpdate()
                ->get();

            /** @var \Illuminate\Support\Collection<int, AdminContCreditNote> $notas */
            $notas = AdminContCreditNote::query()
                ->where('master_client_id', $masterClientId)
                ->where('resultado', AdminContCreditNote::RESULTADO_SALDO_FAVOR)
                ->where('estado', AdminContCreditNote::ESTADO_APLICADA)
                ->lockForUpdate()
                ->get();

            /** @var list<array{tipo: 'abono'|'nota', fecha: \Carbon\Carbon, disponible: float, aplicar: callable(float): void}> $candidatos */
            $candidatos = [];
            foreach ($abonos as $a) {
                $candidatos[] = [
                    'tipo'       => 'abono',
                    'fecha'      => $a->fecha_registro,
                    'disponible' => $a->montoDisponible(),
                    'aplicar'    => fn (float $consumo) => $a->update(['monto_usado' => round($a->monto_usado + $consumo, 2)]),
                ];
            }
            foreach ($notas as $n) {
                $candidatos[] = [
                    'tipo'       => 'nota',
                    'fecha'      => $n->fecha,
                    'disponible' => $n->montoDisponibleSaldoFavor(),
                    'aplicar'    => fn (float $consumo) => $n->update(['monto_usado' => round($n->monto_usado + $consumo, 2)]),
                ];
            }
            usort($candidatos, fn (array $a, array $b) => $a['fecha'] <=> $b['fecha']);

            $restante = $monto;
            $consumido = ['abonos' => 0.0, 'notas' => 0.0];
            foreach ($candidatos as $c) {
                if ($restante <= 0) {
                    break;
                }
                if ($c['disponible'] <= 0) {
                    continue;
                }

                $consumo  = round(min($c['disponible'], $restante), 2);
                $restante = round($restante - $consumo, 2);
                ($c['aplicar'])($consumo);
                $consumido[$c['tipo'] === 'nota' ? 'notas' : 'abonos'] = round($consumido[$c['tipo'] === 'nota' ? 'notas' : 'abonos'] + $consumo, 2);
            }

            // SCRUM-1168 F0 — antes, si el saldo ya no alcanzaba (lo consumió otro cobro) esto terminaba en silencio y el
            // cobro quedaba con un saldo a favor aplicado que nunca se descontó de ningún abono ni nota.
            if ($restante > 0.005) {
                throw new AbonoStateException(
                    'El saldo a favor del cliente ya no alcanza para este cobro (faltan $'.number_format($restante, 2).'); otro cobro lo usó antes.'
                );
            }

            return $consumido;
        });
    }

}
