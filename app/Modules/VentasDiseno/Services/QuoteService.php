<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Services;

use App\Modules\VentasDiseno\Events\QuoteConfirmed;
use App\Modules\VentasDiseno\Events\QuoteGenerated;
use App\Modules\VentasDiseno\Exceptions\QuoteActionBlockedException;
use App\Modules\VentasDiseno\Exceptions\QuoteValidationBlockedException;
use App\Modules\VentasDiseno\Models\PipelineCard;
use App\Modules\VentasDiseno\Models\Quote;
use App\Modules\VentasDiseno\Models\QuoteConditionsSettings;
use App\Modules\VentasDiseno\Models\QuoteItem;
use App\Modules\VentasDiseno\Models\SalesProject;
use App\Modules\VentasDiseno\Models\SubClient;
use App\Modules\VentasDiseno\Models\SubClientContact;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

final class QuoteService
{
    private const DETAIL_RELATIONS = [
        'masterClient', 'subClient', 'salesProject', 'owner', 'architect', 'contacts', 'deliveryDates',
        // REQ-039 — catalogProduct se precarga acá (no en el map de formatDetail) para no
        // disparar un N+1 por cada ítem al leer stock_quantity.
        'parts.items.catalogProduct',
    ];

    public function __construct(
        private readonly QuoteFolioService $folioService,
        private readonly PipelineService $pipelineService,
        private readonly ProjectAssociateService $associates,
        private readonly QuoteContactCardSyncService $cardContactSync,
    ) {}

    public function findDetailed(int $id): ?Quote
    {
        return Quote::with(self::DETAIL_RELATIONS)->find($id);
    }

    /**
     * REQ-050: precarga opcional desde una tarjeta de Pipeline (Cliente Master,
     * Subcliente, RUC, Proyecto y el primer contacto de la tarjeta, copiado como un
     * SubClientContact nuevo — PipelineCardContact y SubClientContact son tablas
     * distintas, no hay identidad compartida) o desde un Subcliente de Clientes
     * (Cliente Master, Subcliente, RUC). Los valores explícitos en $data siempre
     * ganan sobre la precarga (por si el frontend ya mandó algo).
     *
     * @param array{
     *   master_client_id?: int|null, sub_client_id?: int|null, sales_project_id?: int|null,
     *   ruc?: string|null, description?: string|null, architect_id?: int|null,
     *   delivery_type?: string|null, delivery_dates?: list<string>,
     * } $data
     */
    public function create(array $data, int $ownerId, ?int $fromPipelineCardId = null, ?int $fromSubClientId = null): Quote
    {
        return DB::transaction(function () use ($data, $ownerId, $fromPipelineCardId, $fromSubClientId): Quote {
            $prefill      = [];
            $pipelineCard = null;

            if ($fromPipelineCardId !== null) {
                $pipelineCard = PipelineCard::with(['contacts', 'subClient'])->find($fromPipelineCardId);
                if ($pipelineCard !== null) {
                    $prefill = [
                        'master_client_id' => $pipelineCard->master_client_id,
                        'sub_client_id'    => $pipelineCard->sub_client_id,
                        'sales_project_id' => $pipelineCard->sales_project_id,
                        'ruc'              => $pipelineCard->subClient?->tax_id,
                    ];
                }
            } elseif ($fromSubClientId !== null) {
                $subClient = SubClient::find($fromSubClientId);
                if ($subClient !== null) {
                    $prefill = [
                        'master_client_id' => $subClient->master_client_id,
                        'sub_client_id'    => $subClient->id,
                        'ruc'              => $subClient->tax_id,
                    ];
                }
            }

            // SCRUM-138: toda cotización nace con el texto de Condiciones vigente en
            // ese momento (QuoteConditionsSettings, editable globalmente en Configuración)
            // — se congela en la cotización como documento histórico, no vuelve a
            // cambiar si el default global se actualiza después.
            $quote = Quote::create(array_merge(
                ['conditions_text' => QuoteConditionsSettings::current()->text],
                $prefill,
                $this->fieldUpdates($data),
                ['owner_id' => $ownerId],
            ));

            // SCRUM-1121 — el arquitecto elegido y los asociados de un borrador sin proyecto viajan al proyecto cuando lo hay.
            $this->associates->mergeDraftIntoProject($quote);
            $this->associates->syncQuoteArchitect($quote);

            // SCRUM-734 — Borradores nunca tocan Pipeline (RN0.4): a diferencia del
            // mecanismo anterior (SCRUM-722, ver docblock de resolveLinkedCardForConfirm()
            // más abajo para el porqué se retiró), sales_project_id acá es un dato de
            // formulario más, sin ningún efecto en PipelineCard. Varios Borradores
            // pueden compartir el mismo proyecto sin ninguna coordinación especial.

            if (array_key_exists('delivery_dates', $data)) {
                foreach ($data['delivery_dates'] as $date) {
                    $quote->deliveryDates()->create(['date' => $date]);
                }
            }

            $firstCardContact = $pipelineCard?->contacts->first();
            if ($firstCardContact !== null && $quote->sub_client_id !== null) {
                $copied = SubClientContact::create([
                    'sub_client_id' => $quote->sub_client_id,
                    'name'          => $firstCardContact->name,
                    'role'          => $firstCardContact->role,
                    'phone'         => $firstCardContact->phone,
                    'email'         => $firstCardContact->email,
                ]);
                $quote->contacts()->attach($copied->id);
            }

            /** @var Quote $fresh */
            $fresh = $quote->fresh(self::DETAIL_RELATIONS);
            return $fresh;
        });
    }

