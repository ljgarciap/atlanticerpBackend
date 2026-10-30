<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Services;

use App\Models\User;
use App\Modules\AdminCont\Exceptions\DailyCashCountStateException;
use App\Modules\AdminCont\Models\AdminContBankMovement;
use App\Modules\AdminCont\Models\AdminContCreditNote;
use App\Modules\AdminCont\Models\AdminContDailyCashCount;
use App\Modules\AdminCont\Models\AdminContDailyCashCountEntry;
use App\Modules\AdminCont\Models\AdminContFiscalSettings;
use App\Modules\AdminCont\Models\AdminContInvoice;
use App\Modules\AdminCont\Models\AdminContPayment;
use App\Modules\AdminCont\Models\AdminContPaymentReversalRequest;
use App\Modules\Bodega\Models\Order;
use App\Modules\VentasDiseno\Models\MasterClient;
use App\Shared\Http\Pagination;
use App\Shared\Services\AuditService;
use Carbon\CarbonImmutable;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Batch 18 de Admin&Cont (SCRUM-597→601, REQ-524) — ver
 * docs/adr/ADR-SCRUM597-601-batch18-arqueo-caja.md. "Hoy" (RN1) — el arqueo del día se arma
 * automáticamente, sin captura manual de movimientos, solo observaciones por fila + general.
 *
 * Batch 19 (SCRUM-602→606, REQ-525→529) — ver
 * docs/adr/ADR-SCRUM602-606-batch19-arqueo-caja-parte2.md. `today()` se refactoriza a `forDate()`
 * (misma lógica, ahora parametrizada) + `activeDate()`/`active()` (el arqueo activo ya no es
 * necesariamente "hoy" — reconciliación punto 2 del ADR) + el ciclo cierre/aprobación
 * (`close()`/`history()`/`detail()`/`approve()`). `updateEntryObservation()`/
 * `updateGeneralObservation()` ahora operan sobre el arqueo ACTIVO (no literal-hoy) y rechazan la
 * escritura si ya no está `abierto` (reconciliación punto 3).
 */
final class DailyCashCountService
{
    public function __construct(
        private readonly AdminContPaymentRetentionAttachmentService $retentionAttachments,
    ) {}

