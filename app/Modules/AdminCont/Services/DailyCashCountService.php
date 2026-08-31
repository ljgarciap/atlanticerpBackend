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
use App\Modules\AdminCont\Models\AdminContPayment;
use App\Modules\VentasDiseno\Models\MasterClient;
use Carbon\CarbonImmutable;
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
     * @return array{fecha: string, cobros: list<array<string, mixed>>, notas_credito: list<array<string, mixed>>,
     *     retenciones: list<array<string, mixed>>, total_cobrado: float, total_notas_credito: float,
     *     total_neto: float, observacion_general: string|null}
     */
    public function forDate(string $date): array
    {
        $entriesByKey = $this->entriesForDate($date);

        $cobros = [];
        $retenciones = [];
        $totalCobrado = 0.0;
        foreach ($this->paymentsConfirmedOn($date) as $payment) {
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
                $retenciones[] = $this->presentRetencion($payment);
            }
        }

        $notasCredito = [];
        $totalNotasCredito = 0.0;
        foreach ($this->creditNotesReturnedOn($date) as [$note, $monto]) {
            $totalNotasCredito += $monto;
            $notasCredito[] = [
                'movement_type' => AdminContDailyCashCountEntry::TYPE_CREDIT_NOTE,
                'movement_id' => $note->id,
                'concepto' => 'Devolución de dinero — '.$note->cliente,
                'monto' => $monto,
                'observacion' => $entriesByKey[AdminContDailyCashCountEntry::TYPE_CREDIT_NOTE.':'.$note->id] ?? null,
            ];
        }

        $dailyCount = AdminContDailyCashCount::where('fecha', $date)->first();

        return [
            'fecha' => $date,
            'cobros' => $cobros,
            'notas_credito' => $notasCredito,
            'retenciones' => $retenciones,
            'total_cobrado' => round($totalCobrado, 2),
            'total_notas_credito' => round($totalNotasCredito, 2),
            'total_neto' => round($totalCobrado - $totalNotasCredito, 2),
            'observacion_general' => $dailyCount?->observacion_general,
        ];
    }

    /**
     * REQ-527 — el "arqueo activo" es siempre el más antiguo sin cerrar (`estado=abierto`, o sin
     * fila todavía, que es lo mismo). Piso: `arqueo_tracking_start_date` (editable por Mark) o, si
     * nunca se fijó, la fecha más vieja que ya tenga fila, o hoy si la tabla está vacía — ver ADR,
     * reconciliación punto 4. Rango acotado (semanas desde el go-live de Batch 19), escaneo día a
     * día sin problema de performance a este volumen.
     */
    public function activeDate(): string
    {
        $floor = AdminContFiscalSettings::current()->arqueo_tracking_start_date?->toDateString()
            ?? AdminContDailyCashCount::min('fecha')
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
            $numero = (int) (AdminContDailyCashCount::whereNotNull('numero')->max('numero') ?? 0) + 1;

            $record->update([
                'numero' => $numero,
                'estado' => AdminContDailyCashCount::ESTADO_PENDIENTE_APROBACION,
                'cerrado_por' => $actorId,
                'cerrado_at' => now(),
            ]);

            return $this->present($record->fresh(), $date);
        });
    }

    /**
     * REQ-528 — historial de arqueos cerrados (cualquier estado != abierto), más recientes primero,
     * con el contador de pendientes de aprobación (RN3).
     *
     * @return array{data: list<array<string, mixed>>, meta: array{current_page: int, last_page: int}, pendientes_aprobacion: int}
     */
    public function history(int $page): array
    {
        $perPage = 20;

        $query = AdminContDailyCashCount::where('estado', '!=', AdminContDailyCashCount::ESTADO_ABIERTO)
            ->orderByDesc('fecha');

        $total = (clone $query)->count();
        $records = $query->forPage(max(1, $page), $perPage)->get();

        $userIds = $records->pluck('cerrado_por')->merge($records->pluck('aprobado_por'))->filter()->unique();
        /** @var Collection<int, string> $userNames */
        $userNames = User::query()->whereIn('id', $userIds)->get(['id', 'first_name', 'last_name'])
            ->mapWithKeys(fn (User $u) => [$u->id => trim("{$u->first_name} {$u->last_name}")]);

        $data = $records->map(function (AdminContDailyCashCount $r) use ($userNames): array {
            $totals = $this->forDate($r->fecha->toDateString());

            return [
                'id' => $r->id,
                'numero' => $r->numero,
                'fecha' => $r->fecha->toDateString(),
                'total_neto' => $totals['total_neto'],
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
            'meta' => ['current_page' => max(1, $page), 'last_page' => max(1, (int) ceil($total / $perPage))],
            'pendientes_aprobacion' => $pendientes,
        ];
    }

    /**
     * REQ-528 RN2/RN4 — exclusivo de Mark (gate real: middleware `primary_approver_only`, ver routes/admin-contab.php).
     *
     * @return array<string, mixed>
     *
     * @throws DailyCashCountStateException si el arqueo ya no está `pendiente_aprobacion`.
     */
    public function approve(int $id, int $markUserId): array
    {
        return DB::transaction(function () use ($id, $markUserId): array {
            /** @var AdminContDailyCashCount $record */
            $record = AdminContDailyCashCount::query()->lockForUpdate()->findOrFail($id);

            if ($record->estado !== AdminContDailyCashCount::ESTADO_PENDIENTE_APROBACION) {
                throw new DailyCashCountStateException('Este arqueo ya no está pendiente de aprobación.');
            }

            $record->update([
                'estado' => AdminContDailyCashCount::ESTADO_APROBADO,
                'aprobado_por' => $markUserId,
                'aprobado_at' => now(),
            ]);

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
            ...$this->forDate($date),
            'cerrado_por' => $record?->cerrado_por !== null ? $this->userName($record->cerrado_por) : null,
            'cerrado_at' => $record?->cerrado_at?->toIso8601String(),
            'aprobado_por' => $record?->aprobado_por !== null ? $this->userName($record->aprobado_por) : null,
            'aprobado_at' => $record?->aprobado_at?->toIso8601String(),
        ];
    }

    private function userName(int $userId): ?string
    {
        $user = User::find($userId);

        return $user !== null ? trim("{$user->first_name} {$user->last_name}") : null;
    }

    /** @return array<string, mixed> */
    private function presentRetencion(AdminContPayment $payment): array
    {
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
     * `presentRetencion()`.
     *
     * @return Collection<int, AdminContPayment>
     */
    private function paymentsConfirmedOn(string $date)
    {
        $immediateMethods = AdminContPayment::METODOS_CONFIRMACION_INMEDIATA;

        return AdminContPayment::where('estado', AdminContPayment::ESTADO_CONFIRMADO)
            ->with(['invoiceLines.invoice', 'retentionAttachment'])
            ->where(function ($query) use ($immediateMethods, $date): void {
                $query->where(function ($q) use ($immediateMethods, $date): void {
                    $q->whereIn('metodo_pago', $immediateMethods)->whereDate('created_at', $date);
                })->orWhere(function ($q) use ($immediateMethods, $date): void {
                    $q->whereNotIn('metodo_pago', $immediateMethods)
                        ->whereHas('confirmation', fn ($q2) => $q2->whereDate('confirmed_at', $date));
                });
            })
            ->get();
    }

    /**
     * RN1 — notas de crédito con devolución de dinero YA aplicadas el día en curso. El monto real
     * es el del `bank_movements` vinculado (`source_type=credit_note`) — ya calculado en
     * `CreditNoteService::applyToBalance()` como el excedente real, nunca se recalcula acá (ADR
     * reconciliación punto 3/6 de Batch 18). Una nota sin movimiento vinculado (excedente resultó 0
     * al aplicarse) no representa un evento de caja real y se omite.
     *
     * @return list<array{0: AdminContCreditNote, 1: float}>
     */
    private function creditNotesReturnedOn(string $date): array
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
}
