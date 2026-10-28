<?php

declare(strict_types=1);

namespace App\Modules\Servicios\Services;

use App\Models\User;
use App\Modules\Servicios\Events\TicketCreated;
use App\Modules\Servicios\Events\TicketStageChanged;
use App\Modules\Servicios\Exceptions\TicketCloseBlockedException;
use App\Modules\Servicios\Models\ClaimSheet;
use App\Modules\Servicios\Models\ServiceQuote;
use App\Modules\Servicios\Models\Ticket;
use App\Modules\Servicios\Models\TicketAttachment;
use App\Modules\Servicios\Models\TicketProduct;
use App\Modules\Servicios\Models\TicketRescheduleHistory;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Modules\VentasDiseno\Models\SalesProject;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Fase 4 — Servicios, Batch 1 (REQ-216→221, REQ-249). Núcleo de Tickets: listado con filtros
 * (tabla + tablero), numeración/derivación al crear, y el gate de cierre (REQ-218).
 */
final class TicketService
{
    public function __construct(
        private readonly TicketFolioService $folio,
        private readonly ServiciosSettingsService $settings,
        private readonly TechnicianSpecialtyService $specialty,
    ) {}

    /**
     * REQ-216/REQ-217 — listado de tabla, paginado.
     *
     * @param array{search?: string, tipo?: string, tecnico_id?: int|string, estado?: string, per_page?: int} $filters
     * @return LengthAwarePaginator<int, Ticket>
     */
    public function list(array $filters = []): LengthAwarePaginator
    {
        $query = $this->filteredQuery($filters);

        $this->applyDefaultOrder($query);

        $perPage = isset($filters['per_page']) ? max(1, (int) $filters['per_page']) : 20;

        return $query->paginate($perPage);
    }

    /**
     * REQ-221 — tablero Kanban: 6 columnas fijas (reported|scheduled|on_site|resolved|closed|
     * cancelled), sin paginar (RN4/RN5 — el conteo por columna debe reflejar TODOS los tickets
     * que cumplen los filtros activos, no solo la página actual, mismo criterio que
     * `OrderBoardService::board()` en Bodega — no hay precedente de paginar un Kanban en AtlanticERP).
     *
     * @param array{search?: string, tipo?: string, tecnico_id?: int|string, estado?: string} $filters
     * @return array{cards: array<int, array<string, mixed>>, counts_by_estado: array<string, int>, total: int}
     */
    public function board(array $filters = []): array
    {
        $query = $this->filteredQuery($filters);
        $this->applyDefaultOrder($query);

        /** @var \Illuminate\Database\Eloquent\Collection<int, Ticket> $tickets */
        $tickets = $query->get();

        $counts = array_fill_keys(Ticket::ESTADOS, 0);
        foreach ($tickets as $ticket) {
            $counts[$ticket->estado] = ($counts[$ticket->estado] ?? 0) + 1;
        }

        $quoteAmounts = $this->latestQuoteAmountsByTicket(array_values($tickets->pluck('id')->all()));

        return [
            'cards'             => $tickets->map(fn (Ticket $t) => $this->cardPayload($t, $quoteAmounts[$t->id] ?? null))->values()->all(),
            'counts_by_estado'  => $counts,
            'total'             => $tickets->count(),
        ];
    }

    /**
     * REQ-222 — 4 tarjetas de estadísticas. RN5: reflejan el total del sistema, NUNCA se filtran
     * por los filtros activos de la tabla (a diferencia del conteo por columna del Tablero,
     * REQ-221 RN4) — por eso esta query no acepta `$filters`.
     *
     * @return array{tickets_abiertos: int, total_tickets: int, cotizaciones_por_generar: int, informes_por_generar: int, sin_agendar: int, sin_agendar_umbral_dias: int}
     */
    public function stats(): array
    {
        $totalTickets = Ticket::count();

        // RN1 — abiertos = todo lo que no está Cerrado ni Cancelado.
        $ticketsAbiertos = Ticket::whereNotIn('estado', [Ticket::ESTADO_CLOSED, Ticket::ESTADO_CANCELLED])->count();

        // RN2 — informe ya listo (Completado o No aplica) pero cotización todavía sin generarse.
        $cotizacionesPorGenerar = Ticket::whereIn('inspection_report_status', [
            Ticket::INSPECTION_STATUS_COMPLETED, Ticket::INSPECTION_STATUS_NOT_APPLICABLE,
        ])->where('quote_status', Ticket::QUOTE_STATUS_PENDING)->count();

        // RN3 — informe de inspección pendiente (Garantías/Retrofit/Instalación-inspección) MÁS
        // hoja de reclamo pendiente (Reclamos) — ver informesPendientesCount().
        $informesPorGenerar = $this->informesPendientesCount();

        // RN4 — sin fecha agendada, no cerrado/cancelado, reportado hace más del umbral
        // configurable (ver ServiciosSettingsService, compartido a futuro con REQ-210 de Inicio).
        $umbralDias = $this->settings->sinAgendarUmbralDias();
        $sinAgendar = Ticket::whereNull('scheduled_at')
            ->whereNotIn('estado', [Ticket::ESTADO_CLOSED, Ticket::ESTADO_CANCELLED])
            ->where('created_at', '<=', now()->subDays($umbralDias))
            ->count();

        return [
            'tickets_abiertos'          => $ticketsAbiertos,
            'total_tickets'             => $totalTickets,
            'cotizaciones_por_generar'  => $cotizacionesPorGenerar,
            'informes_por_generar'      => $informesPorGenerar,
            'sin_agendar'               => $sinAgendar,
            // El frontend lo usa para el subtítulo de la tarjeta ("hace más de N días") — nunca
            // hardcodear el "3" en la UI, ya que el valor es configurable (ServiciosSettingsService).
            'sin_agendar_umbral_dias'   => $umbralDias,
        ];
    }

