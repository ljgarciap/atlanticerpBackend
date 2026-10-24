<?php

declare(strict_types=1);

namespace App\Modules\Biblioteca\Services;

use App\Modules\Auth\Models\SystemSetting;

/**
 * SCRUM-984 — topes, formatos y TTL de la biblioteca. Viven en `auth.system_settings` (nunca
 * constantes de negocio, regla del workspace): superadmin los ajusta sin deploy vía
 * PUT /api/biblioteca/config. Los defaults de acá solo cubren un tenant sin la fila todavía —
 * la migración 2026_09_30_100000 siembra los mismos valores.
 */
final class LibrarySettings
{
    public const MAX_PDF_MB           = 'library_max_pdf_mb';
    public const MAX_VIDEO_MB         = 'library_max_video_mb';
    public const ALLOWED_EXTENSIONS   = 'library_allowed_extensions';
    public const TTL_VIEW_SECONDS     = 'library_url_ttl_view_seconds';
    public const TTL_VIDEO_SECONDS    = 'library_url_ttl_video_seconds';
    public const TTL_UPLOAD_SECONDS   = 'library_url_ttl_upload_seconds';
    public const MAX_PENDING_UPLOADS  = 'library_max_pending_uploads';

    /**
     * Forma ÚNICA de la API (GET y PUT /api/biblioteca/config): clave de la API => clave en
     * `system_settings`. `max_*_bytes` y `modules` se devuelven pero son derivados (solo lectura).
     */
    public const API_KEYS = [
        'max_pdf_mb'           => self::MAX_PDF_MB,
        'max_video_mb'         => self::MAX_VIDEO_MB,
        'allowed_extensions'   => self::ALLOWED_EXTENSIONS,
        'url_ttl_view_seconds'  => self::TTL_VIEW_SECONDS,
        'url_ttl_video_seconds' => self::TTL_VIDEO_SECONDS,
        'url_ttl_upload_seconds' => self::TTL_UPLOAD_SECONDS,
        'max_pending_uploads'  => self::MAX_PENDING_UPLOADS,
    ];

    /** @var array<string, mixed> */
    public const DEFAULTS = [
        self::MAX_PDF_MB          => 50,
        self::MAX_VIDEO_MB        => 500,
        self::ALLOWED_EXTENSIONS  => ['pdf', 'mp4', 'webm'],
        self::TTL_VIEW_SECONDS    => 900,
        self::TTL_VIDEO_SECONDS   => 3600,
        self::TTL_UPLOAD_SECONDS  => 1800,
        self::MAX_PENDING_UPLOADS => 5,
    ];

    public function int(string $key): int
    {
        $value = $this->raw($key);

        return is_numeric($value) ? (int) $value : (int) self::DEFAULTS[$key];
    }

    /** @return list<string> */
    public function allowedExtensions(): array
    {
        $value = $this->raw(self::ALLOWED_EXTENSIONS);
        $list  = is_array($value) ? $value : (array) self::DEFAULTS[self::ALLOWED_EXTENSIONS];

        return array_values(array_map(static fn ($e): string => strtolower((string) $e), $list));
    }

    public function maxBytesFor(string $type): int
    {
        if ($type === 'video') {
            return $this->int(self::MAX_VIDEO_MB) * 1024 * 1024;
        }

        // Un PDF mayor que el tope del escáner se rechazaría en finalize ("no se pudo verificar"): el tope
        // efectivo nunca lo supera, aunque un valor ya guardado en system_settings sea mayor.
        return min($this->int(self::MAX_PDF_MB) * 1024 * 1024, $this->pdfScanCeilingBytes());
    }

    /** Máximo configurable de `library_max_pdf_mb`: lo que el escáner de contenido activo puede verificar completo. */
    public function maxPdfMbCeiling(): int
    {
        return max(1, intdiv($this->pdfScanCeilingBytes(), 1024 * 1024));
    }

    private function pdfScanCeilingBytes(): int
    {
        return (int) config('biblioteca.pdf_scan_max_bytes', 100 * 1024 * 1024);
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        $out = [];
        foreach (array_keys(self::DEFAULTS) as $key) {
            $out[$key] = $key === self::ALLOWED_EXTENSIONS ? $this->allowedExtensions() : $this->int($key);
        }

        return $out;
    }

    /**
     * Lo que devuelven GET y PUT /config: las mismas claves que acepta el PUT (MB, TTL, extensiones,
     * cuota) más los derivados de solo lectura que ya consumía el frontend (bytes y módulos).
     *
     * @return array<string, mixed>
     */
    public function toApi(): array
    {
        $out = [];
        foreach (self::API_KEYS as $apiKey => $settingKey) {
            $out[$apiKey] = $settingKey === self::ALLOWED_EXTENSIONS ? $this->allowedExtensions() : $this->int($settingKey);
        }
        $out['max_pdf_bytes']   = $this->maxBytesFor('pdf');
        $out['max_video_bytes'] = $this->maxBytesFor('video');
        $out['modules']         = array_values((array) config('biblioteca.modules'));

        return $out;
    }

    private function raw(string $key): mixed
    {
        $row = SystemSetting::find($key);

        return $row !== null ? $row->value : self::DEFAULTS[$key];
    }
}
