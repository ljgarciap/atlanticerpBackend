<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Services;

use App\Modules\AdminCont\Models\AdminContAbono;
use App\Modules\AdminCont\Models\AdminContCompensationLine;
use App\Modules\AdminCont\Models\AdminContCreditNote;
use App\Modules\AdminCont\Models\AdminContInvoice;
use App\Modules\AdminCont\Models\AdminContPayment;
use App\Modules\VentasDiseno\Models\MasterClient;
use App\Modules\VentasDiseno\Models\SalesProject;

/**
 * SCRUM-903 (Saldo a Favor de Clientes) — pantalla 100% de solo consulta, sin lógica de negocio
 * propia (ticket §1/§4.1): unión de `abonos` (`estado=confirmado`, cualquier nivel) + `credit_notes`
 * (`resultado=saldo_favor`, `estado=aplicada`, siempre nivel Cliente master por construcción — ver
 * `CreditNoteService::applyToBalance()`). No existe una tercera fuente ni una categoría "Otros"
 * (§2.3) — agregar acá SOLO si el negocio define un origen legítimo nuevo con nombre propio.
 */
final class SaldoFavorService
{
    public function __construct(private readonly ClientBalanceService $clientBalance) {}

    private const ORIGEN_ABONO = 'abono';
    private const ORIGEN_COBRO  = 'cobro';
    private const ORIGEN_DEVOLUCION = 'devolucion';
    private const ORIGEN_DESCUENTO  = 'descuento';
    private const ORIGEN_ANULACION  = 'anulacion';

