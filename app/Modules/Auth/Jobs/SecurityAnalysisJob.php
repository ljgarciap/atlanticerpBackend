<?php

declare(strict_types=1);

namespace App\Modules\Auth\Jobs;

use App\Modules\AdminCont\Models\AdminContPayment;
use App\Modules\AdminCont\Models\AdminContPaymentConfirmation;
use App\Modules\AdminCont\Services\AdminContSettingsService;
use App\Modules\Auth\Events\SecurityAnomalyDetected;
use App\Modules\Auth\Models\AuditLog;
use App\Modules\Auth\Models\ImpersonationSession;
use App\Modules\Auth\Models\SecurityAnomaly;
use App\Modules\Auth\Services\ImpersonationSettings;
use App\Modules\Bodega\Models\AdjustmentRequest;
use App\Modules\Bodega\Models\AdjustmentRequestLine;
use App\Modules\Bodega\Models\RelocationRequest;
use App\Modules\Bodega\Services\BodegaSettingsService;
use App\Shared\Multitenancy\Tenant;
use App\Shared\Services\AuditService;
use App\Shared\Support\AiUsageAuditor;
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

            // REQ-635 (SCRUM-811) — reglas determinísticas de fraude económico en Bodega y
            // Admin&Cont, mismo patrón que flagPublicUploads() (sin IA). Ver
            // docs/security/THREAT-MODEL-bodega-admincont-fraude.md para el razonamiento de cada
            // regla y sus umbrales configurables.
            $this->flagBodegaLargeAdjustments($rangeStart, $rangeEnd);
            $this->flagBodegaOffHoursActivity($rangeStart, $rangeEnd);
            $this->flagBodegaCorrelatedAdjustmentRelocation($rangeStart, $rangeEnd);
            $this->flagBodegaNewIpActivity($rangeStart, $rangeEnd);
            $this->flagAdminContUnusualPaymentAmounts($rangeStart, $rangeEnd);
            $this->flagAdminContRepeatedApprovals($rangeStart, $rangeEnd);

            // SCRUM-1158 (C7) — uso inusual de «Ver como usuario». Umbrales en los ajustes globales (grupo `impersonation`).
            $this->flagImpersonationUsage($rangeStart, $rangeEnd);

            $summary = $this->buildUserSummary($rangeStart, $rangeEnd);

            if ($summary === []) {
                return; // sin actividad de usuarios en la ventana, nada que analizar
            }

            if ($this->attempts() === 1) {
                AiUsageAuditor::log(
                    static::class,
                    'security_analysis_run',
                    $rangeStart->toDateString(),
                    $this->systemPrompt(),
                );
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
                'refresh_token_reuse_count' => 0,
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
                case AuditService::AUTH_REFRESH_TOKEN_REUSE:   // SCRUM-1033 (A-03)
                    $summary[$userId]['refresh_token_reuse_count']++;
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
     * SCRUM-1158 (condición C7 del threat model de «Ver como usuario») — tres señales determinísticas, sin IA, sobre las sesiones de
     * impersonación de la ventana. Los umbrales viven en los ajustes globales de superadmin (`ImpersonationSettings`), no en código; una señal salta
     * cuando el valor SUPERA el umbral:
     *  - sesiones de un mismo actor en el día (reconocimiento masivo de colegas);
     *  - personas distintas vistas por un actor dentro de una misma hora (barrido);
     *  - intentos de escritura bloqueados dentro de UNA sesión (un guion probando endpoints; el modo solo lectura los rechaza, pero que alguien
     *    insista es en sí una señal).
     */
    private function flagImpersonationUsage(Carbon $start, Carbon $end): void
    {
        $settings = app(ImpersonationSettings::class);

        $sessions = ImpersonationSession::query()
            ->whereBetween('started_at', [$start, $end])
            ->orderBy('started_at')
            ->get(['id', 'actor_id', 'target_id', 'started_at']);

        foreach ($sessions->groupBy('actor_id') as $actorId => $mine) {
            $actorId = (int) $actorId;

            $perDayLimit = $settings->alertSessionsPerDay();
            if ($mine->count() > $perDayLimit) {
                $this->emitImpersonationAnomaly(
                    SecurityAnomaly::SEVERITY_MEDIUM,
                    sprintf('Un superadmin inició %d sesiones de «Ver como usuario» en un día (umbral %d).', $mine->count(), $perDayLimit),
                    'impersonation_sesiones_por_dia',
                    ['sessions' => $mine->count(), 'threshold' => $perDayLimit, 'target_user_ids' => $mine->pluck('target_id')->unique()->values()->all()],
                    $actorId,
                    $start,
                    $end,
                );
            }

            // Ventana deslizante de 1 hora que empieza en cada sesión: ¿a cuántas personas distintas vio el actor?
            $perHourLimit = $settings->alertTargetsPerHour();
            $worstTargets = [];
            foreach ($mine->values() as $i => $first) {
                $limit   = $first->started_at->copy()->addHour();
                $targets = $mine->slice($i)
                    ->filter(fn (ImpersonationSession $s): bool => $s->started_at->lessThanOrEqualTo($limit))
                    ->pluck('target_id')->unique()->values()->all();

                if (count($targets) > count($worstTargets)) {
                    $worstTargets = $targets;
                }
            }
            if (count($worstTargets) > $perHourLimit) {
                $this->emitImpersonationAnomaly(
                    SecurityAnomaly::SEVERITY_MEDIUM,
                    sprintf('Un superadmin vio como %d personas distintas en una misma hora con «Ver como usuario» (umbral %d).', count($worstTargets), $perHourLimit),
                    'impersonation_objetivos_por_hora',
                    ['distinct_targets' => count($worstTargets), 'threshold' => $perHourLimit, 'target_user_ids' => $worstTargets],
                    $actorId,
                    $start,
                    $end,
                );
            }
        }

        $blockedLimit = $settings->alertBlockedWritesPerSession();
        $blocked = AuditLog::query()
            ->whereBetween('created_at', [$start, $end])
            ->where('action', AuditService::AUTH_IMPERSONATION_WRITE_BLOCKED)
            ->get(['user_id', 'metadata'])
            ->filter(fn (AuditLog $log): bool => is_string($log->metadata['impersonation_id'] ?? null))
            ->groupBy(fn (AuditLog $log): string => (string) $log->metadata['impersonation_id']);

        foreach ($blocked as $sessionId => $logs) {
            if ($logs->count() <= $blockedLimit) {
                continue;
            }

            $this->emitImpersonationAnomaly(
                SecurityAnomaly::SEVERITY_MEDIUM,
                sprintf('%d intentos de escritura bloqueados dentro de una sola sesión de «Ver como usuario» (umbral %d).', $logs->count(), $blockedLimit),
                'impersonation_escrituras_bloqueadas',
                ['impersonation_id' => (string) $sessionId, 'blocked_writes' => $logs->count(), 'threshold' => $blockedLimit],
                $logs->first()?->user_id,
                $start,
                $end,
            );
        }
    }

    /** @param array<string, mixed> $details */
    private function emitImpersonationAnomaly(string $severity, string $reasoning, string $rule, array $details, ?int $actorId, Carbon $start, Carbon $end): void
    {
        $row = SecurityAnomaly::create([
            'severity'         => $severity,
            'reasoning'        => $reasoning,
            'raw_response'     => ['source' => 'deterministic', 'rule' => $rule] + $details,
            'affected_user_id' => $actorId,
            'range_start'      => $start,
            'range_end'        => $end,
            'status'           => SecurityAnomaly::STATUS_PENDING,
        ]);

        event(new SecurityAnomalyDetected($row));
    }

    /**
     * REQ-635 (Hallazgo 1 del threat model) — línea de ajuste cuya magnitud supera el umbral
     * configurable de Bodega. `AdjustmentRequestLine::cantidad` ya viene sin signo (la dirección
     * la da `tipo`), `abs()` es solo defensa en profundidad.
     */
    private function flagBodegaLargeAdjustments(Carbon $start, Carbon $end): void
    {
        $threshold = (new BodegaSettingsService())->fraudeAjusteGrandeUnidades();

        $lines = AdjustmentRequestLine::query()
            ->whereBetween('created_at', [$start, $end])
            ->with('request')
            ->get();

        foreach ($lines as $line) {
            if (abs($line->cantidad) < $threshold) {
                continue;
            }

            $request = $line->request;
            if ($request === null) {
                continue;
            }

            $row = SecurityAnomaly::create([
                'severity'  => SecurityAnomaly::SEVERITY_MEDIUM,
                'reasoning' => sprintf(
                    'Ajuste de inventario de %d unidades (umbral configurado: %d) — producto #%d.',
                    abs($line->cantidad),
                    $threshold,
                    $request->catalog_product_id,
                ),
                'raw_response' => [
                    'source'                => 'deterministic',
                    'rule'                  => 'bodega_ajuste_grande',
                    'adjustment_request_id' => $request->id,
                    'adjustment_line_id'    => $line->id,
                    'catalog_product_id'    => $request->catalog_product_id,
                    'cantidad'              => $line->cantidad,
                ],
                'affected_user_id' => $request->solicitado_por,
                'range_start'      => $start,
                'range_end'        => $end,
                'status'           => SecurityAnomaly::STATUS_PENDING,
            ]);

            event(new SecurityAnomalyDetected($row));
        }
    }

    /**
     * REQ-635 (Hallazgo 2 del threat model) — ajustes/reubicaciones registrados en madrugada,
     * ventana siempre [00:00, fraude_horario_fin_hora) en hora local del tenant. A propósito NO
     * usa una ventana de "horario de oficina": los datos reales de producción mostraron actividad
     * legítima hasta las 22:27 y en domingo — ver threat model, Hallazgo 2.
     */
    private function flagBodegaOffHoursActivity(Carbon $start, Carbon $end): void
    {
        $finHora = (new BodegaSettingsService())->fraudeHorarioFinHora();

        $adjustments = AdjustmentRequest::query()->whereBetween('created_at', [$start, $end])->get();

        foreach ($adjustments as $adjustment) {
            $local = $adjustment->created_at->copy()->timezone(self::BUSINESS_TIMEZONE);
            if ($local->hour >= $finHora) {
                continue;
            }

            $row = SecurityAnomaly::create([
                'severity'  => SecurityAnomaly::SEVERITY_MEDIUM,
                'reasoning' => sprintf('Ajuste de inventario registrado en madrugada (%s hora local).', $local->format('H:i')),
                'raw_response' => [
                    'source'                 => 'deterministic',
                    'rule'                   => 'bodega_fuera_de_horario',
                    'adjustment_request_id'  => $adjustment->id,
                    'catalog_product_id'     => $adjustment->catalog_product_id,
                    'hora_local'             => $local->format('H:i'),
                ],
                'affected_user_id' => $adjustment->solicitado_por,
                'range_start'      => $start,
                'range_end'        => $end,
                'status'           => SecurityAnomaly::STATUS_PENDING,
            ]);

            event(new SecurityAnomalyDetected($row));
        }

        $relocations = RelocationRequest::query()->whereBetween('created_at', [$start, $end])->get();

        foreach ($relocations as $relocation) {
            $local = $relocation->created_at->copy()->timezone(self::BUSINESS_TIMEZONE);
            if ($local->hour >= $finHora) {
                continue;
            }

            $row = SecurityAnomaly::create([
                'severity'  => SecurityAnomaly::SEVERITY_MEDIUM,
                'reasoning' => sprintf('Reubicación de inventario registrada en madrugada (%s hora local).', $local->format('H:i')),
                'raw_response' => [
                    'source'                 => 'deterministic',
                    'rule'                   => 'bodega_fuera_de_horario',
                    'relocation_request_id'  => $relocation->id,
                    'catalog_product_id'     => $relocation->catalog_product_id,
                    'hora_local'             => $local->format('H:i'),
                ],
                'affected_user_id' => $relocation->solicitado_por,
                'range_start'      => $start,
                'range_end'        => $end,
                'status'           => SecurityAnomaly::STATUS_PENDING,
            ]);

            event(new SecurityAnomalyDetected($row));
        }
    }

    /**
     * REQ-635 (Hallazgo 3 del threat model) — mismo actor ajustando y reubicando el mismo
     * producto dentro de la ventana configurable. Busca ajustes con un margen a ambos lados del
     * rango del día (padding = la ventana de correlación) para no perder correlaciones cerca del
     * borde del día calendario — limitación conocida documentada en el threat model: una
     * correlación que cruce la medianoche por más que ese margen no se detecta.
     */
    private function flagBodegaCorrelatedAdjustmentRelocation(Carbon $start, Carbon $end): void
    {
        $windowMinutes = (new BodegaSettingsService())->fraudeVentanaCorrelacionMinutos();

        $relocations = RelocationRequest::query()->whereBetween('created_at', [$start, $end])->get();
        if ($relocations->isEmpty()) {
            return;
        }

        $adjustments = AdjustmentRequest::query()
            ->whereBetween('created_at', [
                $start->copy()->subMinutes($windowMinutes),
                $end->copy()->addMinutes($windowMinutes),
            ])
            ->get();

        foreach ($relocations as $relocation) {
            foreach ($adjustments as $adjustment) {
                if ((int) $adjustment->solicitado_por !== (int) $relocation->solicitado_por
                    || (int) $adjustment->catalog_product_id !== (int) $relocation->catalog_product_id
                ) {
                    continue;
                }

                $diffMinutes = abs($adjustment->created_at->diffInMinutes($relocation->created_at));
                if ($diffMinutes > $windowMinutes) {
                    continue;
                }

                $row = SecurityAnomaly::create([
                    'severity'  => SecurityAnomaly::SEVERITY_MEDIUM,
                    'reasoning' => sprintf(
                        'Mismo usuario ajustó y reubicó el producto #%d con %d min de diferencia (umbral: %d min).',
                        $relocation->catalog_product_id,
                        $diffMinutes,
                        $windowMinutes,
                    ),
                    'raw_response' => [
                        'source'                 => 'deterministic',
                        'rule'                   => 'bodega_correlacion_ajuste_reubicacion',
                        'adjustment_request_id'  => $adjustment->id,
                        'relocation_request_id'  => $relocation->id,
                        'catalog_product_id'     => $relocation->catalog_product_id,
                        'diff_minutos'           => $diffMinutes,
                    ],
                    'affected_user_id' => $relocation->solicitado_por,
                    'range_start'      => $start,
                    'range_end'        => $end,
                    'status'           => SecurityAnomaly::STATUS_PENDING,
                ]);

                event(new SecurityAnomalyDetected($row));

                break; // un hallazgo por reubicación alcanza, no uno por cada ajuste que matchee
            }
        }
    }

    /**
     * REQ-635 (Hallazgo 4 del threat model) — IP nueva para el usuario en una acción de Bodega.
     * Solo se evalúa si el usuario ya tiene al menos 1 evento previo con IP registrada dentro de
     * la ventana de lookback — evita el falso positivo garantizado de "primera acción del usuario
     * siempre dispara" (arranque en frío, ver threat model).
     */
    private function flagBodegaNewIpActivity(Carbon $start, Carbon $end): void
    {
        $lookbackDays = (new BodegaSettingsService())->fraudeIpLookbackDias();

        $events = AuditLog::query()
            ->whereBetween('created_at', [$start, $end])
            ->whereIn('action', ['bodega.adjustment_request.created', 'bodega.relocation_request.created'])
            ->whereNotNull('user_id')
            ->whereNotNull('ip_address')
            ->get(['id', 'user_id', 'ip_address', 'action', 'entity_id', 'created_at']);

        foreach ($events as $auditEvent) {
            $priorIps = AuditLog::query()
                ->where('user_id', $auditEvent->user_id)
                ->where('created_at', '<', $auditEvent->created_at)
                ->where('created_at', '>=', $auditEvent->created_at->copy()->subDays($lookbackDays))
                ->whereNotNull('ip_address')
                ->pluck('ip_address')
                ->unique();

            if ($priorIps->isEmpty()) {
                continue; // sin historial previo — arranque en frío, no evaluar
            }

            if ($priorIps->contains($auditEvent->ip_address)) {
                continue; // IP ya conocida para este usuario
            }

            $row = SecurityAnomaly::create([
                'severity'  => SecurityAnomaly::SEVERITY_LOW,
                'reasoning' => sprintf('Acción de Bodega desde una IP nueva para este usuario (%s).', $auditEvent->ip_address),
                'raw_response' => [
                    'source'     => 'deterministic',
                    'rule'       => 'bodega_ip_nueva',
                    'action'     => $auditEvent->action,
                    'entity_id'  => $auditEvent->entity_id,
                    'ip_address' => $auditEvent->ip_address,
                ],
                'affected_user_id' => $auditEvent->user_id,
                'range_start'      => $start,
                'range_end'        => $end,
                'status'           => SecurityAnomaly::STATUS_PENDING,
            ]);

            event(new SecurityAnomalyDetected($row));
        }
    }

    /**
     * REQ-635 (Hallazgo 5 del threat model) — Admin&Cont no tenía NINGÚN dato histórico real al
     * momento del threat model (2026-09-01), así que en vez de un monto fijo en dólares (que
     * hubiera sido una adivinanza sin respaldo), el umbral se auto-calibra por desviación
     * estándar sobre la media móvil de los últimos N días de esa misma tabla. Con menos de 10
     * pagos en la ventana, la muestra no es significativa y la regla simplemente no se evalúa —
     * se activa sola a medida que haya suficiente historial real.
     */
    private function flagAdminContUnusualPaymentAmounts(Carbon $start, Carbon $end): void
    {
        $settings   = new AdminContSettingsService();
        $windowDays = $settings->fraudeMontoVentanaDias();
        $deviations = $settings->fraudeMontoDesviaciones();

        $baseline = AdminContPayment::query()
            ->whereBetween('created_at', [$start->copy()->subDays($windowDays), $start])
            ->pluck('monto_recibido');

        if ($baseline->count() < 10) {
            return;
        }

        $mean   = (float) $baseline->avg();
        $stdDev = $this->populationStdDev(array_values($baseline->map(fn (mixed $v): float => (float) $v)->all()), $mean);

        if ($stdDev <= 0.0) {
            return;
        }

        $ceiling = $mean + ($deviations * $stdDev);

        $todaysPayments = AdminContPayment::query()->whereBetween('created_at', [$start, $end])->get();

        foreach ($todaysPayments as $payment) {
            if ($payment->monto_recibido <= $ceiling) {
                continue;
            }

            $row = SecurityAnomaly::create([
                'severity'  => SecurityAnomaly::SEVERITY_HIGH,
                'reasoning' => sprintf(
                    'Pago de $%s muy por encima del histórico reciente (media $%s + %d desviaciones = $%s).',
                    number_format($payment->monto_recibido, 2),
                    number_format($mean, 2),
                    $deviations,
                    number_format($ceiling, 2),
                ),
                'raw_response' => [
                    'source'              => 'deterministic',
                    'rule'                => 'admin_cont_monto_fuera_de_rango',
                    'payment_id'          => $payment->id,
                    'monto'               => $payment->monto_recibido,
                    'media_baseline'      => $mean,
                    'desviacion_estandar' => $stdDev,
                    'techo'               => $ceiling,
                ],
                'affected_user_id' => $payment->created_by,
                'range_start'      => $start,
                'range_end'        => $end,
                'status'           => SecurityAnomaly::STATUS_PENDING,
            ]);

            event(new SecurityAnomalyDetected($row));
        }
    }

    /** @param list<float> $values */
    private function populationStdDev(array $values, float $mean): float
    {
        $count = count($values);
        if ($count === 0) {
            return 0.0;
        }

        $sumSquares = 0.0;
        foreach ($values as $value) {
            $sumSquares += ($value - $mean) ** 2;
        }

        return sqrt($sumSquares / $count);
    }

    /**
     * REQ-635 (Hallazgo 6 del threat model) — el mismo actor confirmando N+ pagos en una ventana
     * corta. Un hallazgo por actor por corrida (no uno por cada ventana deslizante que matchee).
     */
    private function flagAdminContRepeatedApprovals(Carbon $start, Carbon $end): void
    {
        $settings      = new AdminContSettingsService();
        $n             = $settings->fraudeAprobacionesRepetidasN();
        $windowMinutes = $settings->fraudeAprobacionesRepetidasMinutos();

        $confirmations = AdminContPaymentConfirmation::query()
            ->whereBetween('confirmed_at', [$start, $end])
            ->whereNotNull('confirmed_by')
            ->orderBy('confirmed_at')
            ->get(['id', 'confirmed_by', 'confirmed_at', 'payment_id']);

        if ($confirmations->count() < $n) {
            return;
        }

        foreach ($confirmations->groupBy('confirmed_by') as $actorId => $actorConfirmations) {
            $sorted = $actorConfirmations->values();

            for ($i = 0; $i <= $sorted->count() - $n; $i++) {
                $first = $sorted->get($i);
                $last  = $sorted->get($i + $n - 1);
                if ($first === null || $last === null) {
                    continue;
                }

                if ($first->confirmed_at->diffInMinutes($last->confirmed_at) > $windowMinutes) {
                    continue;
                }

                $row = SecurityAnomaly::create([
                    'severity'  => SecurityAnomaly::SEVERITY_LOW,
                    'reasoning' => sprintf('%d confirmaciones de pago en %d minutos por el mismo actor.', $n, $windowMinutes),
                    'raw_response' => [
                        'source'      => 'deterministic',
                        'rule'        => 'admin_cont_aprobaciones_repetidas',
                        'payment_ids' => $sorted->slice($i, $n)->pluck('payment_id')->values()->all(),
                    ],
                    'affected_user_id' => (int) $actorId,
                    'range_start'      => $start,
                    'range_end'        => $end,
                    'status'           => SecurityAnomaly::STATUS_PENDING,
                ]);

                event(new SecurityAnomalyDetected($row));

                break;
            }
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

REUSO DE REFRESH TOKEN (posible robo de sesión):
- medio: refresh_token_reuse_count mayor a 0 (un token de refresco ya rotado se presentó de nuevo); sube a alto si coincide con distinct_login_ips distintas o auth_failed_count elevado

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
