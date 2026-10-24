<?php

declare(strict_types=1);

namespace App\Modules\CRM\Services;

use App\Modules\CRM\Models\CrmSetting;
use App\Shared\Auth\JwtActor;

/**
 * SCRUM-944/945 — auditoría de valores de negocio hardcodeados (docs/specs/
 * auditoria-valores-parametricos-superadmin.md). `DashboardService`/`ProjectService` tenían,
 * duplicados en ambos archivos, 3 umbrales de días hardcodeados: "próximo a vencer" (entrega
 * dentro de N días), "reciente" y "frío" (sin actividad hace N días). Único punto de lectura/
 * escritura ahora — mismo patrón `intSetting()` que `BodegaSettingsService`.
 */
final class CrmSettingsService
{
    private const KEY_PROXIMO_DIAS = 'entrega_proxima_dias';

    /** REQ original (SCRUM-14/CRM Home): "próxima a vencer" = entrega dentro de 3 días. */
    private const DEFAULT_PROXIMO_DIAS = 3;

    private const KEY_FRESHNESS_RECENT_DIAS = 'freshness_recent_dias';

    private const DEFAULT_FRESHNESS_RECENT_DIAS = 7;

    private const KEY_FRESHNESS_STALE_DIAS = 'freshness_stale_dias';

    private const DEFAULT_FRESHNESS_STALE_DIAS = 21;

    // ── SCRUM-998 — PDF de Catálogo: tamaño máximo del PDF plano y volúmenes en segundo plano ──────────────
    // Medido en dev con el catálogo real: 500 productos = 6,1 s y 84 MB; 2.000 = 45 s y 299 MB (PHP-FPM corta a
    // los 30 s), y ~30K serían ~5.000 páginas. Umbral de negocio => configurable por superadmin, nunca constante.

    private const KEY_CATALOG_PDF_MAX_SINGLE = 'catalog_pdf_max_single_products';

    private const DEFAULT_CATALOG_PDF_MAX_SINGLE = 500;

    // Fotos remotas del PDF plano: dompdf renderiza ~500 productos en ~6 s, pero cada foto se descarga de un proveedor externo
    // (~83 ms efectivos con 20 conexiones en paralelo, medido en dev): un PDF plano de 500 productos que TODOS tengan foto tardaría
    // ~50 s y toparía con el timeout de 60 s de nginx. Por eso el PDF plano también tiene un máximo de fotos.
    private const KEY_CATALOG_PDF_MAX_SINGLE_PHOTOS = 'catalog_pdf_max_single_photos';

    private const DEFAULT_CATALOG_PDF_MAX_SINGLE_PHOTOS = 200;

    private const KEY_CATALOG_PDF_VOLUME_SIZE = 'catalog_pdf_volume_size';

    private const DEFAULT_CATALOG_PDF_VOLUME_SIZE = 500;

    private const KEY_CATALOG_PDF_RETENTION_DIAS = 'catalog_pdf_export_retention_days';

    private const DEFAULT_CATALOG_PDF_RETENTION_DIAS = 7;

    /** Días hacia adelante desde hoy que cuentan como "próximo a vencer" (Dashboard + filtro urgency=proximo). */
    public function proximoDias(): int
    {
        return $this->intSetting(self::KEY_PROXIMO_DIAS, self::DEFAULT_PROXIMO_DIAS);
    }

    /** Días hacia atrás de actividad para que un proyecto cuente como "reciente" (filtro freshness=recent). */
    public function freshnessRecentDias(): int
    {
        return $this->intSetting(self::KEY_FRESHNESS_RECENT_DIAS, self::DEFAULT_FRESHNESS_RECENT_DIAS);
    }

    /** Días sin actividad a partir de los cuales un proyecto cuenta como "frío"/stale. */
    public function freshnessStaleDias(): int
    {
        return $this->intSetting(self::KEY_FRESHNESS_STALE_DIAS, self::DEFAULT_FRESHNESS_STALE_DIAS);
    }