    /**
     * SCRUM-1195 — «Diseñador» que imprime el PDF de la cotización: el Responsable del proyecto (dueño de la tarjeta del Pipeline), no
     * quien generó esa versión. Cuando Gerencia (Gerencia/Whil/Felix) crea una versión nueva sobre la cotización de un vendedor,
     * `quotes.owner_id` de esa fila es Gerencia (queda como «Generado por» en el historial de versiones, trazabilidad interna) pero el
     * documento que llega al cliente debe seguir diciendo el nombre del vendedor. Si Gerencia reasigna la tarjeta, el PDF sale con el
     * nuevo Responsable. Sin tarjeta (borrador sin confirmar) o una cotización que Gerencia armó desde cero, el nombre es el
     * de quien la generó, como siempre.
     *
     * @return array{id: int, name: string, phone: string|null}
     */
    public function pdfDesigner(Quote $quote): array
    {
        $card   = $quote->linkedPipelineCard();
        $person = $card !== null ? $card->owner : $quote->owner;

        return [
            'id'    => $person->id,
            'name'  => trim("{$person->first_name} {$person->last_name}"),
            'phone' => $person->phone,
        ];
    }

    /**
     * SCRUM-734 (reemplaza el mecanismo de clonado de SCRUM-722/105): decisión
     * confirmada con Luis (2026-08-06) — un Borrador nunca toca Pipeline sin
     * importar cuántos compartan `sales_project_id` (RN0.4); un proyecto es
     * siempre 1 sola PipelineCard, nunca se clona. La vinculación a Pipeline
     * ocurre EXCLUSIVAMENTE acá, en el momento de confirmar ("en firme"):
     *
     * - Si el proyecto todavía no tiene tarjeta, se crea directo en etapa
     *   "quote" (RN0.3 — "al generar se crea una tarjeta nueva en Pipeline, en
     *   etapa Cotización", ya no en "lead" como hacía el mecanismo anterior).
     * - Si ya tiene tarjeta en lead/design/quote, se resuelve tal cual (se avanza
     *   después en advanceCardToQuoteStage()) — esto es lo que RN2.3 llama
     *   "crear una versión nueva": una segunda cotización confirmándose sobre la
     *   MISMA tarjeta mientras sigue en Lead, Diseño o Cotización (SCRUM-1115: un
     *   proyecto restaurado desde Perdido parte de su historial en Lead/Diseño).
     * - Si ya avanzó a "proposal"/"approved" (RN2.4/RN2.6) o está "lost"
     *   (RN2.5 — hay que reactivarla primero), confirmar se bloquea con
     *   QuoteActionBlockedException. Nunca hay excepción para Gerencia/David acá —
     *   esa excepción es solo de margen (SCRUM-725), un concepto distinto.
     *
     * Conserva el `pg_advisory_xact_lock` de SCRUM-722 (ver historial en git
     * blame de este archivo para el porqué del lock — deadlock real reproducido
     * con `SalesProject::lockForUpdate()`, resuelto con el espacio de locks
     * separado de `pg_advisory_xact_lock`): sigue haciendo falta acá porque
     * varias confirmaciones concurrentes sobre el mismo proyecto sin tarjeta
     * previa podrían, sin el lock, crear más de una PipelineCard para el mismo
     * `sales_project_id`.
     *
     * @throws QuoteActionBlockedException si la tarjeta ya está congelada para
     *   nuevas confirmaciones (Propuesta/Aprobado/Perdido)
     */
    private function resolveLinkedCardForConfirm(Quote $quote): PipelineCard
    {
        DB::select('select pg_advisory_xact_lock(?)', [$quote->sales_project_id]);

        $card = PipelineCard::where('sales_project_id', $quote->sales_project_id)->first();

        if ($card === null) {
            $project = SalesProject::find($quote->sales_project_id);

            return PipelineCard::create([
                'sales_project_id' => $quote->sales_project_id,
                'stage'            => 'quote',
                'master_client_id' => $project?->subClient?->master_client_id,
                'sub_client_id'    => $project?->sub_client_id,
                'owner_id'         => $quote->owner_id,
                // SCRUM-884 — cotización confirmada sin tarjeta previa: el dueño de la
                // cotización es quien de hecho origina el proyecto (SCRUM-1187: crear un cliente
                // ya no crea tarjeta, así que esta es la vía normal de nacimiento).
                'created_by'       => $quote->owner_id,
                'stage_changed_at' => now(),
            ]);
        }

        if (in_array($card->stage, ['proposal', 'approved'], true)) {
            throw new QuoteActionBlockedException(
                'Este proyecto ya avanzó más allá de Cotización — no se pueden confirmar nuevas versiones. '
                . 'Una vez en Propuesta o Aprobado, la última cotización confirmada queda congelada.',
            );
        }

        if ($card->stage === 'lost') {
            throw new QuoteActionBlockedException(
                'Este proyecto está marcado como Perdido — reactívelo a Lead antes de confirmar una cotización nueva.',
            );
        }

        return $card;
    }