    /**
     * `$asOf` (Pre-QA SCRUM-878/879, 2026-09-10) — cuándo reconstruir el mundo, no qué día mirar:
     * null = ahora mismo (uso normal, arqueo abierto o snapshot al cerrar). Un valor explícito
     * filtra cada fuente por su propio `created_at <= asOf` — reconstruye lo que un arqueo YA
     * cerrado tenía en ese instante, usado únicamente por el backfill de `snapshot` (ver comando
     * `admin-contab:backfill-daily-cash-count-snapshots`) para no congelar un total ya contaminado
     * por un movimiento retroactivo posterior al cierre real.
     *
     * @return array{fecha: string, cobros: list<array<string, mixed>>, notas_credito: list<array<string, mixed>>,
     *     comisiones: list<array<string, mixed>>, retenciones: array<int, array<string, mixed>>,
     *     total_cobrado: float, total_notas_credito: float, total_comisiones: float,
     *     total_neto: float, total_facturado_dia: float, total_pasado_a_pedido_dia: float,
     *     observacion_general: string|null}
     */
    public function forDate(string $date, ?CarbonImmutable $asOf = null): array
    {
        $entriesByKey = $this->entriesForDate($date);

        $cobros = [];
        $paymentIdsRetenc = [];
        $totalCobrado = 0.0;
        foreach ($this->paymentsConfirmedOn($date, $asOf) as $payment) {
            $totalCobrado += $payment->monto_recibido;
            $cobros[] = [
                'movement_type' => AdminContDailyCashCountEntry::TYPE_PAYMENT,
                'movement_id' => $payment->id,
                'concepto' => "Cobro {$payment->numero_recibo}",
                'metodo_pago' => $payment->metodo_pago,
                'monto' => $payment->monto_recibido,
                'observacion' => $entriesByKey[AdminContDailyCashCountEntry::TYPE_PAYMENT.':'.$payment->id] ?? null,
            ];

            // REQ-525 RN1 — sección Ajustes/Retención, solo cobros con este método.
            if ($payment->metodo_pago === AdminContPayment::METODO_RETENCION_IMPUESTOS) {
                $paymentIdsRetenc[] = $payment->id;
            }
        }

        $notasCredito = [];
        $totalNotasCredito = 0.0;
        foreach ($this->creditNotesReturnedOn($date, $asOf) as [$note, $monto]) {
            $totalNotasCredito += $monto;
            $notasCredito[] = [
                'movement_type' => AdminContDailyCashCountEntry::TYPE_CREDIT_NOTE,
                'movement_id' => $note->id,
                'concepto' => 'Devolución de dinero — '.$note->cliente,
                'monto' => $monto,
                'observacion' => $entriesByKey[AdminContDailyCashCountEntry::TYPE_CREDIT_NOTE.':'.$note->id] ?? null,
            ];
        }

        // SCRUM-879 — comisiones (externas o internas) pagadas el día en curso: salida real de
        // dinero, ya reflejada en `bank_movements` (tipo `comision`) por
        // `ArchitectCommissionService`/`commissions:settle-internal`. `bank_account_id` puede ser
        // null (pago sin cuenta asignada todavía, REQ-534) — igual cuenta acá, es plata que salió.
        $comisiones = [];
        $totalComisiones = 0.0;
        foreach ($this->commissionsPaidOn($date, $asOf) as $movement) {
            $totalComisiones += $movement->monto;
            $comisiones[] = [
                'movement_type' => AdminContDailyCashCountEntry::TYPE_COMMISSION,
                'movement_id' => $movement->id,
                'concepto' => $movement->concepto,
                'monto' => $movement->monto,
                'observacion' => $entriesByKey[AdminContDailyCashCountEntry::TYPE_COMMISSION.':'.$movement->id] ?? null,
            ];
        }

        // SCRUM-1168 F4/F5 — anulaciones de cobros y devoluciones de dinero al cliente del día: salida real de caja, por la fecha en que ocurren (un arqueo
        // cerrado de otro día no se toca). Se restan del neto igual que las devoluciones de notas y las comisiones.
        $reversos = [];
        $totalReversos = 0.0;
        foreach ($this->reversosOn($date, $asOf) as $movement) {
            $totalReversos += $movement->monto;
            $reversos[] = [
                'movement_type' => AdminContDailyCashCountEntry::TYPE_REVERSAL,
                'movement_id' => $movement->id,
                'concepto' => $movement->concepto,
                'monto' => $movement->monto,
                'observacion' => $entriesByKey[AdminContDailyCashCountEntry::TYPE_REVERSAL.':'.$movement->id] ?? null,
            ];
        }

        $dailyCount = AdminContDailyCashCount::where('fecha', $date)->first();

        return [
            'fecha' => $date,
            // SCRUM-1175 — cada línea lleva el nombre del cliente (las comisiones no tienen cliente).
            'cobros' => $this->anotarClientes($cobros),
            'notas_credito' => $this->anotarClientes($notasCredito),
            'comisiones' => $comisiones,
            'reversos' => $this->anotarClientes($reversos),
            // Siempre en vivo (nunca congelada, ni siquiera cuando el resto viene de `snapshot`,
            // ver `present()`) — RN4: la constancia se puede subir/reemplazar después del cierre.
            'retenciones' => $this->presentRetenciones($paymentIdsRetenc),
            'total_cobrado' => round($totalCobrado, 2),
            'total_notas_credito' => round($totalNotasCredito, 2),
            'total_comisiones' => round($totalComisiones, 2),
            'total_reversos' => round($totalReversos, 2),
            'total_neto' => round($totalCobrado - $totalNotasCredito - $totalComisiones - $totalReversos, 2),
            // SCRUM-934 — indicadores del día, informativos: NO entran en `total_neto` (no son
            // movimientos de caja, son actividad comercial del mismo día).
            ...$this->dayIndicators($date, $asOf),
            'observacion_general' => $dailyCount?->observacion_general,
        ];
    }

    /**
     * SCRUM-934 — indicadores comerciales del día, calculados "al momento exacto" en que se arma el
     * arqueo (mismo criterio que el resto de `forDate()`: en vivo mientras está abierto, congelados
     * en `snapshot` al cerrar). Se reinician solos cada día porque el filtro es la fecha del arqueo
     * (zona America/Panama, sesión Postgres alineada — ver `config/database.php`), sin ningún job.
     *
     * - `total_facturado_dia`: Σ `invoices.monto` creadas ese día y no anuladas.
     * - `total_pasado_a_pedido_dia`: Σ del monto (`pipeline_cards.amount`, el mismo total que usa la
     *   factura) de los pedidos RAÍZ (`order_type=pedido`, `family_id IS NULL`) creados ese día, es
     *   decir cotizaciones aprobadas que ya pasaron a Bodega. Solo raíces: un pedido dividido en N
     *   entregas parciales cuenta una sola vez, nunca N.
     *
     * `$asOf` — ver docblock de `forDate()`.
     *
     * @return array{total_facturado_dia: float, total_pasado_a_pedido_dia: float}
     */
    private function dayIndicators(string $date, ?CarbonImmutable $asOf = null): array
    {
        $facturado = (float) AdminContInvoice::query()
            ->whereNull('anulada_at')
            ->whereDate('created_at', $date)
            ->when($asOf !== null, fn ($q) => $q->where('created_at', '<=', $asOf))
            ->sum('monto');

        $pasadoAPedido = 0.0;
        $pedidos = Order::query()
            ->where('order_type', Order::ORDER_TYPE_PEDIDO)
            ->whereNull('family_id')
            ->whereDate('created_at', $date)
            ->when($asOf !== null, fn ($q) => $q->where('created_at', '<=', $asOf))
            ->with('pipelineCard:id,amount')
            ->get();
        foreach ($pedidos as $pedido) {
            $pasadoAPedido += (float) ($pedido->pipelineCard->amount ?? 0);
        }

        return [
            'total_facturado_dia' => round($facturado, 2),
            'total_pasado_a_pedido_dia' => round($pasadoAPedido, 2),
        ];
    }

