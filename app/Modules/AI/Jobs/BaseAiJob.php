<?php

declare(strict_types=1);

namespace App\Modules\AI\Jobs;

use App\Modules\AI\Events\AnalysisCompleted;
use App\Modules\AI\Models\AiAnalysis;
use App\Modules\AI\Models\AiModelPricing;
use App\Modules\AI\Support\PromptSanitizer;
use App\Shared\Multitenancy\Tenant;
use App\Shared\Services\AuditService;
use App\Shared\Support\AiUsageAuditor;
use App\Shared\Support\AnthropicApiException;
use App\Shared\Support\AnthropicClient;
use App\Shared\Support\CircuitBreaker;
use App\Shared\Support\CircuitOpenException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

abstract class BaseAiJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 90;

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [5, 30, 120];
    }

    /**
     * @param string $taskInstruction  Trusted — the Job's own instruction to Claude
     *                                 (e.g. "Resume el siguiente proyecto..."). Sent as-is.
     * @param string $untrustedContext Untrusted — project/activity/user data. Wrapped in
     *                                 <user_content> before being sent (see PromptSanitizer),
     *                                 so it's the only part ever delimited as "data, not
     *                                 instructions" — wrapping $taskInstruction too would
     *                                 self-contradict the system prompt's own guidance.
     */
    protected function run(
        string $analysisId,
        string $tenantSlug,
        string $model,
        string $systemPrompt,
        string $taskInstruction,
        string $untrustedContext,
    ): void {
        $tenant = Tenant::where('slug', $tenantSlug)->first();

        if ($tenant === null) {
            return;
        }

        $tenant->makeCurrent();

        /** @var AiAnalysis|null $analysis */
        $analysis = AiAnalysis::find($analysisId);

        if ($analysis === null) {
            return;
        }

        $analysis->update(['status' => AiAnalysis::STATUS_RUNNING]);

        if ($this->attempts() === 1) {
            AiUsageAuditor::log(static::class, 'ai_analysis', $analysisId, $taskInstruction);
        }

        // Solo en el primer intento — reintentos (tries=3) no deben duplicar la
        // detección ni el audit log para el mismo evento lógico.
        if ($this->attempts() === 1 && PromptSanitizer::looksLikeInjection($untrustedContext)) {
            app(AuditService::class)->log(
                action:     AuditService::AI_PROMPT_SUSPICIOUS_PATTERN_DETECTED,
                entityType: 'ai_analysis',
                entityId:   $analysisId,
                metadata:   ['job' => static::class],
            );
        }

        $userMessage = $taskInstruction . "\n\n" . PromptSanitizer::wrap($untrustedContext);

        try {
            $cb     = new CircuitBreaker('anthropic', threshold: 5, windowSeconds: 60, openTimeout: 30);
            $client = new AnthropicClient();

            $result = $cb->call(fn () => $client->complete(
                model:       $model,
                systemPrompt: $systemPrompt,
                userMessage:  $userMessage,
            ));

            $analysis->update([
                'status' => AiAnalysis::STATUS_COMPLETED,
                'result' => $result,
                'error'  => null,
                ...$this->usageFields($client, $model),
            ]);

            event(new AnalysisCompleted($analysisId, true));
        } catch (CircuitOpenException $e) {
            $analysis->update([
                'status' => AiAnalysis::STATUS_FAILED,
                'error'  => 'El servicio de AI no está disponible temporalmente. Intenta de nuevo en unos minutos.',
            ]);
            event(new AnalysisCompleted($analysisId, false));
        } catch (\Throwable $e) {
            $isLastAttempt = $this->attempts() >= $this->tries || ! $this->isRetryable($e);

            if ($isLastAttempt) {
                $analysis->update([
                    'status' => AiAnalysis::STATUS_FAILED,
                    'error'  => $this->userFacingError($e, $analysis->id),
                ]);
                event(new AnalysisCompleted($analysisId, false));
            } else {
                $analysis->update(['status' => AiAnalysis::STATUS_PENDING]);
                throw $e;
            }
        }
    }

    /**
     * Variante de run() para documentos binarios (PDF/imagen) con tool_choice forzado — ver
     * ADR-SCRUM211. A diferencia de run(), no aplica PromptSanitizer::wrap(): el documento no es
     * texto y tool_choice forzado ya acota la salida del modelo al schema de la tool, que es la
     * mitigación principal contra prompt injection embebido en el archivo.
     *
     * @param array<string, mixed> $tool Definición completa de la tool (name, description, input_schema)
     * @return array<string, mixed>|null null si el tenant no resuelve, el analysis no existe, o
     *                                    la llamada falló (ya quedó registrado en AiAnalysis.error)
     */
    protected function runWithDocument(
        string $analysisId,
        string $tenantSlug,
        string $model,
        string $systemPrompt,
        string $taskInstruction,
        string $documentBase64,
        string $documentMediaType,
        array  $tool,
    ): ?array {
        $tenant = Tenant::where('slug', $tenantSlug)->first();

        if ($tenant === null) {
            return null;
        }

        $tenant->makeCurrent();

        /** @var AiAnalysis|null $analysis */
        $analysis = AiAnalysis::find($analysisId);

        if ($analysis === null) {
            return null;
        }

        $analysis->update(['status' => AiAnalysis::STATUS_RUNNING]);

        if ($this->attempts() === 1) {
            AiUsageAuditor::log(static::class, 'ai_analysis', $analysisId, $taskInstruction);
        }

        try {
            $cb     = new CircuitBreaker('anthropic', threshold: 5, windowSeconds: 60, openTimeout: 30);
            $client = new AnthropicClient();

            $result = $cb->call(fn () => $client->completeWithDocumentTool(
                model:             $model,
                systemPrompt:      $systemPrompt,
                taskInstruction:   $taskInstruction,
                documentBase64:    $documentBase64,
                documentMediaType: $documentMediaType,
                tool:              $tool,
            ));

            $analysis->update([
                'status' => AiAnalysis::STATUS_COMPLETED,
                'result' => json_encode($result),
                'error'  => null,
                ...$this->usageFields($client, $model),
            ]);

            event(new AnalysisCompleted($analysisId, true));

            return $result;
        } catch (CircuitOpenException $e) {
            $analysis->update([
                'status' => AiAnalysis::STATUS_FAILED,
                'error'  => 'El servicio de AI no está disponible temporalmente. Intenta de nuevo en unos minutos.',
            ]);
            event(new AnalysisCompleted($analysisId, false));

            return null;
        } catch (\Throwable $e) {
            $isLastAttempt = $this->attempts() >= $this->tries || ! $this->isRetryable($e);

            if ($isLastAttempt) {
                $analysis->update([
                    'status' => AiAnalysis::STATUS_FAILED,
                    'error'  => $this->userFacingError($e, $analysis->id),
                ]);
                event(new AnalysisCompleted($analysisId, false));

                return null;
            }

            $analysis->update(['status' => AiAnalysis::STATUS_PENDING]);
            throw $e;
        }
    }

    /**
     * Variante de runWithDocument() sin content block binario — para cuando el input ya es texto
     * plano (ver ADR-SCRUM224: facturas Excel/CSV extraídas con phpspreadsheet, que no tienen un
     * content block de documento válido para la API). Mismo criterio de seguridad que
     * runWithDocument(): no aplica PromptSanitizer::wrap() porque tool_choice forzado ya acota la
     * salida al schema de la tool — la mitigación real contra contenido manipulado no es el
     * wrapping sino que el resultado nunca referencia un ID real (matching determinístico del
     * caller contra datos reales, ver InvoiceOrderMatcher).
     *
     * @param array<string, mixed> $tool Definición completa de la tool (name, description, input_schema)
     * @return array<string, mixed>|null null si el tenant no resuelve, el analysis no existe, o
     *                                    la llamada falló (ya quedó registrado en AiAnalysis.error)
     */
    protected function runWithTool(
        string $analysisId,
        string $tenantSlug,
        string $model,
        string $systemPrompt,
        string $taskInstruction,
        array  $tool,
    ): ?array {
        $tenant = Tenant::where('slug', $tenantSlug)->first();

        if ($tenant === null) {
            return null;
        }

        $tenant->makeCurrent();

        /** @var AiAnalysis|null $analysis */
        $analysis = AiAnalysis::find($analysisId);

        if ($analysis === null) {
            return null;
        }

        $analysis->update(['status' => AiAnalysis::STATUS_RUNNING]);

        if ($this->attempts() === 1) {
            AiUsageAuditor::log(static::class, 'ai_analysis', $analysisId, $taskInstruction);
        }

        try {
            $cb     = new CircuitBreaker('anthropic', threshold: 5, windowSeconds: 60, openTimeout: 30);
            $client = new AnthropicClient();

            $result = $cb->call(fn () => $client->completeWithTool(
                model:           $model,
                systemPrompt:    $systemPrompt,
                taskInstruction: $taskInstruction,
                tool:            $tool,
            ));

            $analysis->update([
                'status' => AiAnalysis::STATUS_COMPLETED,
                'result' => json_encode($result),
                'error'  => null,
                ...$this->usageFields($client, $model),
            ]);

            event(new AnalysisCompleted($analysisId, true));

            return $result;
        } catch (CircuitOpenException $e) {
            $analysis->update([
                'status' => AiAnalysis::STATUS_FAILED,
                'error'  => 'El servicio de AI no está disponible temporalmente. Intenta de nuevo en unos minutos.',
            ]);
            event(new AnalysisCompleted($analysisId, false));

            return null;
        } catch (\Throwable $e) {
            $isLastAttempt = $this->attempts() >= $this->tries || ! $this->isRetryable($e);

            if ($isLastAttempt) {
                $analysis->update([
                    'status' => AiAnalysis::STATUS_FAILED,
                    'error'  => $this->userFacingError($e, $analysis->id),
                ]);
                event(new AnalysisCompleted($analysisId, false));

                return null;
            }

            $analysis->update(['status' => AiAnalysis::STATUS_PENDING]);
            throw $e;
        }
    }

    /**
     * Variante de runWithDocument() para varias imágenes/documentos en una sola llamada (ver
     * ADR-SCRUM245: comparación de una foto/ficha subida contra hasta 25 candidatos del catálogo).
     * Mismo criterio de seguridad: no aplica PromptSanitizer::wrap() porque son binarios, no texto,
     * y tool_choice forzado ya acota la salida — el systemPrompt es responsable de instruir que
     * NINGUNO de los content blocks (ni el subido por el usuario ni los del catálogo propio) se
     * interprete como instrucción.
     *
     * @param list<array{base64: string, mediaType: string, label: string}> $documents
     * @param array<string, mixed> $tool Definición completa de la tool (name, description, input_schema)
     * @return array<string, mixed>|null null si el tenant no resuelve, el analysis no existe, o
     *                                    la llamada falló (ya quedó registrado en AiAnalysis.error)
     */
    protected function runWithDocuments(
        string $analysisId,
        string $tenantSlug,
        string $model,
        string $systemPrompt,
        string $taskInstruction,
        array  $documents,
        array  $tool,
    ): ?array {
        $tenant = Tenant::where('slug', $tenantSlug)->first();

        if ($tenant === null) {
            return null;
        }

        $tenant->makeCurrent();

        /** @var AiAnalysis|null $analysis */
        $analysis = AiAnalysis::find($analysisId);

        if ($analysis === null) {
            return null;
        }

        $analysis->update(['status' => AiAnalysis::STATUS_RUNNING]);

        if ($this->attempts() === 1) {
            AiUsageAuditor::log(static::class, 'ai_analysis', $analysisId, $taskInstruction);
        }

        try {
            $cb     = new CircuitBreaker('anthropic', threshold: 5, windowSeconds: 60, openTimeout: 30);
            $client = new AnthropicClient();

            $result = $cb->call(fn () => $client->completeWithDocumentsTool(
                model:            $model,
                systemPrompt:     $systemPrompt,
                taskInstruction:  $taskInstruction,
                documents:        $documents,
                tool:             $tool,
            ));

            $analysis->update([
                'status' => AiAnalysis::STATUS_COMPLETED,
                'result' => json_encode($result),
                'error'  => null,
                ...$this->usageFields($client, $model),
            ]);

            event(new AnalysisCompleted($analysisId, true));

            return $result;
        } catch (CircuitOpenException $e) {
            $analysis->update([
                'status' => AiAnalysis::STATUS_FAILED,
                'error'  => 'El servicio de AI no está disponible temporalmente. Intenta de nuevo en unos minutos.',
            ]);
            event(new AnalysisCompleted($analysisId, false));

            return null;
        } catch (\Throwable $e) {
            $isLastAttempt = $this->attempts() >= $this->tries || ! $this->isRetryable($e);

            if ($isLastAttempt) {
                $analysis->update([
                    'status' => AiAnalysis::STATUS_FAILED,
                    'error'  => $this->userFacingError($e, $analysis->id),
                ]);
                event(new AnalysisCompleted($analysisId, false));

                return null;
            }

            $analysis->update(['status' => AiAnalysis::STATUS_PENDING]);
            throw $e;
        }
    }

    /**
     * SCRUM-1030 (R-10): lo que se guarda en `AiAnalysis.error` lo ve el usuario. El mensaje de la excepción (host, conexión, rutas)
     * va SOLO al log; el usuario recibe un texto genérico.
     */
    private function userFacingError(\Throwable $e, string|int $analysisId): string
    {
        Log::error('Job de IA falló en el último intento', [
            'job'         => static::class,
            'analysis_id' => $analysisId,
            'exception'   => $e::class,
            'message'     => $e->getMessage(),
        ]);

        // Un error de la propia API de Anthropic (p. ej. 400 "credit balance is too low") SÍ se muestra: es información que el usuario necesita
        // (hallazgo QA 2026-07-20, SCRUM-211/224) y no contiene detalle de nuestra infraestructura. Cualquier otra excepción (Redis, base de
        // datos, red interna) queda con el texto genérico.
        if ($e instanceof AnthropicApiException) {
            return $e->getMessage();
        }

        return 'No se pudo completar el análisis. Intenta de nuevo en unos minutos.';
    }

    /**
     * Campos de consumo/costo a fusionar en el $analysis->update() de éxito de cada variante —
     * centralizado acá para no repetir el cálculo de costo 4 veces. Sin tarifa configurada para
     * el modelo, estimated_cost_usd queda null (nunca bloquea el guardado del resultado real).
     *
     * @return array<string, mixed>
     */
    private function usageFields(AnthropicClient $client, string $model): array
    {
        $usage = $client->getLastUsage();

        if ($usage === null) {
            return ['model' => $model];
        }

        return [
            'model'              => $model,
            'input_tokens'       => $usage['input_tokens'],
            'output_tokens'      => $usage['output_tokens'],
            'estimated_cost_usd' => AiModelPricing::estimateCostUsd($model, $usage['input_tokens'], $usage['output_tokens']),
        ];
    }

    /**
     * Un 4xx de la API (request inválido, créditos agotados, key revocada) no se arregla
     * reintentando — repetir el backoff [5s, 30s, 120s] solo deja al usuario mirando
     * "Validando…"/"Detectando…" ~2.5 minutos antes del failed (hallazgo QA SCRUM-211/224).
     * 429 y 5xx sí son transitorios y mantienen el ciclo normal de reintentos.
     */
    private function isRetryable(\Throwable $e): bool
    {
        if ($e instanceof AnthropicApiException) {
            return $e->isRetryable();
        }

        return true;
    }
}