    /**
     * SCRUM-734 (RN2.2) — una cotización ya confirmada ("en firme") nunca se
     * edita directamente, sin excepción de rol/permiso (la excepción de margen
     * de Gerencia/David, SCRUM-725, es un concepto distinto y no aplica acá). La
     * única forma de modificar su contenido es "Usar como base para nueva
     * versión" (ver duplicate()), que crea una cotización NUEVA en Borrador.
     *
     * @param array{
     *   master_client_id?: int|null, sub_client_id?: int|null, sales_project_id?: int|null,
     *   ruc?: string|null, description?: string|null, architect_id?: int|null,
     *   delivery_type?: string|null, delivery_dates?: list<string>,
     * } $data
     *
     * @throws QuoteActionBlockedException si la cotización ya está confirmada
     */
    public function update(Quote $quote, array $data): Quote
    {
        if ($quote->confirmed_at !== null) {
            throw new QuoteActionBlockedException(
                'Una cotización generada no se puede editar directamente. Use "Usar como base para nueva versión".',
            );
        }

        return DB::transaction(function () use ($quote, $data): Quote {
            $previousDiscountMode = $quote->discount_mode;

            $updates = $this->fieldUpdates($data);

            // SCRUM-1164 — «Aplicar a todos»: el tipo de precio general rige sobre TODAS las partidas (antes una partida con tipo propio lo ignoraba).
            if (filter_var($data['clear_item_price_overrides'] ?? false, FILTER_VALIDATE_BOOLEAN) && array_key_exists('price_type', $data)) {   // acepta true/1/"1" igual que la validación `boolean`
                QuoteItem::whereIn('quote_part_id', $quote->parts()->pluck('id'))
                    ->whereNotNull('price_type_override')
                    ->get()
                    ->each(fn (QuoteItem $i) => $i->update(['price_type_override' => null]));   // por modelo: queda en la bitácora de auditoría
            }

            if ($updates !== []) {
                $quote->update($updates);
                // SCRUM-1121 — al asignarle proyecto, los asociados del borrador pasan al proyecto; el arquitecto elegido también es del proyecto.
                $this->associates->mergeDraftIntoProject($quote);
                // Solo si el arquitecto o el proyecto CAMBIARON de verdad: el formulario manda siempre todos los campos (con el mismo
                // architect_id de antes), y re-agregarlo en cada «Guardar borrador» hacía «resucitar» a un arquitecto que el usuario
                // había quitado de Asociados (Pre-QA SCRUM-1121).
                if ($quote->wasChanged('architect_id') || $quote->wasChanged('sales_project_id')) {
                    $this->associates->syncQuoteArchitect($quote);
                }
            }

            // SCRUM-734 — sales_project_id es un dato de formulario más acá también
            // (ver el mismo comentario en create()): ya no hay ensurePipelineLinkage()
            // que resolver, un Borrador puede cambiar de proyecto libremente.

            // SCRUM-716 (Pre-QA, hallazgo CRÍTICO) — sin esto, cambiar el Subcliente de
            // una cotización que ya tenía contactos vinculados dejaba esos contactos
            // pegados aunque pertenecieran a OTRO sub_client_id: sub_client_contacts no
            // se valida contra el nuevo dueño en ningún otro lado, así que el contacto
            // mal vinculado terminaba visible en el PDF real de un cliente distinto.
            // Antes esto era casi imposible de disparar (elegir un Subcliente EXISTENTE
            // no persistía hasta "Guardar borrador", que reconciliaba todo el form) —
            // el fix de "Contacto sin guardar el form completo" (misma sesión) lo volvió
            // trivial: elegir A, agregar contacto, elegir B, listo.
            if (array_key_exists('sub_client_id', $data) && $data['sub_client_id'] !== null) {
                $quote->contacts()->get()
                    ->each(function (SubClientContact $contact) use ($quote, $data): void {
                        if ($contact->sub_client_id !== $data['sub_client_id']) {
                            $quote->contacts()->detach($contact->id);
                        }
                    });
            }

            if (array_key_exists('delivery_dates', $data)) {
                $quote->deliveryDates()->delete();
                foreach ($data['delivery_dates'] as $date) {
                    $quote->deliveryDates()->create(['date' => $date]);
                }
            }

            if (array_key_exists('discount_mode', $data) && $data['discount_mode'] !== $previousDiscountMode) {
                $this->resetDiscountsForModeSwitch($quote, $data['discount_mode']);
            }

            /** @var Quote $fresh */
            $fresh = $quote->fresh(self::DETAIL_RELATIONS);
            return $fresh;
        });
    }