    /**
     * REQ-280 RN4 (Reportes, SCRUM-350) / RN3 de `stats()` arriba — informes pendientes = Informe
     * de Inspección pendiente (`Ticket.inspection_report_status`) MÁS Hoja de Reclamo pendiente
     * (`ClaimSheet.estado`, Batch 9), sumados. Extraído a método propio para que Reportes reuse
     * exactamente este número — nunca un cálculo paralelo (el gap real que corrige esto: antes
     * `stats()` solo contaba Informe de Inspección; los tickets tipo Reclamo siempre derivan
     * `inspection_report_status = not_applicable` desde que existe Hoja de Reclamo, así que
     * quedaban invisibles en este contador pese a tener su propio documento pendiente).
     */
    public function informesPendientesCount(): int
    {
        $inspectionPending = Ticket::where('inspection_report_status', Ticket::INSPECTION_STATUS_PENDING)->count();
        $claimSheetPending = ClaimSheet::where('estado', ClaimSheet::ESTADO_PENDING)->count();

        return $inspectionPending + $claimSheetPending;
    }

    public function find(int $id): ?Ticket
    {
        return Ticket::with(['internalTechnician', 'salesProject.subClient.masterClient', 'ticketProducts', 'attachments'])->find($id);
    }

    /**
     * REQ-224 — modal de detalle (solo lectura). RN2: Cliente Master/Subcliente por separado
     * cuando difieren, un solo campo "Cliente" cuando son iguales — acá siempre se devuelven
     * ambos valores (`cliente_master`/`subcliente`), el frontend colapsa a un campo cuando son
     * idénticos. Si el ticket no viene de un `sales_project_id` (tickets creados antes de Batch 3
     * parte 2, cuando el endpoint todavía no lo exigía), no hay jerarquía Master/Subcliente que
     * resolver — se usa el `cliente` plano capturado al crear para ambos valores, que por
     * construcción son iguales y colapsan a un solo campo.
     * RN3 (REQ-247) — "productos reclamados/afectados" viene de `ticket_products`, poblada desde
     * Batch 3 parte 2; tickets anteriores simplemente no tienen filas y quedan en lista vacía
     * ("No aplica para este tipo de ticket").
     * "Observaciones"/"Adjuntos" (REQ-248, Batch 4) — columna `observaciones` dedicada y relación
     * `ticket_attachments`; tickets creados antes de este batch simplemente no tienen datos.
     *
     * @return array<string, mixed>
     */
    public function detail(Ticket $ticket): array
    {
        $subClient    = $ticket->salesProject?->subClient;
        $masterClient = $subClient !== null ? $subClient->masterClient : null;

        $clienteMaster = $masterClient !== null ? $masterClient->name : $ticket->cliente;
        $subcliente    = $subClient !== null ? $subClient->business_name : $ticket->cliente;

        return [
            'id'                         => $ticket->id,
            'numero'                     => $ticket->numero,
            'tipo'                       => $ticket->tipo,
            'subtipo'                    => $ticket->subtipo,
            'tipo_instalacion'           => $ticket->tipo_instalacion,
            'cliente_master'             => $clienteMaster,
            'subcliente'                 => $subcliente,
            'email'                      => $ticket->email,
            // SCRUM-781 (punto 4.2, REQ-247) — id real del proyecto, para que el frontend pueda
            // filtrar el buscador de "Productos reclamados/afectados" por proyecto al editar un
            // ticket ya existente (al crear, el id ya lo tiene el propio formulario). `proyecto`
            // (nombre, abajo) sigue siendo solo para mostrar.
            'sales_project_id'           => $ticket->sales_project_id,
            'proyecto'                   => $ticket->salesProject?->name,
            'contacto'                   => $ticket->contacto,
            // Visual Review 2026-08-03 (Batch 2) — el mockup muestra teléfono junto al nombre de
            // contacto; el campo ya existía en el ticket pero nunca se serializaba acá.
            'telefono'                   => $ticket->telefono,
            'direccion'                  => $ticket->direccion,
            // "Horario de trabajo/visita" del mockup no tiene columna propia en el esquema de
            // Batch 1 — el frontend lo deriva de scheduled_at/scheduled_ends_at (rango de hora).
            'scheduled_at'               => $ticket->scheduled_at?->toIso8601String(),
            'scheduled_ends_at'          => $ticket->scheduled_ends_at?->toIso8601String(),
            'requerimientos_especiales'  => $this->requirementsPayload($ticket->requerimientos_especiales),
            'productos'                  => $ticket->ticketProducts->map(fn (TicketProduct $p): array => [
                'id'                  => $p->id,
                'catalog_product_id'  => $p->catalog_product_id,
                'marca'               => $p->marca,
                'referencia'          => $p->referencia,
                'descripcion'         => $p->descripcion,
                'cantidad_reclamada'  => $p->cantidad_reclamo,
                'cantidad_recibida'   => $p->cantidad_recibida,
                'cantidad_pendiente'  => $p->cantidad_pendiente,
            ])->values()->all(),
            'inspection_report_status'   => $ticket->inspection_report_status,
            'quote_status'               => $ticket->quote_status,
            'observaciones'              => $ticket->observaciones,
            'adjuntos'                   => $ticket->attachments->map(fn (TicketAttachment $a): array => [
                'id'             => $a->id,
                'nombre_archivo' => $a->nombre_archivo,
                'size_bytes'     => $a->size_bytes,
                'mime_type'      => $a->mime_type,
                'created_at'     => $a->created_at->toIso8601String(),
            ])->values()->all(),
            'estado'                     => $ticket->estado,
            'cancellation_reason'        => $ticket->cancellation_reason,
            'internal_technician'        => $ticket->internalTechnician === null ? null : [
                'id'         => $ticket->internalTechnician->id,
                'first_name' => $ticket->internalTechnician->first_name,
                'last_name'  => $ticket->internalTechnician->last_name,
            ],
            // SCRUM-804 — más reciente primero (ver `Ticket::rescheduleHistory()`).
            'reschedule_history'         => $ticket->rescheduleHistory->map(fn (TicketRescheduleHistory $h): array => [
                'id'                          => $h->id,
                'previous_scheduled_at'       => $h->previous_scheduled_at->toIso8601String(),
                'previous_scheduled_ends_at'  => $h->previous_scheduled_ends_at?->toIso8601String(),
                'new_scheduled_at'            => $h->new_scheduled_at->toIso8601String(),
                'new_scheduled_ends_at'       => $h->new_scheduled_ends_at?->toIso8601String(),
                'motivo'                      => $h->motivo,
                'rescheduled_by'              => $h->reschedulerUser === null ? null : [
                    'id'         => $h->reschedulerUser->id,
                    'first_name' => $h->reschedulerUser->first_name,
                    'last_name'  => $h->reschedulerUser->last_name,
                ],
                'created_at'                  => $h->created_at->toIso8601String(),
            ])->values()->all(),
            'created_at'                 => $ticket->created_at->toIso8601String(),
        ];
    }

