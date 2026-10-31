<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Services;

use App\Models\User;
use App\Modules\AdminCont\Exceptions\PaymentStateException;
use App\Modules\AdminCont\Models\AdminContCompensation;
use App\Modules\AdminCont\Models\AdminContCompensationLine;
use App\Modules\AdminCont\Models\AdminContCreditNote;
use App\Modules\AdminCont\Models\AdminContInvoice;
use App\Modules\AdminCont\Models\AdminContPayment;
use App\Modules\AdminCont\Models\AdminContPaymentInvoice;
use App\Modules\AdminCont\Models\AdminContPaymentMovement;
use App\Modules\Bodega\Models\Order;
use App\Modules\VentasDiseno\Models\MasterClient;
use App\Modules\VentasDiseno\Models\SalesProject;
use Illuminate\Support\Facades\DB;

/**
 * SCRUM-1168 F3 — compensación (cruce) del dinero disponible de un cliente contra sus facturas pendientes.
 *
 * Fuentes de dinero disponible (todas del mismo cliente): COBROS con saldo sin aplicar (el libro dice cuánto) y saldo de NOTAS DE CRÉDITO con resultado saldo a favor (que siguen fuera del libro; el cruce solo les descuenta lo usado, sin tocar su lógica).
 *
 * El cruce se arma en pantalla (nada cambia en las facturas) y se compromete al confirmar, en una sola transacción, bajo el bloqueo del cliente.
 * Nunca aplica solo: `sugerencia()` solo PROPONE (facturas más antiguas primero; si el dinero venía atado a una factura, esa primero).
 */
final class CompensationService
{
    private const TOLERANCIA = 0.005;

    public function __construct(
        private readonly ClientLock $clientLock,
        private readonly InvoiceBalanceWriter $balance,
        private readonly PaymentLedger $ledger,
        private readonly ClientBalanceService $clientBalance,
        private readonly AdminContCompensationFolioService $folio,
        private readonly CobrosConfigService $config,
        private readonly ApplicationReversalService $reversals,
    ) {}

    // ── lista de clientes con saldo activo ──────────────────────────────────────────────────────

