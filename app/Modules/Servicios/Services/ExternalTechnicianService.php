<?php

declare(strict_types=1);

namespace App\Modules\Servicios\Services;

use App\Models\User;
use App\Modules\Servicios\Exceptions\ExternalTechnicianDeactivationRequiresConfirmationException;
use App\Modules\Servicios\Models\ExternalTechnician;
use App\Modules\Servicios\Models\ServiceQuote;
use App\Modules\Servicios\Models\ServiceQuoteItem;
use App\Modules\Servicios\Models\Ticket;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Fase 4 — Servicios, Batch 5 (REQ-263→267). Ver docs/architecture/servicios-fase4-diseno.md.
 */
final class ExternalTechnicianService
{
    /**
     * REQ-263 — listado con búsqueda por nombre + filtro por estado.
     *
     * @param array{search?: string, estado?: string, per_page?: int} $filters
     * @return LengthAwarePaginator<int, ExternalTechnician>
     */
    public function list(array $filters = []): LengthAwarePaginator
    {
        $query = ExternalTechnician::query()->orderBy('nombre');

        $search = $filters['search'] ?? null;
        if (is_string($search) && trim($search) !== '') {
            $query->where('nombre', 'ilike', '%' . trim($search) . '%');
        }

        $estado = $filters['estado'] ?? null;
        if (is_string($estado) && $estado !== '') {
            $query->where('estado', $estado);
        }

        $perPage = isset($filters['per_page']) ? max(1, (int) $filters['per_page']) : 20;

        return $query->paginate($perPage);
    }

    /** @return array{active: int, inactive: int, total: int} — conteos para los chips (REQ-263), sin aplicar filtros. */
    public function counts(): array
    {
        $active   = (int) ExternalTechnician::query()->where('estado', ExternalTechnician::ESTADO_ACTIVE)->count();
        $inactive = (int) ExternalTechnician::query()->where('estado', ExternalTechnician::ESTADO_INACTIVE)->count();

        return ['active' => $active, 'inactive' => $inactive, 'total' => $active + $inactive];
    }

    /**
     * REQ-264 — alta. RN2: siempre inicia Activo y con tarifa visible. RN3: la tarifa inicial
     * queda como primer punto del historial.
     *
     * @param array{nombre: string, empresa: string, especialidad?: ?string, telefono?: ?string, email?: ?string, tarifa_dia: float|string} $data
     */
    public function create(array $data, User $user): ExternalTechnician
    {
        return DB::transaction(function () use ($data, $user): ExternalTechnician {
            $technician = ExternalTechnician::create([
                'nombre'         => $data['nombre'],
                'empresa'        => $data['empresa'],
                'especialidad'   => $data['especialidad'] ?? null,
                'telefono'       => $data['telefono'] ?? null,
                'email'          => $data['email'] ?? null,
                'tarifa_dia'     => $data['tarifa_dia'],
                'tarifa_visible' => true,
                'estado'         => ExternalTechnician::ESTADO_ACTIVE,
            ]);

            $technician->rateHistory()->create([
                'tarifa_dia' => $data['tarifa_dia'],
                'changed_by' => $user->id,
            ]);

            return $technician;
        });
    }

    /**
     * REQ-266 — edición de tarifa (RN2: registra en el historial) y/o del interruptor
     * mostrar/ocultar (RN3: puramente visual, no genera entrada de historial).
     *
     * @param array{tarifa_dia?: float|string|null, tarifa_visible?: bool|null} $data
     */
    public function updateTarifa(ExternalTechnician $technician, array $data, User $user): ExternalTechnician
    {
        return DB::transaction(function () use ($technician, $data, $user): ExternalTechnician {
            if (array_key_exists('tarifa_dia', $data) && $data['tarifa_dia'] !== null) {
                $technician->tarifa_dia = (string) $data['tarifa_dia'];
                $technician->rateHistory()->create([
                    'tarifa_dia' => $data['tarifa_dia'],
                    'changed_by' => $user->id,
                ]);
            }

            if (array_key_exists('tarifa_visible', $data) && $data['tarifa_visible'] !== null) {
                $technician->tarifa_visible = $data['tarifa_visible'];
            }

            $technician->save();

            return $technician->refresh();
        });
    }

