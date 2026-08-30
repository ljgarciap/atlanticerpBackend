<?php

namespace App\Providers;

use App\Modules\AI\Listeners\NotifyLongQueueWait;
use App\Modules\AdminCont\Events\InvoiceCreated;
use App\Modules\Auth\Events\SecurityAnomalyDetected;
use App\Modules\Auth\Listeners\NotifySuperadminsOfAnomaly;
use App\Modules\Bodega\Listeners\CreateOrderFromApprovedQuote;
use App\Modules\Bodega\Listeners\MarkOrderInvoiceReady;
use App\Modules\Bodega\Listeners\ReleaseOrdersAwaitingStock;
use App\Modules\CRM\Events\ActivityAdded;
use App\Modules\CRM\Events\ModelChangeDetected;
use App\Modules\CRM\Events\ProjectCreated;
use App\Modules\CRM\Events\ProjectStageChanged;
use App\Modules\CRM\Listeners\EvaluateNotificationRulesListener;
use App\Modules\CRM\Listeners\LogActivityAdded;
use App\Modules\CRM\Listeners\LogProjectCreated;
use App\Modules\CRM\Listeners\LogProjectStageChanged;
use App\Modules\CRM\Notifications\NotifiableModel;
use App\Modules\CRM\Observers\NotificationRuleObserver;
use App\Modules\Compras\Events\PurchaseOrderReceived;
use App\Modules\Legacy\Contracts\AlbaranExtractorContract;
use App\Modules\Legacy\Contracts\ArticuloFotoExtractorContract;
use App\Modules\Legacy\Contracts\ClienteExtractorContract;
use App\Modules\Legacy\Contracts\CotizacionExtractorContract;
use App\Modules\Legacy\Contracts\FacturaExtractorContract;
use App\Modules\Legacy\Contracts\PagoExtractorContract;
use App\Modules\Legacy\Contracts\PedidoExtractorContract;
use App\Modules\Legacy\Contracts\ProductoExtractorContract;
use App\Modules\Legacy\Contracts\ProveedorExtractorContract;
use App\Modules\Legacy\Extractors\AlbaranExtractor;
use App\Modules\Legacy\Extractors\ArticuloFotoExtractor;
use App\Modules\Legacy\Extractors\ClienteExtractor;
use App\Modules\Legacy\Extractors\CotizacionExtractor;
use App\Modules\Legacy\Extractors\FacturaExtractor;
use App\Modules\Legacy\Extractors\PagoExtractor;
use App\Modules\Legacy\Extractors\PedidoExtractor;
use App\Modules\Legacy\Extractors\ProductoExtractor;
use App\Modules\Legacy\Extractors\ProveedorExtractor;
use App\Modules\Servicios\Listeners\SyncInsumoReservationOnPurchaseOrderReceived;
use App\Modules\VentasDiseno\Events\QuoteApproved;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Laravel\Horizon\Events\LongWaitDetected;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // SCRUM-750 — implementación real de producción (conexión legacy_icg, SQL Server). Los
        // Feature tests de App\Console\Commands\LegacySyncProductosCommand sobreescriben este
        // bind con `$this->app->instance(ProductoExtractorContract::class, $fake)` para no
        // requerir SQL Server real.
        $this->app->bind(ProductoExtractorContract::class, ProductoExtractor::class);

        // SCRUM-751 — mismo criterio: implementación real de producción (conexión legacy_icg,
        // SQL Server), sobreescrita con un fake en los Feature tests de
        // App\Modules\Legacy\Jobs\SyncProductoFotoJob / App\Console\Commands\LegacySyncProductosFotosCommand.
        $this->app->bind(ArticuloFotoExtractorContract::class, ArticuloFotoExtractor::class);

        // SCRUM-755 — mismo criterio: implementación real de producción (conexión legacy_icg,
        // SQL Server), sobreescrita con un fake en los Feature tests de
        // App\Console\Commands\LegacySyncClientesCommand.
        $this->app->bind(ClienteExtractorContract::class, ClienteExtractor::class);

        // SCRUM-756 — mismo criterio: implementación real de producción (conexión legacy_icg,
        // SQL Server), sobreescrita con un fake en los Feature tests de
        // App\Console\Commands\LegacySyncProveedoresCommand.
        $this->app->bind(ProveedorExtractorContract::class, ProveedorExtractor::class);

        // SCRUM-757 — mismo criterio: implementación real de producción (conexión legacy_icg,
        // SQL Server), sobreescrita con un fake en los Feature tests de
        // App\Console\Commands\LegacySyncCotizacionesCommand.
        $this->app->bind(CotizacionExtractorContract::class, CotizacionExtractor::class);

        // SCRUM-758 — mismo criterio: implementación real de producción (conexión legacy_icg,
        // SQL Server), sobreescrita con un fake en los Feature tests de
        // App\Console\Commands\LegacySyncPedidosCommand.
        $this->app->bind(PedidoExtractorContract::class, PedidoExtractor::class);

        // SCRUM-759 — mismo criterio: implementación real de producción (conexión legacy_icg,
        // SQL Server), sobreescrita con un fake en los Feature tests de
        // App\Console\Commands\LegacySyncAlbaranesCommand.
        $this->app->bind(AlbaranExtractorContract::class, AlbaranExtractor::class);

        // SCRUM-760 — mismo criterio: implementación real de producción (conexión legacy_icg,
        // SQL Server), sobreescrita con un fake en los Feature tests de
        // App\Console\Commands\LegacySyncFacturasCommand.
        $this->app->bind(FacturaExtractorContract::class, FacturaExtractor::class);

        // SCRUM-761 — mismo criterio: implementación real de producción (conexión legacy_icg,
        // SQL Server), sobreescrita con un fake en los Feature tests de
        // App\Console\Commands\LegacySyncPagosCommand.
        $this->app->bind(PagoExtractorContract::class, PagoExtractor::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(database_path('migrations/landlord'));

        // Fail loud if FRONTEND_URL was never set — catches misconfigured deploys before emails break.
        if (! app()->isLocal() && ! app()->runningInConsole() && str_contains((string) config('app.frontend_url'), 'localhost')) {
            throw new \RuntimeException('FRONTEND_URL must be set to the public domain in non-local environments (current value points to localhost).');
        }

        Event::listen(ProjectCreated::class, LogProjectCreated::class);
        Event::listen(ProjectStageChanged::class, LogProjectStageChanged::class);
        Event::listen(ActivityAdded::class, LogActivityAdded::class);
        Event::listen(LongWaitDetected::class, NotifyLongQueueWait::class);
        Event::listen(SecurityAnomalyDetected::class, NotifySuperadminsOfAnomaly::class);
        Event::listen(ModelChangeDetected::class, EvaluateNotificationRulesListener::class);
        // SCRUM-329 Oleada A / Batch A1 (REQ-308) — puente Ventas & Diseño -> Bodega.
        Event::listen(QuoteApproved::class, CreateOrderFromApprovedQuote::class);
        // SCRUM-406 RN2 (ADR docs/adr/ADR-SCRUM406-compras-bodega-stock-bridge.md) — puente Compras -> Bodega.
        Event::listen(PurchaseOrderReceived::class, ReleaseOrdersAwaitingStock::class);
        Event::listen(InvoiceCreated::class, MarkOrderInvoiceReady::class);
        // Fase 4 — Servicios, Grupo D parte 2 (REQ-277, SCRUM-347) — puente Compras -> Servicios
        // (Reserva Servicios). Segundo listener sobre el mismo evento — Laravel despacha ambos.
        Event::listen(PurchaseOrderReceived::class, SyncInsumoReservationOnPurchaseOrderReceived::class);

        // Motor de reglas CRM (SCRUM-49 Fase 2) — Observer registrado solo sobre la
        // whitelist de NotifiableModel::REGISTRY, nunca globalmente (ver docs/specs/
        // notifications.md § "Salvaguarda de alcance").
        foreach (NotifiableModel::REGISTRY as $config) {
            $config['class']::observe(NotificationRuleObserver::class);
        }
    }
}
