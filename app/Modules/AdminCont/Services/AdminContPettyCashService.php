<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Services;

use App\Models\User;
use App\Modules\AdminCont\Exceptions\PettyCashStateException;
use App\Modules\AdminCont\Models\AdminContFiscalSettings;
use App\Modules\AdminCont\Models\AdminContPettyCashExpense;
use App\Modules\AdminCont\Models\AdminContPettyCashExpenseAttachment;
use App\Modules\AdminCont\Models\AdminContPettyCashExpenseHistory;
use App\Modules\AdminCont\Models\AdminContPettyCashReport;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Batch 20 de Admin&Cont (SCRUM-612→617, REQ-535→540) — Caja Chica. Lógica de negocio de las 3
 * pestañas (Pendientes/Reportes/Rechazados) y del flujo de generar+aprobar reporte.
 * Batch 21 (SCRUM-618→623, REQ-541→546) agrega rechazo (línea/reporte, regla de 2 intentos),
 * reapertura, edición de línea, soporte adicional y el detalle unificado.
 */
final class AdminContPettyCashService
{
    public function __construct(private readonly AdminContPettyCashAttachmentService $attachments) {}

    /**
     * REQ-535 — RN4: todas las líneas válidas se registran a la vez, o ninguna. Si el upload de
     * una línea falla después de que otras del mismo lote ya subieron su foto a S3, se borran esas
     * keys ya subidas antes de relanzar (S3 no es transaccional, la fila de BD sí gracias al
     * `DB::transaction` de afuera).
     *
     * @param  list<array{fecha: string, solicitante_id: int, proveedor: string, descripcion: string, monto_bruto: float, itbms: float, foto: UploadedFile}>  $lineas
     * @return list<AdminContPettyCashExpense>
     */
    public function registerExpenses(array $lineas, int $createdBy): array
    {
        $uploadedKeys = [];

        try {
            return DB::transaction(function () use ($lineas, $createdBy, &$uploadedKeys): array {
                $created = [];

                foreach ($lineas as $linea) {
                    $expense = AdminContPettyCashExpense::create([
                        'fecha' => $linea['fecha'],
                        'solicitante_id' => $linea['solicitante_id'],
                        'proveedor' => $linea['proveedor'],
                        'descripcion' => $linea['descripcion'],
                        'monto_bruto' => $linea['monto_bruto'],
                        'itbms' => $linea['itbms'],
                        'created_by' => $createdBy,
                    ]);

                    $attachment = $this->attachments->upload($linea['foto'], $expense, $createdBy);
                    $uploadedKeys[] = $attachment->storage_key;

                    $created[] = $expense;
                }

                return $created;
            });
        } catch (\Throwable $e) {
            foreach ($uploadedKeys as $key) {
                $this->attachments->deleteStorage($key);
            }

            throw $e;
        }
    }

    /**
     * REQ-538 — RN2/RN5: bloquea los gastos seleccionados para evitar que 2 reportes concurrentes
     * incluyan el mismo gasto (misma familia de condición de carrera ya documentada en
     * `TicketAttachmentService`), asigna folio y los saca de "Pendientes".
     *
     * @param  list<int>  $expenseIds
     */
    public function generateReport(array $expenseIds, string $formaPago, int $actorId): AdminContPettyCashReport
    {
        return DB::transaction(function () use ($expenseIds, $formaPago, $actorId): AdminContPettyCashReport {
            $expenses = AdminContPettyCashExpense::whereIn('id', $expenseIds)
                ->whereNull('report_id')
                ->lockForUpdate()
                ->get();

            if ($expenses->count() !== count($expenseIds)) {
                throw new PettyCashStateException('Uno o más gastos ya no están disponibles — puede que otro reporte ya los haya incluido.');
            }

            $numero = (new AdminContPettyCashReportFolioService)->next();

            $report = AdminContPettyCashReport::create([
                'numero' => $numero,
                'forma_pago' => $formaPago,
                'estado' => AdminContPettyCashReport::ESTADO_PENDIENTE_APROBACION,
                'realizado_por' => $actorId,
            ]);

            AdminContPettyCashExpense::whereIn('id', $expenseIds)->update(['report_id' => $report->id]);

            return $report;
        });
    }

    /** REQ-540 RN3 — solo si sigue pendiente de aprobación. */
    public function approveReport(AdminContPettyCashReport $report, int $actorId): void
    {
        if ($report->estado !== AdminContPettyCashReport::ESTADO_PENDIENTE_APROBACION) {
            throw new PettyCashStateException('Este reporte ya fue aprobado — no puede volver a aprobarse.');
        }

        $report->update([
            'estado' => AdminContPettyCashReport::ESTADO_FINALIZADO,
            'aprobado_por' => $actorId,
            'fecha_aprobacion' => now(),
        ]);
    }