    /**
     * REQ-247 — normaliza `requerimientos_especiales` a `{catalog: [{key, label}], otros: []}`
     * para que el frontend/PDF nunca tengan que conocer `Ticket::REQUIREMENT_LABELS` por su cuenta.
     *
     * @param array{catalog?: list<string>, otros?: list<string>}|null $raw
     * @return array{catalog: list<array{key: string, label: string}>, otros: list<string>}
     */
    private function requirementsPayload(?array $raw): array
    {
        $catalogKeys = $raw['catalog'] ?? [];
        $otros       = $raw['otros'] ?? [];

        return [
            'catalog' => array_values(array_map(
                fn (string $key): array => ['key' => $key, 'label' => Ticket::REQUIREMENT_LABELS[$key] ?? $key],
                array_filter($catalogKeys, fn (string $key): bool => isset(Ticket::REQUIREMENT_LABELS[$key])),
            )),
            'otros' => array_values($otros),
        ];
    }

    /**
     * REQ-225 RN2-6 — edición global. RN5: si el nuevo tipo ya no corresponde a la especialidad
     * del técnico asignado, el ticket queda "Sin asignar" y avisa que se debe reagendar.
     *
     * @param array{tipo: string, subtipo: ?string, tipo_instalacion: string, requerimientos_especiales: ?array{catalog?: list<string>, otros?: list<string>}} $data
     * @return array{ticket: Ticket, technician_unassigned: bool}
     */
    public function update(Ticket $ticket, array $data, User $user): array
    {
        $technicianUnassigned = false;

        DB::transaction(function () use ($ticket, $data, &$technicianUnassigned): void {
            $ticket->tipo                      = $data['tipo'];
            $ticket->subtipo                   = $data['subtipo'] ?? null;
            $ticket->tipo_instalacion          = $data['tipo_instalacion'];
            $ticket->requerimientos_especiales = $data['requerimientos_especiales'] ?? null;

            // RN5 — si cambia el tipo y el técnico ya asignado no atiende esa especialidad,
            // queda "Sin asignar" (no se reasigna solo, el usuario debe usar Reagendar).
            if ($ticket->internal_technician_id !== null) {
                $currentTechnician = $ticket->internalTechnician;
                if ($currentTechnician !== null && ! $this->specialty->matches($currentTechnician, $data['tipo'])) {
                    $ticket->internal_technician_id = null;
                    $technicianUnassigned = true;
                }
            }

            $ticket->save();
        });

        return ['ticket' => $ticket->refresh()->load('internalTechnician'), 'technician_unassigned' => $technicianUnassigned];
    }

