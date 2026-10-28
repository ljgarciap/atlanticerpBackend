<?php

declare(strict_types=1);

namespace App\Modules\AI\Support;

use App\Modules\AdminCont\Models\AdminContCompanyProfile;
use Illuminate\Support\Str;

/**
 * Fase 4c — a qué se dedica la empresa, para los prompts de IA. Sale de «Datos de la Empresa» (`company_profile.descripcion_corta`,
 * editable por superadmin): ningún prompt fija un sector. Requiere el tenant activo. Vacío → «una empresa».
 */
final class BusinessProfile
{
    /** Marcador que los jobs ponen en su prompt de sistema; `BaseAiJob::run()` lo sustituye con el tenant ya activo. */
    public const PLACEHOLDER = '{negocio}';

    public const FALLBACK = 'una empresa';

    public static function description(): string
    {
        $text = trim(AdminContCompanyProfile::current()->descripcion_corta);

        return $text === '' ? self::FALLBACK : Str::limit($text, 500, '');
    }

    public static function fill(string $systemPrompt): string
    {
        return str_contains($systemPrompt, self::PLACEHOLDER)
            ? str_replace(self::PLACEHOLDER, self::description(), $systemPrompt)
            : $systemPrompt;
    }
}
