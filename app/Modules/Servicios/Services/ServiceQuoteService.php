<?php

declare(strict_types=1);

namespace App\Modules\Servicios\Services;

use App\Models\User;
use App\Modules\Auth\Support\RoleKeys;
use App\Modules\Servicios\Events\ServiceQuoteApproved;
use App\Modules\Servicios\Events\ServiceQuoteCreated;
use App\Modules\Servicios\Events\ServiceQuoteRejected;
use App\Modules\Servicios\Events\ServiceQuoteSent;
use App\Modules\Servicios\Exceptions\ServiceQuoteAccessDeniedException;
use App\Modules\Servicios\Exceptions\ServiceQuoteActionBlockedException;
use App\Modules\Servicios\Models\ExternalTechnician;
use App\Modules\Servicios\Models\InspectionReport;
use App\Modules\Servicios\Models\ServiceQuote;
use App\Modules\Servicios\Models\ServiceQuoteItem;
use App\Modules\Servicios\Models\Ticket;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Modules\VentasDiseno\Models\PricingSettings;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Fase 4 — Servicios, Batch 11 (REQ-229→234, docs/architecture/servicios-fase4-diseno.md sección
 * 8) — Cotización de Servicio: formulario, ítems Producto/Mano de obra/Subcontratado, totales
 * (ITBMS consistente formulario/documento), ciclo de vida.
 *
 * Margen mínimo (REQ-230 RN — ítem Producto, BLOQUEO DURO) reusa
 * `App\Modules\VentasDiseno\Models\PricingSettings::min_margin_percent` — el MISMO valor
 * parametrizado que ya usa Ventas & Diseño (SCRUM-293 lo pide explícito: "igual que la regla ya
 * usada en el módulo Ventas & Diseño"), no una copia/tabla nueva. A diferencia de Ventas & Diseño
 * (que tiene `ventas_diseno.override_min_margin` para Gerencia/David), acá NO hay excepción — el
 * ticket lo describe como bloqueo sin salvedad.
 */
final class ServiceQuoteService
{
    public function __construct(
        private readonly ServiceQuoteFolioService $folios,
        private readonly ServiciosSettingsService $settings,
    ) {}

    /**
     * REQ-229 RN — solo Lider Servicios/Líder de Servicios (superadmin) o el técnico interno asignado a ESTE
     * ticket pueden generar/editar su Cotización. Mismo patrón que
     * `InspectionReportService::assertCanEdit()`.
     *
     * @throws ServiceQuoteAccessDeniedException
     */
    public function assertCanEdit(Ticket $ticket, User $user): void
    {
        $roleKey = $user->role?->key;

        if ($roleKey === RoleKeys::SUPERADMIN || $roleKey === RoleKeys::LIDER_SERVICIOS) {
            return;
        }

        if (($roleKey === RoleKeys::TECNICO || $roleKey === RoleKeys::TECNICO_GARANTIAS)
            && $ticket->internal_technician_id === $user->id
        ) {
            return;
        }

        throw new ServiceQuoteAccessDeniedException();
    }

    /**
     * La cotización más reciente del ticket (RN de REQ-234: "la más reciente" gobierna el ciclo
     * de vida).
     *
     * `created_at` de `service_quotes` tiene precisión de SEGUNDO (`timestamp(0)`, ver migración
     * de creación) — 2 cotizaciones creadas dentro del mismo segundo (ej. generar → rechazar →
     * generar de nuevo, en un mismo flujo de usuario o test) empatan en `created_at` y quedan en
     * orden no determinístico sin un desempate. `id` es monotónico y nunca empata — desempata
     * hacia la fila insertada después, que es siempre la real "más reciente".
     */
    public function current(Ticket $ticket): ?ServiceQuote
    {
        return ServiceQuote::where('ticket_id', $ticket->id)
            ->with('items')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * REQ-236 — historial completo del ticket, TODAS las versiones (incluida la Rechazada que dio
     * origen a una nueva), más recientes primero. Nunca filtra por técnico asignado — visibilidad
     * transversal a todo el equipo, mismo criterio que `globalHistory()` (REQ-250).
     *
     * SCRUM-292 — un borrador sin `numero` todavía (nadie hizo un guardado real, ver
     * `ensureFolioAssigned()`) no cuenta como una versión real de la cotización — no aparece acá.
     *
     * @return Collection<int, ServiceQuote>
     */
    public function historyForTicket(Ticket $ticket): Collection
    {
        return ServiceQuote::where('ticket_id', $ticket->id)
            ->whereNotNull('numero')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * REQ-235/236 — una versión puntual del historial (para "Ver/Imprimir documento" y para abrir
     * una fila del historial en modo solo lectura). `null` si el id no pertenece a este ticket —
     * nunca resuelve por id global sin la comprobación de pertenencia (evita que un ticket ajeno
     * filtre el número/total de otro).
     */
    public function findForTicket(Ticket $ticket, int $quoteId): ?ServiceQuote
    {
        return ServiceQuote::where('ticket_id', $ticket->id)
            ->where('id', $quoteId)
            ->with('items')
            ->first();
    }

    /**
     * REQ-250 — historial global, transversal a todo el equipo (RN: incluye TODAS las cotizaciones,
     * incluidas versiones reemplazadas, sin filtrar por técnico asignado — mismo criterio que
     * `historyForTicket()`). `$estado === null` = todas.
     *
     * SCRUM-292 — mismo criterio que `historyForTicket()`: un borrador sin `numero` todavía no
     * cuenta como una versión real, ni en las filas ni en los conteos.
     *
     * @return array{data: array<int, array<string, mixed>>, counts: array<string, int>}
     */
    public function globalHistory(?string $estado): array
    {
        $counts = ['all' => 0, ...array_fill_keys(ServiceQuote::ESTADOS, 0)];

        // Un solo recorrido para los conteos por estado (sin filtro), independiente del filtro
        // aplicado a `$data` — mismo criterio que REQ-222 RN5 (las tarjetas de estadísticas de
        // Tickets nunca se filtran por lo que el usuario esté viendo en la tabla).
        foreach (ServiceQuote::query()->select('estado')->whereNotNull('numero')->get() as $quote) {
            $counts['all']++;
            $counts[$quote->estado] = ($counts[$quote->estado] ?? 0) + 1;
        }

        $query = ServiceQuote::with('ticket')->whereNotNull('numero')->orderByDesc('created_at')->orderByDesc('id');
        if ($estado !== null) {
            $query->where('estado', $estado);
        }

        $data = $query->get()->map(fn (ServiceQuote $quote): array => [
            'id'         => $quote->id,
            'numero'     => $quote->numero,
            'estado'     => $quote->estado,
            'total'      => $quote->total,
            'created_at' => $quote->created_at->toIso8601String(),
            'sent_at'    => $quote->sent_at?->toIso8601String(),
            'ticket'     => $quote->ticket === null ? null : [
                'id'      => $quote->ticket->id,
                'numero'  => $quote->ticket->numero,
                'cliente' => $quote->ticket->cliente,
            ],
        ])->values()->all();

        return ['data' => $data, 'counts' => $counts];
    }

    /**
     * REQ-229 — notas informativas de precarga (no persistidas, solo para el frontend): productos
     * reclamados/afectados a considerar para reemplazo (redacción distinta si Retrofit) y aviso de
     * instalación subcontratada.
     *
     * @return list<string>
     */
    public function notes(Ticket $ticket): array
    {
        $notes = [];

        if ($ticket->ticketProducts()->exists()) {
            $notes[] = $ticket->tipo === Ticket::TIPO_RETROFIT
                ? 'Este ticket tiene productos a cambiar registrados en la inspección — considere cotizar su reemplazo.'
                : 'Este ticket tiene productos reclamados/afectados registrados — considere cotizar su reemplazo.';
        }

        if ($ticket->tipo_instalacion === Ticket::TIPO_INSTALACION_SUBCONTRACTED) {
            $notes[] = 'Esta instalación es subcontratada — recuerde agregar un ítem Subcontratado.';
        }

        return $notes;
    }

    /** REQ-229 RN — si el Informe de Inspección dejó una recomendación marcada, se precarga en Observaciones (editable). */
    public function recommendedObservations(Ticket $ticket): ?string
    {
        $report = InspectionReport::where('ticket_id', $ticket->id)->first();
        if ($report === null || $report->findings === null) {
            return null;
        }

        foreach ($report->findings as $finding) {
            if (! empty($finding['is_quote_recommendation']) && trim((string) ($finding['value'] ?? '')) !== '') {
                return $finding['value'];
            }
        }

        return null;
    }

    /**
     * REQ-229 RN1 — solo se puede generar si el Informe de Inspección está Completado o No aplica
     * (gate ya definido en REQ-218/219, reusado tal cual — `Ticket::INSPECTION_STATUSES_ALLOWING_CLOSE`).
     *
     * @throws ServiceQuoteActionBlockedException
     */
    private function assertInspectionGate(Ticket $ticket): void
    {
        if (! in_array($ticket->inspection_report_status, Ticket::INSPECTION_STATUSES_ALLOWING_CLOSE, true)) {
            throw new ServiceQuoteActionBlockedException(
                'No se puede generar la cotización hasta que el Informe de Inspección esté Completado o No aplique.',
            );
        }
    }

    /**
     * REQ-229 RN2 — "Generar cotización" (primera vez) o "Generar nueva cotización" (REQ-234, solo
     * si la más reciente está Rechazada).
     *
     * SCRUM-292 (rebote QA 2026-08-13) — el docblock original decía "folio asignado de inmediato,
     * la creación de la fila ES el primer guardado" — esa asunción era incorrecta: la fila se crea
     * al solo ABRIR el formulario (antes de que el usuario cargue nada), así que "Generar
     * cotización → cerrar sin tocar nada" dejaba un folio consumido y un borrador de $0 visible en
     * el historial y en el indicador del ticket. `numero` (y el `quote_status` del ticket) ahora se
     * asignan de forma perezosa recién en el primer guardado REAL — ver `ensureFolioAssigned()`,
     * llamado desde `save()`/`addItem()`/`updateItem()` — mismo mecanismo ya usado en Ventas &
     * Diseño (`Quote::folio`, ver `QuoteService::generate()`).
     *
     * @throws ServiceQuoteAccessDeniedException
     * @throws ServiceQuoteActionBlockedException
     */
    public function startNew(Ticket $ticket, User $user): ServiceQuote
    {
        $this->assertCanEdit($ticket, $user);
        $this->assertInspectionGate($ticket);

        return DB::transaction(function () use ($ticket, $user): ServiceQuote {
            $latest = ServiceQuote::where('ticket_id', $ticket->id)->orderByDesc('created_at')->orderByDesc('id')->lockForUpdate()->first();

            if ($latest !== null && $latest->estado !== ServiceQuote::ESTADO_REJECTED) {
                throw new ServiceQuoteActionBlockedException(
                    'Ya existe una cotización para este ticket. Solo se puede generar una nueva si la más reciente fue Rechazada.',
                );
            }

            $quote = new ServiceQuote();
            $quote->numero        = null;
            $quote->ticket_id     = $ticket->id;
            $quote->estado        = ServiceQuote::ESTADO_DRAFT;
            $quote->created_by    = $user->id;
            $quote->observations  = $this->recommendedObservations($ticket);
            $quote->save();

            return $quote->refresh()->load(['items']);
        });
    }

    /**
     * SCRUM-292 — fuente única de "esto ya dejó de ser un borrador vacío": asigna el folio (si
     * todavía no tiene) y recién ahí refleja el `quote_status` real en el ticket. Idempotente —
     * un segundo guardado sobre una cotización que ya tiene número no hace nada. Debe llamarse
     * DENTRO de la misma transacción que el guardado real que la disparó, con el ticket ya
     * resuelto (evita una query extra por caller).
     */
    private function ensureFolioAssigned(ServiceQuote $quote, User $user): void
    {
        if ($quote->numero !== null) {
            return;
        }

        $quote->numero = $this->folios->next();
        $quote->save();

        $ticket = $quote->ticket()->lockForUpdate()->first() ?? $quote->ticket;
        $ticket->quote_status = Ticket::QUOTE_STATUS_DRAFT;
        $ticket->save();

        event(new ServiceQuoteCreated(
            serviceQuoteId: $quote->id,
            numero:          $quote->numero,
            ticketId:        $quote->ticket_id,
            createdBy:       $user->id,
            tenant:          $this->requiredTenantSlug(),
        ));
    }

    /**
     * "Guardar" — persiste discount_percent/observations sobre el Borrador. Nunca cambia el estado
     * (REQ-234 RN: corrige el mockup, donde guardar promovía Borrador->Enviada automáticamente).
     *
     * @param array{discount_percent?: float|string, observations?: ?string} $data
     * @throws ServiceQuoteAccessDeniedException
     * @throws ServiceQuoteActionBlockedException
     */
    public function save(ServiceQuote $quote, array $data, User $user): ServiceQuote
    {
        $ticket = $quote->ticket;
        $this->assertCanEdit($ticket, $user);

        return DB::transaction(function () use ($quote, $data, $user): ServiceQuote {
            $quote = ServiceQuote::where('id', $quote->id)->lockForUpdate()->firstOrFail();
            $this->assertDraft($quote);

            if (array_key_exists('discount_percent', $data)) {
                $quote->discount_percent = (float) $data['discount_percent'];
            }
            if (array_key_exists('observations', $data)) {
                $quote->observations = $data['observations'];
            }
            $quote->save();
            $this->ensureFolioAssigned($quote, $user);

            $this->recalculateTotals($quote);

            return $quote->refresh()->load(['items']);
        });
    }

    /**
     * REQ-234 — único botón que promueve Borrador -> Enviada. Exige al menos 1 ítem (defensivo:
     * enviar una cotización vacía no tiene sentido comercial, mismo criterio que
     * `Quote::validationErrorsForGenerating(requireItems: true)` en Ventas & Diseño).
     *
     * @throws ServiceQuoteAccessDeniedException
     * @throws ServiceQuoteActionBlockedException
     */
    public function send(ServiceQuote $quote, User $user): ServiceQuote
    {
        $ticket = $quote->ticket;
        $this->assertCanEdit($ticket, $user);

        return DB::transaction(function () use ($quote, $ticket): ServiceQuote {
            $quote = ServiceQuote::where('id', $quote->id)->lockForUpdate()->firstOrFail();
            $this->assertDraft($quote);

            if ($quote->items()->doesntExist()) {
                throw new ServiceQuoteActionBlockedException('Agregue al menos un ítem antes de enviar la cotización.');
            }

            // SCRUM-292 — invariante: si ya tiene ítems, `addItem()` ya le asignó número (ver
            // `ensureFolioAssigned()`). Nunca debería pasar, pero se hace explícito en vez de
            // dejar que `numero` viaje como `string|null` sin narrow hacia adelante.
            if ($quote->numero === null) {
                throw new \LogicException('Una cotización con ítems no debería quedar sin número.');
            }

            $quote->estado     = ServiceQuote::ESTADO_SENT;
            $quote->sent_at    = now();
            // REQ-237 — congela el texto vigente de Condiciones en la cotización, SIEMPRE en este
            // único punto (nunca una referencia viva a la key de settings) — un cambio posterior
            // de Gerencia no debe alterar cotizaciones ya enviadas/aprobadas/rechazadas.
            $quote->conditions = $this->settings->conditionsCotizacionServicios();
            $quote->save();

            $ticket->quote_status = Ticket::QUOTE_STATUS_SENT;
            $ticket->save();

            event(new ServiceQuoteSent(
                serviceQuoteId: $quote->id,
                numero:          $quote->numero,
                ticketId:        $ticket->id,
                total:           (float) $quote->total,
                tenant:          $this->requiredTenantSlug(),
            ));

            return $quote->refresh()->load(['items']);
        });
    }

    /**
     * REQ-234 — Aprobar/Rechazar. Exclusivo de Lider Servicios/Líder de Servicios (role: en la ruta ya lo
     * garantiza; acá solo se valida el estado previo). `tickets.quote_status` se actualiza siempre
     * que ESTA sea la cotización más reciente del ticket (siempre lo es — REQ-234 solo permite
     * generar una nueva cuando la anterior ya está Rechazada, así que nunca hay 2 en estado
     * decidible en simultáneo).
     *
     * @throws ServiceQuoteActionBlockedException
     */
    public function decide(ServiceQuote $quote, string $estado, User $user): ServiceQuote
    {
        if (! in_array($estado, ServiceQuote::ESTADOS_DECISION, true)) {
            throw new \InvalidArgumentException('Estado de decisión inválido.');
        }

        return DB::transaction(function () use ($quote, $estado, $user): ServiceQuote {
            $quote = ServiceQuote::where('id', $quote->id)->lockForUpdate()->firstOrFail();

            if ($quote->estado !== ServiceQuote::ESTADO_SENT) {
                throw new ServiceQuoteActionBlockedException('Solo se puede Aprobar/Rechazar una cotización Enviada.');
            }

            // SCRUM-292 — invariante: solo se llega a ESTADO_SENT vía send(), que ya exige numero
            // no-null (ver su propio guard). Ver docblock de esa comprobación.
            if ($quote->numero === null) {
                throw new \LogicException('Una cotización Enviada no debería quedar sin número.');
            }

            $quote->estado      = $estado;
            $quote->decided_at  = now();
            $quote->save();

            $ticket = $quote->ticket;
            $ticket->quote_status = $estado === ServiceQuote::ESTADO_APPROVED
                ? Ticket::QUOTE_STATUS_APPROVED
                : Ticket::QUOTE_STATUS_REJECTED;
            $ticket->save();

            $eventClass = $estado === ServiceQuote::ESTADO_APPROVED ? ServiceQuoteApproved::class : ServiceQuoteRejected::class;
            event(new $eventClass(
                serviceQuoteId: $quote->id,
                numero:          $quote->numero,
                ticketId:        $ticket->id,
                decidedBy:       $user->id,
                tenant:          $this->requiredTenantSlug(),
            ));

            return $quote->refresh()->load(['items']);
        });
    }

    // ── Ítems ────────────────────────────────────────────────────────────────────────────────

    /**
     * @param array{
     *   tipo: string, catalog_product_id?: ?int, is_custom?: bool, external_technician_id?: ?int,
     *   description?: ?string, quantity: float|string, unit_price?: float|string|null,
     *   margin_percent?: float|string|null,
     * } $data
     * @throws ServiceQuoteAccessDeniedException
     * @throws ServiceQuoteActionBlockedException
     * @throws \InvalidArgumentException validación de negocio (duplicado, margen, técnico inactivo)
     */
    public function addItem(ServiceQuote $quote, array $data, User $user): ServiceQuoteItem
    {
        $this->assertCanEdit($quote->ticket, $user);

        return DB::transaction(function () use ($quote, $data, $user): ServiceQuoteItem {
            $quote = ServiceQuote::where('id', $quote->id)->lockForUpdate()->firstOrFail();
            $this->assertDraft($quote);

            $resolved = $this->resolveItemData($quote, $data);
            $item     = $quote->items()->create($resolved);

            $this->ensureFolioAssigned($quote, $user);
            $this->recalculateTotals($quote);

            return $item;
        });
    }

    /** @param array<string, mixed> $data */
    public function updateItem(ServiceQuote $quote, ServiceQuoteItem $item, array $data, User $user): ServiceQuoteItem
    {
        $this->assertCanEdit($quote->ticket, $user);

        return DB::transaction(function () use ($quote, $item, $data): ServiceQuoteItem {
            $quote = ServiceQuote::where('id', $quote->id)->lockForUpdate()->firstOrFail();
            $this->assertDraft($quote);

            // El tipo de una línea no se reasigna — mismo criterio que Ventas&Diseño (un ítem
            // "custom" no se convierte en catálogo ni viceversa editando in-place); para cambiar de
            // tipo, borrar y agregar de nuevo. Campos ausentes en $data (edición parcial, ej. solo
            // `quantity`) retienen el valor actual de la línea — sin este merge, actualizar solo la
            // cantidad de un ítem Producto perdía silenciosamente su `catalog_product_id` (se
            // resolvía como "Otro/referencia libre" al no venir el campo en el payload).
            $merged = [
                'tipo'                   => $item->tipo,
                'catalog_product_id'     => $data['catalog_product_id'] ?? $item->catalog_product_id,
                'is_custom'              => $data['is_custom'] ?? $item->is_custom,
                'external_technician_id' => $data['external_technician_id'] ?? $item->external_technician_id,
                'description'            => $data['description'] ?? $item->description,
                'quantity'               => $data['quantity'] ?? $item->quantity,
                'unit_price'             => array_key_exists('unit_price', $data) ? $data['unit_price'] : $item->unit_price,
                // `??`, no `array_key_exists` — a propósito distinto del resto de este merge. Con
                // `margin_percent` ahora oculto en el GET para quien no tiene
                // `servicios.quotes.view_cost_breakdown` (ver ServiceQuoteController::formatItem()),
                // ese usuario reenvía siempre `margin_percent: null` en cada edición (el campo del
                // formulario nunca tuvo el valor real para prellenar) — con `array_key_exists` eso
                // pisaba la línea al margen mínimo global en cada guardado, sin que el usuario lo
                // pidiera. Un valor explícito no-null sigue pudiendo cambiar el margen normalmente;
                // null/ausente ahora siempre retiene el valor ya guardado en la línea.
                'margin_percent'         => $data['margin_percent'] ?? $item->margin_percent,
                // SCRUM-781 (punto 3) — mismo criterio que margin_percent dos líneas arriba: para
                // subcontratado, cost_reference también está gateado por view_cost_breakdown en el
                // GET (ServiceQuoteController::formatItem()), así que un usuario sin ese permiso
                // nunca recibe el valor real para reenviarlo — `??` retiene el override ya
                // guardado en vez de resetearlo a tarifa_dia en cada guardado ajeno a este campo.
                'cost_reference'         => $data['cost_reference'] ?? $item->cost_reference,
            ];

            $resolved = $this->resolveItemData($quote, $merged, excludeItemId: $item->id);
            $item->update($resolved);

            $this->recalculateTotals($quote);

            return $item->refresh();
        });
    }

    public function deleteItem(ServiceQuote $quote, ServiceQuoteItem $item, User $user): void
    {
        $this->assertCanEdit($quote->ticket, $user);

        DB::transaction(function () use ($quote, $item): void {
            $quote = ServiceQuote::where('id', $quote->id)->lockForUpdate()->firstOrFail();
            $this->assertDraft($quote);

            $item->delete();
            $this->recalculateTotals($quote);
        });
    }

    /** @throws ServiceQuoteActionBlockedException */
    private function assertDraft(ServiceQuote $quote): void
    {
        if ($quote->estado !== ServiceQuote::ESTADO_DRAFT) {
            throw new ServiceQuoteActionBlockedException('Esta cotización ya fue enviada y no se puede editar.');
        }
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     * @throws \InvalidArgumentException
     */
    private function resolveItemData(ServiceQuote $quote, array $data, ?int $excludeItemId = null): array
    {
        $tipo = $data['tipo'] ?? null;
        if (! in_array($tipo, ServiceQuoteItem::TIPOS, true)) {
            throw new \InvalidArgumentException('Tipo de ítem inválido.');
        }

        return match ($tipo) {
            ServiceQuoteItem::TIPO_PRODUCT       => $this->resolveProductItem($quote, $data, $excludeItemId),
            ServiceQuoteItem::TIPO_LABOR         => $this->resolveLaborItem($data),
            ServiceQuoteItem::TIPO_SUBCONTRACTED => $this->resolveSubcontractedItem($data),
        };
    }

    /**
     * REQ-230. RN2: no se puede repetir el mismo catalog_product_id en dos líneas de la misma
     * cotización. RN3: "Otro (referencia libre)" (`is_custom`) sin costo de referencia, nunca
     * valida margen. RN4 (BLOQUEO DURO): margen contra `PricingSettings::min_margin_percent`.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function resolveProductItem(ServiceQuote $quote, array $data, ?int $excludeItemId): array
    {
        $isCustom = (bool) ($data['is_custom'] ?? false) || empty($data['catalog_product_id']);

        if ($isCustom) {
            $description = trim((string) ($data['description'] ?? ''));
            if ($description === '') {
                throw new \InvalidArgumentException('La descripción es obligatoria para un producto sin catálogo.');
            }

            return [
                'tipo'                    => ServiceQuoteItem::TIPO_PRODUCT,
                'catalog_product_id'      => null,
                'is_custom'               => true,
                'external_technician_id'  => null,
                'description'             => $description,
                'quantity'                => (float) ($data['quantity'] ?? 1),
                'unit_price'              => (float) ($data['unit_price'] ?? 0),
                'cost_reference'          => null,
                'margin_percent'          => null,
                'subtotal'                => round((float) ($data['unit_price'] ?? 0) * (float) ($data['quantity'] ?? 1), 2),
            ];
        }

        $productId = (int) $data['catalog_product_id'];
        $product   = CatalogProduct::find($productId);
        if ($product === null) {
            throw new \InvalidArgumentException('Producto de catálogo no encontrado.');
        }

        $duplicate = $quote->items()
            ->where('tipo', ServiceQuoteItem::TIPO_PRODUCT)
            ->where('catalog_product_id', $productId)
            ->when($excludeItemId !== null, fn ($q) => $q->where('id', '!=', $excludeItemId))
            ->exists();
        if ($duplicate) {
            throw new \InvalidArgumentException("Este producto ya está en la cotización — ajuste la cantidad en la línea existente ({$product->name}).");
        }

        $quantity   = (float) ($data['quantity'] ?? 1);
        $unitPrice  = isset($data['unit_price']) ? (float) $data['unit_price'] : $product->price_full;
        $costRef    = $product->costTotal();

        if ($costRef > 0 && $unitPrice > 0) {
            $marginPercent = round((($unitPrice - $costRef) / $unitPrice) * 100, 2);
            $minMargin     = PricingSettings::current()->min_margin_percent;

            if ($marginPercent < $minMargin) {
                throw new \InvalidArgumentException(sprintf(
                    'La línea "%s" queda con un margen de %s%%, por debajo del mínimo permitido (%s%%). Ajuste el precio o la cantidad.',
                    $product->name,
                    $this->formatPercent($marginPercent),
                    $this->formatPercent($minMargin),
                ));
            }
        }

        return [
            'tipo'                   => ServiceQuoteItem::TIPO_PRODUCT,
            'catalog_product_id'     => $product->id,
            'is_custom'              => false,
            'external_technician_id' => null,
            'description'            => $data['description'] ?? $product->name,
            'quantity'               => $quantity,
            'unit_price'             => $unitPrice,
            'cost_reference'         => $costRef,
            'margin_percent'         => null,
            'subtotal'               => round($unitPrice * $quantity, 2),
        ];
    }

    /**
     * REQ-231 — mano de obra: descripción libre, cantidad, precio. Sin catálogo, sin costo de
     * referencia, sin validación de margen (RN — "no tiene costo de referencia").
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function resolveLaborItem(array $data): array
    {
        $description = trim((string) ($data['description'] ?? ''));
        if ($description === '') {
            throw new \InvalidArgumentException('La descripción es obligatoria para un ítem de Mano de obra.');
        }

        $quantity  = (float) ($data['quantity'] ?? 1);
        $unitPrice = (float) ($data['unit_price'] ?? 0);

        return [
            'tipo'                   => ServiceQuoteItem::TIPO_LABOR,
            'catalog_product_id'     => null,
            'is_custom'              => false,
            'external_technician_id' => null,
            'description'            => $description,
            'quantity'               => $quantity,
            'unit_price'             => $unitPrice,
            'cost_reference'         => null,
            'margin_percent'         => null,
            'subtotal'               => round($unitPrice * $quantity, 2),
        ];
    }

    /**
     * REQ-232 — subcontratado: solo técnicos Activos. `quantity` son los "días cotizados". Margen
     * default = `PricingSettings::min_margin_percent` (mismo valor parametrizado reusado, nunca un
     * literal 30 propio) pero editable libremente por línea (a diferencia de Producto, acá NO hay
     * bloqueo duro). Precio final de línea = costo/día × (1 + margen/100) × días_cotizados —
     * único valor visible al cliente, bajo la descripción fija de RN.
     *
     * SCRUM-781 (punto 3) — el costo/día ahora es editable por ítem vía `cost_reference` en el
     * payload: default = `$technician->tarifa_dia` (mismo criterio ya usado por Producto, que
     * también reusa `cost_reference` como snapshot propio del ítem, nunca el maestro). Un override
     * explícito nunca toca `$technician->tarifa_dia` — aplica solo a esta cotización, tal como ya
     * lo hacía margin_percent.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function resolveSubcontractedItem(array $data): array
    {
        if (empty($data['external_technician_id'])) {
            throw new \InvalidArgumentException('Seleccione un técnico externo.');
        }

        $technician = ExternalTechnician::find((int) $data['external_technician_id']);
        if ($technician === null || $technician->estado !== ExternalTechnician::ESTADO_ACTIVE) {
            throw new \InvalidArgumentException('Solo se pueden seleccionar técnicos externos Activos.');
        }

        $diasCotizados = (float) ($data['quantity'] ?? 1);
        if ($diasCotizados <= 0) {
            throw new \InvalidArgumentException('Los días cotizados deben ser mayor a 0.');
        }

        $marginPercent = isset($data['margin_percent'])
            ? (float) $data['margin_percent']
            : PricingSettings::current()->min_margin_percent;

        $costoDia = isset($data['cost_reference'])
            ? (float) $data['cost_reference']
            : (float) $technician->tarifa_dia;
        if ($costoDia < 0) {
            throw new \InvalidArgumentException('El costo no puede ser negativo.');
        }
        $unitPrice = round($costoDia * (1 + $marginPercent / 100), 2);

        return [
            'tipo'                   => ServiceQuoteItem::TIPO_SUBCONTRACTED,
            'catalog_product_id'     => null,
            'is_custom'              => false,
            'external_technician_id' => $technician->id,
            'description'            => "Instalación subcontratada — {$technician->nombre} ({$technician->empresa})",
            'quantity'               => $diasCotizados,
            'unit_price'             => $unitPrice,
            'cost_reference'         => $costoDia,
            'margin_percent'         => $marginPercent,
            'subtotal'               => round($unitPrice * $diasCotizados, 2),
        ];
    }

    /** REQ-233 — recalcula y persiste subtotal/itbms/total. Debe llamarse dentro de una transacción externa. */
    private function recalculateTotals(ServiceQuote $quote): void
    {
        $subtotal = (float) $quote->items()->sum('subtotal');
        $totals   = ServiceQuote::computeTotals($subtotal, (float) $quote->discount_percent, (float) $this->settings->itbmsPercent());

        $quote->update($totals);
    }

    /** `30.0` -> "30", `28.5` -> "28.5" — sin ceros decimales de relleno (mismo formato que QuoteItemController). */
    private function formatPercent(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
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
