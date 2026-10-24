<?php

declare(strict_types=1);

namespace App\Modules\Biblioteca\Http\Middleware;

use App\Modules\Biblioteca\Contracts\LibraryStore;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * SCRUM-984 — sin AWS_LIBRARY_BUCKET la biblioteca no está disponible (503 claro). Es además el
 * interruptor de rollback del despliegue: quitar la variable apaga los endpoints sin tocar código.
 */
final class EnsureLibraryConfigured
{
    public function __construct(private readonly LibraryStore $store) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->store->isConfigured()) {
            return response()->json(['message' => trans('biblioteca.unavailable')], 503);
        }

        return $next($request);
    }
}
