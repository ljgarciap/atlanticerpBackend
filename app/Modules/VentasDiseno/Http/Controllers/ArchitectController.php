<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Http\Controllers;

use App\Models\User;
use App\Modules\VentasDiseno\Http\Requests\StoreArchitectRequest;
use App\Modules\VentasDiseno\Http\Requests\UpdateArchitectRequest;
use App\Modules\VentasDiseno\Models\Architect;
use App\Modules\VentasDiseno\Services\AssociateDuplicateFinder;
use App\Modules\VentasDiseno\Models\PipelineCard;
use App\Modules\VentasDiseno\Models\Quote;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Routing\Controller;

/**
 * REQ-030 / SCRUM-122: buscar/crear un Arquitecto acotado a un Subcliente ya
 * elegido (mismo patrón que SalesProjectController) — ya no es un directorio
 * global... salvo para la pantalla "Arquitectos" (SCRUM-907), que SÍ necesita un
 * directorio global de solo consulta/edición limitada. index() sirve ambos casos
 * con el mismo endpoint: sub_client_id presente = picker acotado de siempre
 * (dentro de Cotización); ausente = listado completo para esa pantalla.
 */
final class ArchitectController extends Controller
{
    public function __construct(private readonly AssociateDuplicateFinder $duplicates) {}

    // SCRUM-907 — tope defensivo del listado global (hoy 15 arquitectos reales en dev, lista
    // chica sin paginación de backend), mismo criterio anti-crecimiento-sin-límite que dejó
    // SCRUM-917 como lección: nunca un listado sin tope aunque hoy sea chico.
    private const GLOBAL_LIST_LIMIT = 500;

    public function index(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $subClientId = $request->query('sub_client_id');
        $query = Architect::query()->orderBy('name');

        // SCRUM-1121 — `type` ausente = solo arquitectos (lo que siempre devolvió este endpoint: pantalla Arquitectos y comisiones de
        // Admin&Cont no deben ver de repente contratistas); `all` = toda la base de asociados; o un tipo puntual.
        $type = $request->query('type');
        if ($type !== null && $type !== 'all') {
            if (! is_string($type) || ! in_array($type, Architect::TYPES, true)) {
                return response()->json(['message' => 'type inválido'], 422);
            }
            $query->where('type', $type);
        } elseif ($type === null) {
            $query->where('type', 'architect');
        }

        if ($subClientId !== null) {
            if (! is_numeric($subClientId)) {
                return response()->json(['message' => 'sub_client_id inválido'], 422);
            }
            // Picker acotado dentro de Cotización (compatibilidad: el frontend nuevo busca en la base global y ya no lo manda).
            $query->where('sub_client_id', (int) $subClientId)->limit(20);
        } else {
            $query->limit(self::GLOBAL_LIST_LIMIT);
        }

        // SCRUM-1121 — autocompletado de la base global: tope propio (1..50) para el desplegable.
        $limit = $request->query('limit');
        if (is_numeric($limit)) {
            $query->limit(max(1, min(50, (int) $limit)));
        }

        $search = $request->query('search');
        if (is_string($search) && $search !== '') {
            // Sin acentos ni mayúsculas ("perez" encuentra "Pérez", como el resto del módulo) y con % y _ escapados: son texto, no comodines (el escape por defecto de LIKE en Postgres ya es la barra invertida).
            $like   = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';
            $digits = (string) preg_replace('/[^0-9]/', '', $search);
            $alnum  = (string) preg_replace('/[^a-z0-9]/', '', mb_strtolower($search)); // documento sin separadores: «8400444» encuentra «8-400-444»
            $query->where(function ($q) use ($like, $digits, $alnum): void {
                $q->whereRaw("public.f_unaccent(lower(name)) like public.f_unaccent(lower(?))", [$like])
                    ->orWhereRaw("lower(coalesce(email, '')) like lower(?)", [$like])
                    ->orWhereRaw("lower(coalesce(document, '')) like lower(?)", [$like]);
                if ($digits !== '') {
                    $q->orWhereRaw("regexp_replace(coalesce(phone, ''), '[^0-9]', '', 'g') like ?", ["%{$digits}%"]);
                }
                if (mb_strlen($alnum) >= 3) {
                    $q->orWhereRaw("regexp_replace(lower(coalesce(document, '')), '[^a-z0-9]', '', 'g') like ?", ["%{$alnum}%"]);
                }
            });
        }

        $architects = $query->get();

        return response()->json([
            'data' => $architects->map(fn (Architect $a) => $this->format($a))->values(),
        ]);
    }

    /**
     * SCRUM-907 — detalle de la pantalla "Arquitectos": nombre/teléfono/correo + la lista de
     * proyectos asociados. No existe FK directa Architect->PipelineCard (un arquitecto se
     * vincula por cotización, `Quote::architect_id`, ver SCRUM-734) — se resuelve vía las
     * cotizaciones de este arquitecto -> su sales_project_id -> la tarjeta de Pipeline de ese
     * proyecto (1:1 en la práctica, ver PipelineService::create()). Mismo shape de fila que
     * `ClientsController::projects()` (ClientProjectRow en el frontend), reutilizado tal cual.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $architect = Architect::find($id);
        if ($architect === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $salesProjectIds = Quote::where('architect_id', $id)
            ->whereNotNull('sales_project_id')
            ->pluck('sales_project_id')
            ->unique();

        $cards = PipelineCard::with(['subClient', 'salesProject'])
            ->whereIn('sales_project_id', $salesProjectIds)
            ->orderByDesc('stage_changed_at')
            ->get();

        return response()->json(array_merge($this->format($architect), [
            'projects' => $cards->map(fn (PipelineCard $c) => [
                'id'         => $c->id,
                'sub_client' => $c->subClient !== null ? [
                    'id' => $c->subClient->id, 'business_name' => $c->subClient->business_name,
                ] : null,
                'project'    => ['id' => $c->salesProject->id, 'name' => $c->salesProject->name],
                'stage'      => $c->stage,
                'amount'     => $c->amount,
            ])->values(),
        ]));
    }

    public function store(StoreArchitectRequest $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        /** @var array{sub_client_id?: int|null, type?: string, name: string, phone?: string|null, email?: string|null, document?: string|null} $validated */
        $validated = $request->validated();

