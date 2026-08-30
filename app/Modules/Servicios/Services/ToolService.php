<?php

declare(strict_types=1);

namespace App\Modules\Servicios\Services;

use App\Models\User;
use App\Modules\Servicios\Exceptions\ToolPurchaseRequestAlreadyReceivedException;
use App\Modules\Servicios\Models\Tool;
use App\Modules\Servicios\Models\ToolMovement;
use App\Modules\Servicios\Models\ToolPurchaseRequest;
use Illuminate\Support\Facades\DB;

/**
 * Fase 4 — Servicios, Batch 13 parte 1 (REQ-268→272, Grupo D "Herramientas"). Ver
 * docs/architecture/servicios-fase4-diseno.md.
 */
final class ToolService
{
    public function __construct(private readonly ToolCodeService $codes) {}

    /**
     * REQ-268 — listado (RN1/RN2: el agrupado por nombre lo arma el frontend, esto retorna las
     * unidades sueltas con todo el detalle por unidad). RN3: `search` cruza nombre/código/persona
     * asignada; `estado` filtra exacto; `assigned_to` acepta el id de `internal_technicians` o el
     * literal "bodega" para las unidades sin asignar.
     *
     * @param array{search?: string, estado?: string, assigned_to?: string} $filters
     * @return array<int, array<string, mixed>>
     */
    public function list(array $filters = []): array
    {
        $query = Tool::query()->with($this->eagerLoads());

        $search = $filters['search'] ?? null;
        if (is_string($search) && trim($search) !== '') {
            $needle = trim($search);
            $query->where(function ($q) use ($needle): void {
                $q->where('nombre', 'ilike', "%{$needle}%")
                    ->orWhere('codigo_unico', 'ilike', "%{$needle}%")
                    ->orWhereHas('assignedTechnician', fn ($q2) => $q2->where('nombre', 'ilike', "%{$needle}%"));
            });
        }

        $estado = $filters['estado'] ?? null;
        if (is_string($estado) && $estado !== '') {
            $query->where('estado', $estado);
        }

        $assignedTo = $filters['assigned_to'] ?? null;
        if (is_string($assignedTo) && $assignedTo !== '') {
            if ($assignedTo === 'bodega') {
                $query->whereNull('assigned_to_technician_id');
            } elseif (is_numeric($assignedTo)) {
                $query->where('assigned_to_technician_id', (int) $assignedTo);
            }
        }

        $tools = $query->orderBy('nombre')->orderBy('codigo_unico')->get();

        return $tools->map(fn (Tool $t): array => $this->summary($t))->values()->all();
    }

    /**
     * Formatea una unidad puntual con el mismo shape que `list()` — usado tras estado/asignación.
     *
     * @return array<string, mixed>
     */
    public function present(Tool $tool): array
    {
        $tool->loadMissing($this->eagerLoads());

        return $this->summary($tool);
    }

