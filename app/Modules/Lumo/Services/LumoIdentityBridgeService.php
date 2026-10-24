<?php

declare(strict_types=1);

namespace App\Modules\Lumo\Services;

use App\Models\User;
use App\Shared\Support\CircuitBreaker;
use Illuminate\Support\Facades\Http;

/**
 * SCRUM-925 — server-to-server contra el Identity Bridge de LUMO (lumo-backend). Nunca llamado
 * desde el frontend de AtlanticERP directamente: la API key es exclusivamente server-to-server (ver
 * docs/specs/lumo-bitacoras-atlanticerp-integracion.md). El exchange y la lectura de bitácoras sí son
 * cross-origin desde el frontend de AtlanticERP directo a LUMO — este service solo cubre el paso 1
 * (emisión del código de un solo uso).
 */
final class LumoIdentityBridgeService
{
    /**
     * @return array{code: string, expires_at: string}
     */
    public function issueCode(User $user): array
    {
        return (new CircuitBreaker('lumo-identity-bridge'))->call(function () use ($user) {
            $response = Http::withHeaders(['X-AtlanticERP-Api-Key' => config('services.lumo.api_key')])
                ->timeout(5)
                ->post(config('services.lumo.base_url') . '/api/identity-bridge/codes/', [
                    'email' => $user->email,
                ]);

            if ($response->failed()) {
                throw new \RuntimeException('lumo-identity-bridge: ' . $response->status());
            }

            /** @var array{code: string, expires_at: string} */
            return $response->json();
        });
    }
}