    /**
     * Clientes con saldo a favor, o con algún movimiento del libro en los últimos N días (parámetro configurable). Positivo = tiene dinero a favor
     * sin aplicar; negativo = debe (facturas pendientes); se muestran ambos montos y el neto.
     *
     * @param array{search?: ?string, signo?: ?string} $filters signo: positivo | negativo
     * @return array{indicadores: array{saldo_a_favor_total: float, clientes_con_saldo: int}, rows: list<array<string, mixed>>}
     */
    public function clientes(array $filters = []): array
    {
        $cutoff = now()->subDays($this->config->diasMovimientoReciente());
        $movements = (new AdminContPaymentMovement())->getTable();

        /** @var array<int, float> $saldo */
        $saldo = [];
        foreach ($this->clientBalance->remanentesPorCobro() as $r) {
            $saldo[$r['master_client_id']] = ($saldo[$r['master_client_id']] ?? 0.0) + $r['remanente'];
        }
        foreach ($this->notasConSaldo() as $n) {
            $saldo[$n->master_client_id] = ($saldo[$n->master_client_id] ?? 0.0) + $n->montoDisponibleSaldoFavor();
        }

        /** @var array<int, string> $ultimo */
        $ultimo = [];
        foreach (DB::select("SELECT master_client_id, MAX(fecha_movimiento) AS ultimo FROM {$movements} GROUP BY master_client_id") as $r) {
            $ultimo[(int) $r->master_client_id] = (string) $r->ultimo;
        }
        $recientes = array_keys(array_filter($ultimo, fn (string $f): bool => $f >= $cutoff->toDateTimeString()));

        $clientIds = array_values(array_unique(array_merge(
            array_map('intval', array_keys(array_filter($saldo, fn (float $v): bool => $v > 0.004))),
            array_map('intval', $recientes),
        )));
        if ($clientIds === []) {
            return ['indicadores' => ['saldo_a_favor_total' => 0.0, 'clientes_con_saldo' => 0], 'rows' => []];
        }

        $invoices = (new AdminContInvoice())->getTable();
        $pend = [];
        foreach (DB::select(
            "SELECT master_client_id, SUM(monto - saldo_aplicado) AS pendiente, COUNT(*) AS n FROM {$invoices}
              WHERE master_client_id IN (".implode(',', array_fill(0, count($clientIds), '?')).")
                AND es_incobrable = FALSE AND anulada_at IS NULL AND monto - saldo_aplicado > 0.004
              GROUP BY master_client_id",
            $clientIds,
        ) as $r) {
            $pend[(int) $r->master_client_id] = ['pendiente' => round((float) $r->pendiente, 2), 'n' => (int) $r->n];
        }

        $names = MasterClient::query()->whereIn('id', $clientIds)->pluck('name', 'id');

        $rows = [];
        foreach ($clientIds as $id) {
            $aFavor    = round($saldo[$id] ?? 0.0, 2);
            $pendiente = $pend[$id]['pendiente'] ?? 0.0;
            $neto      = round($aFavor - $pendiente, 2);
            $rows[] = [
                'master_client_id'      => $id,
                'cliente'               => (string) ($names->get($id) ?? "Cliente #{$id}"),
                'saldo_a_favor'         => $aFavor,
                'pendiente'             => $pendiente,
                'neto'                  => $neto,
                'signo'                 => $neto > self::TOLERANCIA ? 'positivo' : ($neto < -self::TOLERANCIA ? 'negativo' : 'cero'),
                'facturas_pendientes'   => $pend[$id]['n'] ?? 0,
                'ultimo_movimiento'     => isset($ultimo[$id]) ? \Illuminate\Support\Carbon::parse($ultimo[$id])->toIso8601String() : null,
            ];
        }

        $indicadores = [
            'saldo_a_favor_total' => round(array_sum(array_column($rows, 'saldo_a_favor')), 2),
            'clientes_con_saldo'  => count(array_filter($rows, fn (array $r): bool => $r['saldo_a_favor'] > 0.004)),
        ];

        $search = mb_strtolower(trim((string) ($filters['search'] ?? '')));
        $signo  = $filters['signo'] ?? null;
        $rows = array_values(array_filter($rows, fn (array $r): bool => ($search === '' || str_contains(mb_strtolower($r['cliente']), $search))
            && ($signo === null || $signo === '' || $r['signo'] === $signo)));

        usort($rows, fn (array $a, array $b): int => [$b['saldo_a_favor'], $a['cliente']] <=> [$a['saldo_a_favor'], $b['cliente']]);

        return ['indicadores' => $indicadores, 'rows' => $rows];
    }

    // ── detalle de un cliente ───────────────────────────────────────────────────────────────────

    /**
     * Todo lo que Contabilidad necesita para cruzar a un cliente: de dónde sale el dinero, qué facturas deben, qué se sugiere y la traza reciente.
     *
     * @return array<string, mixed>
     */
    public function detalle(int $masterClientId): array
    {
        $fuentes  = $this->fuentes($masterClientId);
        $facturas = $this->facturasPendientes($masterClientId);

        return [
            'master_client_id' => $masterClientId,
            'cliente'          => (string) (MasterClient::query()->whereKey($masterClientId)->value('name') ?? "Cliente #{$masterClientId}"),
            'saldo_a_favor'    => round(array_sum(array_column($fuentes, 'disponible')), 2),
            'pendiente'        => round(array_sum(array_column($facturas, 'pendiente')), 2),
            'fuentes'          => $fuentes,
            'facturas'         => $facturas,
            'sugerencia'       => $this->sugerencia($fuentes, $facturas),
            'movimientos'      => $this->movimientos($masterClientId),
            'compensaciones'   => AdminContCompensation::query()->where('master_client_id', $masterClientId)->orderByDesc('id')->limit(10)->get()
                ->map(fn (AdminContCompensation $c): array => [
                    'id' => $c->id, 'folio' => $c->folio, 'estado' => $c->estado, 'monto_total' => $c->monto_total, 'confirmed_at' => $c->confirmed_at->toIso8601String(),
                ])->all(),
        ];
    }

