<?php

declare(strict_types=1);

namespace App\Modules\Auth\Services;

use App\Models\User;
use App\Modules\Auth\Models\SystemSetting;
use App\Modules\Integrations\Lafayette\Services\LafayetteSettings;
use App\Shared\Services\AuditService;
use Illuminate\Support\Facades\DB;

class PreferencesService
{
    private const VALID_LANGUAGES  = ['es', 'en'];
    private const VALID_THEMES     = ['light', 'dark'];
    // SCRUM-746 — 480/600/1440/2880 (8h/10h/24h/48h) agregados a pedido de Luis durante el
    // desarrollo del ticket (aclaración directa, no quedó en Jira) — valores más altos para
    // acompañar el nuevo mecanismo de inactividad, sin tocar los 5 preexistentes.
    private const VALID_TIMEOUTS   = [15, 30, 60, 120, 240, 480, 600, 1440, 2880];
    private const VALID_MFA_MODES  = ['disabled', 'optional', 'required'];
    private const MIN_SHARE_LINK_EXPIRATION_DAYS = 1;
    private const MAX_SHARE_LINK_EXPIRATION_DAYS = 365;

    // SCRUM-746 — sesión expira por inactividad consecutiva, no absoluta desde el login.
    private const MIN_SESSION_INACTIVITY_HOURS     = 1;
    private const MAX_SESSION_INACTIVITY_HOURS     = 720;
    private const DEFAULT_SESSION_INACTIVITY_HOURS = 10;

    /**
     * Techo absoluto de sesión (defensa en profundidad contra un refresh token robado +
     * tráfico sostenido, ver Threat Model `docs/security/THREAT-MODEL-scrum746-session-inactivity.md`
     * Hallazgo 3, decisión de Luis 2026-08-13). Deliberadamente FIJO en código, no expuesto en
     * `system_settings`/UI — no es un valor de negocio ajustable por el cliente, es un límite de
     * seguridad silencioso alineado a `REFRESH_TOKEN_EXPIRATION_DAYS`.
     */
    public const SESSION_ABSOLUTE_CEILING_DAYS = 30;

    /**
     * @return array<string, mixed>
     */
    public function getUserPreferences(User $user): array
    {
        $prefs = $user->preferences ?? [];
        return $this->mergeWithDefaults($prefs);
    }

    /**
     * Precedencia: preferencia personal del usuario -> default de organización
     * -> 60 min. Usado por JwtService al emitir cada token, para que la
     * duración de sesión real coincida con lo configurado, no con un valor
     * fijo de despliegue.
     */
    public function getEffectiveSessionTimeoutMs(User $user): int
    {
        $prefs   = is_array($user->preferences) ? $user->preferences : [];
        $minutes = $this->mergeWithDefaults($prefs)['session_timeout_min'];

        return (int) $minutes * 60_000;
    }

    /**
     * Umbral de inactividad (horas) que gatea `POST /auth/refresh` (SCRUM-746) — valor de
     * `auth.system_settings`, default 10h. Independiente de `session_timeout_min` (que sigue
     * controlando solo el `exp` del access token en sí, sin cambios por este ticket).
     */
    public function getSessionInactivityHours(): int
    {
        return $this->getSystemSettings()['session_inactivity_hours'];
    }

    /**
     * docs/specs/demo-mode.md — único lugar que combina los 2 gates de Modo Demo (global +
     * personal). Los 3 endpoints que aplican `CatalogProduct::scopeVisibleForDemoMode()`
     * (CatalogProductController, BodegaInventoryController, ProductLookupController) llaman
     * este método en vez de repetir `$globalGate && $personalGate` cada uno por su cuenta.
     */
    public function isDemoModeActive(User $user): bool
    {
        $globalGate = $this->getSystemSettings()['demo_mode_enabled'];
        $prefs      = is_array($user->preferences) ? $user->preferences : [];
        $personalGate = (bool) ($prefs['demo_mode_enabled'] ?? false);

        return $globalGate && $personalGate;
    }