    /**
     * REQ-269 — cambio de estado. RN1: Dañada/Perdida completan `responsable_incidente` con quien
     * tenía la herramienta asignada EN ESE MOMENTO (se lee antes de tocar cualquier otra cosa) —
     * "Sin asignar al momento del incidente" si no había nadie. RN2: Desgaste nunca atribuye
     * responsable — se limpia igual que al volver a Buen estado (RN4). RN3: Dañada/Perdida/
     * Desgaste generan un movimiento en el kardex; reasignar (REQ-270) nunca pasa por acá.
     */
    /**
     * SCRUM-779 (rebote QA/Daniela 2026-08-20) — `$detalle` es nuevo: antes el movimiento de
     * Dañada/Pérdida/Desgaste se creaba sin ningún detalle del incidente (ni el controller lo
     * pedía al request), así que el Kardex siempre mostraba "—" para esos 3 tipos — no era un
     * campo que se perdía, nunca se capturó. Buen estado no genera movimiento, así que `$detalle`
     * no aplica ahí (se ignora si llega).
     *
     * SCRUM-779 (2do rebote de Daniela, 2026-08-24) — 2 correcciones más sobre el mismo método:
     * (1) el texto de REQ-276 punto 3 pide literal "Dañada por [Nombre]"/"Pérdida por [Nombre]" en
     * Detalle — antes solo se guardaba el motivo de texto libre del modal, sin el responsable
     * (`incidentDetalle()` compone ambos). (2) restaurar a Buen estado desde Dañada/Perdida/
     * Desgaste no generaba ningún movimiento — el saldo de `listMovements()` nunca revertía una
     * baja corregida (encontrar la herramienta perdida, reparar la dañada). Ahora genera un
     * Ingreso, mismo `tipo` que una recepción real (`saldoDeltaFor()` ya lo suma) — la limitación
     * documentada en el docblock de `listMovements()` queda resuelta, no aplica desde este fix.
     */
    public function updateEstado(Tool $tool, string $estado, User $user, ?string $detalle = null): Tool
    {
        return DB::transaction(function () use ($tool, $estado, $user, $detalle): Tool {
            $previousEstado = $tool->estado;
            $responsable    = null;

            if (in_array($estado, Tool::ESTADOS_WITH_RESPONSABLE, true)) {
                // Sin "?->" a propósito: "??" ya evalúa la cadena completa en modo isset-safe (no
                // dispara "member access on null" aunque assignedTechnician sea null) — mismo
                // motivo en summary()/createPurchaseRequest() de este archivo.
                $responsable = $tool->assignedTechnician->nombre ?? Tool::UNASSIGNED_INCIDENT_LABEL;
                $tool->responsable_incidente = $responsable;
            } else {
                // RN2 (Desgaste) y RN4 (Buen estado) — ninguno atribuye/mantiene responsable.
                $tool->responsable_incidente = null;
            }

            $tool->estado = $estado;
            $tool->save();

            if (in_array($estado, [Tool::ESTADO_DAMAGED, Tool::ESTADO_LOST, Tool::ESTADO_WORN], true)) {
                $tool->movements()->create([
                    'tipo'    => $this->movementTipoFor($estado),
                    'detalle' => $this->incidentDetalle($estado, $responsable, $detalle),
                    'user_id' => $user->id,
                ]);
            } elseif ($estado === Tool::ESTADO_GOOD
                && in_array($previousEstado, [Tool::ESTADO_DAMAGED, Tool::ESTADO_LOST, Tool::ESTADO_WORN], true)
            ) {
                $tool->movements()->create([
                    'tipo'    => ToolMovement::TIPO_INGRESO,
                    'detalle' => $detalle !== null && trim($detalle) !== ''
                        ? "Restaurada a Buen estado — {$detalle}"
                        : 'Restaurada a Buen estado',
                    'user_id' => $user->id,
                ]);
            }

            return $tool->refresh();
        });
    }

    /**
     * REQ-276 punto 3 — Dañada/Pérdida deben leerse en Detalle como "Dañada por [Nombre]"/
     * "Pérdida por [Nombre]"; el motivo de texto libre capturado por el modal (rebote anterior,
     * 2026-08-23) se conserva agregado, no se descarta. Desgaste no atribuye responsable (RN2) —
     * queda solo el motivo tal cual se escribió.
     */
    private function incidentDetalle(string $estado, ?string $responsable, ?string $motivo): string
    {
        $label = match ($estado) {
            Tool::ESTADO_DAMAGED => "Dañada por {$responsable}",
            Tool::ESTADO_LOST    => "Pérdida por {$responsable}",
            default              => null,
        };

        if ($label === null) {
            return $motivo ?? '';
        }

        return $motivo !== null && trim($motivo) !== '' ? "{$label} — {$motivo}" : $label;
    }

    /**
     * REQ-270 — reasignación. RN1: `assigned_since` siempre se actualiza a hoy, incluso al
     * devolver la herramienta a bodega (`$technicianId = null`). RN3: nunca genera movimiento de
     * kardex — a diferencia de `updateEstado()`, esto es un traspaso, no un ingreso/baja.
     */
    public function updateAsignacion(Tool $tool, ?int $technicianId): Tool
    {
        $tool->assigned_to_technician_id = $technicianId;
        $tool->assigned_since            = now();
        $tool->save();

        return $tool->refresh();
    }

    /**
     * REQ-271 (alta nueva, sin `tool_id`) / REQ-272 (reposición, con `tool_id`) — crea una
     * solicitud pendiente, 0 unidades reales todavía. REQ-272: `nombre` SIEMPRE se deriva
     * server-side de la unidad referenciada, nunca del texto libre del cliente.
     *
     * @param array{nombre?: string, tool_id?: int, cantidad: int} $data
     */
    public function createPurchaseRequest(array $data, User $user): ToolPurchaseRequest
    {
        $sourceTool = isset($data['tool_id']) ? Tool::findOrFail((int) $data['tool_id']) : null;
        $nombre     = $sourceTool->nombre ?? (string) ($data['nombre'] ?? '');

        return ToolPurchaseRequest::create([
            'nombre'         => $nombre,
            'cantidad'       => (int) $data['cantidad'],
            'estado'         => ToolPurchaseRequest::ESTADO_SOLICITADO,
            'source_tool_id' => $sourceTool?->id,
            'requested_by'   => $user->id,
        ]);
    }