    /**
     * REQ-527 — el "arqueo activo" es siempre el más antiguo sin cerrar (`estado=abierto`, o sin
     * fila todavía, que es lo mismo). Piso: `arqueo_tracking_start_date` (editable por Gerencia) o, si
     * nunca se fijó, la fecha más vieja que ya tenga fila, o hoy si la tabla está vacía — ver ADR,
     * reconciliación punto 4. Rango acotado (semanas desde el go-live de Batch 19), escaneo día a
     * día sin problema de performance a este volumen.
     *
     * SCRUM-867 (hallazgo 2026-09-09) — el piso "o, si nunca se fijó... o hoy si la tabla está
     * vacía" es ciego a un caso real: las filas de esta tabla se crean de forma PEREZOSA (solo al
     * primer movimiento escrito ese día, ver `activeRecordForWrite()`), así que un día en el que
     * nadie tocó Arqueo de Caja en absoluto nunca genera fila — si además es el PRIMER día que
     * alguien usa la pantalla (tabla todavía vacía, sin ningún arqueo cerrado), el piso caía
     * directo en "hoy" sin ningún aviso, aunque ya hubiera cobros confirmados el día anterior sin
     * arquear. Cuando la tabla está vacía Y no hay `arqueo_tracking_start_date` configurado, se
     * agrega un candidato de piso adicional: la fecha del primer cobro confirmado que exista — así
     * un día con actividad real pero sin fila todavía sigue siendo detectable. No cambia ninguno
     * de los otros 2 casos del piso (start_date explícito, o tabla ya con al menos una fila).
     */
    public function activeDate(): string
    {
        $floor = AdminContFiscalSettings::current()->arqueo_tracking_start_date?->toDateString()
            ?? AdminContDailyCashCount::min('fecha')
            ?? AdminContPayment::where('estado', AdminContPayment::ESTADO_CONFIRMADO)->min('created_at')
            ?? now()->toDateString();

        $cursor = CarbonImmutable::parse($floor);
        $today = CarbonImmutable::parse(now()->toDateString());

        while ($cursor->lt($today)) {
            $record = AdminContDailyCashCount::where('fecha', $cursor->toDateString())->first();
            if ($record === null || $record->estado === AdminContDailyCashCount::ESTADO_ABIERTO) {
                return $cursor->toDateString();
            }
            $cursor = $cursor->addDay();
        }

        return $today->toDateString();
    }

    /**
     * GET /cash-position/daily-count — el arqueo ACTIVO, con `es_atrasado` si no es hoy.
     *
     * @return array<string, mixed>
     */
    public function active(): array
    {
        $date = $this->activeDate();
        $record = AdminContDailyCashCount::where('fecha', $date)->first();
        $today = now()->toDateString();

        return [
            ...$this->present($record, $date),
            'es_atrasado' => $date !== $today,
            'fecha_real_hoy' => $today,
            // QA SCRUM-603 (2026-08-29 RN1) — el modal de confirmación de cierre necesita mostrar
            // un número; el real (`numero`) recién se asigna en close() (ver ADR §8), así que acá
            // se previsualiza con el mismo cálculo, sin persistir nada.
            'numero_preview' => (int) (AdminContDailyCashCount::whereNotNull('numero')->max('numero') ?? 0) + 1,
        ];
    }

    /**
     * GET /cash-position/history/{id} y detalle usado por `/export` — cualquier arqueo, activo o cerrado.
     *
     * @return array<string, mixed>|null
     */
    public function detail(int $id): ?array
    {
        $record = AdminContDailyCashCount::find($id);

        return $record === null ? null : $this->present($record, $record->fecha->toDateString());
    }