    /**
     * REQ-226 — Agendar/Reagendar. RN2: el técnico debe corresponder a la especialidad del tipo
     * del ticket. RN4: primer agendamiento (ticket "Reportado") pasa a "Agendado" automáticamente.
     * RN5: reagendar (ya tenía fecha) NO cambia el estado, solo actualiza fecha/técnico.
     *
     * SCRUM-804 — `motivo_reagendamiento` es obligatorio SOLO cuando el ticket ya tenía una fecha
     * agendada (reagendar), nunca en el primer agendamiento (`$wasUnscheduled`) — validado acá y
     * no en `ScheduleTicketRequest` porque depende del estado ya persistido del ticket, mismo
     * criterio que la validación de especialidad del técnico (RN2) unas líneas abajo. Cada
     * reagendamiento real deja una fila en `TicketRescheduleHistory` (fecha/hora anterior, nueva,
     * motivo) — el primer agendamiento no genera historial, no hay "anterior" que registrar.
     *
     * @param array{internal_technician_id: int, scheduled_at: string, scheduled_ends_at?: string|null, motivo_reagendamiento?: string|null} $data
     * @throws \InvalidArgumentException RN2 (técnico no atiende este tipo) o motivo faltante al reagendar.
     */
    public function schedule(Ticket $ticket, array $data, User $user): Ticket
    {
        $technician = User::find($data['internal_technician_id']);

        if ($technician === null || ! $this->specialty->matches($technician, $ticket->tipo)) {
            throw new \InvalidArgumentException('El técnico seleccionado no atiende este tipo de ticket.');
        }

        $wasUnscheduled = $ticket->scheduled_at === null;
        $isReschedule   = ! $wasUnscheduled;

        $motivo = isset($data['motivo_reagendamiento']) ? trim((string) $data['motivo_reagendamiento']) : '';
        if ($isReschedule && $motivo === '') {
            throw new \InvalidArgumentException('El motivo del reagendamiento es obligatorio.');
        }

        return DB::transaction(function () use ($ticket, $data, $user, $wasUnscheduled, $isReschedule, $motivo, $technician): Ticket {
            $previousScheduledAt     = $ticket->scheduled_at;
            $previousScheduledEndsAt = $ticket->scheduled_ends_at;

            $ticket->internal_technician_id = $technician->id;
            $ticket->scheduled_at           = \Carbon\Carbon::parse($data['scheduled_at']);
            $ticket->scheduled_ends_at      = isset($data['scheduled_ends_at']) ? \Carbon\Carbon::parse($data['scheduled_ends_at']) : null;

            // RN4 — solo transiciona automáticamente desde "Reportado" en el PRIMER agendamiento.
            // RN5 — reagendar (ya tenía fecha, o el ticket ya avanzó de estado por otra vía) nunca
            // toca el estado.
            if ($wasUnscheduled && $ticket->estado === Ticket::ESTADO_REPORTED) {
                $ticket->estado = Ticket::ESTADO_SCHEDULED;
            }

            $ticket->save();

            if ($isReschedule) {
                TicketRescheduleHistory::create([
                    'ticket_id'                  => $ticket->id,
                    'previous_scheduled_at'      => $previousScheduledAt,
                    'previous_scheduled_ends_at' => $previousScheduledEndsAt,
                    'new_scheduled_at'           => $ticket->scheduled_at,
                    'new_scheduled_ends_at'      => $ticket->scheduled_ends_at,
                    'motivo'                     => $motivo,
                    'rescheduled_by'             => $user->id,
                ]);
            }

            return $ticket->refresh()->load('internalTechnician');
        });
    }

