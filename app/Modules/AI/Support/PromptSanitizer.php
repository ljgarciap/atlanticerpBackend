<?php

declare(strict_types=1);

namespace App\Modules\AI\Support;

/**
 * Delimits untrusted user content before it is interpolated into an AI prompt
 * (SCRUM-54). Wraps it in <user_content> tags so the system prompt can instruct
 * the model to treat everything inside as data, never as instructions, and
 * flags common injection patterns for audit logging (never blocks the request).
 */
final class PromptSanitizer
{
    /**
     * Case-insensitive patterns commonly seen in prompt injection attempts.
     * Hardcoded by design (confirmed by Luis, 2026-07-05) — not configurable
     * from Settings, there is no clear use case for a superadmin to edit this.
     *
     * @var list<string>
     */
    private const SUSPICIOUS_PATTERNS = [
        '/ignor[ae]?\s+(las\s+)?instruccion/i',
        '/ignore\s+(the\s+)?(previous|above|prior)\s+instructions?/i',
        '/you\s+are\s+now\s+/i',
        '/ahora\s+(sos|eres)\s+/i',
        '/system\s*:/i',
        '/\[?system\]?\s*prompt/i',
        '/act\s+as\s+(a|an)\s+/i',
        '/disregard\s+(all\s+)?(previous|prior)/i',
        '/<\/?user_content>/i',
        '/<\/?system>/i',
    ];

    public static function wrap(string $userContent): string
    {
        $escaped = str_ireplace(
            ['<user_content>', '</user_content>', '<system>', '</system>'],
            ['&lt;user_content&gt;', '&lt;/user_content&gt;', '&lt;system&gt;', '&lt;/system&gt;'],
            $userContent,
        );

        return "<user_content>\n{$escaped}\n</user_content>";
    }

    public static function looksLikeInjection(string $userContent): bool
    {
        foreach (self::SUSPICIOUS_PATTERNS as $pattern) {
            if (preg_match($pattern, $userContent) === 1) {
                return true;
            }
        }

        return false;
    }
}
