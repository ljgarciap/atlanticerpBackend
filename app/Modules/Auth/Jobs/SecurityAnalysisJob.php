<?php

declare(strict_types=1);

namespace App\Modules\Auth\Jobs;

use App\Modules\Auth\Events\SecurityAnomalyDetected;
use App\Modules\Auth\Models\AuditLog;
use App\Modules\Auth\Models\SecurityAnomaly;
use App\Shared\Multitenancy\Tenant;
use App\Shared\Services\AuditService;
use App\Shared\Support\AnthropicClient;
use App\Shared\Support\CircuitBreaker;
use App\Shared\Support\CircuitOpenException;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Analiza audit_logs de las últimas 24h con Claude y sugiere (nunca ejecuta) bloqueos de
 * usuario. Contrato completo: docs/adr/004-epic11-seguridad-agentica-diseno.md y
 * docs/ai/security-analysis-job-prompt.md.
 *
 * No extiende BaseAiJob (módulo AI): ese helper está acoplado a AiAnalysis (resultado de texto
 * libre para un request síncrono en curso); acá el resultado es un array de 0..N hallazgos
 * estructurados que se validan y persisten como filas propias en security_anomalies.
 */
final class SecurityAnalysisJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const MODEL              = 'claude-sonnet-5';
    private const BUSINESS_TIMEZONE  = 'America/Panama';
    private const BUSINESS_START_HOUR = 7;
    private const BUSINESS_END_HOUR   = 21;

    public int $tries   = 3;
    public int $timeout = 120;

    public function __construct(
        private readonly string $tenantSlug,
    ) {
    }

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [5, 30, 120];
    }

    public function handle(): void
    {
        $tenant = Tenant::where('slug', $this->tenantSlug)->first();

        if ($tenant === null) {
            return;
        }

        $tenant->makeCurrent();

        try {
            // Ancla al día calendario anterior (00:00 a 00:00), no a Carbon::now() — el dedupe
            // de existsForRange() solo funciona si dos ejecuciones del mismo día calculan
            // EXACTAMENTE la misma ventana. Con now() cada corrida (un reintento, un re-disparo
            // manual) calculaba un rango distinto y el dedupe nunca coincidía (encontrado en
            // revisión de código — el test original solo pasaba porque congelaba el reloj).
            $rangeStart = Carbon::yesterday()->startOfDay();
            $rangeEnd   = Carbon::today()->startOfDay();

            if (SecurityAnomaly::existsForRange($rangeStart, $rangeEnd)) {
                return;
            }

            $this->flagPublicUploads($rangeStart, $rangeEnd);

            $summary = $this->buildUserSummary($rangeStart, $rangeEnd);

            if ($summary === []) {
                return; // sin actividad de usuarios en la ventana, nada que analizar
            }

            $cb = new CircuitBreaker('anthropic', threshold: 5, windowSeconds: 60, openTimeout: 30);

            $response = $cb->call(fn () => (new AnthropicClient())->complete(
                model:        self::MODEL,
                systemPrompt: $this->systemPrompt(),
                userMessage:  json_encode([
                    'window' => [
                        'start' => $rangeStart->toIso8601String(),
                        'end'   => $rangeEnd->toIso8601String(),
                    ],
                    'business_hours' => [
                        'timezone' => self::BUSINESS_TIMEZONE,
                        'days'     => 'Mon-Fri',
                        'start'    => sprintf('%02d:00', self::BUSINESS_START_HOUR),
                        'end'      => sprintf('%02d:00', self::BUSINESS_END_HOUR),
                    ],
                    'users' => array_values($summary),
                ], JSON_THROW_ON_ERROR),
            ));

            $this->persistFindings($response, array_keys($summary), $rangeStart, $rangeEnd);
        } catch (CircuitOpenException $e) {
            Log::warning('security_analysis.circuit_open', ['tenant' => $this->tenantSlug]);
            throw $e; // deja que el backoff reintente — nunca crea filas parciales
        } finally {
            Tenant::forgetCurrent();
        }
    }

    /**
     * Agrega audit_logs por usuario. Nunca incluye old_values/new_values (texto libre de
     * usuario) en lo que se envía a Claude — solo conteos/metadatos estructurados, ver
     * guardrail de prompt injection en docs/ai/security-analysis-job-prompt.md.
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildUserSummary(Carbon $start, Carbon $end): array
    {
        $logs = AuditLog::query()
            ->whereBetween('created_at', [$start, $end])
            ->whereNotNull('user_id')
            ->get(['user_id', 'action', 'entity_id', 'ip_address', 'created_at']);

        if ($logs->isEmpty()) {
            return [];
        }

        /** @var array<int, array<string, mixed>> $summary */
        $summary = [];

        foreach ($logs as $log) {
            $userId = (int) $log->user_id;

            $summary[$userId] ??= [
                'user_id'                   => $userId,
                'auth_failed_count'         => 0,
                'auth_login_count'          => 0,
                'distinct_login_ips'        => [],
                'document_downloads'        => 0,
                'distinct_projects_touched' => [],
                'off_hours_action_count'    => 0,
                'security_field_changes'    => 0,
            ];

            switch ($log->action) {
                case AuditService::AUTH_FAILED:
                    $summary[$userId]['auth_failed_count']++;
                    break;
                case AuditService::AUTH_LOGIN:
                    $summary[$userId]['auth_login_count']++;
                    if ($log->ip_address !== null) {
                        $summary[$userId]['distinct_login_ips'][$log->ip_address] = true;
                    }
                    break;
                case AuditService::CRM_DOCUMENT_DOWNLOADED:
                    $summary[$userId]['document_downloads']++;
                    if ($log->entity_id !== null) {
                        $summary[$userId]['distinct_projects_touched'][$log->entity_id] = true;
                    }
                    break;
                case AuditService::SECURITY_USER_UPDATED:
                case AuditService::SECURITY_LEVEL_UPDATED:
                    $summary[$userId]['security_field_changes']++;
                    break;
            }

            if ($this->isOffHours($log->created_at)) {
                $summary[$userId]['off_hours_action_count']++;
            }
        }

        foreach ($summary as &$row) {
            $row['distinct_login_ips']        = array_keys($row['distinct_login_ips']);
            $row['distinct_projects_touched'] = count($row['distinct_projects_touched']);
        }
        unset($row);

        return $summary;
    }

    private function isOffHours(Carbon $timestamp): bool
    {
        $local = $timestamp->copy()->timezone(self::BUSINESS_TIMEZONE);

        return $local->isWeekend()
            || $local->hour < self::BUSINESS_START_HOUR
            || $local->hour >= self::BUSINESS_END_HOUR;
    }

    /**
     * Amenaza #6 del threat model (exposición S3 pública) — chequeo determinístico, no pasa
     * por Claude. Documentos subidos a zona pública en la ventana quedan como hallazgo
     * informativo aparte (raw_response.source = 'deterministic'), no como anomalía de IA.
     */
    private function flagPublicUploads(Carbon $start, Carbon $end): void
    {
        $publicUploads = AuditLog::query()
            ->whereBetween('created_at', [$start, $end])
            ->where('action', AuditService::CRM_DOCUMENT_UPLOADED)
            ->whereJsonContains('new_values->storage_zone', 'public')
            ->get(['user_id', 'entity_id', 'new_values']);

        foreach ($publicUploads as $upload) {
            $row = SecurityAnomaly::create([
                'severity'         => SecurityAnomaly::SEVERITY_LOW,
                'reasoning'        => 'Documento subido a zona pública de S3 — revisar si fue intencional.',
                'raw_response'     => [
                    'source'      => 'deterministic',
                    'document_id' => $upload->entity_id,
                ],
                'affected_user_id' => $upload->user_id,
                'range_start'      => $start,
                'range_end'        => $end,
                'status'           => SecurityAnomaly::STATUS_PENDING,
            ]);

            event(new SecurityAnomalyDetected($row));
        }
    }

    /**
     * @param array<int, int> $knownUserIds ids enviados a Claude — un hallazgo con un
     *   affected_user_id fuera de esta lista se descarta (Claude no puede inventar un usuario
     *   que el job no le mandó).
     */
    private function persistFindings(string $response, array $knownUserIds, Carbon $start, Carbon $end): void
    {
        try {
            /** @var array{anomalies?: list<array<string, mixed>>} $decoded */
            $decoded = json_decode($response, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            Log::warning('security_analysis.invalid_json', ['tenant' => $this->tenantSlug, 'error' => $e->getMessage()]);

            return;
        }

        $anomalies = $decoded['anomalies'] ?? null;

        if (! is_array($anomalies)) {
            Log::warning('security_analysis.invalid_schema', ['tenant' => $this->tenantSlug]);

            return;
        }

        foreach ($anomalies as $finding) {
            if (! $this->isValidFinding($finding, $knownUserIds)) {
                Log::warning('security_analysis.invalid_finding', ['tenant' => $this->tenantSlug, 'finding' => $finding]);

                continue;
            }

            $row = SecurityAnomaly::create([
                'severity'         => $finding['severity'],
                'reasoning'        => $finding['reasoning'],
                'raw_response'     => array_merge($finding, ['source' => 'claude']),
                'affected_user_id' => $finding['affected_user_id'],
                'range_start'      => $start,
                'range_end'        => $end,
                'status'           => SecurityAnomaly::STATUS_PENDING,
            ]);

            event(new SecurityAnomalyDetected($row));
        }
    }

    /**
     * @param mixed $finding
     * @param array<int, int> $knownUserIds
     */
    private function isValidFinding(mixed $finding, array $knownUserIds): bool
    {
        if (! is_array($finding)) {
            return false;
        }

        $userId   = $finding['affected_user_id']   ?? null;
        $severity = $finding['severity']  ?? null;
        $reasoning = $finding['reasoning'] ?? null;

        return is_int($userId)
            && in_array($userId, $knownUserIds, true)
            && in_array($severity, [SecurityAnomaly::SEVERITY_LOW, SecurityAnomaly::SEVERITY_MEDIUM, SecurityAnomaly::SEVERITY_HIGH], true)
            && is_string($reasoning)
            && $reasoning !== '';
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
Sos un analista de seguridad para AtlanticERP, un CRM B2B de diseño de iluminación (Panamá). Vas a
recibir un resumen JSON de actividad agregada por usuario de las últimas 24 horas — nunca
contenido de texto libre, solo conteos y metadatos.

Evaluá cada usuario contra estos patrones de referencia (son anclas de calibración, no reglas
exactas — tu valor es detectar variantes que no calcen exacto):

CREDENTIAL STUFFING:
- medio: más de 5 auth_failed_count en la ventana para un usuario, o intentos fallidos distribuidos
- alto: más de 10 auth_failed_count, o auth_login_count exitoso inmediatamente tras ráfaga de fallos

EXFILTRACIÓN GRADUAL:
- medio: más de 20 document_downloads en la ventana, o distinct_projects_touched mayor a 5
- alto: más de 50 document_downloads

ACCESO FUERA DE HORARIO:
- no reportar off_hours_action_count solo, sin otra señal — es ruido esperado
- medio: off_hours_action_count mayor a 0 combinado con document_downloads o security_field_changes mayor a 0
- alto: lo anterior combinado con volumen alto de las categorías de arriba

Un usuario sin ninguna de estas condiciones NO es un hallazgo, aunque tenga alguna actividad.
Priorizá NO reportar sobre reportar de más — un falso positivo le hace perder confianza al
superadmin en el panel.

Respondé ÚNICAMENTE con este JSON, sin texto antes o después:
{"anomalies": [{"affected_user_id": <int>, "severity": "low"|"medium"|"high", "reasoning": "<string, máx 280 caracteres>"}]}

Si no hay hallazgos: {"anomalies": []}
PROMPT;
    }
}