    /**
     * REQ-245/246/247/249 — formulario completo de "Nuevo ticket". RN1 (REQ-246) — Servicios
     * nunca crea Cliente Master/Subcliente/Proyecto, solo lee: el snapshot `cliente` se deriva acá
     * del Subcliente del proyecto elegido, nunca de un string suelto que mande el cliente HTTP
     * (StoreTicketRequest ya no acepta `cliente` como input).
     *
     * @param array{
     *   tipo: string, subtipo?: string|null, tipo_instalacion: string, descripcion: string,
     *   sales_project_id: int|null, contacto?: string|null,
     *   telefono?: string|null, email?: string|null, direccion?: string|null,
     *   requerimientos_especiales?: array{catalog?: list<string>, otros?: list<string>}|null,
     *   observaciones?: string|null,
     *   productos?: list<array{catalog_product_id: int, cantidad_reclamo: int}>,
     * } $data
     */
    public function create(array $data, User $user): Ticket
    {
        return DB::transaction(function () use ($data, $user): Ticket {
            $numero = $this->folio->next($data['tipo']);
            [$quoteStatus, $inspectionStatus] = $this->deriveInitialStatuses($data['tipo'], $data['subtipo'] ?? null);

            $salesProjectId = $data['sales_project_id'] ?? null;
            $salesProject = $salesProjectId !== null ? SalesProject::with('subClient')->find($salesProjectId) : null;
            $clienteSnapshot = $salesProject?->subClient?->business_name;

            $ticket = Ticket::create([
                'numero'                    => $numero,
                'tipo'                      => $data['tipo'],
                'subtipo'                   => $data['subtipo'] ?? null,
                'tipo_instalacion'          => $data['tipo_instalacion'],
                'descripcion'               => $data['descripcion'],
                'sales_project_id'          => $salesProjectId,
                'cliente'                   => $clienteSnapshot,
                'contacto'                  => $data['contacto'] ?? null,
                'telefono'                  => $data['telefono'] ?? null,
                'email'                     => $data['email'] ?? null,
                'direccion'                 => $data['direccion'] ?? null,
                'requerimientos_especiales' => $data['requerimientos_especiales'] ?? null,
                'observaciones'             => $data['observaciones'] ?? null,
                // RN3 — siempre Reportado, sin técnico, sin fecha agendada.
                'estado'                    => Ticket::ESTADO_REPORTED,
                'quote_status'              => $quoteStatus,
                'inspection_report_status'  => $inspectionStatus,
                'internal_technician_id'    => null,
                'scheduled_at'              => null,
                'scheduled_ends_at'         => null,
                'created_by'                => $user->id,
            ]);

            $this->createTicketProducts($ticket, $data['productos'] ?? []);

            event(new TicketCreated(
                ticketId:  $ticket->id,
                numero:    $ticket->numero,
                tipo:      $ticket->tipo,
                createdBy: $user->id,
                tenant:    $this->requiredTenantSlug(),
            ));

            return $ticket;
        });
    }

    /**
     * REQ-247 RN5 — al crear, cada línea solo captura la cantidad reclamada; recibida arranca en 0
     * y pendiente = reclamada (se actualizan más adelante por el resto del ciclo de vida del
     * ticket, fuera de este batch). Snapshot de marca/referencia/descripción tomado del catálogo
     * real en este momento — mismo criterio que el resto de los campos "congelados" del ticket.
     *
     * @param list<array{catalog_product_id: int, cantidad_reclamo: int}> $productos
     */
    private function createTicketProducts(Ticket $ticket, array $productos): void
    {
        if ($productos === []) {
            return;
        }

        $catalogProducts = CatalogProduct::whereIn('id', array_column($productos, 'catalog_product_id'))->get()->keyBy('id');

        foreach ($productos as $producto) {
            $catalogProduct = $catalogProducts->get($producto['catalog_product_id']);

            TicketProduct::create([
                'ticket_id'           => $ticket->id,
                'catalog_product_id'  => $producto['catalog_product_id'],
                'marca'               => $catalogProduct?->brand,
                'referencia'          => $catalogProduct?->reference,
                'descripcion'         => $catalogProduct?->name,
                'cantidad_reclamo'    => $producto['cantidad_reclamo'],
                'cantidad_recibida'   => 0,
                'cantidad_pendiente'  => $producto['cantidad_reclamo'],
            ]);
        }
    }

    /**
     * SCRUM-781 (punto 1, REQ-225 RN2) — agrega una fila de producto reclamado/afectado a un
     * ticket ya existente, desde la edición. Mismo criterio de snapshot que
     * createTicketProducts() (marca/referencia/descripción del catálogo real en este momento).
     */
    public function addProduct(Ticket $ticket, int $catalogProductId, int $cantidadReclamo): TicketProduct
    {
        $catalogProduct = CatalogProduct::find($catalogProductId);

        return TicketProduct::create([
            'ticket_id'          => $ticket->id,
            'catalog_product_id' => $catalogProductId,
            'marca'              => $catalogProduct?->brand,
            'referencia'         => $catalogProduct?->reference,
            'descripcion'        => $catalogProduct?->name,
            'cantidad_reclamo'   => $cantidadReclamo,
            'cantidad_recibida'  => 0,
            'cantidad_pendiente' => $cantidadReclamo,
        ]);
    }

