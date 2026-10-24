<?php

declare(strict_types=1);

namespace App\Shared\Support;

use App\Modules\Auth\Models\GlobalSetting;
use App\Shared\Services\AuditService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * SCRUM-814 (Epic SCRUM-788 — Logs y Telemetría) — deja registro de qué se le pidió a Claude en
 * cada uso de IA, sin duplicar contenido sensible en audit_logs. Usado tanto por
 * App\Modules\AI\Jobs\BaseAiJob (todas sus variantes run*) como por
 * App\Modules\Auth\Jobs\SecurityAnalysisJob (no extiende BaseAiJob — ver su propio docblock).
 *
 * $promptPreview debe ser siempre contenido confiable/fijo del propio Job (una instrucción de
 * tarea, un system prompt) — NUNCA el contenido no confiable del tenant (ver
 * App\Modules\AI\Support\PromptSanitizer). Ese contenido real completo ya vive en el registro
 * fuente de cada Job (AiAnalysis para BaseAiJob; el propio audit_logs analizado, para
 * SecurityAnalysisJob) — acá solo queda una referencia + preview truncado, nunca un duplicado.
 */
final class AiUsageAuditor
{
    private const DEFAULT_PREVIEW_CHARS = 500;
    private const CACHE_KEY             = 'global:ai.audit_prompt_preview_chars';
    private const CACHE_TTL_SECONDS     = 300;

    public static function log(string $jobClass, string $entityType, string|int|null $entityId, string $promptPreview): void
    {
        app(AuditService::class)->log(
            action:     AuditService::AI_USAGE_REQUESTED,
            entityType: $entityType,
            entityId:   $entityId,
            metadata:   [
                'job'            => $jobClass,
                'prompt_preview' => Str::limit($promptPreview, self::previewChars()),
            ],
        );
    }

    private static function previewChars(): int
    {
        /** @var int $chars */
        $chars = Cache::remember(self::CACHE_KEY, self::CACHE_TTL_SECONDS, function (): int {
            try {
                $setting = GlobalSetting::find('ai.audit_prompt_preview_chars');

                if ($setting !== null && $setting->value !== '') {
                    return (int) $setting->value;
                }
            } catch (\Throwable) {
                // La tabla puede no existir todavía durante migraciones — fallback al default
            }

            return self::DEFAULT_PREVIEW_CHARS;
        });

        // El store redis devuelve los enteros como texto (R-09): sin el cast, `strict_types` lanza TypeError en la 2.ª lectura.
        return (int) $chars;
    }
}
