<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Jobs;

use App\Modules\Legacy\Contracts\ArticuloFotoExtractorContract;
use App\Modules\Legacy\Models\Producto;
use App\Modules\Legacy\Services\CatalogPhotoLoader;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Shared\Multitenancy\Tenant;
use App\Shared\Support\CircuitBreaker;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * SCRUM-751 — un Job por artículo, encolado en Horizon (Illumination/migracion-icg-diseno.md
 * §15.4) — nunca un comando síncrono que procese los ~9707 artículos de un tirón. Con ~19GB
 * reales sobre el túnel VPN (§15.1), una corrida síncrona larga es frágil ante un corte
 * SSH/restart de contenedor; Horizon persiste la cola, reintenta con el mismo patrón backoff
 * `[5s, 30s, 120s]` ya usado en `App\Modules\AI\Jobs\BaseAiJob`, y una corrida se puede resumir
 * sola gracias a la idempotencia de abajo.
 *
 * IDEMPOTENCIA — si `catalog_products.photo_url` ya no es null al momento de correr (carga manual
 * concurrente, o este mismo Job reintentando tras un fallo parcial de un intento anterior), el
 * Job no hace nada. Esto permite resumir una corrida cortada sin duplicar trabajo ni pisar una
 * foto ya cargada.
 *
 * CIRCUIT BREAKER — mismo mecanismo que `BaseAiJob` (Redis, `App\Shared\Support\CircuitBreaker`),
 * acá sobre el nombre `legacy_icg` (la conexión SQL Server compartida) para no machacarla si
 * empieza a fallar en cadena — con potencialmente miles de Jobs despachados de una corrida
 * completa, sin este breaker una caída del túnel VPN dispararía miles de intentos de conexión
 * fallidos en paralelo.
 *
 * ⚠️ DISPATCH MANUAL (ej. tinker, para reintentar un artículo puntual) — `spatie/laravel-
 * multitenancy` intercepta todo `ShouldQueue` vía un listener `JobProcessing` y exige que
 * `Tenant::current()` esté seteado en el proceso que llama a `::dispatch()`. Si se despacha este
 * Job sin `Tenant::where('slug', ...)->first()->makeCurrent()` hecho ANTES del dispatch, el
 * listener aborta el Job ANTES de `handle()` — Horizon lo marca "completed" igual, sin ninguna
 * entrada en `failed_jobs`, solo un `ERROR` en `storage/logs/laravel.log`. Encontrado por Pre-QA
 * 2026-08-13. El flujo real (`LegacySyncProductosFotosCommand`, que sí llama `makeCurrent()` antes
 * del loop de dispatch) no está afectado — esto solo importa si alguna vez se despacha a mano.
 */
final class SyncProductoFotoJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /**
     * CORREGIDO 2026-08-14 tras la primera corrida real completa contra ICG (11538 despachados):
     * los 300s originales ("generoso vs. los 90s de BaseAiJob") NO alcanzaban para los archivos
     * más grandes reales — 51 de 54 fallos fueron `TimeoutExceededException`, los otros 3
     * `MaxAttemptsExceededException` con la misma causa de fondo (agotaron los 3 reintentos, cada
     * uno timeouteando igual). A ~80-100 KB/s medidos en vivo (§15.1/§15 del diseño), un BMP de
     * 125MB (el máximo real documentado) tarda ~23 minutos — muy por arriba de los 300s. La
     * tolerancia a fallos de `ProductoFotoConverter` nunca llegaba a aplicarse porque el timeout
     * mata el Job en la extracción, antes de llegar ahí. 2400s (40 min) da margen sobre el peor
     * caso real incluso a throughput pesimista.
     */
    public int $timeout = 2400;

    public function __construct(
        private readonly int $productoId,
        private readonly string $tenantSlug,
    ) {
    }

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [5, 30, 120];
    }

    public function handle(ArticuloFotoExtractorContract $extractor, CatalogPhotoLoader $loader): void
    {
        // CRÍTICO real encontrado por Pre-QA (2026-08-13), reproducido contra un BMP de ~66MB
        // real vía el pipeline async: el worker de Horizon corre con el memory_limit default del
        // contenedor (128M, config/horizon.php), no con los 512M que sí tiene
        // LegacySyncProductosFotosCommand::handle() (mismo criterio §13.3 del runbook). Con datos
        // reales de hasta 125MB (§15.1), el límite se agota en la EXTRACCIÓN (leer el BLOB a un
        // string PHP), antes de llegar al try/catch tolerante a fallos de ProductoFotoConverter —
        // el crash escapa esa protección por completo. Tras 3 reintentos, Horizon lo marca
        // MaxAttemptsExceededException, un mensaje que NO delata la causa real (memoria) — si esto
        // vuelve a pasar, revisar memoria antes que asumir un bug de datos.
        ini_set('memory_limit', '512M');

        $tenant = Tenant::where('slug', $this->tenantSlug)->first();

        if ($tenant === null) {
            return;
        }

        $tenant->makeCurrent();

        try {
            /** @var Producto|null $producto */
            $producto = Producto::query()->find($this->productoId);

            if ($producto === null || $producto->catalog_product_id === null) {
                return;
            }

            /** @var CatalogProduct|null $catalogProduct */
            $catalogProduct = CatalogProduct::query()->find($producto->catalog_product_id);

            // Idempotencia — ver docblock de la clase.
            if ($catalogProduct === null || $catalogProduct->photo_url !== null) {
                return;
            }

            $cb = new CircuitBreaker('legacy_icg', threshold: 5, windowSeconds: 60, openTimeout: 30);

            $bmpBytes = $cb->call(fn (): ?string => $extractor->extract($producto->icg_codarticulo));

            $loader->load($producto, $this->tenantSlug, $bmpBytes);
        } finally {
            Tenant::forgetCurrent();
        }
    }
}
