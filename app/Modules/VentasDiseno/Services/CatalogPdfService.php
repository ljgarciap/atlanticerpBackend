<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Services;

use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Shared\Support\PdfLogo;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as PdfDocument;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * SCRUM-702 (REQ-622) — PDF dinámico de Catálogo pensado para compartir con un cliente externo.
 * Replica el layout del PDF de ejemplo estático (`CatalogController::exampleCatalogPdf()`, REQ-621)
 * pero con datos reales: por producto, foto/referencia/marca/descripción/precio + tabla de specs
 * clave tomada de `technical_spec` (ficha técnica SCRUM-425/426) — NUNCA el archivo real de ficha
 * técnica (RN2/RN6: solo se refleja como datos, no se adjunta el documento).
 *
 * SCRUM-844 — 3 correcciones sobre el PDF: (1) `resolvePhotoUrl()` replica el criterio de
 * `CatalogService::photoUrl()` (foto S3 vía `photo_key`, fallback a `photo_url` legacy) en vez de
 * inyectar el servicio completo — mismo motivo que RN1 de abajo, esta clase arma su propio array
 * desde cero; (2) `isRemoteEnabled` habilitado — sin esto dompdf nunca cargaba ninguna imagen
 * remota (S3 ni legacy), el bug no era solo la URL sin resolver; (3) header/footer estandarizados
 * contra `quote-pdf.blade.php` (logo vía `PdfLogo::dataUri()`, footer real vía `page_text()` —
 * ver ese blade y `QuotePdfService` para el porqué de `isPhpEnabled`).
 *
 * RN1 (criterio duro, no negociable) — el PDF NUNCA incluye ningún dato de stock/disponibilidad
 * (`disponible`/`en_camino`/`stock_quantity` o equivalente). A propósito `formatForPdf()` arma el
 * array desde cero seleccionando solo los campos permitidos — nunca reutiliza
 * `CatalogService::format()` (que sí incluye stock, ver su docblock) ni pasa el modelo Eloquent
 * completo a la vista, para que sea estructuralmente imposible que un campo de stock se filtre por
 * accidente a través de la Blade.
 */
final class CatalogPdfService
{
    /**
     * SCRUM-998 — tope duro del camino síncrono, independiente del umbral configurable (`catalog_pdf_max_single_products`,
     * que `CrmSettingsRequest` limita a 1.000): dompdf mantiene todo el árbol en memoria y con ~2.000 productos ya pasa de
     * 299 MB y 45 s (medido en dev; PHP-FPM corta a los 30 s). Ningún llamador puede hidratar más que esto en una petición.
     */
    public const HARD_MAX_SINGLE_PRODUCTS = 1000;

    /**
     * Fotos remotas que se descargan a la vez antes de renderizar, y sus plazos: una foto lenta no frena el PDF. Medido en dev contra el
     * proveedor real de fotos: 10 conexiones ~56-210 ms/foto, 20 ~35-83 ms/foto, 40 ~74 ms/foto (sin ganancia sobre 20). El proveedor es
     * INTERMITENTE: en una corrida con 20 conexiones 23 de 100 fotos dieron `cURL error 28` (conexión agotada a los 2 s) y en la siguiente
     * ninguna, con las mismas URLs (que en serie responden todas 200). Por eso hay un reintento (abajo), no solo plazos más largos.
     */
    private const PHOTO_CONCURRENCY = 20;

    private const PHOTO_CONNECT_TIMEOUT_SECONDS = 3;

    private const PHOTO_TIMEOUT_SECONDS = 6;

    /** Reintento ÚNICO solo de las fotos que fallaron por una causa transitoria: menos conexiones a la vez y plazos más holgados. */
    private const PHOTO_RETRY_CONCURRENCY = 10;

    private const PHOTO_RETRY_CONNECT_TIMEOUT_SECONDS = 5;

    private const PHOTO_RETRY_TIMEOUT_SECONDS = 10;

    /** Una foto de más de 2 MB se descarta (recuadro "Sin imagen"): un PDF de cientos de productos no puede crecer sin techo. */
    private const PHOTO_MAX_BYTES = 2_097_152;

    /**
     * SCRUM-998 — cuántos productos entrarían en el PDF, sin hidratarlos (decide PDF plano vs. ZIP de volúmenes).
     *
     * @param 'completo'|'seleccionados' $mode
     * @param list<int> $productIds Ignorado cuando $mode === 'completo' (RN4).
     */
    public function countProducts(string $mode, array $productIds = []): int
    {
        return $this->productsQuery($mode, $productIds)->count();
    }

    /**
     * SCRUM-998 — cuántos de esos productos llevan foto (`photo_url` o `photo_key`), sin hidratarlos: cada foto remota es una descarga
     * y decide, junto con `countProducts()`, si el PDF cabe en una petición.
     *
     * @param 'completo'|'seleccionados' $mode
     * @param list<int> $productIds Ignorado cuando $mode === 'completo' (RN4).
     */
    public function countPhotos(string $mode, array $productIds = []): int
    {
        return $this->productsQuery($mode, $productIds)
            ->where(fn ($q) => $q->whereRaw("coalesce(photo_url, '') <> ''")->orWhereRaw("coalesce(photo_key, '') <> ''"))
            ->count();
    }