    /**
     * SCRUM-781 (punto 1) — edita la cantidad reclamada de una fila ya existente. Decisión de
     * Luis (2026-08-20): una fila con cantidad_recibida > 0 (ya avanzó por el flujo real de
     * recepción de otro batch) queda de solo lectura — no se puede editar, para no perder esa
     * trazabilidad. Filas sin recepción se editan libre.
     *
     * @throws \InvalidArgumentException si la fila ya tiene recepción registrada.
     */
    public function updateProductQuantity(TicketProduct $product, int $cantidadReclamo): TicketProduct
    {
        if ($product->cantidad_recibida > 0) {
            throw new \InvalidArgumentException('Este producto ya tiene recepción registrada, no se puede editar.');
        }

        $product->cantidad_reclamo   = $cantidadReclamo;
        $product->cantidad_pendiente = $cantidadReclamo;
        $product->save();

        return $product;
    }

    /**
     * SCRUM-781 (punto 1) — quita una fila. Mismo criterio de solo-lectura que
     * updateProductQuantity() (decisión de Luis 2026-08-20).
     *
     * @throws \InvalidArgumentException si la fila ya tiene recepción registrada.
     */
    public function removeProduct(TicketProduct $product): void
    {
        if ($product->cantidad_recibida > 0) {
            throw new \InvalidArgumentException('Este producto ya tiene recepción registrada, no se puede quitar.');
        }

        $product->delete();
    }

    /**
     * REQ-218/REQ-221 RN3 — cambio de estado (select inline en la tabla o arrastre en el
     * tablero, misma validación en ambos casos). RN7 (solo lider_servicios/superadmin puede
     * ejecutar esto) se aplica en la ruta (`role:superadmin,lider_servicios`), no acá.
     *
     * @throws TicketCloseBlockedException RN1/RN2/RN4 — falta cotización aprobada o informe completado.
     */
    public function changeStage(Ticket $ticket, string $newEstado, User $user): Ticket
    {
        $this->assertTransitionAllowed($ticket, $newEstado);

        if (in_array($newEstado, Ticket::ESTADOS_REQUIRING_CLOSE_GATE, true)) {
            $this->assertCloseAllowed($ticket);
        }

        $oldEstado = $ticket->estado;

        $ticket->estado = $newEstado;

        // REQ-211 RN3 (Batch 15) — cada vez que el ticket entra a "En sitio" cuenta como una
        // visita técnica real. "Resuelto en 1ra visita" (panel Indicadores del mes) se deriva de
        // este contador — no había ninguna señal existente para esto antes de este batch.
        if ($newEstado === Ticket::ESTADO_ON_SITE) {
            $ticket->visit_count++;
        }

        // REQ-211 RN2/RN4 (Batch 15) — se fija una sola vez, la primera vez que el ticket llega a
        // resolved/closed; ediciones posteriores (poco frecuentes, pero posibles) no lo corren.
        if ($ticket->closed_at === null && in_array($newEstado, Ticket::ESTADOS_REQUIRING_CLOSE_GATE, true)) {
            $ticket->closed_at = now();
        }

        $ticket->save();

        event(new TicketStageChanged(
            ticketId:  $ticket->id,
            numero:    $ticket->numero,
            oldEstado: $oldEstado,
            newEstado: $newEstado,
            changedBy: $user->id,
            tenant:    $this->requiredTenantSlug(),
        ));

        return $ticket;
    }

    /**
     * Fase 12a — solo se avanza por la matriz `Ticket::TRANSICIONES`; `scheduled` y `on_site` exigen técnico y agenda.
     *
     * @throws TicketCloseBlockedException
     */
    private function assertTransitionAllowed(Ticket $ticket, string $nuevo): void
    {
        $actual = $ticket->estado;
        if ($actual === $nuevo) {
            return;
        }

        $permitidos = Ticket::TRANSICIONES[$actual] ?? [];
        if (! in_array($nuevo, $permitidos, true)) {
            $detalle = $permitidos === []
                ? 'es un estado final: se reabre con la reapertura (permiso y motivo)'
                : 'solo puede pasar a: '.implode(', ', $permitidos);

            throw new TicketCloseBlockedException("Un ticket en «{$actual}» no puede pasar a «{$nuevo}»: {$detalle}.");
        }

        if (in_array($nuevo, [Ticket::ESTADO_SCHEDULED, Ticket::ESTADO_ON_SITE], true)
            && ($ticket->internal_technician_id === null || $ticket->scheduled_at === null)) {
            throw new TicketCloseBlockedException("Para pasar a «{$nuevo}» el ticket necesita un técnico asignado y una fecha agendada.");
        }
    }

