<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\VentasDiseno\Models\CotizaYaSettings;
use App\Modules\VentasDiseno\Models\MasterClient;
use App\Modules\VentasDiseno\Models\Quote;
use App\Modules\VentasDiseno\Models\QuoteConditionsSettings;
use App\Modules\VentasDiseno\Models\SubClient;
use App\Modules\VentasDiseno\Models\SubClientContact;
use App\Modules\VentasDiseno\Services\CotizaYaService;
use App\Modules\VentasDiseno\Services\QuotePdfService;
use App\Modules\VentasDiseno\Services\QuoteService;
use App\Shared\Http\Pagination;
use App\Modules\VentasDiseno\Exceptions\QuoteValidationBlockedException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * SCRUM-1120 — CotizaYA: cotizaciones de venta chica, independientes del flujo comercial. Todas las rutas pasan por
 * el middleware `cotizaya.context` (ver `EnsureCotizaYaContext`), que es lo único que hace visibles estas cotizaciones.
 * Partidas, ítems, contactos y PDF reutilizan los controladores de Cotizaciones tal cual (rutas gemelas bajo
 * `/cotizaya/{quoteId}/...`); este controlador solo cubre lo propio: contexto del formulario, listado, crear un
 * borrador, editar los campos propios y Generar.
 *
 * Nunca crea Lead, SalesProject ni tarjeta de Pipeline y nunca llama a `QuoteService::confirm()`.
 */
final class CotizaYaController extends Controller
{
    public function __construct(
        private readonly CotizaYaService $service,
        private readonly QuoteService $quoteService,
        private readonly QuoteController $quotes,
        private readonly QuotePdfService $pdf,
    ) {}