    /**
     * REQ-526 — cierra el arqueo activo. RN2 (aviso de retención sin constancia) es responsabilidad
     * del frontend, que ya tiene `retenciones[]` de `active()` antes de confirmar — este método no
     * bloquea por eso.
     *
     * @return array<string, mixed>
     *
     * @throws DailyCashCountStateException si el arqueo activo ya no está `abierto` (doble click / carrera).
     */
    public function close(int $actorId): array
    {
        return DB::transaction(function () use ($actorId): array {
            $date = $this->activeDate();

            $record = AdminContDailyCashCount::where('fecha', $date)->lockForUpdate()->first();
            if ($record === null) {
                // `create()` en Postgres solo hace RETURNING id — el atributo `estado` quedaría
                // null en memoria (no el default de la BD) si no se pasa explícito acá, y el guard
                // de abajo lo interpretaría como "no abierto" en un registro recién creado.
                $record = AdminContDailyCashCount::create(['fecha' => $date, 'estado' => AdminContDailyCashCount::ESTADO_ABIERTO]);
            }

            if ($record->estado !== AdminContDailyCashCount::ESTADO_ABIERTO) {
                throw new DailyCashCountStateException('Este arqueo ya no está abierto.');
            }

            // REQ-525→529 punto 8 del ADR — entero simple, asignado solo al cerrar, sin tabla de
            // folio dedicada (volumen de 1 cierre/día, acción humana explícita).
            // Fase 13 (2.10): un arqueo reabierto conserva su número al volver a cerrarse.
            $numero = $record->numero ?? (int) (AdminContDailyCashCount::whereNotNull('numero')->max('numero') ?? 0) + 1;

            // Pre-QA SCRUM-878/879 (2026-09-10) — el total se congela ACÁ, en el instante del
            // cierre (forDate() en vivo, sin $asOf — es exactamente lo que Contabilidad ve al confirmar),
            // y nunca se vuelve a recalcular mientras el arqueo no esté `abierto` (ver totalsFor()).
            $record->update([
                'numero' => $numero,
                'estado' => AdminContDailyCashCount::ESTADO_PENDIENTE_APROBACION,
                'cerrado_por' => $actorId,
                'cerrado_at' => now(),
                'snapshot' => $this->forDate($date),
            ]);

            return $this->present($record->fresh(), $date);
        });
    }

    /**
     * REQ-528 — historial de arqueos cerrados (cualquier estado != abierto), más recientes primero,
     * con el contador de pendientes de aprobación (RN3).
     *
     * SCRUM-947 — `$perPage` antes vivía hardcodeado acá adentro (sin selector de cantidad, a
     * diferencia del resto de listados del sistema); ahora usa el mismo helper estándar
     * (`App\Shared\Http\Pagination`) que el resto, con `meta` completo (antes solo traía
     * `current_page`/`last_page`, sin `total`/`per_page`).
     *
     * @return array{data: list<array<string, mixed>>, meta: array{total: int, per_page: int, current_page: int, last_page: int}, pendientes_aprobacion: int}
     */
    public function history(int $page, int $perPage = 20): array
    {
        $query = AdminContDailyCashCount::where('estado', '!=', AdminContDailyCashCount::ESTADO_ABIERTO)
            ->orderByDesc('fecha');

        /** @var LengthAwarePaginator<int, AdminContDailyCashCount> $paginator */
        $paginator = $query->paginate($perPage, ['*'], 'page', max(1, $page));
        $records = collect($paginator->items());

        $userIds = $records->pluck('cerrado_por')->merge($records->pluck('aprobado_por'))->filter()->unique();
        /** @var Collection<int, string> $userNames */
        $userNames = User::query()->whereIn('id', $userIds)->get(['id', 'first_name', 'last_name'])
            ->mapWithKeys(fn (User $u) => [$u->id => trim("{$u->first_name} {$u->last_name}")]);

        $data = $records->map(function (AdminContDailyCashCount $r) use ($userNames): array {
            // Pre-QA SCRUM-878/879 — mismo criterio que `present()`/`totalsFor()`: el listado no
            // puede mostrar un total_neto que luego difiera del detalle (`historyDetail()`), que sí
            // ya usaba el snapshot congelado.
            $totalNeto = $r->snapshot !== null ? $r->snapshot['total_neto'] : $this->forDate($r->fecha->toDateString())['total_neto'];

            return [
                'id' => $r->id,
                'numero' => $r->numero,
                'fecha' => $r->fecha->toDateString(),
                'total_neto' => $totalNeto,
                'estado' => $r->estado,
                'aprobado_por' => $r->aprobado_por !== null ? ($userNames->get($r->aprobado_por) ?? null) : null,
                'aprobado_at' => $r->aprobado_at?->toIso8601String(),
                'realizado_por' => $r->cerrado_por !== null ? ($userNames->get($r->cerrado_por) ?? null) : null,
                // QA SCRUM-603 (2026-08-29 RN4) — el historial mostraba quién cerró pero no la hora.
                'cerrado_at' => $r->cerrado_at?->toIso8601String(),
            ];
        })->values();

        /** @var list<array<string, mixed>> $dataList */
        $dataList = $data->all();

        $pendientes = AdminContDailyCashCount::where('estado', AdminContDailyCashCount::ESTADO_PENDIENTE_APROBACION)->count();

        return [
            'data' => $dataList,
            'meta' => Pagination::meta($paginator),
            'pendientes_aprobacion' => $pendientes,
        ];
    }

