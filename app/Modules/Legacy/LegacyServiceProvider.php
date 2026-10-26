<?php

declare(strict_types=1);

namespace App\Modules\Legacy;

use App\Modules\Legacy\Contracts\AlbaranCompraExtractorContract;
use App\Modules\Legacy\Contracts\AlbaranExtractorContract;
use App\Modules\Legacy\Contracts\AlmacenExtractorContract;
use App\Modules\Legacy\Contracts\AlmacenVerificationExtractorContract;
use App\Modules\Legacy\Contracts\ArticuloFotoExtractorContract;
use App\Modules\Legacy\Contracts\ClienteExtractorContract;
use App\Modules\Legacy\Contracts\ClienteRawExtractorContract;
use App\Modules\Legacy\Contracts\ClienteRawVerificationExtractorContract;
use App\Modules\Legacy\Contracts\ContactoClienteRawExtractorContract;
use App\Modules\Legacy\Contracts\CostoAlmacenExtractorContract;
use App\Modules\Legacy\Contracts\CotizacionExtractorContract;
use App\Modules\Legacy\Contracts\DescatalogadoStockIcgExtractorContract;
use App\Modules\Legacy\Contracts\FacturaExtractorContract;
use App\Modules\Legacy\Contracts\InventarioCabExtractorContract;
use App\Modules\Legacy\Contracts\MovimientoExtractorContract;
use App\Modules\Legacy\Contracts\MovimientoVerificationExtractorContract;
use App\Modules\Legacy\Contracts\PagoExtractorContract;
use App\Modules\Legacy\Contracts\PedidoExtractorContract;
use App\Modules\Legacy\Contracts\ProductoDescatalogadoExtractorContract;
use App\Modules\Legacy\Contracts\ProductoExtractorContract;
use App\Modules\Legacy\Contracts\ProveedorExtractorContract;
use App\Modules\Legacy\Contracts\StockAl4ExtractorContract;
use App\Modules\Legacy\Contracts\StockAlmacenExtractorContract;
use App\Modules\Legacy\Contracts\TraspasoCabExtractorContract;
use App\Modules\Legacy\Extractors\AlbaranCompraExtractor;
use App\Modules\Legacy\Extractors\AlbaranExtractor;
use App\Modules\Legacy\Extractors\AlmacenExtractor;
use App\Modules\Legacy\Extractors\AlmacenVerificationExtractor;
use App\Modules\Legacy\Extractors\ArticuloFotoExtractor;
use App\Modules\Legacy\Extractors\ClienteExtractor;
use App\Modules\Legacy\Extractors\ClienteRawExtractor;
use App\Modules\Legacy\Extractors\ClienteRawVerificationExtractor;
use App\Modules\Legacy\Extractors\ContactoClienteRawExtractor;
use App\Modules\Legacy\Extractors\CostoAlmacenExtractor;
use App\Modules\Legacy\Extractors\CotizacionExtractor;
use App\Modules\Legacy\Extractors\DescatalogadoStockIcgExtractor;
use App\Modules\Legacy\Extractors\FacturaExtractor;
use App\Modules\Legacy\Extractors\InventarioCabExtractor;
use App\Modules\Legacy\Extractors\MovimientoExtractor;
use App\Modules\Legacy\Extractors\MovimientoVerificationExtractor;
use App\Modules\Legacy\Extractors\PagoExtractor;
use App\Modules\Legacy\Extractors\PedidoExtractor;
use App\Modules\Legacy\Extractors\ProductoDescatalogadoExtractor;
use App\Modules\Legacy\Extractors\ProductoExtractor;
use App\Modules\Legacy\Extractors\ProveedorExtractor;
use App\Modules\Legacy\Extractors\StockAl4Extractor;
use App\Modules\Legacy\Extractors\StockAlmacenExtractor;
use App\Modules\Legacy\Extractors\TraspasoCabExtractor;
use App\Modules\Legacy\Observers\SubClientObserver;
use App\Modules\VentasDiseno\Models\SubClient;
use Illuminate\Support\ServiceProvider;

/**
 * Fase 5a — se registra SOLO con `modules.legacy_icg.enabled` (config/modules.php): bindings de los extractores de ICG, observer y comandos `legacy:*`.
 */
class LegacyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // SCRUM-750 — implementación real de producción (conexión legacy_icg, SQL Server). Los
        // Feature tests de App\Modules\Legacy\Console\Commands\LegacySyncProductosCommand sobreescriben este
        // bind con `$this->app->instance(ProductoExtractorContract::class, $fake)` para no
        // requerir SQL Server real.
        $this->app->bind(ProductoExtractorContract::class, ProductoExtractor::class);
        // SCRUM-1136 F5 — extractor aparte de descatalogados con stock (los tests bindean un fake).
        $this->app->bind(ProductoDescatalogadoExtractorContract::class, ProductoDescatalogadoExtractor::class);
        $this->app->bind(DescatalogadoStockIcgExtractorContract::class, DescatalogadoStockIcgExtractor::class);
        // SCRUM-1114 — mismo criterio: los tests bindean un fake con `$this->app->instance(...)`.
        $this->app->bind(StockAl4ExtractorContract::class, StockAl4Extractor::class);

        // SCRUM-751 — mismo criterio: implementación real de producción (conexión legacy_icg,
        // SQL Server), sobreescrita con un fake en los Feature tests de
        // App\Modules\Legacy\Jobs\SyncProductoFotoJob / App\Modules\Legacy\Console\Commands\LegacySyncProductosFotosCommand.
        $this->app->bind(ArticuloFotoExtractorContract::class, ArticuloFotoExtractor::class);

        // SCRUM-755 — mismo criterio: implementación real de producción (conexión legacy_icg,
        // SQL Server), sobreescrita con un fake en los Feature tests de
        // App\Modules\Legacy\Console\Commands\LegacySyncClientesCommand.
        $this->app->bind(ClienteExtractorContract::class, ClienteExtractor::class);

        // SCRUM-756 — mismo criterio: implementación real de producción (conexión legacy_icg,
        // SQL Server), sobreescrita con un fake en los Feature tests de
        // App\Modules\Legacy\Console\Commands\LegacySyncProveedoresCommand.
        $this->app->bind(ProveedorExtractorContract::class, ProveedorExtractor::class);

        // SCRUM-757 — mismo criterio: implementación real de producción (conexión legacy_icg,
        // SQL Server), sobreescrita con un fake en los Feature tests de
        // App\Modules\Legacy\Console\Commands\LegacySyncCotizacionesCommand.
        $this->app->bind(CotizacionExtractorContract::class, CotizacionExtractor::class);

        // SCRUM-758 — mismo criterio: implementación real de producción (conexión legacy_icg,
        // SQL Server), sobreescrita con un fake en los Feature tests de
        // App\Modules\Legacy\Console\Commands\LegacySyncPedidosCommand.
        $this->app->bind(PedidoExtractorContract::class, PedidoExtractor::class);

        // SCRUM-759 — mismo criterio: implementación real de producción (conexión legacy_icg,
        // SQL Server), sobreescrita con un fake en los Feature tests de
        // App\Modules\Legacy\Console\Commands\LegacySyncAlbaranesCommand.
        $this->app->bind(AlbaranExtractorContract::class, AlbaranExtractor::class);

        // SCRUM-760 — mismo criterio: implementación real de producción (conexión legacy_icg,
        // SQL Server), sobreescrita con un fake en los Feature tests de
        // App\Modules\Legacy\Console\Commands\LegacySyncFacturasCommand.
        $this->app->bind(FacturaExtractorContract::class, FacturaExtractor::class);

        // SCRUM-761 — mismo criterio: implementación real de producción (conexión legacy_icg,
        // SQL Server), sobreescrita con un fake en los Feature tests de
        // App\Modules\Legacy\Console\Commands\LegacySyncPagosCommand.
        $this->app->bind(PagoExtractorContract::class, PagoExtractor::class);

        // SCRUM-1136 F1 — staging de almacenes/stock/costos/traspasos/inventarios de ICG
        // (App\Modules\Legacy\Console\Commands\LegacySyncAlmacenesCommand / LegacyVerifyAlmacenesCommand).
        $this->app->bind(AlmacenExtractorContract::class, AlmacenExtractor::class);
        $this->app->bind(StockAlmacenExtractorContract::class, StockAlmacenExtractor::class);
        $this->app->bind(CostoAlmacenExtractorContract::class, CostoAlmacenExtractor::class);
        $this->app->bind(TraspasoCabExtractorContract::class, TraspasoCabExtractor::class);
        $this->app->bind(InventarioCabExtractorContract::class, InventarioCabExtractor::class);
        $this->app->bind(AlmacenVerificationExtractorContract::class, AlmacenVerificationExtractor::class);

        // SCRUM-1136 F2 — carga única reanudable de MOVIMENTS (legacy:sync-movimientos / legacy:verify-movimientos).
        $this->app->bind(MovimientoExtractorContract::class, MovimientoExtractor::class);
        $this->app->bind(MovimientoVerificationExtractorContract::class, MovimientoVerificationExtractor::class);

        // SCRUM-1137 F1 — copia cruda de CLIENTES/CONTACTOSCLIENTES de ICG
        // (App\Modules\Legacy\Console\Commands\LegacySyncClientesRawCommand / LegacyVerifyClientesRawCommand).
        $this->app->bind(ClienteRawExtractorContract::class, ClienteRawExtractor::class);
        $this->app->bind(ContactoClienteRawExtractorContract::class, ContactoClienteRawExtractor::class);
        $this->app->bind(ClienteRawVerificationExtractorContract::class, ClienteRawVerificationExtractor::class);

        // SCRUM-840 — mismo criterio: implementación real de producción (conexión legacy_icg,
        // SQL Server), sobreescrita con un fake en los Feature tests de
        // App\Modules\Legacy\Console\Commands\LegacySyncAlbaranesCompraCommand.
        $this->app->bind(AlbaranCompraExtractorContract::class, AlbaranCompraExtractor::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands(array_map(
                static fn (string $file): string => 'App\\Modules\\Legacy\\Console\\Commands\\'.basename($file, '.php'),
                glob(__DIR__.'/Console/Commands/*Command.php') ?: [],
            ));
        }
        // SCRUM-763 — mantiene legacy.clientes.match_status consistente cuando se borra el
        // SubClient vinculado, sin esperar a la próxima corrida semanal de legacy:sync-clientes.
        SubClient::observe(SubClientObserver::class);
    }
}