    /**
     * REQ-265 — activar/inactivar. RN2: inactivar con proyectos activos exige confirmación
     * explícita (`$confirm`), salvo que no tenga ninguno (RN3). RN4: reactivar nunca exige
     * confirmación.
     *
     * @throws ExternalTechnicianDeactivationRequiresConfirmationException
     */
    public function updateEstado(ExternalTechnician $technician, string $estado, bool $confirm): ExternalTechnician
    {
        if ($estado === ExternalTechnician::ESTADO_INACTIVE && ! $confirm) {
            $activeCount = $this->activeProjectsCount($technician);
            if ($activeCount > 0) {
                throw new ExternalTechnicianDeactivationRequiresConfirmationException($activeCount);
            }
        }

        $technician->estado = $estado;
        $technician->save();

        return $technician;
    }

    /**
     * REQ-267 RN1 — cantidad de asignaciones cuya cotización asociada no está "Rechazada" y cuyo
     * ticket no está Cerrado/Cancelado. Fuente real: `ServiceQuoteItem` tipo=subcontracted (Batch
     * 11, REQ-232) — antes de Batch 11 esto era un stub fijo en 0 (ver historial de git).
     *
     * ⚠️ El listado (`ExternalTechnicianController::index()`) llama esto una vez por fila — sigue
     * siendo O(1) query por técnico, no N+1 sobre `ServiceQuoteItem` en sí (cada llamada es un
     * `count()` filtrado), aceptable para el volumen esperado del roster de técnicos externos; si
     * el listado creciera mucho, resolver con un conteo agregado (whereIn + groupBy) antes de
     * mapear las filas, no dentro del loop.
     */
    public function activeProjectsCount(ExternalTechnician $technician): int
    {
        return $this->activeAssignmentsQuery($technician)->count();
    }

    /**
     * REQ-267 — ficha: proyectos/tickets asignados (activos e inactivos, sin filtrar por estado —
     * a diferencia de `activeProjectsCount()`, que sí filtra). RN3: mensaje de estado vacío lo
     * resuelve el frontend a partir de un array vacío.
     *
     * SCRUM-337 (rebote QA 2026-08-13, CRÍTICO) — el propio título del REQ-267 es "...y desglose
     * de costo/margen"; el docblock viejo de este método decía a propósito "sin costo/margen acá,
     * vive en el detalle de la Cotización" — decisión tomada en el Batch 5 (época de stub vacío,
     * antes de que Batch 11 conectara datos reales), nunca reconciliada contra el texto real del
     * ticket. `$canSeeCosts` (mismo criterio que `puede_ver_costos`/`tarifa_dia` en
     * `ExternalTechnicianController::show()`) gatea costo_dia/margen_percent/precio_final igual
     * que `ServiceQuoteController::formatItem()` gatea `cost_reference`/`margin_percent` — mismo
     * patrón, ni una fuente nueva de la verdad ni un cálculo paralelo.
     *
     * @return list<array<string, mixed>>
     */
    public function assignedProjects(ExternalTechnician $technician, bool $canSeeCosts): array
    {
        $items = ServiceQuoteItem::where('external_technician_id', $technician->id)
            ->where('tipo', ServiceQuoteItem::TIPO_SUBCONTRACTED)
            ->with(['serviceQuote.ticket'])
            ->get();

        $rows = [];
        foreach ($items as $item) {
            $ticket = $item->serviceQuote->ticket;

            $row = [
                'ticket_id'      => $ticket->id,
                'ticket_numero'  => $ticket->numero,
                'cliente'        => $ticket->cliente,
                'ticket_estado'  => $ticket->estado,
                'quote_numero'   => $item->serviceQuote->numero,
                'quote_estado'   => $item->serviceQuote->estado,
                'dias_cotizados' => $item->quantity,
                'precio_final'   => $item->subtotal,
            ];

            if ($canSeeCosts) {
                $row['costo_dia']      = $item->cost_reference;
                $row['margen_percent'] = $item->margin_percent;
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /** @return \Illuminate\Database\Eloquent\Builder<ServiceQuoteItem> */
    private function activeAssignmentsQuery(ExternalTechnician $technician)
    {
        return ServiceQuoteItem::where('external_technician_id', $technician->id)
            ->where('tipo', ServiceQuoteItem::TIPO_SUBCONTRACTED)
            ->whereHas('serviceQuote', function ($query): void {
                $query->where('estado', '!=', ServiceQuote::ESTADO_REJECTED)
                    ->whereHas('ticket', function ($ticketQuery): void {
                        $ticketQuery->whereNotIn('estado', [Ticket::ESTADO_CLOSED, Ticket::ESTADO_CANCELLED]);
                    });
            });
    }
}