    /**
     * REQ-528 RN2/RN4 — exclusivo de Gerencia (gate real: middleware `primary_approver_only`, ver routes/admin-contab.php).
     *
     * @return array<string, mixed>
     *
     * @throws DailyCashCountStateException si el arqueo ya no está `pendiente_aprobacion`.
     */
    public function approve(int $id, int $approverUserId): array
    {
        return DB::transaction(function () use ($id, $approverUserId): array {
            /** @var AdminContDailyCashCount $record */
            $record = AdminContDailyCashCount::query()->lockForUpdate()->findOrFail($id);

            if ($record->estado !== AdminContDailyCashCount::ESTADO_PENDIENTE_APROBACION) {
                throw new DailyCashCountStateException('Este arqueo ya no está pendiente de aprobación.');
            }

            $record->update([
                'estado' => AdminContDailyCashCount::ESTADO_APROBADO,
                'aprobado_por' => $approverUserId,
                'aprobado_at' => now(),
            ]);

            return $this->present($record->fresh(), $record->fecha->toDateString());
        });
    }

    /**
     * Fase 13 (2.10) — reabre un arqueo cerrado (pendiente de aprobación o aprobado). Exclusivo del aprobador
     * o su suplente (middleware `primary_approver_only`). Conserva el número y las observaciones, descarta el
     * snapshot (vuelve a calcularse en vivo hasta el nuevo cierre) y deja motivo y autor en el registro y en
     * `audit_logs`.
     *
     * @return array<string, mixed>
     *
     * @throws DailyCashCountStateException si el arqueo ya está abierto.
     */
    public function reopen(int $id, int $actorId, string $motivo): array
    {
        return DB::transaction(function () use ($id, $actorId, $motivo): array {
            /** @var AdminContDailyCashCount $record */
            $record = AdminContDailyCashCount::query()->lockForUpdate()->findOrFail($id);

            if ($record->estado === AdminContDailyCashCount::ESTADO_ABIERTO) {
                throw new DailyCashCountStateException('Este arqueo ya está abierto.');
            }

            $estadoPrevio = $record->estado;

            $record->update([
                'estado' => AdminContDailyCashCount::ESTADO_ABIERTO,
                'cerrado_por' => null,
                'cerrado_at' => null,
                'aprobado_por' => null,
                'aprobado_at' => null,
                'snapshot' => null,
                'reabierto_por' => $actorId,
                'reabierto_at' => now(),
                'reabierto_motivo' => $motivo,
            ]);

            app(AuditService::class)->log(
                'admin_contab.daily_cash_count.reopened',
                'daily_cash_count',
                $record->id,
                ['estado' => $estadoPrevio],
                ['estado' => AdminContDailyCashCount::ESTADO_ABIERTO],
                ['motivo' => $motivo, 'fecha' => $record->fecha->toDateString(), 'numero' => $record->numero],
                $actorId,
            );

            return $this->present($record->fresh(), $record->fecha->toDateString());
        });
    }

    public function updateEntryObservation(string $movementType, int $movementId, ?string $observacion): void
    {
        $count = $this->activeRecordForWrite();
        AdminContDailyCashCountEntry::updateOrCreate(
            ['daily_cash_count_id' => $count->id, 'movement_type' => $movementType, 'movement_id' => $movementId],
            ['observacion' => $observacion],
        );
    }

    public function updateGeneralObservation(?string $observacion): void
    {
        $this->activeRecordForWrite()->update(['observacion_general' => $observacion]);
    }

    /**
     * REQ-528 RN1 — un arqueo que ya no está `abierto` es un registro histórico fijo, salvo la
     * constancia de retención (que no pasa por acá, ver `AdminContPaymentRetentionAttachmentService`).
     *
     * @throws DailyCashCountStateException si el arqueo activo ya no está `abierto`.
     */
    private function activeRecordForWrite(): AdminContDailyCashCount
    {
        // Mismo motivo que en close() — `estado` explícito en el create() para no depender del
        // default de la BD, que `create()`/`firstOrCreate()` no reflejan en el objeto en memoria.
        $record = AdminContDailyCashCount::firstOrCreate(
            ['fecha' => $this->activeDate()],
            ['estado' => AdminContDailyCashCount::ESTADO_ABIERTO],
        );

        if ($record->estado !== AdminContDailyCashCount::ESTADO_ABIERTO) {
            throw new DailyCashCountStateException('Este arqueo ya no está abierto — no se puede editar.');
        }

        return $record;
    }

    /** @return array<string, mixed> */
    private function present(?AdminContDailyCashCount $record, string $date): array
    {
        $estado = $record !== null ? $record->estado : AdminContDailyCashCount::ESTADO_ABIERTO;

        return [
            'id' => $record?->id,
            'numero' => $record?->numero,
            'estado' => $estado,
            ...$this->totalsFor($record, $date),
            'cerrado_por' => $record?->cerrado_por !== null ? $this->userName($record->cerrado_por) : null,
            'cerrado_at' => $record?->cerrado_at?->toIso8601String(),
            'aprobado_por' => $record?->aprobado_por !== null ? $this->userName($record->aprobado_por) : null,
            'aprobado_at' => $record?->aprobado_at?->toIso8601String(),
            'reabierto_por' => $record?->reabierto_por !== null ? $this->userName($record->reabierto_por) : null,
            'reabierto_at' => $record?->reabierto_at?->toIso8601String(),
            'reabierto_motivo' => $record?->reabierto_motivo,
        ];
    }