    /**
     * Fase 12a — reabrir un ticket cerrado o cancelado: permiso y motivo obligatorios; vuelve a `reported` y limpia el cierre.
     *
     * @throws TicketCloseBlockedException si el ticket no está en un estado final
     */
    public function reopen(Ticket $ticket, string $motivo, User $user): Ticket
    {
        if (! in_array($ticket->estado, [Ticket::ESTADO_CLOSED, Ticket::ESTADO_CANCELLED], true)) {
            throw new TicketCloseBlockedException('Solo se puede reabrir un ticket cerrado o cancelado.');
        }

        $oldEstado = $ticket->estado;

        $ticket->estado              = Ticket::ESTADO_REPORTED;
        $ticket->closed_at           = null;
        $ticket->reopened_at         = now();
        $ticket->reopened_reason     = trim($motivo);
        $ticket->save();

        event(new TicketStageChanged(
            ticketId:  $ticket->id,
            numero:    $ticket->numero,
            oldEstado: $oldEstado,
            newEstado: Ticket::ESTADO_REPORTED,
            changedBy: $user->id,
            tenant:    $this->requiredTenantSlug(),
        ));

        return $ticket;
    }

    /**
     * REQ-227 — cancelar ticket. RN1 (solo lider_servicios/superadmin) se aplica en la ruta.
     * RN3: sin gate de cotización/informe, a diferencia de resolved/closed. RN4: no se puede
     * cancelar un ticket ya cancelado. RN6: motivo obligatorio, validado en el Request.
     *
     * @throws \InvalidArgumentException RN4 — el ticket ya está cancelado.
     */
    public function cancel(Ticket $ticket, string $motivo, User $user): Ticket
    {
        if ($ticket->estado === Ticket::ESTADO_CANCELLED) {
            throw new \InvalidArgumentException('Este ticket ya está cancelado.');
        }

        $oldEstado = $ticket->estado;

        $ticket->estado               = Ticket::ESTADO_CANCELLED;
        $ticket->cancellation_reason  = trim($motivo);
        $ticket->save();

        event(new TicketStageChanged(
            ticketId:  $ticket->id,
            numero:    $ticket->numero,
            oldEstado: $oldEstado,
            newEstado: Ticket::ESTADO_CANCELLED,
            changedBy: $user->id,
            tenant:    $this->requiredTenantSlug(),
        ));

        return $ticket;
    }

    /**
     * REQ-218 RN1: (a) informe Completado o No aplica, Y (b) cotización Aprobada o No aplica.
     * RN2: una cotización Rechazada sin una posterior Aprobada bloquea igual (ya cubierto por (b),
     * "rejected" no está en QUOTE_STATUSES_ALLOWING_CLOSE). RN4: mensaje específico según cuál
     * de las 2 condiciones falla — si fallan ambas, se prioriza el mensaje de informe porque la
     * cotización normalmente depende de que el informe esté completo primero (REQ-219 RN2).
     */
    private function assertCloseAllowed(Ticket $ticket): void
    {
        if (! in_array($ticket->inspection_report_status, Ticket::INSPECTION_STATUSES_ALLOWING_CLOSE, true)) {
            throw new TicketCloseBlockedException('No se puede cerrar: falta completar el informe de inspección.');
        }

        if (! in_array($ticket->quote_status, Ticket::QUOTE_STATUSES_ALLOWING_CLOSE, true)) {
            throw new TicketCloseBlockedException('No se puede cerrar: falta aprobar la cotización.');
        }
    }

    /**
     * REQ-249 RN2 — derivación automática, el usuario nunca la elige a mano.
     *
     * @return array{0: string, 1: string} [quote_status, inspection_report_status]
     */
    private function deriveInitialStatuses(string $tipo, ?string $subtipo): array
    {
        // Instalación (subtipo "Instalación", venta previa) — ambos No aplica.
        if (TicketTypes::is($tipo, TicketTypes::INSTALACION) && $subtipo === Ticket::SUBTIPO_INSTALLATION) {
            return [Ticket::QUOTE_STATUS_NOT_APPLICABLE, Ticket::INSPECTION_STATUS_NOT_APPLICABLE];
        }

        // Reclamos — informe No aplica (usa su propio indicador de Hoja de Reclamo, otro batch),
        // cotización pendiente de generar.
        if (TicketTypes::is($tipo, TicketTypes::RECLAMO)) {
            return [Ticket::QUOTE_STATUS_PENDING, Ticket::INSPECTION_STATUS_NOT_APPLICABLE];
        }

        // Cualquier otro caso (Garantías, Retrofit, Inspección de instalación) — ambos pendientes.
        return [Ticket::QUOTE_STATUS_PENDING, Ticket::INSPECTION_STATUS_PENDING];
    }