    private const UBICACION_PENDIENTES = 'pendientes';

    private const UBICACION_REPORTE = 'reporte';

    private const UBICACION_RECHAZADOS = 'rechazados';

    /** Memoizado por instancia (una por request) — evita 1 query al singleton de settings por
     *  cada línea mapeada en `pending()`/`rejected()`/`groupBySolicitante()`. Ver hallazgo #5 de
     *  Senior Review (Batch 21): la regla de "N intentos" nunca puede ser un literal en el código. */
    private ?int $maxIntentosRechazoCache = null;

    private function maxIntentosRechazo(): int
    {
        return $this->maxIntentosRechazoCache ??= AdminContFiscalSettings::current()->petty_cash_max_intentos_rechazo;
    }

    /**
     * REQ-541 RN2/RN4/RN5 — rechazo de una línea puntual dentro de un reporte pendiente de
     * aprobación. Comparte `applyRejection()` con `rejectReport()` — el destino de la línea
     * depende únicamente de su contador de intentos, nunca de si el rechazo fue individual o de
     * reporte completo.
     *
     * Senior Review hallazgo #1: el reporte se bloquea SIEMPRE primero, la línea después — mismo
     * orden que `rejectReport()` — para que un rechazo individual y uno de reporte completo
     * disparados casi al mismo tiempo sobre el mismo reporte nunca puedan deadlockear por
     * bloquear en orden inverso.
     *
     * @return array{destino: string, reporte_disuelto: bool}
     */
    public function rejectExpense(AdminContPettyCashExpense $expense, string $motivo, int $actorId): array
    {
        $reportId = $expense->report_id;
        if ($reportId === null) {
            throw new PettyCashStateException('Esta línea no forma parte de un reporte pendiente de aprobación.');
        }

        return DB::transaction(function () use ($expense, $reportId, $motivo, $actorId): array {
            $report = AdminContPettyCashReport::whereKey($reportId)->lockForUpdate()->first();
            if ($report === null || $report->estado !== AdminContPettyCashReport::ESTADO_PENDIENTE_APROBACION) {
                throw new PettyCashStateException('Este reporte ya no está pendiente de aprobación.');
            }

            $expense = AdminContPettyCashExpense::whereKey($expense->id)->lockForUpdate()->firstOrFail();
            if ($expense->report_id !== $report->id) {
                throw new PettyCashStateException('Esta línea ya no forma parte de este reporte.');
            }

            $destino = $this->applyRejection($expense, $motivo, $actorId);

            // RN4 — si esa línea era la última del reporte, el reporte se disuelve.
            $disuelto = false;
            if (! AdminContPettyCashExpense::where('report_id', $report->id)->exists()) {
                $report->update(['estado' => AdminContPettyCashReport::ESTADO_RECHAZADO]);
                $disuelto = true;
            }

            return ['destino' => $destino, 'reporte_disuelto' => $disuelto];
        });
    }

    /**
     * REQ-541 RN3 — rechaza TODAS las líneas de un reporte con un único motivo. El reporte
     * siempre se disuelve al final (nunca le queda ninguna línea).
     *
     * @return array{a_pendientes: int, a_rechazados: int}
     */
    public function rejectReport(AdminContPettyCashReport $report, string $motivo, int $actorId): array
    {
        return DB::transaction(function () use ($report, $motivo, $actorId): array {
            $report = AdminContPettyCashReport::whereKey($report->id)->lockForUpdate()->first();
            if ($report === null || $report->estado !== AdminContPettyCashReport::ESTADO_PENDIENTE_APROBACION) {
                throw new PettyCashStateException('Este reporte ya no está pendiente de aprobación.');
            }

            $expenses = AdminContPettyCashExpense::where('report_id', $report->id)->lockForUpdate()->get();

            $aPendientes = 0;
            $aRechazados = 0;
            foreach ($expenses as $expense) {
                if ($this->applyRejection($expense, $motivo, $actorId) === 'permanente') {
                    $aRechazados++;
                } else {
                    $aPendientes++;
                }
            }

            $report->update(['estado' => AdminContPettyCashReport::ESTADO_RECHAZADO]);

            return ['a_pendientes' => $aPendientes, 'a_rechazados' => $aRechazados];
        });
    }