    /**
     * Pre-QA SCRUM-878/879 (2026-09-10, hallazgo CRÍTICO) — un arqueo que ya no está `abierto` es
     * un registro histórico FIJO: usa el `snapshot` congelado en `close()`, nunca recalcula en
     * vivo. Antes, `forDate()` se recomputaba en cada consulta, así que un movimiento retroactivo
     * (ej. `commissions:backfill-cash-movements`) cambiaba en silencio el `total_neto` que Contabilidad ya
     * había cerrado y que Gerencia aprobaba sin saberlo — sin ningún rastro en `audit_logs`.
     * `retenciones` es la única excepción, siempre en vivo (RN4 — constancia subible post-cierre),
     * reconstruida a partir de los IDs de pago congelados en el snapshot (el pago en sí no cambia,
     * solo si tiene constancia adjunta). Un registro `abierto`, o cerrado pero sin snapshot todavía
     * (fila legada previa al backfill, ver `admin-contab:backfill-daily-cash-count-snapshots`),
     * sigue en vivo como red de seguridad.
     *
     * @return array<string, mixed>
     */
    private function totalsFor(?AdminContDailyCashCount $record, string $date): array
    {
        $estado = $record !== null ? $record->estado : AdminContDailyCashCount::ESTADO_ABIERTO;

        if ($record === null || $estado === AdminContDailyCashCount::ESTADO_ABIERTO || $record->snapshot === null) {
            return $this->forDate($date);
        }

        /** @var array<string, mixed> $snapshot */
        $snapshot = $record->snapshot;
        /** @var list<array<string, mixed>> $cobrosSnapshot */
        $cobrosSnapshot = $snapshot['cobros'] ?? [];
        $paymentIdsRetenc = [];
        foreach ($cobrosSnapshot as $cobro) {
            if ($cobro['metodo_pago'] === AdminContPayment::METODO_RETENCION_IMPUESTOS) {
                $paymentIdsRetenc[] = (int) $cobro['movement_id'];
            }
        }

        // SCRUM-934 — un snapshot cerrado ANTES de que existieran los indicadores no los trae:
        // se reconstruyen "a la hora del cierre" (mismo criterio `$asOf` que el backfill de
        // snapshots), nunca en vivo — un arqueo cerrado sigue siendo un registro histórico fijo.
        $indicators = [];
        if (! array_key_exists('total_facturado_dia', $snapshot)) {
            $indicators = $this->dayIndicators(
                $date,
                $record->cerrado_at !== null ? CarbonImmutable::parse($record->cerrado_at) : null,
            );
        }

        // SCRUM-1175 — un arqueo cerrado antes de este cambio no trae el cliente en sus líneas: se completa al leerlo (el dato no cambia, solo se muestra).
        foreach (['cobros', 'notas_credito', 'reversos'] as $seccion) {
            if (isset($snapshot[$seccion]) && is_array($snapshot[$seccion])) {
                /** @var list<array<string, mixed>> $lineas */
                $lineas = array_values($snapshot[$seccion]);
                $snapshot[$seccion] = $this->anotarClientes($lineas);
            }
        }

        return [
            ...$snapshot,
            ...$indicators,
            'retenciones' => $this->presentRetenciones($paymentIdsRetenc),
            'observacion_general' => $record->observacion_general,
        ];
    }