    /**
     * @param array{search?: string, tipo?: string, tecnico_id?: int|string, estado?: string} $filters
     * @return Builder<Ticket>
     */
    private function filteredQuery(array $filters): Builder
    {
        // Batch 2 — eager load para no golpear la BD por fila al serializar internal_technician
        // (ver docblock de Ticket::internalTechnician()).
        $query = Ticket::query()->with('internalTechnician');

        // REQ-217 RN2 — "contiene", case-insensitive, sobre N° ticket + cliente simultáneamente.
        // Nota: el criterio original también pide buscar por técnico, pero `internal_technicians`
        // (Batch 6) todavía no existe — no hay tabla/nombre que unir todavía, ver reporte final.
        if (! empty($filters['search'])) {
            $term = trim((string) $filters['search']);
            $query->where(function (Builder $q) use ($term): void {
                $q->where('numero', 'ilike', "%{$term}%")
                    ->orWhere('cliente', 'ilike', "%{$term}%");
            });
        }

        if (! empty($filters['tipo'])) {
            $query->where('tipo', $filters['tipo']);
        }

        if (! empty($filters['estado'])) {
            $query->where('estado', $filters['estado']);
        }

        if (array_key_exists('tecnico_id', $filters) && $filters['tecnico_id'] !== null && $filters['tecnico_id'] !== '') {
            if ($filters['tecnico_id'] === 'unassigned') {
                $query->whereNull('internal_technician_id');
            } else {
                $query->where('internal_technician_id', (int) $filters['tecnico_id']);
            }
        }

        return $query;
    }

    /**
     * RN6(bis) — primero "Reportado" sin agendar, luego el resto por fecha de reporte (created_at)
     * más reciente primero.
     *
     * @param Builder<Ticket> $query
     */
    private function applyDefaultOrder(Builder $query): void
    {
        $query->orderByRaw(
            "CASE WHEN estado = ? AND scheduled_at IS NULL THEN 0 ELSE 1 END",
            [Ticket::ESTADO_REPORTED],
        )->orderBy('created_at', 'desc');
    }

    /** @return array<string, mixed> */
    private function cardPayload(Ticket $ticket, ?float $quoteAmount = null): array
    {
        return [
            'id'                        => $ticket->id,
            'numero'                    => $ticket->numero,
            'tipo'                      => $ticket->tipo,
            'subtipo'                   => $ticket->subtipo,
            'cliente'                   => $ticket->cliente,
            'descripcion'               => $ticket->descripcion,
            'estado'                    => $ticket->estado,
            'quote_status'              => $ticket->quote_status,
            // Batch 11 (REQ-233) — total real de la cotización más reciente del ticket, antes
            // siempre null (Cotización no existía como módulo). Solo se usa en el Tablero.
            'quote_amount'              => $quoteAmount,
            'inspection_report_status'  => $ticket->inspection_report_status,
            'internal_technician_id'    => $ticket->internal_technician_id,
            'internal_technician'       => $ticket->internalTechnician === null ? null : [
                'id'         => $ticket->internalTechnician->id,
                'first_name' => $ticket->internalTechnician->first_name,
                'last_name'  => $ticket->internalTechnician->last_name,
            ],
            'scheduled_at'              => $ticket->scheduled_at?->toIso8601String(),
            'created_at'                => $ticket->created_at->toIso8601String(),
        ];
    }

    /**
     * Batch 11 — total de la cotización MÁS RECIENTE por ticket (`ServiceQuote.created_at` desc),
     * para el Tablero (REQ-233). Una sola query batched + agrupado en memoria — evita N+1 sobre
     * `$tickets` (mismo criterio ya usado en `ExternalTechnicianService::activeAssignmentsQuery()`
     * para no consultar por fila).
     *
     * @param list<int> $ticketIds
     * @return array<int, float>
     */
    private function latestQuoteAmountsByTicket(array $ticketIds): array
    {
        if ($ticketIds === []) {
            return [];
        }

        $quotes = ServiceQuote::whereIn('ticket_id', $ticketIds)
            ->orderBy('created_at')
            ->get(['ticket_id', 'total', 'created_at']);

        $amounts = [];
        foreach ($quotes as $quote) {
            // La última en el orden ascendente gana — sobrescribe cualquier cotización más vieja
            // del mismo ticket.
            $amounts[$quote->ticket_id] = (float) $quote->total;
        }

        return $amounts;
    }

    private function requiredTenantSlug(): string
    {
        $tenant = Tenant::current();

        if ($tenant === null) {
            throw new \RuntimeException('Cannot dispatch domain event without an active tenant.');
        }

        return $tenant->slug;
    }
}