    /**
     * PDF plano y síncrono. SOLO para conjuntos que caben en una petición (`countProducts() <=
     * catalog_pdf_max_single_products`); el catálogo grande va por `generateVolume()` desde el job.
     *
     * @param 'completo'|'seleccionados' $mode
     * @param list<int> $productIds Ignorado cuando $mode === 'completo' (RN4).
     */
    public function generate(string $mode, array $productIds = []): PdfDocument
    {
        if ($this->countProducts($mode, $productIds) > self::HARD_MAX_SINGLE_PRODUCTS) {
            throw new \DomainException('El PDF plano no admite más de '.self::HARD_MAX_SINGLE_PRODUCTS.' productos; usar volúmenes.');
        }

        return $this->render(array_values($this->productsQuery($mode, $productIds)->get()->all()), null);
    }

    /**
     * SCRUM-998 — un volumen (una tanda de `$volumeSize` productos, ordenados por nombre y id) del ZIP en segundo plano.
     * Mismo layout, mismas reglas (RN1) y misma precarga de fotos que el PDF plano; el subtítulo indica "Volumen i de n".
     *
     * @param 'completo'|'seleccionados' $mode
     * @param list<int> $productIds
     */
    public function generateVolume(string $mode, array $productIds, int $volume, int $totalVolumes, int $volumeSize): PdfDocument
    {
        /** @var list<CatalogProduct> $products */
        $products = array_values($this->productsQuery($mode, $productIds)->forPage($volume, $volumeSize)->get()->all());

        return $this->render($products, "Volumen {$volume} de {$totalVolumes}");
    }

    /** @param list<CatalogProduct> $products */
    private function render(array $products, ?string $volumeLabel): PdfDocument
    {
        $rows = $this->inlinePhotos(array_map(fn (CatalogProduct $p) => $this->formatForPdf($p), $products));

        // `isRemoteEnabled` apagado: las fotos ya vienen embebidas (data URI) desde `inlinePhotos()`, así que dompdf nunca
        // sale a la red por su cuenta (una foto lenta o caída ya no frena el PDF ni deja una petición colgada).
        return Pdf::setOption('isRemoteEnabled', false)
            ->setOption('isPhpEnabled', true)
            ->loadView('ventas-diseno.catalog-pdf', [
                'products'     => $rows,
                'generatedAt'  => now(),
                'logoDataUri'  => PdfLogo::dataUri(),
                'volumeLabel'  => $volumeLabel,
            ])->setPaper('letter');
    }

    /**
     * @param 'completo'|'seleccionados' $mode
     * @param list<int> $productIds
     * @return \Illuminate\Database\Eloquent\Builder<CatalogProduct>
     */
    private function productsQuery(string $mode, array $productIds): \Illuminate\Database\Eloquent\Builder
    {
        $query = CatalogProduct::query()->where('is_active', true)->orderBy('name')->orderBy('id');

        // RN4 — "completo" trae TODOS los productos activos, ignorando cualquier
        // selección/filtro recibido; solo "seleccionados" acota por product_ids.
        if ($mode === 'seleccionados') {
            $query->whereIn('id', $productIds);
        }

        return $query;
    }

    /**
     * SCRUM-998 — descarga las fotos remotas de a `PHOTO_CONCURRENCY` en paralelo y las embebe como data URI. Antes dompdf las
     * bajaba una por una (~0,73 s cada una, medido en dev), lo que con unas 40 fotos ya superaba los 30 s de PHP-FPM. Una foto que
     * falla, tarda más de `PHOTO_TIMEOUT_SECONDS`, no es una imagen soportada o pesa más de `PHOTO_MAX_BYTES` queda en `null`
     * (recuadro "Sin imagen") en vez de frenar o romper el PDF.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function inlinePhotos(array $rows): array
    {
        $urls = [];
        foreach ($rows as $i => $row) {
            $url = $row['photo_url'] ?? null;
            if (is_string($url) && preg_match('#^https?://#i', $url) === 1) {
                $urls[$i] = $url;
            } elseif ($url !== null && ! (is_string($url) && str_starts_with($url, 'data:'))) {
                $rows[$i]['photo_url'] = null; // ruta relativa o valor no descargable: nunca lo dejamos a dompdf
            }
        }

        $results = $this->fetchPhotos($urls, self::PHOTO_CONCURRENCY, self::PHOTO_CONNECT_TIMEOUT_SECONDS, self::PHOTO_TIMEOUT_SECONDS);

        // Un solo reintento, solo de lo transitorio (timeout/conexión, 5xx, 408, 429): un 404 o una imagen inválida no mejoran al repetir.
        $retry = array_filter($urls, fn (string $url, int $i): bool => $this->isTransientFailure($results[$i] ?? null), ARRAY_FILTER_USE_BOTH);
        if ($retry !== []) {
            $results = $this->fetchPhotos($retry, self::PHOTO_RETRY_CONCURRENCY, self::PHOTO_RETRY_CONNECT_TIMEOUT_SECONDS, self::PHOTO_RETRY_TIMEOUT_SECONDS) + $results;
        }

        foreach ($urls as $i => $url) {
            $response = $results[$i] ?? null;
            $rows[$i]['photo_url'] = $response instanceof Response ? $this->toDataUri($response) : null;
        }

        return $rows;
    }

    /**
     * @param array<int, string> $urls índice de fila => URL
     * @return array<int, Response|\Throwable|null> índice de fila => respuesta (o la excepción de conexión)
     */
    private function fetchPhotos(array $urls, int $concurrency, int $connectTimeout, int $timeout): array
    {
        $results = [];
        foreach (array_chunk($urls, max(1, $concurrency), true) as $chunk) {
            $responses = Http::pool(function (Pool $pool) use ($chunk, $connectTimeout, $timeout) {
                $requests = [];
                foreach ($chunk as $i => $url) {
                    $requests[] = $pool->as((string) $i)->connectTimeout($connectTimeout)->timeout($timeout)->get($url);
                }

                return $requests;
            });

            foreach ($chunk as $i => $url) {
                $results[$i] = $responses[(string) $i] ?? null;
            }
        }

        return $results;
    }

