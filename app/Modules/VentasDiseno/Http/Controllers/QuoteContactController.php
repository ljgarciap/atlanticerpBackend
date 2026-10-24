<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Http\Controllers;

use App\Models\User;
use App\Modules\VentasDiseno\Models\PipelineCard;
use App\Modules\VentasDiseno\Models\Quote;
use App\Modules\VentasDiseno\Services\QuoteContactCardSyncService;
use App\Modules\VentasDiseno\Models\SubClientContact;
use App\Shared\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

/**
 * REQ-031: agrega/quita contactos a una cotización. Los contactos en sí viven en
 * sub_client_contacts (buscar/crear pasa por SubClientContactController, ya
 * construido en Grupo 2) — este controller solo decide cuáles de esos contactos
 * están vinculados a ESTA cotización puntual. Es un pivot (quote_contacts), no un
 * modelo Eloquent propio — LogsAuditTrail (REQ-053) no lo cubre automáticamente,
 * se audita a mano acá.
 */
final class QuoteContactController extends Controller
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly QuoteContactCardSyncService $cardContactSync,
    ) {
    }

    public function store(Request $request, int $quoteId): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $quote = Quote::find($quoteId);
        if ($quote === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        if (! $this->canAccess($quote, $user)) {
            return response()->json(['message' => trans('auth.unauthorized')], 403);
        }


        /** @var array{sub_client_contact_id: int} $validated */
        $validated = $request->validate([
            'sub_client_contact_id' => ['required', 'integer', Rule::exists('sub_client_contacts', 'id')],
        ]);

        $contact = SubClientContact::find($validated['sub_client_contact_id']);
        if ($contact === null || $contact->sub_client_id !== $quote->sub_client_id) {
            return response()->json(['message' => 'El contacto no pertenece al Subcliente de la cotización'], 422);
        }

        $quote->contacts()->syncWithoutDetaching([$contact->id]);
        $this->audit->log('ventas_diseno.quote.contact_added', 'quote', $quote->id, null, ['sub_client_contact_id' => $contact->id]);

        // SCRUM-1121 — una cotización ya confirmada ya vive en Pipeline: el contacto agregado después también pasa a la tarjeta (solo agrega).
        // Un borrador nunca toca Pipeline (SCRUM-734): ahí la copia ocurre al confirmar.
        if ($quote->confirmed_at !== null && $quote->sales_project_id !== null) {
            $card = PipelineCard::where('sales_project_id', $quote->sales_project_id)->first();
            if ($card !== null) {
                $this->cardContactSync->sync($quote, $card);
            }
        }

        return response()->json($this->format($contact), 201);
    }

    public function destroy(Request $request, int $quoteId, int $contactId): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $quote = Quote::find($quoteId);
        if ($quote === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        if (! $this->canAccess($quote, $user)) {
            return response()->json(['message' => trans('auth.unauthorized')], 403);
        }


        $quote->contacts()->detach($contactId);
        $this->audit->log('ventas_diseno.quote.contact_removed', 'quote', $quote->id, ['sub_client_contact_id' => $contactId], null);

        return response()->json(null, 204);
    }

    private function canAccess(Quote $quote, User $user): bool
    {
        // ADR-006, Fase C Task 4 — canViewTeamV2() reemplaza hasRole(MANAGEMENT/SUPERADMIN)
        if ($user->canViewTeamV2('ventas_diseno')) {
            return true;
        }

        return $quote->owner_id === $user->id;
    }

    /** @return array<string, mixed> */
    private function format(SubClientContact $contact): array
    {
        return [
            'id' => $contact->id, 'name' => $contact->name,
            'role' => $contact->role, 'phone' => $contact->phone, 'email' => $contact->email,
        ];
    }

    private function resolveUser(Request $request): ?User
    {
        /** @var array{sub?: int}|null $payload */
        $payload = $request->attributes->get('jwt');

        if ($payload === null || ! isset($payload['sub'])) {
            return null;
        }

        return User::find((int) $payload['sub']);
    }
}