    /**
     * REQ-086: "Guardar borrador" — persiste los mismos campos que update(), pero
     * antes exige que todos los campos generales obligatorios estén completos
     * (todo REQ-047 excepto tener al menos 1 ítem y al menos 1 contacto — ver
     * docblock de Quote::validationErrorsForGenerating() para el porqué de
     * $requireContacts=false). Si falta algo, no se persiste nada —
     * QuoteValidationBlockedException se lanza dentro de la transacción, lo que
     * fuerza el rollback automático de Laravel antes de relanzarla.
     *
     * @param array{
     *   master_client_id?: int|null, sub_client_id?: int|null, sales_project_id?: int|null,
     *   ruc?: string|null, description?: string|null, architect_id?: int|null,
     *   delivery_type?: string|null, delivery_dates?: list<string>,
     * } $data
     *
     * @throws QuoteValidationBlockedException
     * @throws QuoteActionBlockedException si la cotización ya está confirmada (ver update())
     */
    public function saveDraft(Quote $quote, array $data): Quote
    {
        return DB::transaction(function () use ($quote, $data): Quote {
            $updated = $this->update($quote, $data);

            $missing = $updated->validationErrorsForGenerating(requireItems: false, requireContacts: false);
            if ($missing !== []) {
                throw new QuoteValidationBlockedException($missing);
            }

            return $updated;
        });
    }