    /**
     * SCRUM-1175 — agrega `cliente` (nombre) a cada línea del arqueo que aún no lo tenga: cobro y anulación por su cobro, nota de crédito por su nota y
     * devolución al cliente por su solicitud. Se resuelve en lote (una consulta por tipo) y nunca pisa un `cliente` ya presente.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function anotarClientes(array $rows): array
    {
        $pendientes = array_filter($rows, fn (array $r): bool => ! array_key_exists('cliente', $r));
        if ($pendientes === []) {
            return $rows;
        }
        $idsDe = fn (string $tipo): array => array_values(array_unique(array_map(
            fn (array $r): int => (int) $r['movement_id'],
            array_filter($pendientes, fn (array $r): bool => $r['movement_type'] === $tipo),
        )));

        // movement_id -> master_client_id
        $masterPorPago = AdminContPayment::query()->whereIn('id', $idsDe(AdminContDailyCashCountEntry::TYPE_PAYMENT))->pluck('master_client_id', 'id');
        $notas = AdminContCreditNote::query()->whereIn('id', $idsDe(AdminContDailyCashCountEntry::TYPE_CREDIT_NOTE))->pluck('cliente', 'id');

        $movimientos = AdminContBankMovement::query()->whereIn('id', $idsDe(AdminContDailyCashCountEntry::TYPE_REVERSAL))->get(['id', 'source_type', 'source_id'])->keyBy('id');
        $pagosDeReverso = AdminContPayment::query()
            ->whereIn('id', $movimientos->where('source_type', AdminContBankMovement::SOURCE_PAYMENT_REVERSAL)->pluck('source_id'))->pluck('master_client_id', 'id');
        $masterDeDevolucion = AdminContPaymentReversalRequest::query()
            ->whereIn('id', $movimientos->where('source_type', AdminContBankMovement::SOURCE_CLIENT_REFUND)->pluck('source_id'))->pluck('master_client_id', 'id');

        $masterDe = function (array $r) use ($masterPorPago, $movimientos, $pagosDeReverso, $masterDeDevolucion): ?int {
            if ($r['movement_type'] === AdminContDailyCashCountEntry::TYPE_PAYMENT) {
                return $masterPorPago->get((int) $r['movement_id']);
            }
            $mov = $movimientos->get((int) $r['movement_id']);

            return match ($mov?->source_type) {
                AdminContBankMovement::SOURCE_PAYMENT_REVERSAL => $pagosDeReverso->get((int) $mov->source_id),
                AdminContBankMovement::SOURCE_CLIENT_REFUND => $masterDeDevolucion->get((int) $mov->source_id),
                default => null,
            };
        };

        $nombres = MasterClient::query()->whereIn('id', collect($pendientes)
            ->filter(fn (array $r): bool => $r['movement_type'] !== AdminContDailyCashCountEntry::TYPE_CREDIT_NOTE)
            ->map($masterDe)->filter()->unique()->values())->pluck('name', 'id');

        $anotadas = [];
        foreach ($rows as $r) {
            if (! array_key_exists('cliente', $r)) {
                $r['cliente'] = $r['movement_type'] === AdminContDailyCashCountEntry::TYPE_CREDIT_NOTE
                    ? $notas->get((int) $r['movement_id'])
                    : $nombres->get($masterDe($r));
            }
            $anotadas[] = $r;
        }

        return $anotadas;
    }

    private function userName(int $userId): ?string
    {
        $user = User::find($userId);

        return $user !== null ? trim("{$user->first_name} {$user->last_name}") : null;
    }

    /**
     * Siempre re-consulta los pagos por ID (nunca desde una colección ya cargada) — el punto de
     * esto es leer `retentionAttachment` en vivo aunque el resto del arqueo venga de `snapshot`
     * (RN4, ver `totalsFor()`).
     *
     * @param  array<int, int>  $paymentIds
     * @return array<int, array<string, mixed>>
     */
    private function presentRetenciones(array $paymentIds): array
    {
        if ($paymentIds === []) {
            return [];
        }

        return AdminContPayment::whereIn('id', $paymentIds)
            ->with(['invoiceLines.invoice', 'retentionAttachment'])
            ->get()
            ->map(function (AdminContPayment $payment): array {
                $constancia = $payment->retentionAttachment;

                return [
                    'payment_id' => $payment->id,
                    'cliente' => MasterClient::find($payment->master_client_id)?->name,
                    'referencia' => $payment->invoiceLines->map(fn ($l) => $l->invoice?->numero)->filter()->implode(', '),
                    'motivo' => $payment->numero_documento_retencion,
                    'monto' => $payment->monto_recibido,
                    'constancia' => $constancia === null ? null : [
                        'nombre_archivo' => $constancia->nombre_archivo,
                        'url' => $this->retentionAttachments->presignedUrl($constancia),
                        'uploaded_at' => $constancia->updated_at->toIso8601String(),
                    ],
                ];
            })->values()->all();
    }

    /** @return array<string, string|null> keyed by "{movement_type}:{movement_id}" */
    private function entriesForDate(string $date): array
    {
        $dailyCount = AdminContDailyCashCount::where('fecha', $date)->with('entries')->first();
        if ($dailyCount === null) {
            return [];
        }

        $map = [];
        foreach ($dailyCount->entries as $entry) {
            $map["{$entry->movement_type}:{$entry->movement_id}"] = $entry->observacion;
        }

        return $map;
    }

    /**
     * RN1 — cobros confirmados el día en curso: métodos de confirmación inmediata (efectivo,
     * retención, ajuste) cuentan por `created_at`; el resto cuenta por `confirmation.confirmed_at`
     * (la confirmación manual puede llegar días después del registro). Batch 19 agrega eager load
     * de `invoiceLines.invoice`/`retentionAttachment` — antes innecesario, ahora usado por
     * `presentRetenciones()`.
     *
     * `$asOf` (Pre-QA SCRUM-878/879) — ver docblock de `forDate()`: filtra a pagos que ya existían
     * (`created_at <= asOf`), para reconstruir un snapshot histórico sin datos posteriores al cierre.
     *
     * @return Collection<int, AdminContPayment>
     */
    private function paymentsConfirmedOn(string $date, ?CarbonImmutable $asOf = null)
    {
        $immediateMethods = PaymentMethods::inmediatos();

        // SCRUM-1168 F4 — un cobro anulado SIGUE contando en el día en que el dinero entró (ese arqueo, abierto o cerrado, no se reescribe); su reverso
        // entra como salida del día de la anulación (ver `reversosOn()`).
        return AdminContPayment::whereIn('estado', [AdminContPayment::ESTADO_CONFIRMADO, AdminContPayment::ESTADO_ANULADO])
            ->with(['invoiceLines.invoice', 'retentionAttachment'])
            ->where(function ($query) use ($immediateMethods, $date): void {
                $query->where(function ($q) use ($immediateMethods, $date): void {
                    $q->whereIn('metodo_pago', $immediateMethods)->whereDate('created_at', $date);
                })->orWhere(function ($q) use ($immediateMethods, $date): void {
                    $q->whereNotIn('metodo_pago', $immediateMethods)
                        ->whereHas('confirmation', fn ($q2) => $q2->whereDate('confirmed_at', $date));
                });
            })
            ->when($asOf !== null, fn ($q) => $q->where('created_at', '<=', $asOf))
            ->get();
    }

