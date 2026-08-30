<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Http\Controllers;

use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Modules\VentasDiseno\Models\CatalogProductFamily;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * REQ-037: tab "Familias" del buscador de Catálogo — listar familias con su
 * cantidad de productos, y ver el detalle completo de una familia antes de
 * confirmar la selección en lote (ver QuoteItemController::bulkStore()).
 * Mismo permiso de lectura que el resto del Catálogo (`ventas_diseno.read`) —
 * sin pantalla de administración todavía, ver docblock de CatalogProductFamily.
 */
final class CatalogProductFamilyController extends Controller
{
    public function index(): JsonResponse
    {
        $families = CatalogProductFamily::where('is_active', true)
            ->withCount('products')
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $families->map(fn (CatalogProductFamily $f) => [
                'id'            => $f->id,
                'name'          => $f->name,
                'description'   => $f->description,
                'products_count' => $f->products_count,
            ])->values(),
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $family = CatalogProductFamily::where('is_active', true)->find($id);
        if ($family === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $products = $family->products()->where('is_active', true)->orderBy('description')->get();

        return response()->json([
            'id'          => $family->id,
            'name'        => $family->name,
            'description' => $family->description,
            // REQ-036 AC2 — misma ficha (nunca `cost`), ver CatalogProductController::format().
            'products'    => $products->map(fn (CatalogProduct $p) => [
                'id'             => $p->id,
                'reference'      => $p->reference,
                'description'    => $p->description,
                'brand'          => $p->brand,
                'photo_url'      => $p->photo_url,
                'price_full'     => $p->price_full,
                'stock_quantity' => $p->stock_quantity,
            ])->values(),
        ]);
    }
}
