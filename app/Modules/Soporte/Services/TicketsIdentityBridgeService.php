<?php

declare(strict_types=1);

namespace App\Modules\Soporte\Services;

use App\Models\User;
use App\Shared\Support\CircuitBreaker;
use Illuminate\Support\Facades\Http;

/**
 * SCRUM-923 — server-to-server contra el Identity Bridge de Illumination Support Tickets
 * (tickets-backend). `external_user_id` sigue el contrato `{tenant_slug}:{user_id}` que
 * tickets-backend espera (ver docs/specs/soporte-tickets-integracion.md).
 */
final class TicketsIdentityBridgeService
{
    /**
     * @return array{code: string, expires_at: string}
     */
    public function issueCode(User $user, string $tenantSlug): array
    {
        return (new CircuitBreaker('tickets-identity-bridge'))->call(function () use ($user, $tenantSlug) {
            $response = Http::withToken((string) config('services.tickets.api_key'))
                ->timeout(5)
                ->post(config('services.tickets.base_url') . '/api/v1/identity-bridge/codes', [
                    'external_user_id' => "{$tenantSlug}:{$user->id}",
                    'requester_name'   => trim("{$user->first_name} {$user->last_name}"),
                    'requester_email'  => $user->email,
                ]);

            if ($response->failed()) {
                throw new \RuntimeException('tickets-identity-bridge: ' . $response->status());
            }

            /** @var array{code: string, expires_at: string} */
            return $response->json();
        });
    }
}