    /**
     * REQ-271 RN2 / REQ-272 — listado de solicitudes ("pendiente de recibir" + historial ya
     * recibido), más reciente primero.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listPurchaseRequests(): array
    {
        return ToolPurchaseRequest::query()
            ->with('sourceTool')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (ToolPurchaseRequest $r): array => $this->purchaseRequestSummary($r))
            ->values()
            ->all();
    }

    /**
     * REQ-271 RN3/RN4, REQ-272 RN3/RN4 — al recibir: genera tantas unidades como se solicitaron,
     * cada una con código propio nuevo (`ToolCodeService::next()` — REQ-272 RN3: NUNCA reutiliza
     * el código de la unidad original que disparó la reposición), asignadas por defecto a "En
     * bodega de herramientas" en Buen estado, y un movimiento "Ingreso" por unidad con el detalle
     * de todos los códigos generados en esta recepción.
     *
     * @return array{purchase_request: ToolPurchaseRequest, tools: list<Tool>}
     * @throws ToolPurchaseRequestAlreadyReceivedException
     */
    public function receivePurchaseRequest(ToolPurchaseRequest $purchaseRequest, User $user): array
    {
        if ($purchaseRequest->estado === ToolPurchaseRequest::ESTADO_RECIBIDA) {
            throw new ToolPurchaseRequestAlreadyReceivedException();
        }

        return DB::transaction(function () use ($purchaseRequest, $user): array {
            $codes = [];
            $tools = [];

            for ($i = 0; $i < $purchaseRequest->cantidad; $i++) {
                $codigo  = $this->codes->next();
                $codes[] = $codigo;

                $tools[] = Tool::create([
                    'nombre'                    => $purchaseRequest->nombre,
                    'codigo_unico'              => $codigo,
                    'estado'                    => Tool::ESTADO_GOOD,
                    'assigned_to_technician_id' => null,
                    'assigned_since'            => now()->toDateString(),
                ]);
            }

            $detalle = 'Recepción de solicitud #' . $purchaseRequest->id . ' — códigos generados: ' . implode(', ', $codes);
            foreach ($tools as $tool) {
                $tool->movements()->create([
                    'tipo'    => ToolMovement::TIPO_INGRESO,
                    'detalle' => $detalle,
                    'user_id' => $user->id,
                ]);
            }

            $purchaseRequest->estado      = ToolPurchaseRequest::ESTADO_RECIBIDA;
            $purchaseRequest->received_at = now();
            $purchaseRequest->save();

            return ['purchase_request' => $purchaseRequest->refresh(), 'tools' => $tools];
        });
    }

    /** @return array<string, mixed> */
    public function presentPurchaseRequest(ToolPurchaseRequest $purchaseRequest): array
    {
        return $this->purchaseRequestSummary($purchaseRequest);
    }

    /**
     * Fase 4 — Servicios, Grupo D parte 2 (REQ-276, SCRUM-346) — Kardex de herramientas.
     * SCRUM-346 es 100% frontend salvo este endpoint de lectura: filtros combinables por
     * herramienta/tipo/persona que realizó el movimiento, más reciente primero.
     *
     * SCRUM-779 (rebote Daniela 2026-08-20 + decisión de Luis 2026-08-23) — "Saldo" se calcula
     * sobre unidades EN BUEN ESTADO agrupadas por `nombre` de herramienta (ej. "Escalera"): Ingreso
     * suma, Dañada/Perdida/Desgaste resta — ver `saldoDeltaFor()`. El saldo SIEMPRE se calcula
     * sobre el historial COMPLETO en orden cronológico (nunca sobre el subconjunto ya filtrado),
     * para que activar un filtro de tipo/persona no altere el saldo mostrado en las filas que sí
     * quedan visibles — los filtros solo deciden qué se MUESTRA, no qué participa del cálculo.
     *
     * Restaurar una unidad a "Buen estado" desde Dañada/Perdida/Desgaste SÍ genera movimiento
     * (Ingreso) desde el fix de 2026-08-24 en `updateEstado()` — la limitación que existía acá
     * (saldo que nunca revertía una baja corregida) queda resuelta. Sigue existiendo el caso ya
     * aceptado de una restauración hecha fuera de este flujo (edición directa en BD) — mismo tipo
     * de limitación que ya se documentó para el Consumo automático de Insumos (REQ-291).
     *
     * @param array{tool_id?: int, tipo?: string, user_id?: int} $filters
     * @return array<int, array<string, mixed>>
     */
    public function listMovements(array $filters = []): array
    {
        /** @var \Illuminate\Support\Collection<int, ToolMovement> $all */
        $all = ToolMovement::query()->with(['tool', 'user'])
            ->orderBy('created_at')->orderBy('id')
            ->get();

        // Saldo por nombre de herramienta, en orden cronológico real — nunca sobre un subconjunto
        // filtrado (ver docblock de arriba).
        $saldoById = [];
        $running   = [];
        foreach ($all as $m) {
            $nombre = $m->tool->nombre ?? '';
            $before = $running[$nombre] ?? 0;
            $after  = $before + $this->saldoDeltaFor($m->tipo);
            $running[$nombre] = $after;
            $saldoById[$m->id] = ['inicial' => $before, 'resultante' => $after];
        }

        $toolId = $filters['tool_id'] ?? null;
        $tipo   = $filters['tipo'] ?? null;
        $userId = $filters['user_id'] ?? null;

        $filtered = $all
            ->when($toolId !== null, fn ($c) => $c->where('tool_id', $toolId))
            ->when($tipo !== null, fn ($c) => $c->where('tipo', $tipo))
            ->when($userId !== null, fn ($c) => $c->where('user_id', $userId))
            ->sortByDesc(fn (ToolMovement $m) => [$m->created_at->timestamp, $m->id]);

        return $filtered
            ->map(fn (ToolMovement $m): array => $this->movementSummary($m, $saldoById[$m->id]))
            ->values()
            ->all();
    }