    /** SCRUM-998 — máximo de productos para generar el PDF de Catálogo plano y síncrono; por encima se usa el ZIP de volúmenes. */
    public function catalogPdfMaxSingleProducts(): int
    {
        return $this->intSetting(self::KEY_CATALOG_PDF_MAX_SINGLE, self::DEFAULT_CATALOG_PDF_MAX_SINGLE);
    }

    /** SCRUM-998 — máximo de productos CON foto para el PDF plano y síncrono; por encima se genera el ZIP de volúmenes en segundo plano. */
    public function catalogPdfMaxSinglePhotos(): int
    {
        return $this->intSetting(self::KEY_CATALOG_PDF_MAX_SINGLE_PHOTOS, self::DEFAULT_CATALOG_PDF_MAX_SINGLE_PHOTOS);
    }

    /** SCRUM-998 — productos por volumen (un PDF por tanda) del ZIP generado en segundo plano. */
    public function catalogPdfVolumeSize(): int
    {
        return $this->intSetting(self::KEY_CATALOG_PDF_VOLUME_SIZE, self::DEFAULT_CATALOG_PDF_VOLUME_SIZE);
    }

    /** SCRUM-998 — días que se conserva un ZIP de catálogo generado antes de borrarlo. */
    public function catalogPdfExportRetentionDays(): int
    {
        return $this->intSetting(self::KEY_CATALOG_PDF_RETENTION_DIAS, self::DEFAULT_CATALOG_PDF_RETENTION_DIAS);
    }

    /**
     * Usado por `CrmSettingsController::show()`.
     *
     * @return array<string, int>
     */
    public function allSettings(): array
    {
        return [
            'entrega_proxima_dias' => $this->proximoDias(),
            'freshness_recent_dias' => $this->freshnessRecentDias(),
            'freshness_stale_dias' => $this->freshnessStaleDias(),
            'catalog_pdf_max_single_products' => $this->catalogPdfMaxSingleProducts(),
            'catalog_pdf_max_single_photos' => $this->catalogPdfMaxSinglePhotos(),
            'catalog_pdf_volume_size' => $this->catalogPdfVolumeSize(),
            'catalog_pdf_export_retention_days' => $this->catalogPdfExportRetentionDays(),
        ];
    }

    /** @param array<string, int> $validated */
    public function updateSettings(array $validated): void
    {
        $map = [
            'entrega_proxima_dias' => self::KEY_PROXIMO_DIAS,
            'freshness_recent_dias' => self::KEY_FRESHNESS_RECENT_DIAS,
            'freshness_stale_dias' => self::KEY_FRESHNESS_STALE_DIAS,
            'catalog_pdf_max_single_products' => self::KEY_CATALOG_PDF_MAX_SINGLE,
            'catalog_pdf_max_single_photos' => self::KEY_CATALOG_PDF_MAX_SINGLE_PHOTOS,
            'catalog_pdf_volume_size' => self::KEY_CATALOG_PDF_VOLUME_SIZE,
            'catalog_pdf_export_retention_days' => self::KEY_CATALOG_PDF_RETENTION_DIAS,
        ];

        foreach ($map as $apiField => $storageKey) {
            if (! array_key_exists($apiField, $validated)) {
                continue;
            }

            CrmSetting::updateOrCreate(
                ['key' => $storageKey],
                ['value' => (string) $validated[$apiField]],
            );
        }
    }

    private function intSetting(string $key, int $default): int
    {
        $row = CrmSetting::where('key', $key)->first();
        if ($row === null) {
            // SCRUM-1158 (Pre-QA) — «Ver como usuario» es de solo lectura: la fila de valor por defecto se crea de forma perezosa en la primera lectura; con `act` se
            // devuelve el mismo valor por defecto SIN persistirlo (lo creará la primera lectura real). Sin `act` queda idéntico.
            $row = JwtActor::currentRequestIsImpersonating()
                ? new CrmSetting(['key' => $key, 'value' => (string) $default])
                : CrmSetting::create(['key' => $key, 'value' => (string) $default]);
        }

        $value = (int) $row->value;

        return $value > 0 ? $value : $default;
    }
}