    /**
     * REQ-047/048/051: "Guardar y generar cotización" — no acepta cambios de
     * campos (esos ya se guardaron vía update()/saveDraft() mientras se llenaba el
     * formulario), solo valida el estado ya persistido (ahora sí exige ≥1 ítem) y,
     * si es válido, asigna folio atómico (una sola vez — generar dos veces no
     * reasigna folio) y marca generated_at.
     *
     * SCRUM-723 (2026-08-03, reemplaza el fix de SCRUM-105): "generar" ya NO
     * registra la cotización en Pipeline/Reportes/Dashboard — solo le asigna folio
     * para poder numerar la Vista Previa/PDF. Antes este método hacía las dos cosas
     * juntas, lo que significaba que abrir la Vista Previa ya contaba como
     * oficial aunque el usuario nunca confirmara nada. Ahora eso pasa recién en
     * confirm(), disparado por el nuevo botón "Guardar" (con modal de
     * confirmación) — ver docblock de confirm() para el resto del flujo.
     *
     * SCRUM-734 (RN10) — "margen" ahora es parte de esta misma lista de
     * faltantes (ver Quote::validationErrorsForGenerating()); $skipMarginCheck
     * es la excepción de Gerencia/David, resuelta por el controller vía
     * `ventas_diseno.override_min_margin` — acá no se conoce al usuario.
     *
     * @throws QuoteValidationBlockedException
     */
    public function generate(Quote $quote, bool $skipMarginCheck = false): Quote
    {
        return DB::transaction(function () use ($quote, $skipMarginCheck): Quote {
            $missing = $quote->validationErrorsForGenerating(requireItems: true, skipMarginCheck: $skipMarginCheck);
            if ($missing !== []) {
                throw new QuoteValidationBlockedException($missing);
            }

            if ($quote->folio === null) {
                $quote->update(['folio' => $this->folioService->next(), 'generated_at' => now()]);

                event(new QuoteGenerated(
                    quoteId: $quote->id,
                    salesProjectId: $quote->sales_project_id,
                    ownerId: $quote->owner_id,
                    folio: (string) $quote->folio,
                    tenant: $this->requiredTenantSlug(),
                ));
            }

            /** @var Quote $fresh */
            $fresh = $quote->fresh(self::DETAIL_RELATIONS);
            return $fresh;
        });
    }

