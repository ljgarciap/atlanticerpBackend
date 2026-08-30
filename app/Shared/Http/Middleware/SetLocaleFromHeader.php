<?php

declare(strict_types=1);

namespace App\Shared\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

final class SetLocaleFromHeader
{
    private const SUPPORTED_LOCALES = ['es', 'en'];

    public function handle(Request $request, Closure $next): Response
    {
        $requested = strtolower(substr((string) $request->header('Accept-Language', ''), 0, 2));

        // Sin esto, App::setLocale() de un request con header quedaba "pegado"
        // para el siguiente request sin header dentro del mismo proceso PHP-FPM
        // (o del mismo test case) — siempre resolvemos explícitamente, nunca
        // dejamos el locale heredado de una petición anterior.
        $locale = in_array($requested, self::SUPPORTED_LOCALES, true)
            ? $requested
            : config('app.locale');

        App::setLocale($locale);

        return $next($request);
    }
}
