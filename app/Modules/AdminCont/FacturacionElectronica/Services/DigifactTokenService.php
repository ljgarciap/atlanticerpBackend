<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\FacturacionElectronica\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Ciclo de vida del JWT de Digifact — separado de DigifactPacAdapter para poder testearlo solo.
 * `POST /login/get_token` con `Username` = "PA.{RUC}.{username}" (formato confirmado en la doc
 * pública), token válido ~30 días. Se cachea con un margen de seguridad (29 días) para nunca
 * usar un token vencido por un cálculo de reloj al límite; `forget()` fuerza un refresh
 * inmediato — el adapter lo llama al recibir un 401.
 */
final class DigifactTokenService
{
    private const CACHE_KEY = 'digifact:jwt';
    private const CACHE_TTL_SECONDS = 29 * 24 * 60 * 60;

    public function token(string $ambiente): string
    {
        return Cache::remember(self::CACHE_KEY.":{$ambiente}", self::CACHE_TTL_SECONDS, function () use ($ambiente): string {
            return $this->requestNewToken($ambiente);
        });
    }

    public function forget(string $ambiente): void
    {
        Cache::forget(self::CACHE_KEY.":{$ambiente}");
    }

    private function requestNewToken(string $ambiente): string
    {
        $baseUrl = $ambiente === 'produccion'
            ? config('services.digifact.base_url_produccion')
            : config('services.digifact.base_url_pruebas');

        $ruc = config('services.digifact.ruc');
        $username = config('services.digifact.username');

        $response = Http::baseUrl($baseUrl)
            ->timeout(10)
            ->post('/login/get_token', [
                'Username' => "PA.{$ruc}.{$username}",
                'Password' => config('services.digifact.password'),
            ]);

        if ($response->failed()) {
            throw new RuntimeException("digifact-token: {$response->status()}");
        }

        $token = $response->json('token') ?? $response->json('Token');

        if (!is_string($token) || $token === '') {
            throw new RuntimeException('digifact-token: respuesta sin token — confirmar el nombre real del campo contra la doc verificada.');
        }

        return $token;
    }
}