        // SCRUM-1121 (D2) — duplicados: correo/teléfono/documento iguales BLOQUEAN (se ofrece usar el existente); nombre parecido solo
        // avisa y se crea al reenviar con confirm_similar. La revisión y el alta van bajo un lock consultivo: dos altas simultáneas con el
        // mismo correo no pueden pasar ambas la revisión (no hay índice único: puede haber duplicados históricos).
        /** @var JsonResponse|null $blocked */
        $blocked = null;
        $architect = DB::transaction(function () use ($validated, $request, $user, &$blocked): ?Architect {
            DB::select("select pg_advisory_xact_lock(hashtext('associates-dedupe'))");

            $found = $this->duplicates->find($validated);
            if ($found['exact'] !== []) {
                $blocked = response()->json([
                    'code'       => 'duplicate_exact',
                    'message'    => 'Ya existe un asociado con el mismo correo, teléfono o documento. Use el existente.',
                    'duplicates' => array_map(fn (Architect $a): array => $this->format($a), $found['exact']),
                ], 409);

                return null;
            }
            if ($found['similar'] !== [] && ! $request->boolean('confirm_similar')) {
                $blocked = response()->json([
                    'code'       => 'duplicate_similar',
                    'message'    => 'Hay un asociado con un nombre parecido. Verifique si es la misma persona antes de crear uno nuevo.',
                    'duplicates' => array_map(fn (Architect $a): array => $this->format($a), $found['similar']),
                ], 409);

                return null;
            }

            return Architect::create($validated + ['type' => 'architect', 'created_by' => $user->id]);
        });

        if ($architect === null) {
            return $blocked ?? response()->json(['message' => 'No se pudo crear el asociado.'], 500);
        }

        return response()->json($this->format($architect), 201);
    }

    /**
     * SCRUM-734 (RN8.5) — edición in-place: si se cambia el teléfono/correo de un
     * Arquitecto ya guardado, actualiza su registro real (asociado al Subcliente),
     * no una copia aislada de esa cotización — mismo comportamiento que ya tiene
     * SubClientContactController::update().
     */
    public function update(UpdateArchitectRequest $request, int $id): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $architect = Architect::find($id);
        if ($architect === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        /** @var array{name: string, type?: string, phone?: string|null, email?: string|null, document?: string|null} $validated */
        $validated = $request->validated();

        // SCRUM-1121 — el tipo `architect` origina comisiones de Admin&Cont (quotes.architect_id): un arquitecto ya usado en una cotización
        // no puede pasar a otro tipo (desaparecería de la pantalla Arquitectos y la cotización dejaría de poder guardarse).
        if (isset($validated['type']) && $validated['type'] !== $architect->type
            && Quote::withoutGlobalScopes()->where('architect_id', $architect->id)->exists()) {
            return response()->json(['message' => 'Este arquitecto ya está asignado a cotizaciones y no puede cambiar de tipo.'], 422);
        }

        // Editar no puede dejar a dos asociados con el mismo correo/teléfono/documento, pero solo se revisan los identificadores que CAMBIARON:
        // si ya existía un duplicado histórico, corregir el nombre de uno no debe quedar bloqueado para siempre.
        $changed = array_filter([
            'email'    => $this->changedIdentifier($validated['email'] ?? null, $architect->email, fn (?string $v): string => mb_strtolower(trim((string) $v))),
            'phone'    => $this->changedIdentifier($validated['phone'] ?? null, $architect->phone, fn (?string $v): string => (string) preg_replace('/[^0-9]/', '', (string) $v)),
            'document' => $this->changedIdentifier($validated['document'] ?? null, $architect->document, fn (?string $v): string => (string) preg_replace('/[^a-z0-9]/', '', mb_strtolower((string) $v))),
        ], fn ($v): bool => $v !== null);
        $found = $changed === [] ? ['exact' => [], 'similar' => []] : $this->duplicates->find($changed, $architect->id, includeSimilar: false);
        if ($found['exact'] !== []) {
            return response()->json([
                'code'       => 'duplicate_exact',
                'message'    => 'Ya existe otro asociado con el mismo correo, teléfono o documento.',
                'duplicates' => array_map(fn (Architect $a): array => $this->format($a), $found['exact']),
            ], 409);
        }

        $architect->update($validated);

        return response()->json($this->format($architect));
    }

    /**
     * El valor nuevo de un identificador si cambió respecto del guardado (comparando ya normalizados); null si no cambió o quedó vacío.
     *
     * @param callable(?string): string $normalize
     */
    private function changedIdentifier(?string $new, ?string $current, callable $normalize): ?string
    {
        $n = $normalize($new);

        return $n !== '' && $n !== $normalize($current) ? (string) $new : null;
    }

    /** @return array<string, mixed> */
    private function format(Architect $architect): array
    {
        return [
            'id' => $architect->id, 'type' => $architect->type, 'name' => $architect->name,
            'phone' => $architect->phone, 'email' => $architect->email, 'document' => $architect->document,
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