    // ── vista previa y confirmación ─────────────────────────────────────────────────────────────

    /**
     * Cómo quedarían las facturas y el dinero disponible si se confirmara este cruce. No cambia nada.
     *
     * @param list<array{source_type: string, source_id: int, invoice_id: int, monto: float}> $lines
     * @return array<string, mixed>
     * @throws PaymentStateException si una línea pide más de lo que tiene la fuente o la factura, o no son del cliente.
     */
    public function preview(int $masterClientId, array $lines): array
    {
        return $this->resolver($masterClientId, $lines, $this->fuentes($masterClientId), $this->facturasPendientes($masterClientId));
    }

    /**
     * Compromete el cruce: aplica cada línea a su factura (única puerta `InvoiceBalanceWriter`), descuenta la fuente y deja la traza en el libro,
     * todo en una transacción bajo el bloqueo del cliente. Revalida con el estado vigente: si algo cambió desde la vista previa, no se aplica.
     *
     * @param list<array{source_type: string, source_id: int, invoice_id: int, monto: float}> $lines
     * @throws PaymentStateException
     */
    public function confirm(int $masterClientId, array $lines, User $actor): AdminContCompensation
    {
        return DB::transaction(function () use ($masterClientId, $lines, $actor): AdminContCompensation {
            $this->clientLock->lock($masterClientId);

            // Orden de bloqueos: cliente → facturas por id → cobros → notas, siempre por id.
            $invoiceIds = array_values(array_unique(array_column($lines, 'invoice_id')));
            /** @var \Illuminate\Support\Collection<int, AdminContInvoice> $invoices */
            $invoices = $invoiceIds === [] ? collect() : AdminContInvoice::query()
                ->whereIn('id', $invoiceIds)->where('master_client_id', $masterClientId)->orderBy('id')->lockForUpdate()->get()->keyBy('id');

            $ids = fn (string $type): array => array_values(array_unique(array_map(
                fn (array $l): int => $l['source_id'],
                array_filter($lines, fn (array $l): bool => $l['source_type'] === $type),
            )));
            $payments = $ids(AdminContCompensationLine::SOURCE_COBRO) === [] ? collect() : AdminContPayment::query()
                ->whereIn('id', $ids(AdminContCompensationLine::SOURCE_COBRO))->where('master_client_id', $masterClientId)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $notas = $ids(AdminContCompensationLine::SOURCE_NOTA_CREDITO) === [] ? collect() : AdminContCreditNote::query()
                ->whereIn('id', $ids(AdminContCompensationLine::SOURCE_NOTA_CREDITO))->where('master_client_id', $masterClientId)->orderBy('id')->lockForUpdate()->get()->keyBy('id');

            // Revalidación con el estado vigente (bajo los bloqueos).
            $this->resolver($masterClientId, $lines, $this->fuentes($masterClientId), $this->facturasPendientes($masterClientId));

            $total = round(array_sum(array_column($lines, 'monto')), 2);
            $compensation = AdminContCompensation::create([
                'master_client_id' => $masterClientId, 'folio' => $this->folio->next(), 'estado' => AdminContCompensation::ESTADO_CONFIRMADO,
                'monto_total' => $total, 'created_by' => $actor->id, 'confirmed_at' => now(),
            ]);

            foreach ($lines as $l) {
                $monto   = round($l['monto'], 2);
                $invoice = $invoices->get($l['invoice_id']) ?? throw new PaymentStateException('Factura no encontrada.');
                $this->balance->apply($invoice, $monto);

                switch ($l['source_type']) {
                    case AdminContCompensationLine::SOURCE_COBRO:
                        $payment = $payments->get($l['source_id']);
                        // Proyección que leen el Estado de Cuenta, las comisiones y el recibo: una fila por (cobro, factura) con lo aplicado neto.
                        $row = AdminContPaymentInvoice::query()->where('payment_id', $payment->id)->where('invoice_id', $invoice->id)->lockForUpdate()->first();
                        if ($row === null) {
                            AdminContPaymentInvoice::create([
                                'payment_id' => $payment->id, 'invoice_id' => $invoice->id, 'monto_aplicado' => $monto,
                                'fecha_aplicacion' => now(),
                            ]);
                        } else {
                            $row->update(['monto_aplicado' => round((float) $row->monto_aplicado + $monto, 2), 'fecha_aplicacion' => now()]);
                        }
                        $this->ledger->aplicacionDeCruce($masterClientId, $invoice->id, $monto, $payment->id, -$monto, $compensation->id, $actor->id);
                        break;

                    default: // nota de crédito: su saldo vive en su tabla, fuera del libro (delta de disponible 0)
                        $nota = $notas->get($l['source_id']);
                        $nota->update(['monto_usado' => round((float) $nota->monto_usado + $monto, 2)]);
                        $this->ledger->aplicacionDeCruce($masterClientId, $invoice->id, $monto, null, 0.0, $compensation->id, $actor->id, "Saldo de la nota {$nota->numero}");
                        break;
                }

                AdminContCompensationLine::create([
                    'compensation_id' => $compensation->id, 'source_type' => $l['source_type'], 'source_id' => $l['source_id'],
                    'invoice_id' => $invoice->id, 'monto' => $monto,
                ]);
            }

            return $compensation->load('lines');
        });
    }

