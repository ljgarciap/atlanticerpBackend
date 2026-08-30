<?php

declare(strict_types=1);

namespace App\Modules\Servicios\Http\Controllers;

use App\Models\User;
use App\Modules\Bodega\Models\Order;
use App\Modules\Bodega\Models\OrderItem;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Modules\VentasDiseno\Services\FuzzySearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * REQ-247 RN3 — buscador de productos del Catálogo para "Productos reclamados/afectados"; reusado
 * también por el ítem Producto de Cotización (Batch 11, REQ-230) para el mismo picker.
 *
 * A propósito NO reutiliza `CatalogProductSearchController` (Compras): ese endpoint expone `cost`,
 * información de margen que no le corresponde a Servicios (mismo criterio inverso al ya
 * documentado ahí — acá el contexto es "qué producto reclama el cliente"/"qué se cotiza", nunca una
 * compra). RN4 — `exclude` filtra del lado del servidor los productos ya agregados en el ticket,
 * mismo criterio de no-duplicados que Cotización (REQ-230 RN2); el frontend también lo aplica
 * localmente, esto es la defensa real.
 *
 * `price_full` (Batch 11) — "precio sugerido" para autocompletar el ítem Producto de Cotización.
 * `cost` sigue SIN exponerse acá, sin excepción — REQ-230 RN: "costo de referencia oculto al
 * cliente, solo interno para calcular margen"; el margen se valida server-side
 * (`ServiceQuoteService::resolveProductItem()`), nunca calculado en el frontend con un costo que
 * no debería tener.
 *
 * SCRUM-781 (punto 4.2, REQ-247) — `sales_project_id` opcional filtra a solo los productos
 * REALMENTE ENTREGADOS a ese proyecto ("evita seleccionar productos... que no hayan sido
 * entregados al cliente", texto literal del ticket — decisión confirmada con Luis 2026-08-20 entre
 * esta cadena y la alternativa "productos cotizados"). No hay FK directa `sales_projects` ↔
 * `catalog_products` en el modelo — se resuelve atravesando Bodega:
 * sales_projects → pipeline_cards → orders (stage=entregado) → order_items → catalog_products.
 * Sin este filtro (ítem Producto de Cotización, Batch 11), el comportamiento es exactamente el
 * de antes — catálogo completo, sin scope de proyecto.
 */
final class ProductLookupController extends Controller
{
    public function __construct(private readonly FuzzySearchService $fuzzySearch) {}

    public function index(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $query = CatalogProduct::where('is_active', true);

        $exclude = array_filter(array_map('intval', explode(',', (string) $request->query('exclude', ''))));
        if ($exclude !== []) {
            $query->whereNotIn('id', $exclude);
        }

        $salesProjectId = $request->query('sales_project_id');
        if (is_numeric($salesProjectId)) {
            $deliveredProductIds = OrderItem::whereNotNull('catalog_product_id')
                ->whereHas('order', function ($q) use ($salesProjectId): void {
                    $q->where('stage', Order::STAGE_ENTREGADO)
                        ->whereHas('pipelineCard', fn ($qq) => $qq->where('sales_project_id', (int) $salesProjectId));
                })
                ->pluck('catalog_product_id');

            $query->whereIn('id', $deliveredProductIds);
        }

        /** @var list<CatalogProduct> $products */
        $products = $query->orderBy('name')->get()->all();

        $search = trim((string) $request->query('search', ''));
        if ($search !== '') {
            [$products] = $this->fuzzySearch->search(
                $products,
                $search,
                fn (CatalogProduct $p): string => trim("{$p->reference} {$p->name} {$p->brand}"),
            );
        }

        return response()->json(['data' => array_map(fn (CatalogProduct $p) => $this->format($p), $products)]);
    }

    /** @return array<string, mixed> */
    private function format(CatalogProduct $product): array
    {
        return [
            'id'          => $product->id,
            'reference'   => $product->reference,
            'name'        => $product->name,
            'description' => $product->description,
            'brand'       => $product->brand,
            'price_full'  => $product->price_full,
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