    /**
     * SCRUM-723: nuevo botón "Guardar" en la Vista Previa — recién acá la
     * cotización queda registrada oficialmente (`confirmed_at`), lo que la saca de
     * `documentStatus() === 'draft'` y por lo tanto la hace contar en
     * Cotizaciones/Pipeline/Reportes/Dashboard/indicadores. Exige que ya se haya
     * generado (folio asignado vía generate()) — si no, no hay nada que confirmar,
     * el frontend nunca ofrece este botón antes de generar. Idempotente: confirmar
     * dos veces no reasigna confirmed_at ni vuelve a mover la tarjeta.
     *
     * SCRUM-734 — esto es también, sin un mecanismo aparte, el momento en que se
     * establece o avanza la vinculación a Pipeline (resolveLinkedCardForConfirm(),
     * ver su docblock): crea la tarjeta en etapa "quote" si el proyecto todavía no
     * tenía una (RN0.3), o resuelve/avanza la existente (RN0.2, RN2.3 "nueva
     * versión"). Nunca retrocede una tarjeta que ya esté en "quote" o más adelante,
     * y nunca clona el proyecto — 1 proyecto es siempre 1 sola tarjeta. A propósito
     * NO pasa por PipelineCard::validationErrorForTransitionTo() — es una
     * transición del sistema, no un click manual de "siguiente etapa" en el Kanban.
     *
     * @throws QuoteValidationBlockedException si todavía no se generó folio
     * @throws QuoteActionBlockedException si la tarjeta vinculada ya está congelada
     *   (Propuesta/Aprobado/Perdido) para nuevas confirmaciones
     */
    public function confirm(Quote $quote): Quote
    {
        return DB::transaction(function () use ($quote): Quote {
            if ($quote->folio === null) {
                throw new QuoteValidationBlockedException(['La cotización debe generarse antes de guardarse']);
            }

            if ($quote->confirmed_at === null) {
                $card = $this->resolveLinkedCardForConfirm($quote);

                // SCRUM-1121 — los contactos de la cotización pasan al campo «Contacto» de la tarjeta (solo agrega, sin duplicar).
                $this->cardContactSync->sync($quote, $card);

                // SCRUM-734 — antes de marcar ESTA como confirmada, para poder distinguir
                // "primera confirmación del proyecto" de "nueva versión" en el evento.
                $isNewVersion = Quote::where('sales_project_id', $quote->sales_project_id)
                    ->whereNotNull('confirmed_at')
                    ->exists();

                $quote->update(['confirmed_at' => now()]);
                $this->advanceCardToQuoteStage($card, $quote);

                event(new QuoteConfirmed(
                    quoteId: $quote->id,
                    salesProjectId: (int) $quote->sales_project_id,
                    pipelineCardId: $card->id,
                    ownerId: $quote->owner_id,
                    folio: (string) $quote->folio,
                    grandTotal: $quote->grandTotal(),
                    isNewVersion: $isNewVersion,
                    tenant: $this->requiredTenantSlug(),
                ));
            }

            /** @var Quote $fresh */
            $fresh = $quote->fresh(self::DETAIL_RELATIONS);
            return $fresh;
        });
    }

