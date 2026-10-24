<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\VentasDiseno\Exceptions\AssociateInUseException;
use App\Modules\VentasDiseno\Models\Architect;
use App\Modules\VentasDiseno\Models\PipelineCard;
use App\Modules\VentasDiseno\Models\Quote;
use App\Modules\VentasDiseno\Services\ProjectAssociateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * SCRUM-1121 — vincular/desvincular asociados (base `architects`) a un proyecto (desde su tarjeta de Pipeline) o a una cotización. Crear un
 * asociado nuevo sigue siendo `POST /architects` (con su detección de duplicados); acá solo se elige uno ya existente. Una cotización con proyecto
 * escribe en el proyecto (una sola fuente de verdad); ver `ProjectAssociateService`.
 */
final class AssociateLinkController extends Controller
{
    public function __construct(private readonly ProjectAssociateService $associates) {}

    public function storeForQuote(Request $request, int $quoteId): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $quote = Quote::find($quoteId); // el filtro global esconde las de CotizaYA: 404
        if ($quote === null) {
            return response()->json(['message' => 'Not found'], 404);
        }
        if (! $this->canAccessQuote($quote, $user) || ! $this->projectCardIsEditable($quote, $user)) {
            return response()->json(['message' => trans('auth.unauthorized')], 403);
        }

        $data = $request->validate(['architect_id' => ['required', 'integer', Rule::exists('architects', 'id')]]);
        $this->associates->attachToQuote($quote, Architect::findOrFail((int) $data['architect_id']));

        return response()->json($this->list($this->associates->forQuote($quote)), 201);
    }

    public function destroyForQuote(Request $request, int $quoteId, int $architectId): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $quote = Quote::find($quoteId);
        if ($quote === null) {
            return response()->json(['message' => 'Not found'], 404);
        }
        if (! $this->canAccessQuote($quote, $user) || ! $this->projectCardIsEditable($quote, $user)) {
            return response()->json(['message' => trans('auth.unauthorized')], 403);
        }

        try {
            $this->associates->detachFromQuote($quote, $architectId);
        } catch (AssociateInUseException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($this->list($this->associates->forQuote($quote)));
    }

    public function storeForCard(Request $request, int $cardId): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $card = PipelineCard::with('salesProject')->find($cardId);
        if ($card === null || $card->salesProject === null) {
            return response()->json(['message' => 'Not found'], 404);
        }
        if (! $this->canAccessCard($card, $user) || ! $card->isEditableBy($user)) {
            return response()->json(['message' => trans('auth.unauthorized')], 403);
        }

        $data = $request->validate(['architect_id' => ['required', 'integer', Rule::exists('architects', 'id')]]);
        $this->associates->attachToProject($card->salesProject, Architect::findOrFail((int) $data['architect_id']));

        return response()->json($this->list($this->associates->forProject($card->salesProject)), 201);
    }

    public function destroyForCard(Request $request, int $cardId, int $architectId): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $card = PipelineCard::with('salesProject')->find($cardId);
        if ($card === null || $card->salesProject === null) {
            return response()->json(['message' => 'Not found'], 404);
        }
        if (! $this->canAccessCard($card, $user) || ! $card->isEditableBy($user)) {
            return response()->json(['message' => trans('auth.unauthorized')], 403);
        }

        try {
            $this->associates->detachFromProject($card->salesProject, $architectId);
        } catch (AssociateInUseException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($this->list($this->associates->forProject($card->salesProject)));
    }

    /**
     * @param iterable<Architect> $associates
     * @return array{data: list<array<string, mixed>>}
     */
    private function list(iterable $associates): array
    {
        $rows = [];
        foreach ($associates as $a) {
            $rows[] = ProjectAssociateService::format($a);
        }

        return ['data' => $rows];
    }

    /**
     * Los asociados son del PROYECTO: desde una cotización no se pueden editar los de un proyecto cuya tarjeta no es editable (Perdido o
     * Aprobado), igual que desde la propia tarjeta. Una cotización sin proyecto o sin tarjeta todavía no tiene esa restricción.
     */
    private function projectCardIsEditable(Quote $quote, User $user): bool
    {
        if ($quote->sales_project_id === null) {
            return true;
        }

        // Un proyecto puede tener más de una tarjeta (datos históricos): basta una no editable (Perdida/Aprobada) para bloquear.
        return PipelineCard::where('sales_project_id', $quote->sales_project_id)->get()
            ->every(fn (PipelineCard $card): bool => $card->isEditableBy($user));
    }

    private function canAccessQuote(Quote $quote, User $user): bool
    {
        return $user->canViewTeamV2('ventas_diseno') || $quote->owner_id === $user->id;
    }

    private function canAccessCard(PipelineCard $card, User $user): bool
    {
        // SCRUM-1139 — `ventas_diseno.pipeline.edit` da alcance de equipo SOLO sobre tarjetas del Pipeline (nunca sobre cotizaciones).
        return $user->canViewTeamV2('ventas_diseno') || PipelineCard::userHasPipelineEdit($user) || $card->owner_id === $user->id;
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