    /** REQ-544 — reabre una línea rechazada permanentemente; su contador vuelve a 0 y el
     *  historial de rechazos anteriores se conserva intacto (RN3). */
    public function reopenExpense(AdminContPettyCashExpense $expense, string $motivo, int $actorId): void
    {
        DB::transaction(function () use ($expense, $motivo, $actorId): void {
            $expense = AdminContPettyCashExpense::whereKey($expense->id)->lockForUpdate()->firstOrFail();

            if ($expense->report_id !== null || $expense->intentos_rechazo < $this->maxIntentosRechazo()) {
                throw new PettyCashStateException('Esta línea no está en "Rechazados" — no puede reabrirse.');
            }

            AdminContPettyCashExpenseHistory::create([
                'expense_id' => $expense->id,
                'accion' => AdminContPettyCashExpenseHistory::ACCION_REAPERTURA,
                'motivo' => $motivo,
                'actor_id' => $actorId,
            ]);

            $expense->update(['intentos_rechazo' => 0]);
        });
    }

    /**
     * REQ-545 RN1/RN4 — editar línea; nunca permitido mientras forma parte de un reporte, nunca
     * toca `solicitante_id`. Senior Review hallazgo #4: bloquea+re-chequea dentro de una
     * transacción (antes solo leía `locationOf()` sin lock, ventana TOCTOU si un rechazo/reabrir
     * concurrente cambiaba `report_id`/`intentos_rechazo` entre el chequeo y el `update()`).
     *
     * @param array{fecha: string, proveedor: string, descripcion: string, monto_bruto: float, itbms: float} $data
     */
    public function updateExpense(AdminContPettyCashExpense $expense, array $data): void
    {
        DB::transaction(function () use ($expense, $data): void {
            $expense = AdminContPettyCashExpense::whereKey($expense->id)->lockForUpdate()->firstOrFail();

            if ($this->locationOf($expense) === self::UBICACION_REPORTE) {
                throw new PettyCashStateException('Esta línea forma parte de un reporte — no puede editarse.');
            }

            $expense->update([
                'fecha' => $data['fecha'],
                'proveedor' => $data['proveedor'],
                'descripcion' => $data['descripcion'],
                'monto_bruto' => $data['monto_bruto'],
                'itbms' => $data['itbms'],
            ]);
        });
    }

    /**
     * REQ-545 RN2 — soporte adicional; permitido en Pendientes/Rechazados o dentro de un reporte
     * todavía pendiente de aprobación, nunca en uno ya finalizado/rechazado. Senior Review
     * hallazgo #4: mismo fix TOCTOU que `updateExpense()` — bloquea el expense (y su reporte, si
     * tiene uno) antes de re-chequear `canAddAttachment()`. El upload a S3 en sí queda fuera de la
     * transacción de BD a propósito (mismo criterio que `registerExpenses()` — S3 no es
     * transaccional), pero el chequeo de estado sí corre bajo lock.
     */
    public function addExpenseAttachment(AdminContPettyCashExpense $expense, UploadedFile $file, int $uploadedBy): AdminContPettyCashExpenseAttachment
    {
        // Lectura sin lock, solo para saber SI hay que bloquear un reporte — mismo patrón que
        // rejectExpense(): el orden reporte-antes-que-línea se decide con este valor, y el estado
        // real se re-deriva después de tomar ambos locks (nunca se confía en este valor stale).
        $reportId = $expense->report_id;

        $locked = DB::transaction(function () use ($expense, $reportId): AdminContPettyCashExpense {
            if ($reportId !== null) {
                AdminContPettyCashReport::whereKey($reportId)->lockForUpdate()->first();
            }

            $expense = AdminContPettyCashExpense::whereKey($expense->id)->lockForUpdate()->firstOrFail();

            if (! $this->canAddAttachment($expense)) {
                throw new PettyCashStateException('No se puede agregar un soporte a esta línea en su estado actual.');
            }

            return $expense;
        });

        return $this->attachments->upload($file, $locked, $uploadedBy);
    }