    /**
     * SCRUM-734 (RN2.3/RN2.5) — "Usar como base para nueva versión": copia los
     * datos maestros/partidas/ítems/contactos/fechas de una cotización YA
     * confirmada a una cotización nueva, en Borrador, sobre el MISMO proyecto
     * (mismo `sales_project_id` — no hay clonado de proyecto, RN0/RN1). Solo
     * disponible mientras la tarjeta vinculada está en Lead, Diseño o Cotización
     * (SCRUM-1115: un proyecto restaurado desde Perdido vuelve a Lead/Diseño con su
     * historial de cotizaciones y la única salida era una cotización en blanco; la
     * entrada a Cotización sigue siendo confirmar una cotización real, RN5, así que
     * la versión nueva mueve la tarjeta igual que cualquier otra al confirmarse).
     * Una vez en Propuesta/Aprobado queda congelada (RN2.4/RN2.6), y en Perdido hay
     * que reactivar primero (RN2.5) — mismo criterio que `resolveLinkedCardForConfirm`,
     * pero de solo lectura acá (no crea tarjeta: duplicar no confirma nada).
     *
     * @throws QuoteActionBlockedException si la cotización origen no está
     *   confirmada, o si la tarjeta vinculada está en Perdido, Propuesta o Aprobado
     */
    public function duplicate(Quote $source, int $ownerId): Quote
    {
        if ($source->confirmed_at === null) {
            throw new QuoteActionBlockedException(
                'Solo se puede usar como base una cotización ya generada y confirmada.',
            );
        }

        $card = $source->linkedPipelineCard();
        if ($card === null) {
            throw new QuoteActionBlockedException(
                'Solo se puede crear una versión nueva de una cotización con un proyecto en el Pipeline.',
            );
        }
        if ($card->stage === 'lost') {
            throw new QuoteActionBlockedException(
                'Este proyecto está marcado como Perdido — reactívelo a Lead antes de crear una versión nueva.',
            );
        }
        if (! in_array($card->stage, ['lead', 'design', 'quote'], true)) {
            throw new QuoteActionBlockedException(
                'Este proyecto ya avanzó más allá de Cotización — la última cotización confirmada quedó congelada '
                . 'y no admite versiones nuevas.',
            );
        }

        return DB::transaction(function () use ($source, $ownerId): Quote {
            $quote = Quote::create([
                'master_client_id'         => $source->master_client_id,
                'sub_client_id'            => $source->sub_client_id,
                'sales_project_id'         => $source->sales_project_id,
                'ruc'                      => $source->ruc,
                'description'              => $source->description,
                'owner_id'                 => $ownerId,
                'architect_id'             => $source->architect_id,
                'delivery_type'            => $source->delivery_type,
                'price_type'               => $source->price_type,
                'discount_mode'            => $source->discount_mode,
                'global_discount_percent'  => $source->global_discount_percent,
                'observations'             => $source->observations,
                'includes_installation'    => $source->includes_installation,
                'discount_totals_type'     => $source->discount_totals_type,
                'discount_totals_value'    => $source->discount_totals_value,
                // SCRUM-138 — una versión nueva es un documento nuevo: se congela el
                // texto de Condiciones VIGENTE ahora, igual que create(), no el que
                // la cotización origen tenía congelado.
                'conditions_text'          => QuoteConditionsSettings::current()->text,
            ]);

            foreach ($source->deliveryDates as $date) {
                $quote->deliveryDates()->create(['date' => $date->date]);
            }

            $quote->contacts()->attach($source->contacts->pluck('id'));

            foreach ($source->parts as $sourcePart) {
                $part = $quote->parts()->create(['name' => $sourcePart->name, 'position' => $sourcePart->position]);

                foreach ($sourcePart->items as $sourceItem) {
                    $part->items()->create([
                        'catalog_product_id'  => $sourceItem->catalog_product_id,
                        'is_custom'           => $sourceItem->is_custom,
                        'reference'           => $sourceItem->reference,
                        'description'         => $sourceItem->description,
                        'quantity'            => $sourceItem->quantity,
                        'unit_price'          => $sourceItem->unit_price,
                        'cost'                => $sourceItem->cost,
                        'price_type_override' => $sourceItem->price_type_override,
                        'discount_percent'    => $sourceItem->discount_percent,
                    ]);
                }
            }

            /** @var Quote $fresh */
            $fresh = $quote->fresh(self::DETAIL_RELATIONS);
            return $fresh;
        });
    }

    /**
     * SCRUM-734 — historial de versiones de un proyecto: todas las cotizaciones
     * CONFIRMADAS que comparten `sales_project_id` con $quote (incluida ella
     * misma si aplica), ordenadas por `confirmed_at` para numerar versiones
     * (RN1.3 — cada versión es una fila independiente). Los Borradores no son
     * "versiones" en este sentido — son documentos de trabajo previos a generar.
     *
     * @return array{versions: Collection<int, Quote>, card_stage: string|null}
     */
    public function versionsFor(Quote $quote): array
    {
        if ($quote->sales_project_id === null) {
            return ['versions' => new Collection(), 'card_stage' => null];
        }

        // parts.items eager-cargado para que grandTotal() (usado por el controller
        // al formatear cada versión) no dispare un N+1 por versión.
        // orderBy('confirmed_at') solo, sin desempate, causaba orden no determinista cuando 2
        // versiones se confirmaban dentro del mismo tick de reloj (Postgres no garantiza orden
        // estable entre empates) — hallazgo real: test_versions_lista_confirmadas_con_numero_y_
        // etapa_de_tarjeta fallaba intermitente en la suite completa (nunca aislado), ver
        // feedback_quotecontrollertest_versions_order_dependent_flake. `id` como desempate es
        // determinista siempre (autoincremental, refleja el orden real de creación).
        $versions = Quote::with(['owner', 'parts.items'])
            ->where('sales_project_id', $quote->sales_project_id)
            ->whereNotNull('confirmed_at')
            ->orderBy('confirmed_at')
            ->orderBy('id')
            ->get();

        $card = PipelineCard::where('sales_project_id', $quote->sales_project_id)->first();

        return ['versions' => $versions, 'card_stage' => $card?->stage];
    }