    public function find(int $id): ?AdminContCompensation
    {
        return AdminContCompensation::query()->with('lines')->find($id);
    }

    /**
     * El cruce con cada línea ya resuelta (de dónde salió el dinero y a qué factura fue), para el detalle y el documento con folio.
     *
     * @return array<string, mixed>
     */
    public function presentar(AdminContCompensation $c): array
    {
        $lines    = $c->lines;
        $payments = AdminContPayment::query()->whereIn('id', $lines->where('source_type', AdminContCompensationLine::SOURCE_COBRO)->pluck('source_id'))->pluck('numero_recibo', 'id');
        $notas    = AdminContCreditNote::query()->whereIn('id', $lines->where('source_type', AdminContCompensationLine::SOURCE_NOTA_CREDITO)->pluck('source_id'))->pluck('numero', 'id');
        $invoices = AdminContInvoice::query()->whereIn('id', $lines->pluck('invoice_id')->unique())->pluck('numero', 'id');
        $creator  = $c->created_by !== null ? User::find($c->created_by) : null;

        return [
            'id' => $c->id, 'folio' => $c->folio, 'estado' => $c->estado, 'master_client_id' => $c->master_client_id,
            'cliente' => (string) (MasterClient::query()->whereKey($c->master_client_id)->value('name') ?? ''),
            'monto_total' => $c->monto_total, 'confirmed_at' => $c->confirmed_at->toIso8601String(),
            'registrado_por' => $creator !== null ? trim("{$creator->first_name} {$creator->last_name}") : null,
            'lineas' => $lines->map(fn (AdminContCompensationLine $l): array => [
                'source_type' => $l->source_type, 'source_id' => $l->source_id,
                'fuente' => match ($l->source_type) {
                    AdminContCompensationLine::SOURCE_COBRO => 'Cobro '.($payments->get($l->source_id) ?? '#'.$l->source_id),
                    default                                  => 'Nota de crédito '.($notas->get($l->source_id) ?? '#'.$l->source_id),
                },
                'invoice_id' => $l->invoice_id, 'factura' => (string) ($invoices->get($l->invoice_id) ?? '#'.$l->invoice_id), 'monto' => $l->monto,
            ])->values()->all(),
        ];
    }

    // ── piezas ──────────────────────────────────────────────────────────────────────────────────