    /** Ingreso suma a la pool de "Buen estado" de ese nombre; Dañada/Perdida/Desgaste resta. */
    private function saldoDeltaFor(string $tipo): int
    {
        return $tipo === ToolMovement::TIPO_INGRESO ? 1 : -1;
    }

    /**
     * SCRUM-779 — `cantidad` agregado a la spec original de REQ-276: siempre 1, porque cada fila
     * de `ToolMovement` es un evento de UNA unidad física puntual (`tool_id`), nunca un lote —
     * dato real, no un valor de relleno.
     *
     * @param array{inicial: int, resultante: int} $saldo
     * @return array<string, mixed>
     */
    private function movementSummary(ToolMovement $m, array $saldo): array
    {
        return [
            'id'         => $m->id,
            'tool_id'    => $m->tool_id,
            'tool_nombre' => $m->tool->nombre ?? null,
            'tool_codigo_unico' => $m->tool->codigo_unico ?? null,
            'tipo'       => $m->tipo,
            'cantidad'   => 1,
            'detalle'    => $m->detalle,
            'saldo_inicial'    => $saldo['inicial'],
            'saldo_resultante' => $saldo['resultante'],
            'user_id'    => $m->user_id,
            'user_nombre' => $m->user !== null ? trim("{$m->user->first_name} {$m->user->last_name}") : null,
            'created_at' => $m->created_at->toIso8601String(),
        ];
    }

    private function movementTipoFor(string $estado): string
    {
        return match ($estado) {
            Tool::ESTADO_DAMAGED => ToolMovement::TIPO_DAMAGED,
            Tool::ESTADO_LOST    => ToolMovement::TIPO_LOST,
            Tool::ESTADO_WORN    => ToolMovement::TIPO_WORN,
            default              => throw new \InvalidArgumentException("Estado [{$estado}] no genera movimiento de kardex."),
        };
    }

    /** @return array<int|string, string|\Closure> */
    private function eagerLoads(): array
    {
        return [
            'assignedTechnician',
            'replacementRequests' => fn ($q) => $q->where('estado', ToolPurchaseRequest::ESTADO_SOLICITADO),
        ];
    }

    /** @return array<string, mixed> */
    private function summary(Tool $t): array
    {
        $pending = $t->replacementRequests->first();

        return [
            'id'                          => $t->id,
            'nombre'                      => $t->nombre,
            'codigo_unico'                => $t->codigo_unico,
            'estado'                      => $t->estado,
            'assigned_to_technician_id'   => $t->assigned_to_technician_id,
            'assigned_to'                 => $t->assignedTechnician->nombre ?? Tool::BODEGA_LABEL,
            'assigned_since'              => $t->assigned_since?->toDateString(),
            'responsable_incidente'       => $t->responsable_incidente,
            'pending_replacement_request' => $pending === null ? null : [
                'id'         => $pending->id,
                'cantidad'   => $pending->cantidad,
                'created_at' => $pending->created_at->toIso8601String(),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function purchaseRequestSummary(ToolPurchaseRequest $r): array
    {
        return [
            'id'             => $r->id,
            'nombre'         => $r->nombre,
            'cantidad'       => $r->cantidad,
            'estado'         => $r->estado,
            'source_tool_id' => $r->source_tool_id,
            'requested_by'   => $r->requested_by,
            'received_at'    => $r->received_at?->toIso8601String(),
            'created_at'     => $r->created_at->toIso8601String(),
        ];
    }
}