    /**
     * REQ-545 — detalle unificado de línea (Pendientes/dentro de un Reporte/Rechazados). No
     * incluye `puede_rechazar`/`puede_reabrir` — el controller los agrega según el actor, mismo
     * criterio que `puede_aprobar` en `reportDetail()`.
     *
     * @return array<string, mixed>|null
     */
    public function expenseDetail(int $id): ?array
    {
        $expense = AdminContPettyCashExpense::with(['solicitante', 'attachments', 'history.actor', 'report'])
            ->find($id);

        if ($expense === null) {
            return null;
        }

        $ubicacion = $this->locationOf($expense);

        return [
            'id' => $expense->id,
            'fecha' => $expense->fecha->toDateString(),
            'solicitante_id' => $expense->solicitante_id,
            'solicitante_nombre' => $this->userName($expense->solicitante),
            'proveedor' => $expense->proveedor,
            'descripcion' => $expense->descripcion,
            'monto_bruto' => $expense->monto_bruto,
            'itbms' => $expense->itbms,
            'total' => $expense->total,
            'estado' => $expense->estado($this->maxIntentosRechazo()),
            'intentos_rechazo' => $expense->intentos_rechazo,
            'ubicacion' => $ubicacion,
            'reporte_numero' => $expense->report?->numero,
            'reporte_estado' => $expense->report?->estado,
            'editable' => $ubicacion !== self::UBICACION_REPORTE,
            'puede_agregar_soporte' => $this->canAddAttachment($expense),
            'attachments' => $expense->attachments->map(fn (AdminContPettyCashExpenseAttachment $a) => $this->attachmentPayload($a))->values()->all(),
            'historial' => $expense->history->map(fn (AdminContPettyCashExpenseHistory $h) => [
                'accion' => $h->accion,
                'motivo' => $h->motivo,
                'fecha' => $h->created_at->toIso8601String(),
                'actor_nombre' => $this->userName($h->actor),
            ])->values()->all(),
        ];
    }

    private function locationOf(AdminContPettyCashExpense $expense): string
    {
        if ($expense->report_id !== null) {
            return self::UBICACION_REPORTE;
        }

        return $expense->intentos_rechazo >= $this->maxIntentosRechazo() ? self::UBICACION_RECHAZADOS : self::UBICACION_PENDIENTES;
    }

    private function canAddAttachment(AdminContPettyCashExpense $expense): bool
    {
        if ($this->locationOf($expense) !== self::UBICACION_REPORTE) {
            return true;
        }

        return $expense->report?->estado === AdminContPettyCashReport::ESTADO_PENDIENTE_APROBACION;
    }

    /** Helper compartido por `rejectExpense()`/`rejectReport()` — registra el intento en el
     *  historial, incrementa el contador y libera la línea del reporte. Retorna el destino
     *  ('pendientes'|'permanente') según la regla de N intentos (REQ-542,
     *  `AdminContFiscalSettings::petty_cash_max_intentos_rechazo`). */
    private function applyRejection(AdminContPettyCashExpense $expense, string $motivo, int $actorId): string
    {
        AdminContPettyCashExpenseHistory::create([
            'expense_id' => $expense->id,
            'accion' => AdminContPettyCashExpenseHistory::ACCION_RECHAZO,
            'motivo' => $motivo,
            'actor_id' => $actorId,
        ]);

        $expense->update([
            'intentos_rechazo' => $expense->intentos_rechazo + 1,
            'report_id' => null,
        ]);

        return $expense->intentos_rechazo >= $this->maxIntentosRechazo() ? 'permanente' : 'pendientes';
    }

    /** @return array{pendientes_count: int, reportes_count: int, reportes_sin_aprobar_count: int, rechazados_count: int, pendientes_total: float} */
    public function summary(): array
    {
        $max = $this->maxIntentosRechazo();

        $pendientes = AdminContPettyCashExpense::whereNull('report_id')
            ->where('intentos_rechazo', '<', $max)
            ->get();

        return [
            'pendientes_count' => $pendientes->count(),
            'pendientes_total' => round((float) $pendientes->sum(fn (AdminContPettyCashExpense $e) => $e->total), 2),
            'reportes_count' => AdminContPettyCashReport::count(),
            'reportes_sin_aprobar_count' => AdminContPettyCashReport::where('estado', AdminContPettyCashReport::ESTADO_PENDIENTE_APROBACION)->count(),
            'rechazados_count' => AdminContPettyCashExpense::where('intentos_rechazo', '>=', $max)->count(),
        ];
    }

    /** @return array{grupos: list<array<string, mixed>>, total_general: float} */
    public function pending(): array
    {
        $expenses = AdminContPettyCashExpense::whereNull('report_id')
            ->where('intentos_rechazo', '<', $this->maxIntentosRechazo())
            ->with(['solicitante', 'attachments', 'history'])
            ->get();

        return $this->groupBySolicitante($expenses);
    }

    /** REQ-536 — pestaña "Rechazados": líneas que llegaron al umbral de intentos, fuera del flujo
     *  normal.
     *  @return array{lineas: list<array<string, mixed>>} */
    public function rejected(): array
    {
        // SCRUM-621 rebote — mismo criterio que locationOf() (report_id NULL + umbral), para que
        // una línea nunca aparezca en "Rechazados" mientras sigue asociada a un reporte.
        $expenses = AdminContPettyCashExpense::whereNull('report_id')
            ->where('intentos_rechazo', '>=', $this->maxIntentosRechazo())
            ->with(['solicitante', 'attachments', 'history'])
            ->get();

        return ['lineas' => array_values($expenses->map(fn (AdminContPettyCashExpense $e) => $this->expensePayload($e))->all())];
    }