    /**
     * De dónde puede salir el dinero de este cliente, con lo disponible de cada fuente, ordenado de la más antigua a la más reciente.
     *
     * @return list<array{source_type: string, source_id: int, etiqueta: string, referencia: ?string, fecha: string, disponible: float, factura_sugerida_id: ?int}>
     */
    public function fuentes(int $masterClientId): array
    {
        $out = [];

        $remanentes = $this->clientBalance->remanentesPorCobro([$masterClientId]);
        if ($remanentes !== []) {
            foreach (AdminContPayment::query()->whereIn('id', array_keys($remanentes))->get() as $p) {
                $out[] = [
                    'source_type' => AdminContCompensationLine::SOURCE_COBRO, 'source_id' => $p->id, 'etiqueta' => 'Cobro '.$p->numero_recibo,
                    'referencia' => $p->referencia, 'fecha' => $p->created_at->toIso8601String(), 'disponible' => $remanentes[$p->id]['remanente'], 'factura_sugerida_id' => null,
                ];
            }
        }

        foreach ($this->notasConSaldo($masterClientId) as $n) {
            $out[] = [
                'source_type' => AdminContCompensationLine::SOURCE_NOTA_CREDITO, 'source_id' => $n->id, 'etiqueta' => 'Nota de crédito '.$n->numero,
                'referencia' => $n->numero, 'fecha' => $n->fecha->toIso8601String(), 'disponible' => $n->montoDisponibleSaldoFavor(), 'factura_sugerida_id' => null,
            ];
        }

        usort($out, fn (array $a, array $b): int => $a['fecha'] <=> $b['fecha']);

        return $out;
    }

    private function nombreDe(?User $u): ?string
    {
        return $u === null ? null : trim($u->first_name.' '.$u->last_name);
    }

    /** @return \Illuminate\Support\Collection<int, AdminContCreditNote> */
    private function notasConSaldo(?int $masterClientId = null): \Illuminate\Support\Collection
    {
        return AdminContCreditNote::query()
            ->when($masterClientId !== null, fn ($q) => $q->where('master_client_id', $masterClientId))
            ->where('resultado', AdminContCreditNote::RESULTADO_SALDO_FAVOR)
            ->where('estado', AdminContCreditNote::ESTADO_APLICADA)
            ->get()
            ->filter(fn (AdminContCreditNote $n): bool => $n->montoDisponibleSaldoFavor() > 0.004)
            ->values();
    }

    /**
     * Facturas del cliente con algo pendiente (sin anuladas ni incobrables), de la más antigua a la más reciente.
     *
     * @return list<array<string, mixed>>
     */
    private function facturasPendientes(int $masterClientId): array
    {
        $invoices = AdminContInvoice::query()
            ->where('master_client_id', $masterClientId)->where('es_incobrable', false)->whereNull('anulada_at')
            ->orderBy('created_at')->orderBy('id')->get()
            ->filter(fn (AdminContInvoice $i): bool => round($i->monto - $i->saldo_aplicado, 2) > 0.004)
            ->values();

        $projects = SalesProject::query()->whereIn('id', $invoices->pluck('sales_project_id')->filter()->unique())->pluck('name', 'id');
        $orders   = Order::query()->whereIn('id', $invoices->pluck('order_id')->filter()->unique())->get()->keyBy('id');

        return array_values($invoices->map(function (AdminContInvoice $i) use ($projects, $orders): array {
            $quote = $orders->get($i->order_id)?->linkedQuote();

            return [
                'id' => $i->id, 'numero' => $i->numero, 'fecha' => $i->created_at->toIso8601String(), 'monto' => (float) $i->monto,
                'saldo_aplicado' => (float) $i->saldo_aplicado, 'pendiente' => round($i->monto - $i->saldo_aplicado, 2),
                'sales_project_id' => $i->sales_project_id, 'proyecto' => $i->sales_project_id !== null ? $projects->get($i->sales_project_id) : null,
                'quote_id' => $quote?->id, 'cotizacion_folio' => $quote?->folio,
            ];
        })->all());
    }