    /**
     * @param  array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function updateUserPreferences(User $user, array $data): array
    {
        $current = $user->preferences ?? [];

        if (isset($data['language']) && in_array($data['language'], self::VALID_LANGUAGES, true)) {
            $current['language'] = $data['language'];
        }
        if (isset($data['theme']) && in_array($data['theme'], self::VALID_THEMES, true)) {
            $current['theme'] = $data['theme'];
        }
        if (isset($data['session_timeout_min']) && in_array((int) $data['session_timeout_min'], self::VALID_TIMEOUTS, true)) {
            $current['session_timeout_min'] = (int) $data['session_timeout_min'];
        }
        // Modo Demo (docs/specs/demo-mode.md) — gate PERSONAL, sin permiso especial: cada usuario
        // gestiona su propia preferencia igual que theme/language. Solo tiene efecto real si el
        // gate GLOBAL (system_settings.demo_mode_enabled) también está activo — ver
        // CatalogProduct::scopeVisibleForDemoMode().
        if (isset($data['demo_mode_enabled'])) {
            $current['demo_mode_enabled'] = (bool) $data['demo_mode_enabled'];
        }

        $user->preferences = $current;
        $user->save();

        return $this->mergeWithDefaults($current);
    }

    /**
     * @return array<string, mixed>
     */
    public function getSystemSettings(): array
    {
        $rows = SystemSetting::all()->keyBy('key');

        return [
            'default_language'    => $rows->get('default_language') !== null ? $rows->get('default_language')->value : 'es',
            'default_theme'       => $rows->get('default_theme')    !== null ? $rows->get('default_theme')->value    : 'light',
            // SCRUM-746 — default subido de 60 a 600 min (10h) a pedido de Luis, mismo valor que
            // el default de session_inactivity_hours. Solo afecta instalaciones sin este row
            // todavía (tenants nuevos) — no pisa el valor ya persistido en ningún entorno existente.
            'session_timeout_min' => (int) ($rows->get('session_timeout_min') !== null ? $rows->get('session_timeout_min')->value : 600),
            'mfa_mode'            => is_string($rows->get('mfa_mode')?->value) ? $rows->get('mfa_mode')->value : 'optional',
            'share_links_expiration_days' => (int) ($rows->get('share_links_expiration_days') !== null ? $rows->get('share_links_expiration_days')->value : 30),
            'session_inactivity_hours' => (int) ($rows->get('session_inactivity_hours') !== null ? $rows->get('session_inactivity_hours')->value : self::DEFAULT_SESSION_INACTIVITY_HOURS),
            // Modo Demo (docs/specs/demo-mode.md) — gate GLOBAL, default apagado. Mientras esté en
            // false, el toggle personal de cada usuario se ignora (ver scopeVisibleForDemoMode()).
            'demo_mode_enabled' => (bool) ($rows->get('demo_mode_enabled') !== null ? $rows->get('demo_mode_enabled')->value : false),
            // SCRUM-990 — umbral de la guarda del sincronizador de Lafayette (0,5..1,0, defecto 0,9).
            'lafayette_guarda_min_ratio' => app(LafayetteSettings::class)->guardaMinRatio(),
        ];
    }