    /**
     * RN1 — notas de crédito con devolución de dinero YA aplicadas el día en curso. El monto real
     * es el del `bank_movements` vinculado (`source_type=credit_note`) — ya calculado en
     * `CreditNoteService::applyToBalance()` como el excedente real, nunca se recalcula acá (ADR
     * reconciliación punto 3/6 de Batch 18). Una nota sin movimiento vinculado (excedente resultó 0
     * al aplicarse) no representa un evento de caja real y se omite.
     *
     * `$asOf` — ver docblock de `forDate()`, filtra el `bank_movement` vinculado por su propio
     * `created_at` (la nota de crédito en sí no tiene noción de "cuándo se creó el movimiento").
     *
     * @return list<array{0: AdminContCreditNote, 1: float}>
     */
    private function creditNotesReturnedOn(string $date, ?CarbonImmutable $asOf = null): array
    {
        $notes = AdminContCreditNote::where('resultado', AdminContCreditNote::RESULTADO_DEVUELTO)
            ->where('estado', AdminContCreditNote::ESTADO_APLICADA)
            ->get()
            ->filter(function (AdminContCreditNote $note) use ($date): bool {
                $processedDate = $note->fecha_decision ?? $note->fecha;

                return $processedDate->toDateString() === $date;
            });

        if ($notes->isEmpty()) {
            return [];
        }

        $movements = AdminContBankMovement::where('source_type', AdminContBankMovement::SOURCE_CREDIT_NOTE)
            ->whereIn('source_id', $notes->pluck('id'))
            ->when($asOf !== null, fn ($q) => $q->where('created_at', '<=', $asOf))
            ->get()
            ->keyBy('source_id');

        $result = [];
        foreach ($notes as $note) {
            $movement = $movements->get($note->id);
            if ($movement === null) {
                continue;
            }

            $result[] = [$note, $movement->monto];
        }

        return $result;
    }

    /**
     * SCRUM-1168 F4/F5 — salidas de caja del día por anular un cobro o devolver dinero al cliente (`bank_movements` con esas dos fuentes). La fecha del
     * movimiento es la del día en que ocurre, así el reverso de un cobro de otro día entra al arqueo de HOY y el arqueo cerrado de aquel día no se toca.
     * `$asOf` — ver docblock de `forDate()`.
     *
     * @return Collection<int, AdminContBankMovement>
     */
    private function reversosOn(string $date, ?CarbonImmutable $asOf = null)
    {
        return AdminContBankMovement::whereIn('source_type', [AdminContBankMovement::SOURCE_PAYMENT_REVERSAL, AdminContBankMovement::SOURCE_CLIENT_REFUND])
            ->whereDate('fecha', $date)
            ->when($asOf !== null, fn ($q) => $q->where('created_at', '<=', $asOf))
            ->orderBy('id')
            ->get();
    }

    /**
     * SCRUM-879 — comisiones (externas o internas) pagadas exactamente el día en curso. `fecha`
     * en `bank_movements` es la fecha real del pago (externa: al marcar Pagada; interna: el 1° del
     * mes siguiente al cierre, ver `commissions:settle-internal`) — no hay noción de "confirmación
     * diferida" acá como en cobros, el movimiento ya nace en la fecha correcta.
     *
     * `$asOf` — ver docblock de `forDate()`. Esto es precisamente lo que el hallazgo CRÍTICO de
     * Pre-QA 2026-09-10 necesitaba: sin este filtro, un `commissions:backfill-cash-movements`
     * corrido DESPUÉS del cierre de un arqueo contamina su total para siempre en cada consulta.
     *
     * @return Collection<int, AdminContBankMovement>
     */
    private function commissionsPaidOn(string $date, ?CarbonImmutable $asOf = null)
    {
        return AdminContBankMovement::where('tipo', AdminContBankMovement::TIPO_COMISION)
            ->whereDate('fecha', $date)
            ->when($asOf !== null, fn ($q) => $q->where('created_at', '<=', $asOf))
            ->orderBy('id')
            ->get();
    }
}