    /**
     * Propuesta de cruce, nunca aplicada sola: cada fuente (la más antigua primero) cubre primero la factura a la que venía atada, si la hay, y
     * luego las facturas más antiguas hasta agotar el dinero o las facturas.
     *
     * @param list<array<string, mixed>> $fuentes
     * @param list<array<string, mixed>> $facturas
     * @return list<array{source_type: string, source_id: int, invoice_id: int, monto: float}>
     */
    private function sugerencia(array $fuentes, array $facturas): array
    {
        $pendiente = [];
        foreach ($facturas as $f) {
            $pendiente[$f['id']] = (float) $f['pendiente'];
        }

        $lines = [];
        foreach ($fuentes as $s) {
            $restante = (float) $s['disponible'];
            $orden    = array_keys($pendiente);
            if ($s['factura_sugerida_id'] !== null && isset($pendiente[$s['factura_sugerida_id']])) {
                $orden = array_merge([$s['factura_sugerida_id']], array_values(array_diff($orden, [$s['factura_sugerida_id']])));
            }
            foreach ($orden as $invoiceId) {
                if ($restante <= 0.004) {
                    break;
                }
                $aplica = round(min($restante, $pendiente[$invoiceId]), 2);
                if ($aplica <= 0.004) {
                    continue;
                }
                $lines[] = ['source_type' => $s['source_type'], 'source_id' => $s['source_id'], 'invoice_id' => $invoiceId, 'monto' => $aplica];
                $pendiente[$invoiceId] = round($pendiente[$invoiceId] - $aplica, 2);
                $restante = round($restante - $aplica, 2);
            }
        }

        return $lines;
    }

    /**
     * Valida las líneas contra lo disponible de cada fuente y lo pendiente de cada factura (acumulado por si una fuente o una factura se repite) y
     * devuelve cómo quedaría todo.
     *
     * @param list<array{source_type: string, source_id: int, invoice_id: int, monto: float}> $lines
     * @param list<array<string, mixed>> $fuentes
     * @param list<array<string, mixed>> $facturas
     * @return array<string, mixed>
     * @throws PaymentStateException
     */
    private function resolver(int $masterClientId, array $lines, array $fuentes, array $facturas): array
    {
        if ($lines === []) {
            throw new PaymentStateException('Indica al menos una línea del cruce.');
        }

        $fuenteDe = [];
        foreach ($fuentes as $s) {
            $fuenteDe[$s['source_type'].':'.$s['source_id']] = $s;
        }
        $facturaDe = [];
        foreach ($facturas as $f) {
            $facturaDe[$f['id']] = $f;
        }

        $usoFuente = [];
        $usoFactura = [];
        foreach ($lines as $l) {
            $monto = round((float) $l['monto'], 2);
            if ($monto <= 0.004) {
                throw new PaymentStateException('Cada línea del cruce debe tener un monto mayor a cero.');
            }
            $key = $l['source_type'].':'.$l['source_id'];
            if (! isset($fuenteDe[$key])) {
                throw new PaymentStateException('Una de las fuentes de dinero no existe, no es de este cliente o ya no tiene saldo disponible.');
            }
            if (! isset($facturaDe[$l['invoice_id']])) {
                throw new PaymentStateException('Una de las facturas no existe, no es de este cliente o ya no tiene nada pendiente.');
            }
            $usoFuente[$key]  = round(($usoFuente[$key] ?? 0.0) + $monto, 2);
            $usoFactura[$l['invoice_id']] = round(($usoFactura[$l['invoice_id']] ?? 0.0) + $monto, 2);
        }

        foreach ($usoFuente as $key => $uso) {
            if ($uso > $fuenteDe[$key]['disponible'] + self::TOLERANCIA) {
                throw new PaymentStateException(
                    "{$fuenteDe[$key]['etiqueta']} solo tiene $".number_format($fuenteDe[$key]['disponible'], 2).' disponibles y el cruce usa $'.number_format($uso, 2).'.'
                );
            }
        }
        foreach ($usoFactura as $invoiceId => $uso) {
            if ($uso > $facturaDe[$invoiceId]['pendiente'] + self::TOLERANCIA) {
                throw new PaymentStateException(
                    "La factura {$facturaDe[$invoiceId]['numero']} tiene pendiente $".number_format($facturaDe[$invoiceId]['pendiente'], 2).' y el cruce le aplica $'.number_format($uso, 2).'.'
                );
            }
        }

        $total = round(array_sum($usoFuente), 2);
        $aFavorAntes = round(array_sum(array_column($fuentes, 'disponible')), 2);

        return [
            'master_client_id' => $masterClientId,
            'total_aplicado'   => $total,
            'saldo_a_favor_antes'   => $aFavorAntes,
            'saldo_a_favor_despues' => round($aFavorAntes - $total, 2),
            'facturas' => array_values(array_map(fn (int $invoiceId): array => [
                'id' => $invoiceId, 'numero' => $facturaDe[$invoiceId]['numero'], 'pendiente_antes' => $facturaDe[$invoiceId]['pendiente'],
                'aplicado' => $usoFactura[$invoiceId], 'pendiente_despues' => round($facturaDe[$invoiceId]['pendiente'] - $usoFactura[$invoiceId], 2),
            ], array_keys($usoFactura))),
            'fuentes' => array_values(array_map(fn (string $key): array => [
                'source_type' => $fuenteDe[$key]['source_type'], 'source_id' => $fuenteDe[$key]['source_id'], 'etiqueta' => $fuenteDe[$key]['etiqueta'],
                'disponible_antes' => $fuenteDe[$key]['disponible'], 'usado' => $usoFuente[$key], 'disponible_despues' => round($fuenteDe[$key]['disponible'] - $usoFuente[$key], 2),
            ], array_keys($usoFuente))),
        ];
    }

