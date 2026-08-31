<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Outlook\Services;

use App\Shared\Support\CircuitBreaker;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * SCRUM-66/177 — lectura de Outlook vía Microsoft Graph, flujo app-only (client_credentials):
 * el App Registration "AtlanticERP CRM - Calendar Integration" (Azure AD del tercero que administra el
 * tenant de Outlook) tiene permisos de aplicación, así que AtlanticERP nunca pide login individual a
 * Microsoft — cada usuario ya está autenticado en AtlanticERP con su email corporativo, que es el mismo
 * UPN de su cuenta de Outlook.
 *
 * Solo lectura — nunca crea/edita/borra eventos en Outlook (alcance confirmado por Luis 2026-07-21,
 * a partir del comentario de Gerencia Test en SCRUM-66: "no es bidireccional").
 */
final class OutlookCalendarService
{
    private const TOKEN_CACHE_KEY = 'outlook:app_token';

    private const TOKEN_CACHE_TTL_SECONDS = 3300; // tokens de Graph duran ~3600s, margen de 5 min

    private const GRAPH_BASE = 'https://graph.microsoft.com/v1.0';

    /** Tope de conexiones simultáneas al pool — "todo el equipo" son ~17 cuentas hoy, cubre eso sin abrir un socket por persona sin límite. */
    private const POOL_CONCURRENCY = 10;

    /**
     * Une los eventos de una o más cuentas (Gerencia puede pedir varias). Una cuenta que falla
     * no tumba a las demás — se degrada a "sin datos de esa cuenta" y se marca `degraded`, para
     * que el frontend pueda distinguir "sin reuniones" de "no se pudo leer Outlook".
     *
     * Hallazgo de Luis en validación (2026-07-21): "vista de equipo" (17 cuentas reales hoy) tenía
     * un retraso notorio — este método llamaba a Graph una cuenta a la vez, en serie, así que el
     * tiempo total era la suma de las ~17 llamadas. Ahora se disparan todas en paralelo con
     * `Http::pool()` (una sola llamada de red conceptual, no 17 secuenciales).
     *
     * @param  list<string>  $emails
     * @return array{events: list<array<string, mixed>>, degraded: bool}
     */
    public function eventsForUsers(array $emails, CarbonInterface $from, CarbonInterface $to): array
    {
        if ($emails === []) {
            return ['events' => [], 'degraded' => false];
        }

        try {
            $token = (new CircuitBreaker('outlook-graph'))->call(fn () => $this->appToken());
        } catch (\Throwable $e) {
            Log::warning('OutlookCalendarService: no se pudo obtener el token de aplicación', [
                'error' => $e->getMessage(),
            ]);

            return ['events' => [], 'degraded' => true];
        }

        /** @var array<string, Response|\Throwable> $responses */
        $responses = Http::pool(fn (Pool $pool) => collect($emails)->map(
            fn (string $email) => $pool->as($email)
                ->withToken($token)
                ->timeout(10)
                ->get(self::GRAPH_BASE.'/users/'.urlencode($email).'/calendarView', [
                    'startDateTime' => $from->toIso8601String(),
                    'endDateTime' => $to->toIso8601String(),
                    '$orderby' => 'start/dateTime',
                    '$top' => 50,
                ]),
        )->all(), self::POOL_CONCURRENCY);

        $events = [];
        $degraded = false;

        foreach ($emails as $email) {
            $response = $responses[$email] ?? null;

            if (! $response instanceof Response) {
                $degraded = true;
                Log::warning('OutlookCalendarService: no se pudo leer el calendario', [
                    'email' => $email,
                    'error' => $response instanceof \Throwable ? $response->getMessage() : 'sin respuesta',
                ]);

                continue;
            }

            // Senior Review 2026-07-21 — confirmado contra el tenant real: un email sin buzón de
            // Microsoft 365 (ej. las cuentas demo *@atlantic.test, que no son cuentas reales
            // del tenant de Outlook) responde 404 `ErrorInvalidUser`, no una falla del servicio.
            // No cuenta como degradación — nunca va a tener calendario, no es un fallo transitorio.
            if ($response->status() === 404) {
                continue;
            }

            if ($response->failed()) {
                $degraded = true;
                Log::warning('OutlookCalendarService: Graph respondió con error', [
                    'email' => $email, 'status' => $response->status(),
                ]);

                continue;
            }

            /** @var list<array<string, mixed>> $items */
            $items = $response->json('value', []);
            array_push($events, ...array_map(fn (array $e) => $this->mapEvent($e, $email), $items));
        }

        usort($events, static fn (array $a, array $b) => $a['start_at'] <=> $b['start_at']);

        return ['events' => $events, 'degraded' => $degraded];
    }

    /**
     * @param  array<string, mixed>  $event
     * @return array{id: string, title: string, start_at: string|null, end_at: string|null, all_day: bool, location: string|null, organizer: string|null, owner_email: string}
     */
    private function mapEvent(array $event, string $ownerEmail): array
    {
        return [
            'id' => (string) ($event['id'] ?? ''),
            'title' => (string) ($event['subject'] ?? ''),
            'start_at' => isset($event['start']['dateTime']) ? (string) $event['start']['dateTime'] : null,
            'end_at' => isset($event['end']['dateTime']) ? (string) $event['end']['dateTime'] : null,
            'all_day' => (bool) ($event['isAllDay'] ?? false),
            'location' => $event['location']['displayName'] ?? null,
            'organizer' => $event['organizer']['emailAddress']['name'] ?? null,
            'owner_email' => $ownerEmail,
        ];
    }

    private function appToken(): string
    {
        /** @var string $token */
        $token = Cache::remember(self::TOKEN_CACHE_KEY, self::TOKEN_CACHE_TTL_SECONDS, function (): string {
            $tenant = (string) config('services.azure.tenant_id');

            $response = Http::asForm()
                ->timeout(10)
                ->post("https://login.microsoftonline.com/{$tenant}/oauth2/v2.0/token", [
                    'client_id' => config('services.azure.client_id'),
                    'client_secret' => config('services.azure.client_secret'),
                    'scope' => 'https://graph.microsoft.com/.default',
                    'grant_type' => 'client_credentials',
                ]);

            if ($response->failed()) {
                throw new \RuntimeException('Azure AD token request failed: HTTP '.$response->status());
            }

            $accessToken = $response->json('access_token');
            if (! is_string($accessToken) || $accessToken === '') {
                throw new \RuntimeException('Azure AD token response missing access_token');
            }

            return $accessToken;
        });

        return $token;
    }
}