    private function isTransientFailure(mixed $result): bool
    {
        if (! $result instanceof Response) {
            return true; // excepción de conexión o timeout (cURL error 28...)
        }

        return $result->serverError() || in_array($result->status(), [408, 429], true);
    }

    private function toDataUri(Response $response): ?string
    {
        if (! $response->successful()) {
            return null;
        }

        $body = $response->body();
        if ($body === '' || strlen($body) > self::PHOTO_MAX_BYTES) {
            return null;
        }

        // Tipo real por contenido (no por el header del servidor remoto): solo los formatos que dompdf renderiza.
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($body) ?: '';
        $allowed = ['image/jpeg', 'image/png', 'image/gif'];
        if (function_exists('imagecreatefromwebp')) {
            $allowed[] = 'image/webp';
        }

        return in_array($mime, $allowed, true) ? 'data:'.$mime.';base64,'.base64_encode($body) : null;
    }

    /**
     * Array explícito de campos permitidos — ver docblock de la clase (RN1). Nunca incluir
     * `stock_quantity`/`disponible`/`en_camino`/`cost`/`margin_percent` ni ningún otro campo
     * interno acá.
     *
     * @return array<string, mixed>
     */
    private function formatForPdf(CatalogProduct $product): array
    {
        return [
            'id'          => $product->id,
            'reference'   => $product->reference,
            'name'        => $product->name,
            'description' => $product->description,
            'brand'       => $product->brand,
            'photo_url'   => $this->resolvePhotoUrl($product),
            'price_full'  => $product->price_full,
            'category'    => $product->category,
            'specs'       => $this->formatSpecs($product->technical_spec),
        ];
    }

    /**
     * Fase 9c — los campos y su orden salen del catálogo `technical_spec_field` (vocabulario de la empresa): se imprimen los ACTIVOS en su
     * orden con la etiqueta del catálogo, y después cualquier clave con valor que el catálogo no conozca (con su clave legible), para no ocultar datos.
     * Una entrada desactivada se omite a propósito.
     *
     * @param array<string, string>|null $technicalSpec
     * @return list<array{label: string, value: string}>
     */
    private function formatSpecs(?array $technicalSpec): array
    {
        if ($technicalSpec === null) {
            return [];
        }

        $all = \App\Shared\Catalogs\CatalogService::entries('technical_spec_field', onlyActive: false);
        $known = $all->pluck('key')->map(strval(...))->all();
        $fields = $all->filter(static fn ($e): bool => $e->is_active)->map(static fn ($e): array => ['key' => (string) $e->key, 'label' => (string) $e->label])->values()->all();
        foreach (array_diff(array_keys($technicalSpec), $known) as $extra) {
            $fields[] = ['key' => (string) $extra, 'label' => ucfirst(str_replace('_', ' ', (string) $extra))];
        }

        $result = [];
        foreach ($fields as $field) {
            $value = $technicalSpec[$field['key']] ?? null;
            if ($value === null || $value === '') {
                continue;
            }

            $result[] = ['label' => $field['label'], 'value' => (string) $value];
        }

        return $result;
    }

    /** Ver docblock de la clase (SCRUM-844) — mismo criterio que `CatalogService::photoUrl()`. */
    private function resolvePhotoUrl(CatalogProduct $product): ?string
    {
        if ($product->photo_key !== null) {
            $base = rtrim(
                (string) (config('filesystems.disks.s3-public.cdn_url') ?: config('filesystems.disks.s3-public.url', '')),
                '/'
            );

            return $base !== '' ? "{$base}/public/{$product->photo_key}" : $product->photo_key;
        }

        return $product->photo_url;
    }
}