    /** @return list<array<string, mixed>> */
    public function reports(): array
    {
        $reports = AdminContPettyCashReport::with(['expenses', 'realizadoPor'])
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (AdminContPettyCashReport $r) => [
                'numero' => $r->numero,
                'fecha_creacion' => $r->created_at->toDateString(),
                'total' => round((float) $r->expenses->sum(fn (AdminContPettyCashExpense $e) => $e->total), 2),
                'estado' => $r->estado,
                'forma_pago' => $r->forma_pago,
                'realizado_por_nombre' => $this->userName($r->realizadoPor),
            ])
            ->all();

        return array_values($reports);
    }

    /** @return array<string, mixed>|null */
    public function reportDetail(string $numero): ?array
    {
        $report = AdminContPettyCashReport::with(['expenses.solicitante', 'expenses.attachments', 'expenses.history', 'realizadoPor', 'aprobadoPor'])
            ->where('numero', $numero)
            ->first();

        if ($report === null) {
            return null;
        }

        $grupo = $this->groupBySolicitante($report->expenses);

        return [
            'numero' => $report->numero,
            'estado' => $report->estado,
            'forma_pago' => $report->forma_pago,
            'fecha_creacion' => $report->created_at->toDateString(),
            'realizado_por_nombre' => $this->userName($report->realizadoPor),
            'aprobado_por_nombre' => $this->userName($report->aprobadoPor),
            'fecha_aprobacion' => $report->fecha_aprobacion?->toIso8601String(),
            'grupos' => $grupo['grupos'],
            'total_general' => $grupo['total_general'],
        ];
    }

    private function userName(?User $user): ?string
    {
        return $user !== null ? trim("{$user->first_name} {$user->last_name}") : null;
    }

    /** @param Collection<int, AdminContPettyCashExpense> $expenses
     *  @return array{grupos: list<array<string, mixed>>, total_general: float} */
    private function groupBySolicitante($expenses): array
    {
        $porSolicitante = $expenses->groupBy(fn (AdminContPettyCashExpense $e) => $this->userName($e->solicitante) ?: 'Sin solicitante')
            ->sortKeys();

        $grupos = [];
        foreach ($porSolicitante as $nombre => $lineas) {
            $lineasSorted = $lineas->values();

            $grupos[] = [
                'solicitante_id' => $lineasSorted->first()?->solicitante_id,
                'solicitante_nombre' => (string) $nombre,
                'subtotal' => round((float) $lineasSorted->sum(fn (AdminContPettyCashExpense $e) => $e->total), 2),
                'lineas' => array_values($lineasSorted->map(fn (AdminContPettyCashExpense $e) => $this->expensePayload($e))->all()),
            ];
        }

        $totalGeneral = round((float) $expenses->sum(fn (AdminContPettyCashExpense $e) => $e->total), 2);

        return ['grupos' => $grupos, 'total_general' => $totalGeneral];
    }

    /** @return array<string, mixed> */
    private function expensePayload(AdminContPettyCashExpense $e): array
    {
        return [
            'id' => $e->id,
            'fecha' => $e->fecha->toDateString(),
            'proveedor' => $e->proveedor,
            'descripcion' => $e->descripcion,
            'monto_bruto' => $e->monto_bruto,
            'itbms' => $e->itbms,
            'total' => $e->total,
            'estado' => $e->estado($this->maxIntentosRechazo()),
            'intentos_rechazo' => $e->intentos_rechazo,
            'attachments' => $e->attachments->map(fn (AdminContPettyCashExpenseAttachment $a) => $this->attachmentPayload($a))->values()->all(),
        ];
    }

    /**
     * Compartido por expenseDetail() y expensePayload() — mime_type viaja en los dos, no solo en
     * el detalle unificado, para que el tipo TS del frontend (`PettyCashAttachment`) no mienta
     * sobre el shape real de pending()/rejected()/reportDetail() (mismo criterio ya documentado
     * tras el bug de setQueryData de Batch 20: nunca un tipo más angosto que la respuesta real).
     *
     * @return array{id: int, nombre_archivo: string, mime_type: string}
     */
    private function attachmentPayload(AdminContPettyCashExpenseAttachment $a): array
    {
        return [
            'id' => $a->id,
            'nombre_archivo' => $a->nombre_archivo,
            'mime_type' => $a->mime_type,
        ];
    }
}