    /**
     * Traza reciente del cliente (libro de movimientos), de la más nueva a la más antigua.
     *
     * @return list<array<string, mixed>>
     */
    private function movimientos(int $masterClientId): array
    {
        $rows = AdminContPaymentMovement::query()->where('master_client_id', $masterClientId)->orderByDesc('fecha_movimiento')->orderByDesc('id')->limit(50)->get();

        $payments = AdminContPayment::query()->whereIn('id', $rows->pluck('payment_id')->filter()->unique())->pluck('numero_recibo', 'id');
        $invoices = AdminContInvoice::query()->whereIn('id', $rows->pluck('invoice_id')->filter()->unique())->pluck('numero', 'id');
        $cmps     = AdminContCompensation::query()->whereIn('id', $rows->pluck('compensation_id')->filter()->unique())->pluck('folio', 'id');
        $actors   = User::query()->whereIn('id', $rows->pluck('actor_id')->filter()->unique())->get()->keyBy('id');
        // SCRUM-1168 F4 — qué aplicaciones se pueden desaplicar desde la traza (las que el libro conoce por componente y siguen vigentes).
        $reversados = AdminContPaymentMovement::query()->whereIn('reverses_movement_id', $rows->pluck('id'))->pluck('reverses_movement_id')->flip()->map(fn () => true)->all();
        $pagos      = AdminContPayment::query()->whereIn('id', $rows->pluck('payment_id')->filter()->unique())->get()->keyBy('id');

        return array_values($rows->map(fn (AdminContPaymentMovement $m): array => [
            'reversible' => $this->reversals->razonNoReversible($m, $reversados, $m->payment_id !== null ? $pagos->get($m->payment_id) : null) === null,
            'reversado'  => isset($reversados[$m->id]),
            'id' => $m->id, 'tipo' => $m->tipo, 'fecha' => $m->fecha_movimiento->toIso8601String(), 'monto' => $m->monto,
            'delta_disponible' => $m->delta_disponible, 'origen' => $m->origen, 'motivo' => $m->motivo,
            'cobro' => $m->payment_id !== null ? $payments->get($m->payment_id) : null,
            'factura' => $m->invoice_id !== null ? $invoices->get($m->invoice_id) : null,
            'compensacion' => $m->compensation_id !== null ? $cmps->get($m->compensation_id) : null,
            'usuario' => $this->nombreDe($actors->get((int) $m->actor_id)),
        ])->all());
    }
}