    /** Cliente fijo, sus contactos y si el entorno ya está configurado — lo que necesita el formulario antes de crear nada. */
    public function context(Request $request): JsonResponse
    {
        $settings = CotizaYaSettings::current();
        if ($settings === null) {
            return response()->json(['configured' => false, 'master_client' => null, 'sub_client' => null, 'contacts' => [], 'default_conditions_text' => QuoteConditionsSettings::current()->text]);
        }

        $master = MasterClient::find($settings->master_client_id);
        $sub    = SubClient::find($settings->sub_client_id);

        return response()->json([
            'configured'    => $master !== null && $sub !== null,
            'master_client' => $master !== null ? ['id' => $master->id, 'name' => $master->name] : null,
            'sub_client'    => $sub !== null ? ['id' => $sub->id, 'business_name' => $sub->business_name] : null,
            // Condiciones vigentes: el formulario las muestra antes de que exista el borrador (se congelan al crearlo).
            'default_conditions_text' => QuoteConditionsSettings::current()->text,
            'contacts'      => $sub === null ? [] : SubClientContact::query()->where('sub_client_id', $sub->id)
                ->orderBy('name')->get()->map(fn (SubClientContact $c): array => [
                    'id' => $c->id, 'name' => $c->name, 'role' => $c->role, 'phone' => $c->phone, 'email' => $c->email,
                ])->values(),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $pagination = Pagination::params($request, [], 'generated_at', 'desc');
        $search     = trim((string) $request->query('search', ''));

        $query = Quote::query()
            ->where('origin', Quote::ORIGIN_COTIZAYA)
            ->whereNotNull('folio')
            ->with(['owner', 'parts.items.catalogProduct'])
            ->orderByDesc('generated_at')
            ->orderByDesc('id');

        // Mismo alcance Mías/Equipo que Cotizaciones: el vendedor ve las suyas; vista de equipo, todas.
        if (! $user->canViewTeamV2('ventas_diseno')) {
            $query->where('owner_id', $user->id);
        }

        if ($search !== '') {
            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $search) . '%';
            $query->where(function ($q) use ($like): void {
                $q->where('folio', 'ilike', $like)
                    ->orWhere('cotizaya_customer_name', 'ilike', $like)
                    ->orWhere('cotizaya_customer_phone', 'ilike', $like)
                    ->orWhere('description', 'ilike', $like);
            });
        }

        $paginator = $query->paginate($pagination['per_page'], ['*'], 'page', $pagination['page']);

        return response()->json([
            'data' => $paginator->getCollection()->map(fn (Quote $q): array => [
                'id'             => $q->id,
                'folio'          => $q->folio,
                'customer_name'  => $q->cotizaya_customer_name,
                'customer_phone' => $q->cotizaya_customer_phone,
                'description'    => $q->description,
                'owner'          => ['id' => $q->owner->id, 'name' => trim("{$q->owner->first_name} {$q->owner->last_name}")],
                'generated_at'   => $q->generated_at?->toIso8601String(),
                'grand_total'    => $q->grandTotal(),
            ])->values(),
            'meta' => Pagination::meta($paginator),
        ]);
    }

    /** Crea el borrador (con el cliente fijo) para poder agregar partidas; se llama al primer dato real, nunca al abrir el formulario. */
    public function store(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $data = $this->validateOwnFields($request);

        $quote = $this->service->createDraft($data, $user->id);
        if ($quote === null) {
            return response()->json(['message' => CotizaYaService::NOT_CONFIGURED_MESSAGE], 422);
        }

        return response()->json($this->detail($quote->id, $user), 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $quote = $this->quoteService->findDetailed($id);
        if ($quote === null) {
            return response()->json(['message' => 'Not found'], 404);
        }
        if (! $this->canAccess($quote, $user)) {
            return response()->json(['message' => trans('auth.unauthorized')], 403);
        }

        return response()->json($this->quotes->detailPayload($quote, $user));
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $quote = $this->quoteService->findDetailed($id);
        if ($quote === null) {
            return response()->json(['message' => 'Not found'], 404);
        }
        if (! $this->canAccess($quote, $user)) {
            return response()->json(['message' => trans('auth.unauthorized')], 403);
        }
        if ($quote->confirmed_at !== null) {
            return response()->json(['message' => 'Una cotización de CotizaYA ya generada no se puede editar.'], 422);
        }

        $data = $this->validateOwnFields($request, withConditions: true);
        if (array_key_exists('conditions_text', $data) && ! $user->hasPermission('ventas_diseno.edit.conditions')) {
            return response()->json(['message' => trans('auth.unauthorized')], 403);
        }

        $quote->update($data);

        return response()->json($this->detail($quote->id, $user));
    }

    public function generate(Request $request, int $id): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $quote = $this->quoteService->findDetailed($id);
        if ($quote === null) {
            return response()->json(['message' => 'Not found'], 404);
        }
        if (! $this->canAccess($quote, $user)) {
            return response()->json(['message' => trans('auth.unauthorized')], 403);
        }

        try {
            $this->service->generate($quote, $user->hasPermission('ventas_diseno.override_min_margin'));
        } catch (QuoteValidationBlockedException $e) {
            return response()->json(['valid' => false, 'missing' => $e->missing], 422);
        }

        return response()->json($this->detail($quote->id, $user));
    }

    /**
     * PDF propio (no `QuoteController::pdf`): aquel además deja descargar a los roles de Admin&Contab cualquier cotización,
     * y una CotizaYA solo la ve su dueño o quien tenga vista de equipo, igual que el detalle.
     */
    public function pdf(Request $request, int $id): \Symfony\Component\HttpFoundation\Response
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            abort(401, trans('auth.unauthenticated'));
        }

        $quote = $this->quoteService->findDetailed($id);
        if ($quote === null) {
            abort(404, 'Not found');
        }
        if (! $this->canAccess($quote, $user)) {
            abort(403, trans('auth.unauthorized'));
        }
        if ($quote->folio === null) {
            abort(422, 'La CotizaYA todavía no se generó.');
        }

        return $this->pdf->generate($this->quotes->detailPayload($quote, $user), $request->boolean('external'))
            ->download('Cotizacion-' . $quote->folio . '.pdf');
    }

    /** @return array<string, mixed> */
    private function detail(int $id, User $user): array
    {
        /** @var Quote $quote */
        $quote = $this->quoteService->findDetailed($id);

        return $this->quotes->detailPayload($quote, $user);
    }

    /**
     * @return array<string, mixed>
     */
    private function validateOwnFields(Request $request, bool $withConditions = false): array
    {
        $rules = [
            'cotizaya_customer_name'  => ['sometimes', 'nullable', 'string', 'max:255'],
            'cotizaya_customer_phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'description'             => ['sometimes', 'nullable', 'string', 'max:2000'],
            'observations'            => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
        if ($withConditions) {
            $rules['conditions_text'] = ['sometimes', 'nullable', 'string', 'max:10000'];
        }

        /** @var array<string, mixed> $validated */
        $validated = $request->validate($rules);

        return $validated;
    }

    private function canAccess(Quote $quote, User $user): bool
    {
        if ($user->canViewTeamV2('ventas_diseno')) {
            return true;
        }

        return $quote->owner_id === $user->id;
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
