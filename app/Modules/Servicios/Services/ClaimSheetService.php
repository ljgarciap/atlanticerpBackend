<?php

declare(strict_types=1);

namespace App\Modules\Servicios\Services;

use App\Models\User;
use App\Modules\Auth\Support\RoleKeys;
use App\Modules\Servicios\Exceptions\ClaimSheetAccessDeniedException;
use App\Modules\Servicios\Exceptions\ClaimSheetLockedException;
use App\Modules\Servicios\Models\ClaimSheet;
use App\Modules\Servicios\Models\Ticket;
use Illuminate\Support\Facades\DB;

/**
 * Fase 4 — Servicios, Batch 9 (REQ-278/279, docs/architecture/servicios-fase4-diseno.md sección
 * 8) — Hoja de Reclamo. Mismo patrón general que `InspectionReportService` (folio-less upsert,
 * `assertCanEdit` por rol + ownership del ticket), con una divergencia RN5 a propósito: una vez
 * `estado = completed` queda en modo solo lectura TOTAL (Informe de Inspección, en cambio, sigue
 * editable después de Completado) — `save()` rechaza cualquier intento posterior con
 * `ClaimSheetLockedException`.
 */
final class ClaimSheetService
{
    /** REQ-278 UBICACION — solo tickets Reclamos generan Hoja de Reclamo (contraparte invertida
     *  de `InspectionReportService::isApplicable()`). */
    public function isApplicable(Ticket $ticket): bool
    {
        return $ticket->tipo === Ticket::TIPO_CLAIM;
    }

    /**
     * REQ-278 RN — solo Lider Servicios/Líder de Servicios (superadmin) o el técnico interno asignado a ESTE
     * ticket pueden generar/editar su Hoja de Reclamo. Sin rama `tecnico_garantias` a propósito
     * (excluida del rol de escritura en `routes/servicios.php`, ver docblock ahí) — Reclamos no es
     * un tipo de ticket que atienda el especialista de garantías.
     *
     * @throws ClaimSheetAccessDeniedException
     */
    public function assertCanEdit(Ticket $ticket, User $user): void
    {
        $roleKey = $user->role?->key;

        if ($roleKey === RoleKeys::SUPERADMIN || $roleKey === RoleKeys::LIDER_SERVICIOS) {
            return;
        }

        if ($roleKey === RoleKeys::TECNICO && $ticket->internal_technician_id === $user->id) {
            return;
        }

        throw new ClaimSheetAccessDeniedException();
    }

    public function findByTicket(Ticket $ticket): ?ClaimSheet
    {
        return ClaimSheet::where('ticket_id', $ticket->id)->first();
    }

    /**
     * Upsert — "Guardar Hoja de Reclamo" es una única acción, sin borrador intermedio (mismo
     * criterio que `InspectionReportService::save()`). RN4: diagnóstico y firma obligatorios para
     * completar (validado por `SaveClaimSheetRequest`, llegar acá ya garantiza un guardado
     * completo). RN5: si la hoja ya está `completed`, ningún guardado posterior es válido.
     *
     * @param array{descripcion_problema?: ?string, fecha_reclamo?: ?string, diagnostico: string, firma_responsable: string} $data
     * @throws ClaimSheetAccessDeniedException
     * @throws ClaimSheetLockedException
     */
    public function save(Ticket $ticket, array $data, User $user): ClaimSheet
    {
        $this->assertCanEdit($ticket, $user);

        return $this->withUniqueRetry(fn (): ClaimSheet => DB::transaction(function () use ($ticket, $data, $user): ClaimSheet {
            $sheet = ClaimSheet::where('ticket_id', $ticket->id)->lockForUpdate()->first();

            if ($sheet !== null && $sheet->estado === ClaimSheet::ESTADO_COMPLETED) {
                throw new ClaimSheetLockedException();
            }

            if ($sheet === null) {
                $sheet             = new ClaimSheet();
                $sheet->ticket_id  = $ticket->id;
                $sheet->created_by = $user->id;
            }

            $sheet->descripcion_problema = $this->blankToNull($data['descripcion_problema'] ?? null);
            $sheet->fecha_reclamo        = isset($data['fecha_reclamo']) ? \Carbon\Carbon::parse($data['fecha_reclamo']) : null;
            $sheet->diagnostico          = $data['diagnostico'];
            $sheet->firma_responsable    = $data['firma_responsable'];
            $sheet->estado               = ClaimSheet::ESTADO_COMPLETED;
            $sheet->save();

            return $sheet->refresh();
        }));
    }

    /**
     * Hallazgo Senior Review (2026-08-10) — mismo bug de condición de carrera que Pre-QA encontró
     * y corrigió un día antes en `InspectionReportService::withUniqueRetry()`: bajo READ
     * COMMITTED, `SELECT ... FOR UPDATE` sobre una fila que TODAVÍA no existe no bloquea nada, así
     * que dos PUT concurrentes sobre un ticket sin hoja todavía pueden ambos ver `$sheet === null`
     * y la segunda transacción en confirmar pierde la carrera del lado de Postgres
     * (`UniqueConstraintViolationException` sobre `servicios_claim_sheets_ticket_uq`) en vez de
     * terminar encontrando la fila que la primera ya creó. Un reintento alcanza: la segunda vez,
     * `beginUpsert` inline de `save()` encuentra la fila real — y como `save()` siempre completa en
     * un solo guardado (sin borrador intermedio), esa fila ya está `completed`, así que el
     * reintento perdedor termina en `ClaimSheetLockedException` (409) en vez de un 500 con stack
     * trace expuesto al cliente.
     */
    private function withUniqueRetry(callable $attempt): ClaimSheet
    {
        try {
            return $attempt();
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            if (! str_contains($e->getMessage(), 'servicios_claim_sheets_ticket_uq')) {
                throw $e;
            }

            return $attempt();
        }
    }

    private function blankToNull(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