    /**
     * SCRUM-105/723: mueve la tarjeta a "quote" con el total ya calculado, nunca
     * retrocediendo una que ya esté en "quote" o más adelante. Debe llamarse dentro
     * de una transacción externa.
     */
    private function advanceCardToQuoteStage(PipelineCard $card, Quote $quote): PipelineCard
    {
        $card->update(['amount' => $quote->grandTotal()]);
        $this->transferDeliveryToCard($card, $quote);

        $stageOrder   = PipelineCard::stageOrder();
        $currentIndex = array_search($card->stage, $stageOrder, true);
        $quoteIndex   = array_search('quote', $stageOrder, true);

        if ($currentIndex !== false && $quoteIndex !== false && $currentIndex < $quoteIndex) {
            $card = $this->pipelineService->changeStage($card, 'quote');
        }

        /** @var PipelineCard $fresh */
        $fresh = $card->fresh();
        return $fresh;
    }

    /**
     * SCRUM-1122 — la Fecha estimada de entrega de la tarjeta sale de la cotización que se confirma (antes había que volver
     * a teclearla en el detalle del proyecto):
     * - Única + fecha → la tarjeta queda `single` con esa fecha.
     * - Parcial + fechas → `partial` con TODAS las fechas, en el orden en que se registraron en la cotización.
     * - «Por definir» (o sin fechas) → no se toca la tarjeta: ni se traslada ni se borra nada, y nunca se inventa una fecha.
     * La cotización manda: si la tarjeta ya tenía otras fechas, quedan las de la cotización (también en una versión nueva).
     * Se reutiliza `PipelineService::update()`, que sincroniza por diferencia: una fecha que se conserva mantiene su id y sus
     * asignaciones de producto (SCRUM-1027). Debe llamarse dentro de la transacción de `confirm()`.
     */
    private function transferDeliveryToCard(PipelineCard $card, Quote $quote): void
    {
        if (! in_array($quote->delivery_type, ['single', 'partial'], true)) {
            return;
        }

        // array_unique: dos fechas iguales en la cotización no deben crear dos filas iguales en la tarjeta.
        $dates = array_values(array_unique($quote->deliveryDates()->orderBy('id')->get()
            ->map(fn ($d): string => $d->date->toDateString())
            ->all()));

        if ($dates === []) {
            return;
        }

        $this->pipelineService->update($card, ['delivery_type' => $quote->delivery_type, 'delivery_dates' => $dates]);
    }

    /**
     * REQ-041: al cambiar de modo, los valores del modo que se deja de usar se
     * reinician a 0 — nunca quedan aplicando en segundo plano.
     */
    private function resetDiscountsForModeSwitch(Quote $quote, string $newMode): void
    {
        if ($newMode === 'global') {
            QuoteItem::whereHas('quotePart', fn ($q) => $q->where('quote_id', $quote->id))
                ->update(['discount_percent' => 0]);
        } else {
            $quote->update(['global_discount_percent' => 0]);
        }
    }

    /** Mismo patrón que PipelineService::requiredTenantSlug() — ver ahí el porqué. */
    private function requiredTenantSlug(): string
    {
        $tenant = Tenant::current();

        if ($tenant === null) {
            throw new \RuntimeException('Cannot dispatch domain event without an active tenant.');
        }

        return $tenant->slug;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function fieldUpdates(array $data): array
    {
        $fields = [
            'master_client_id', 'sub_client_id', 'sales_project_id',
            'ruc', 'description', 'architect_id', 'delivery_type',
            'price_type', 'discount_mode', 'global_discount_percent',
            'observations', 'includes_installation', 'discount_totals_type',
            'discount_totals_value',
        ];

        $updates = [];
        foreach ($fields as $field) {
            if (array_key_exists($field, $data)) {
                $updates[$field] = $data[$field];
            }
        }

        return $updates;
    }
}