    /**
     * SCRUM-947 — `cliente`/`proyecto`/`factura` son NUEVOS (antes solo existían como filtros
     * client-side en `SaldoFavorPage.tsx`, nunca llegaban acá — ver commit del frontend). Con
     * paginación real, un filtro client-side sobre una sola página deja de tener sentido: el
     * frontend ahora los manda como texto ("contiene", igual criterio que `search`), combinables
     * entre sí (AND) y con el resto de filtros — no reemplazan a `master_client_id`/
     * `sales_project_id`/`invoice_id` (filtro exacto por id, reservado para un futuro deep-link,
     * el frontend hoy no los usa).
     *
     * @param array{search?: ?string, cliente?: ?string, proyecto?: ?string, factura?: ?string, master_client_id?: ?int, sales_project_id?: ?int, invoice_id?: ?int, estado?: ?string, origen?: ?string} $filters
     * @return array{indicadores: array{saldo_disponible_total: float, clientes_con_saldo: int}, rows: array<int, array<string, mixed>>}
     */
    public function list(array $filters = []): array
    {
        $rows = $this->allRows();

        // Indicadores SIEMPRE sobre el total real, sin importar el filtro aplicado en la tabla (§3.1).
        $saldoDisponibleTotal = round(array_sum(array_map(fn (array $r) => $r['monto_disponible'], $rows)), 2);
        $clientesConSaldo     = count(array_unique(array_map(
            fn (array $r) => $r['master_client_id'],
            array_filter($rows, fn (array $r) => $r['monto_disponible'] > 0),
        )));

        $filtered = array_values(array_filter($rows, function (array $r) use ($filters): bool {
            if (($filters['master_client_id'] ?? null) !== null && $r['master_client_id'] !== $filters['master_client_id']) {
                return false;
            }
            if (($filters['sales_project_id'] ?? null) !== null && $r['sales_project_id'] !== $filters['sales_project_id']) {
                return false;
            }
            if (($filters['invoice_id'] ?? null) !== null && $r['invoice_id'] !== $filters['invoice_id']) {
                return false;
            }
            if (($filters['estado'] ?? null) !== null && $r['estado'] !== $filters['estado']) {
                return false;
            }
            if (($filters['origen'] ?? null) !== null && $r['origen_tipo'] !== $filters['origen']) {
                return false;
            }
            if (($filters['cliente'] ?? null) !== null && trim((string) $filters['cliente']) !== ''
                && ! str_contains(mb_strtolower($r['cliente']), mb_strtolower(trim((string) $filters['cliente'])))) {
                return false;
            }
            if (($filters['proyecto'] ?? null) !== null && trim((string) $filters['proyecto']) !== ''
                && ! str_contains(mb_strtolower((string) ($r['proyecto'] ?? '')), mb_strtolower(trim((string) $filters['proyecto'])))) {
                return false;
            }
            if (($filters['factura'] ?? null) !== null && trim((string) $filters['factura']) !== ''
                && ! str_contains(mb_strtolower((string) ($r['factura'] ?? '')), mb_strtolower(trim((string) $filters['factura'])))) {
                return false;
            }
            if (($filters['search'] ?? null) !== null && trim((string) $filters['search']) !== '') {
                $needle   = mb_strtolower(trim((string) $filters['search']));
                $haystack = mb_strtolower(implode(' ', [
                    $r['cliente'], $r['proyecto'] ?? '', $r['factura'] ?? '', $r['origen'], $r['referencia'],
                ]));
                if (! str_contains($haystack, $needle)) {
                    return false;
                }
            }

            return true;
        }));

        usort($filtered, fn (array $a, array $b) => $b['fecha'] <=> $a['fecha']);

        return [
            'indicadores' => [
                'saldo_disponible_total' => $saldoDisponibleTotal,
                'clientes_con_saldo'     => $clientesConSaldo,
                // Fase 13 (2.2) — el total desglosado, para que ninguna cifra mezcle niveles sin decirlo.
                'saldo_aplicable_cliente' => $this->sumaDisponible($rows, fn (array $r): bool => $r['nivel'] === 'cliente' && $r['origen_tipo'] !== self::ORIGEN_COBRO),
                'saldo_a_cuenta'          => $this->sumaDisponible($rows, fn (array $r): bool => $r['origen_tipo'] === self::ORIGEN_COBRO),
                'saldo_reservado'         => $this->sumaDisponible($rows, fn (array $r): bool => $r['nivel'] !== 'cliente'),
            ],
            'rows'        => $filtered,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param callable(array<string, mixed>): bool $filtro
     */
    private function sumaDisponible(array $rows, callable $filtro): float
    {
        return round(array_sum(array_map(fn (array $r): float => (float) $r['monto_disponible'], array_filter($rows, $filtro))), 2);
    }

    /** @return array<int, array<string, mixed>> */
    private function allRows(): array
    {
        $abonos = AdminContAbono::query()->where('estado', AdminContAbono::ESTADO_CONFIRMADO)->get();
        $notas  = AdminContCreditNote::query()
            ->where('resultado', AdminContCreditNote::RESULTADO_SALDO_FAVOR)
            ->where('estado', AdminContCreditNote::ESTADO_APLICADA)
            ->get();

        // SCRUM-1168 F2 — cobros del concepto nuevo con saldo sin aplicar (a cuenta): el libro dice cuánto queda.
        $remanentes = $this->clientBalance->remanentesPorCobro();
        $cobros     = $remanentes === [] ? collect() : AdminContPayment::query()->whereIn('id', array_keys($remanentes))->get();

        $clientIds  = $abonos->pluck('master_client_id')->concat($notas->pluck('master_client_id'))->concat($cobros->pluck('master_client_id'))->unique();
        $projectIds = $abonos->pluck('sales_project_id')->filter()->unique();
        $invoiceIds = $abonos->pluck('invoice_id')->filter()->unique();

        $clientNames  = MasterClient::whereIn('id', $clientIds)->pluck('name', 'id');
        $projectNames = SalesProject::whereIn('id', $projectIds)->pluck('name', 'id');
        $invoiceNumbers = AdminContInvoice::whereIn('id', $invoiceIds)->pluck('numero', 'id');

        $rows = [];
        foreach ($abonos as $a) {
            $rows[] = [
                'origen_tipo'      => self::ORIGEN_ABONO,
                // Fase 13 (2.2) — la compensación pide `source_type` y `source_id` por línea; antes el listado no los exponía.
                'source_type'      => AdminContCompensationLine::SOURCE_ABONO,
                'source_id'        => $a->id,
                'nivel'            => $a->nivelAsociacion(),
                'origen'           => 'Abono de más',
                'master_client_id' => $a->master_client_id,
                'cliente'          => $clientNames->get($a->master_client_id) ?? '',
                'sales_project_id' => $a->sales_project_id,
                'proyecto'         => $a->sales_project_id !== null ? ($projectNames->get($a->sales_project_id) ?? '') : null,
                'invoice_id'       => $a->invoice_id,
                'factura'          => $a->invoice_id !== null ? ($invoiceNumbers->get($a->invoice_id) ?? '') : null,
                'asociado_a'       => ucfirst($a->nivelAsociacion() === 'cliente' ? 'Cliente master' : $a->nivelAsociacion()),
                'referencia'       => $a->referencia,
                'fecha'            => $a->fecha_registro->toIso8601String(),
                'monto_original'   => $a->monto_original,
                'monto_usado'      => $a->monto_usado,
                'monto_disponible' => $a->montoDisponible(),
                'estado'           => $a->estadoSaldo(),
            ];
        }

        foreach ($cobros as $c) {
            $disponible = $remanentes[$c->id]['remanente'];
            $original   = round((float) $c->monto_recibido, 2);
            $rows[] = [
                'origen_tipo'      => self::ORIGEN_COBRO,
                'source_type'      => AdminContCompensationLine::SOURCE_COBRO,
                'source_id'        => $c->id,
                'nivel'            => 'cliente',
                'origen'           => 'Cobro a cuenta',
                'master_client_id' => $c->master_client_id,
                'cliente'          => $clientNames->get($c->master_client_id) ?? '',
                'sales_project_id' => null,
                'proyecto'         => null,
                'invoice_id'       => null,
                'factura'          => null,
                'asociado_a'       => 'Cliente master',
                'referencia'       => $c->numero_recibo,
                'fecha'            => $c->created_at->toIso8601String(),
                'monto_original'   => $original,
                'monto_usado'      => round($original - $disponible, 2),
                'monto_disponible' => $disponible,
                'estado'           => $disponible >= $original - 0.005 ? 'disponible' : 'usado_parcialmente',
            ];
        }

        $notaRows = [];
        foreach ($notas as $n) {
            $label = match ($n->tipo) {
                AdminContCreditNote::TIPO_DEVOLUCION_MERCANCIA => 'Devolución de mercancía',
                AdminContCreditNote::TIPO_DESCUENTO_COMERCIAL   => 'Descuento comercial',
                AdminContCreditNote::TIPO_ANULACION_COMPLETA    => 'Anulación de factura',
                default                                          => 'Nota de crédito',
            };
            $tipo = match ($n->tipo) {
                AdminContCreditNote::TIPO_DEVOLUCION_MERCANCIA => self::ORIGEN_DEVOLUCION,
                AdminContCreditNote::TIPO_DESCUENTO_COMERCIAL   => self::ORIGEN_DESCUENTO,
                AdminContCreditNote::TIPO_ANULACION_COMPLETA    => self::ORIGEN_ANULACION,
                default                                          => self::ORIGEN_DEVOLUCION,
            };

            $notaRows[] = [
                'origen_tipo'      => $tipo,
                'source_type'      => AdminContCompensationLine::SOURCE_NOTA_CREDITO,
                'source_id'        => $n->id,
                'nivel'            => 'cliente',
                'origen'           => $label,
                'master_client_id' => $n->master_client_id,
                'cliente'          => $clientNames->get($n->master_client_id) ?? $n->cliente,
                'sales_project_id' => null,
                'proyecto'         => null,
                'invoice_id'       => null,
                'factura'          => null,
                // §2.1/3.3 — SIEMPRE nivel Cliente master, nunca proyecto/factura (Escenario 3).
                'asociado_a'       => 'Cliente master',
                'referencia'       => $n->numero,
                'fecha'            => $n->fecha->toIso8601String(),
                'monto_original'   => round($n->monto - (float) ($n->monto_aplicado_saldo ?? 0.0), 2),
                'monto_usado'      => $n->monto_usado,
                'monto_disponible' => $n->montoDisponibleSaldoFavor(),
                'estado'           => $n->estadoSaldoFavor(),
            ];
        }

        return array_values(array_merge($rows, $notaRows));
    }
}