    /**
     * @param  array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function updateSystemSettings(User $updatedBy, array $data): array
    {
        $now = now();

        if (isset($data['default_language']) && in_array($data['default_language'], self::VALID_LANGUAGES, true)) {
            SystemSetting::updateOrCreate(['key' => 'default_language'], [
                'value'      => $data['default_language'],
                'updated_by' => $updatedBy->id,
                'updated_at' => $now,
            ]);
        }
        if (isset($data['default_theme']) && in_array($data['default_theme'], self::VALID_THEMES, true)) {
            SystemSetting::updateOrCreate(['key' => 'default_theme'], [
                'value'      => $data['default_theme'],
                'updated_by' => $updatedBy->id,
                'updated_at' => $now,
            ]);
        }
        if (isset($data['session_timeout_min']) && in_array((int) $data['session_timeout_min'], self::VALID_TIMEOUTS, true)) {
            SystemSetting::updateOrCreate(['key' => 'session_timeout_min'], [
                'value'      => (int) $data['session_timeout_min'],
                'updated_by' => $updatedBy->id,
                'updated_at' => $now,
            ]);
        }
        if (isset($data['mfa_mode']) && in_array($data['mfa_mode'], self::VALID_MFA_MODES, true)) {
            SystemSetting::updateOrCreate(['key' => 'mfa_mode'], [
                'value'      => $data['mfa_mode'],
                'updated_by' => $updatedBy->id,
                'updated_at' => $now,
            ]);
        }
        if (
            isset($data['share_links_expiration_days'])
            && (int) $data['share_links_expiration_days'] >= self::MIN_SHARE_LINK_EXPIRATION_DAYS
            && (int) $data['share_links_expiration_days'] <= self::MAX_SHARE_LINK_EXPIRATION_DAYS
        ) {
            SystemSetting::updateOrCreate(['key' => 'share_links_expiration_days'], [
                'value'      => (int) $data['share_links_expiration_days'],
                'updated_by' => $updatedBy->id,
                'updated_at' => $now,
            ]);
        }
        if (
            isset($data['session_inactivity_hours'])
            && (int) $data['session_inactivity_hours'] >= self::MIN_SESSION_INACTIVITY_HOURS
            && (int) $data['session_inactivity_hours'] <= self::MAX_SESSION_INACTIVITY_HOURS
        ) {
            // SCRUM-746, Threat Model Hallazgo 5 — rango validado explícitamente: un valor 0 o
            // negativo aquí produciría DoS total de autenticación del tenant (el gate atómico de
            // AuthController::refresh() nunca encontraría una fila dentro del umbral).
            SystemSetting::updateOrCreate(['key' => 'session_inactivity_hours'], [
                'value'      => (int) $data['session_inactivity_hours'],
                'updated_by' => $updatedBy->id,
                'updated_at' => $now,
            ]);
        }
        if (isset($data['demo_mode_enabled'])) {
            $newValue = (bool) $data['demo_mode_enabled'];
            $oldValue = (bool) (SystemSetting::where('key', 'demo_mode_enabled')->first()->value ?? false);

            SystemSetting::updateOrCreate(['key' => 'demo_mode_enabled'], [
                'value'      => $newValue,
                'updated_by' => $updatedBy->id,
                'updated_at' => $now,
            ]);

            // docs/specs/demo-mode.md — se audita solo el gate GLOBAL (afecta lo que ve todo el
            // tenant); el toggle personal de cada usuario no se audita, es preferencia de UI.
            if ($newValue !== $oldValue) {
                app(AuditService::class)->log(
                    action:       AuditService::SETTINGS_UPDATED,
                    entityType:   'system_setting',
                    entityId:     'demo_mode_enabled',
                    oldValues:    ['demo_mode_enabled' => $oldValue],
                    newValues:    ['demo_mode_enabled' => $newValue],
                    actingUserId: $updatedBy->id,
                );
            }
        }

        if (isset($data['lafayette_guarda_min_ratio'])) {
            $newRatio = round((float) $data['lafayette_guarda_min_ratio'], 4);
            $oldRatio = app(LafayetteSettings::class)->guardaMinRatio();

            SystemSetting::updateOrCreate(['key' => LafayetteSettings::GUARDA_MIN_RATIO], [
                'value'      => $newRatio,
                'updated_by' => $updatedBy->id,
                'updated_at' => $now,
            ]);

            if ($newRatio !== $oldRatio) {
                app(AuditService::class)->log(
                    action:       AuditService::SETTINGS_UPDATED,
                    entityType:   'system_setting',
                    entityId:     LafayetteSettings::GUARDA_MIN_RATIO,
                    oldValues:    [LafayetteSettings::GUARDA_MIN_RATIO => $oldRatio],
                    newValues:    [LafayetteSettings::GUARDA_MIN_RATIO => $newRatio],
                    actingUserId: $updatedBy->id,
                );
            }
        }

        return $this->getSystemSettings();
    }

    /**
     * @param  array<string, mixed> $prefs
     * @return array<string, mixed>
     */
    private function mergeWithDefaults(array $prefs): array
    {
        $system = $this->getSystemSettings();

        return [
            'language'           => $prefs['language']           ?? $system['default_language'],
            'theme'              => $prefs['theme']              ?? $system['default_theme'],
            'session_timeout_min'=> $prefs['session_timeout_min'] ?? $system['session_timeout_min'],
            // Sin fallback a un default de sistema — a diferencia de language/theme, el gate
            // personal de Modo Demo no tiene "default de organización", arranca en false para
            // todo usuario que nunca lo tocó.
            'demo_mode_enabled'  => (bool) ($prefs['demo_mode_enabled'] ?? false),
        ];
    }
}
