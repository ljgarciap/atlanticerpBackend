<?php

declare(strict_types=1);

namespace App\Shared\Catalogs;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

/**
 * Fase 7a — `index`/`domains` son de lectura general (pueblan selects en cualquier pantalla); `all`/`store`/`update`/`destroy`
 * viven bajo `permission:superadmin.all` en routes/admin.php, mismo criterio que el catálogo de módulos.
 */
final class CatalogController extends Controller
{
    public function __construct(private readonly CatalogService $service) {}

    /** Entradas activas de un dominio. */
    public function index(string $domain): JsonResponse
    {
        abort_unless(CatalogService::exists($domain), 404);

        return response()->json(CatalogService::entries($domain)->map(fn (CatalogEntry $e) => $this->format($e))->values());
    }

    /** Dominios disponibles para la pantalla de administración. */
    public function domains(): JsonResponse
    {
        $out = [];
        foreach (CatalogService::domains() as $key => $def) {
            $out[] = ['domain' => $key, 'label' => $def['label'], 'props' => $def['props']];
        }

        return response()->json($out);
    }

    /** Todas las entradas (también las inactivas) para administrar. */
    public function all(string $domain): JsonResponse
    {
        abort_unless(CatalogService::exists($domain), 404);

        return response()->json(CatalogService::entries($domain, false)->map(fn (CatalogEntry $e) => $this->format($e))->values());
    }

    public function store(Request $request, string $domain): JsonResponse
    {
        abort_unless(CatalogService::exists($domain), 404);

        /** @var array{key: string, label: string, sort_order?: int, props?: array<string, mixed>} $data */
        $data = $request->validate([
            'key' => ['required', 'string', 'max:60', 'regex:/^[a-z][a-z0-9_]*$/', Rule::unique('catalog_entries', 'key')->where('domain', $domain)],
            'label' => ['required', 'string', 'max:120'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'props' => ['sometimes', 'array'],
        ]);

        return response()->json($this->format($this->service->create($domain, $data)), 201);
    }

    public function update(Request $request, string $domain, int $id): JsonResponse
    {
        $entry = CatalogEntry::query()->where('domain', $domain)->findOrFail($id);

        /** @var array{label?: string, sort_order?: int, is_active?: bool, props?: array<string, mixed>} $data */
        $data = $request->validate([
            'label' => ['sometimes', 'string', 'max:120'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
            'props' => ['sometimes', 'array'],
        ]);

        try {
            return response()->json($this->format($this->service->update($entry, $data)));
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function destroy(string $domain, int $id): JsonResponse
    {
        $entry = CatalogEntry::query()->where('domain', $domain)->findOrFail($id);

        try {
            $this->service->delete($entry);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(null, 204);
    }

    /** @return array<string, mixed> */
    private function format(CatalogEntry $e): array
    {
        return [
            'id' => $e->id, 'domain' => $e->domain, 'key' => $e->key, 'label' => $e->label, 'sort_order' => $e->sort_order,
            'is_active' => $e->is_active, 'is_system' => $e->is_system, 'props' => $e->props ?? new \stdClass,
        ];
    }
}
