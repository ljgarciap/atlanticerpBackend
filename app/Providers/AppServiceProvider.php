<?php

namespace App\Providers;

use App\Modules\AI\Listeners\NotifyLongQueueWait;
use App\Shared\Auth\JwtService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use App\Modules\AdminCont\Events\InvoiceCreated;
use App\Modules\AdminCont\FacturacionElectronica\Listeners\CertifyInvoiceOnCreated;
use App\Modules\Auth\Events\SecurityAnomalyDetected;
use App\Modules\Auth\Listeners\NotifySuperadminsOfAnomaly;
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
use App\Modules\Integrations\Lafayette\Contracts\ProductoExtractorContract as LafayetteProductoExtractorContract;
use App\Modules\Integrations\Lafayette\Extractors\ProductoExtractor as LafayetteProductoExtractor;
use App\Modules\Legacy\Contracts\AlbaranCompraExtractorContract;
use App\Modules\Legacy\Contracts\AlbaranExtractorContract;
use App\Modules\Legacy\Contracts\ArticuloFotoExtractorContract;
use App\Modules\Legacy\Contracts\ClienteExtractorContract;
use App\Modules\Legacy\Contracts\CotizacionExtractorContract;
use App\Modules\Legacy\Contracts\FacturaExtractorContract;
use App\Modules\Legacy\Contracts\PagoExtractorContract;
use App\Modules\Legacy\Contracts\PedidoExtractorContract;
use App\Modules\Legacy\Contracts\DescatalogadoStockIcgExtractorContract;
use App\Modules\Legacy\Contracts\ProductoDescatalogadoExtractorContract;
use App\Modules\Legacy\Contracts\ProductoExtractorContract;
use App\Modules\Legacy\Contracts\StockAl4ExtractorContract;
use App\Modules\Legacy\Contracts\ProveedorExtractorContract;
use App\Modules\Legacy\Extractors\AlbaranCompraExtractor;
use App\Modules\Legacy\Extractors\AlbaranExtractor;
use App\Modules\Legacy\Extractors\ArticuloFotoExtractor;
use App\Modules\Legacy\Extractors\ClienteExtractor;
use App\Modules\Legacy\Extractors\CotizacionExtractor;
use App\Modules\Legacy\Extractors\FacturaExtractor;
use App\Modules\Legacy\Extractors\PagoExtractor;
use App\Modules\Legacy\Extractors\PedidoExtractor;
use App\Modules\Legacy\Extractors\DescatalogadoStockIcgExtractor;
use App\Modules\Legacy\Extractors\ProductoDescatalogadoExtractor;
use App\Modules\Legacy\Extractors\ProductoExtractor;
use App\Modules\Legacy\Extractors\StockAl4Extractor;
use App\Modules\Legacy\Extractors\ProveedorExtractor;
use App\Modules\Legacy\Observers\SubClientObserver;
use App\Modules\Servicios\Listeners\SyncInsumoReservationOnPurchaseOrderReceived;
use App\Modules\VentasDiseno\Models\PipelineCard;
use App\Modules\VentasDiseno\Models\Quote;
use App\Modules\VentasDiseno\Models\SalesProject;
use App\Modules\VentasDiseno\Models\SubClient;
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
        // SCRUM-1136 F5 — extractor aparte de descatalogados con stock (los tests bindean un fake).
        $this->app->bind(ProductoDescatalogadoExtractorContract::class, ProductoDescatalogadoExtractor::class);
        $this->app->bind(DescatalogadoStockIcgExtractorContract::class, DescatalogadoStockIcgExtractor::class);
        // SCRUM-1114 — mismo criterio: los tests bindean un fake con `$this->app->instance(...)`.
        $this->app->bind(StockAl4ExtractorContract::class, StockAl4Extractor::class);

        // SCRUM-971 (épica SCRUM-969) — mismo criterio: implementación real de producción
        // (conexión lafayette_lighting, MariaDB externa), sobreescrita con un fake en los Feature
        // tests de App\Console\Commands\LafayetteSyncProductosCommand.
        $this->app->bind(LafayetteProductoExtractorContract::class, LafayetteProductoExtractor::class);

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

        // SCRUM-1136 F1 — staging de almacenes/stock/costos/traspasos/inventarios de ICG
        // (App\Console\Commands\LegacySyncAlmacenesCommand / LegacyVerifyAlmacenesCommand).
        $this->app->bind(\App\Modules\Legacy\Contracts\AlmacenExtractorContract::class, \App\Modules\Legacy\Extractors\AlmacenExtractor::class);
        $this->app->bind(\App\Modules\Legacy\Contracts\StockAlmacenExtractorContract::class, \App\Modules\Legacy\Extractors\StockAlmacenExtractor::class);
        $this->app->bind(\App\Modules\Legacy\Contracts\CostoAlmacenExtractorContract::class, \App\Modules\Legacy\Extractors\CostoAlmacenExtractor::class);
        $this->app->bind(\App\Modules\Legacy\Contracts\TraspasoCabExtractorContract::class, \App\Modules\Legacy\Extractors\TraspasoCabExtractor::class);
        $this->app->bind(\App\Modules\Legacy\Contracts\InventarioCabExtractorContract::class, \App\Modules\Legacy\Extractors\InventarioCabExtractor::class);
        $this->app->bind(\App\Modules\Legacy\Contracts\AlmacenVerificationExtractorContract::class, \App\Modules\Legacy\Extractors\AlmacenVerificationExtractor::class);

        // SCRUM-1136 F2 — carga única reanudable de MOVIMENTS (legacy:sync-movimientos / legacy:verify-movimientos).
        $this->app->bind(\App\Modules\Legacy\Contracts\MovimientoExtractorContract::class, \App\Modules\Legacy\Extractors\MovimientoExtractor::class);
        $this->app->bind(\App\Modules\Legacy\Contracts\MovimientoVerificationExtractorContract::class, \App\Modules\Legacy\Extractors\MovimientoVerificationExtractor::class);

        // SCRUM-1137 F1 — copia cruda de CLIENTES/CONTACTOSCLIENTES de ICG
        // (App\Console\Commands\LegacySyncClientesRawCommand / LegacyVerifyClientesRawCommand).
        $this->app->bind(\App\Modules\Legacy\Contracts\ClienteRawExtractorContract::class, \App\Modules\Legacy\Extractors\ClienteRawExtractor::class);
        $this->app->bind(\App\Modules\Legacy\Contracts\ContactoClienteRawExtractorContract::class, \App\Modules\Legacy\Extractors\ContactoClienteRawExtractor::class);
        $this->app->bind(\App\Modules\Legacy\Contracts\ClienteRawVerificationExtractorContract::class, \App\Modules\Legacy\Extractors\ClienteRawVerificationExtractor::class);

        // SCRUM-840 — mismo criterio: implementación real de producción (conexión legacy_icg,
        // SQL Server), sobreescrita con un fake en los Feature tests de
        // App\Console\Commands\LegacySyncAlbaranesCompraCommand.
        $this->app->bind(AlbaranCompraExtractorContract::class, AlbaranCompraExtractor::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(database_path('migrations/landlord'));

        // SCRUM-1178 — SES con el rol de la instancia (ver SesRoleTransport).
        \Illuminate\Support\Facades\Mail::extend('ses-role', fn (array $config) => \App\Shared\Mail\SesRoleTransport::make());

        // Fail loud if FRONTEND_URL was never set — catches misconfigured deploys before emails break.
        if (! app()->isLocal() && ! app()->runningInConsole() && str_contains((string) config('app.frontend_url'), 'localhost')) {
            throw new \RuntimeException('FRONTEND_URL must be set to the public domain in non-local environments (current value points to localhost).');
        }

        // SCRUM-1007 — acciones fiscales manuales sobre documentos electrónicos: 6 por minuto POR USUARIO. El `throttle:N,M` por defecto
        // limita por IP (el guard JWT propio no define `user()`), y detrás de un NAT compartiría el cupo con IA, soporte, etc. Además, Laravel
        // ordena `ThrottleRequests` ANTES de `AuthenticateJwt` (prioridad de middleware), así que el `jwt` de la request aún no existe cuando
        // corre este limitador: se decodifica el bearer aquí mismo (firma incluida) y solo si es inválido se cae a la IP.
        RateLimiter::for('fe-accion', static function (Request $request): Limit {
            $token = $request->bearerToken();
            $sub   = $token !== null ? app(JwtService::class)->decode($token)['sub'] ?? null : null;

            return Limit::perMinute(6)->by('fe-accion:'.($sub !== null ? 'u'.$sub : 'ip'.$request->ip()));
        });

        // Reset administrativo de contraseñas (H3 del cierre de la revisión de seguridad): límite de peticiones POR USUARIO operador (antes
        // era por IP con `throttle:6,1`). Mismo motivo que `fe-accion`: ThrottleRequests corre antes de AuthenticateJwt, así que se decodifica
        // el bearer aquí (firma incluida) y solo si es inválido se cae a la IP. El tope de contraseñas de operador erróneas vive en el controlador.
        RateLimiter::for('password-reset-admin', static function (Request $request): Limit {
            $token = $request->bearerToken();
            $sub   = $token !== null ? app(JwtService::class)->decode($token)['sub'] ?? null : null;

            return Limit::perMinute(max(1, (int) config('throttle.password_reset_admin.per_minute', 20)))
                ->by('password-reset-admin:'.($sub !== null ? 'u'.$sub : 'ip'.$request->ip()));
        });

        // SCRUM-1158 — «Ver como usuario»: inicios de sesión de impersonación por minuto POR USUARIO superadmin (mismo motivo que `password-reset-admin`:
        // ThrottleRequests corre antes de AuthenticateJwt, así que se decodifica el bearer aquí y solo si es inválido se cae a la IP). El tope de
        // contraseñas erróneas es aparte y vive en OperatorReauth (ámbito `impersonation`).
        RateLimiter::for('impersonation-start', static function (Request $request): Limit {
            $token = $request->bearerToken();
            $sub   = $token !== null ? app(JwtService::class)->decode($token)['sub'] ?? null : null;

            return Limit::perMinute(max(1, (int) config('throttle.impersonation.per_minute', 10)))
                ->by('impersonation-start:'.($sub !== null ? 'u'.$sub : 'ip'.$request->ip()));
        });

        // SCRUM-1002 — validación de RUC/Cédula contra la DGI (vía MSL) desde la UI de Clientes: 20 por minuto POR USUARIO. Mismo
        // motivo que `fe-accion`: sin esto el límite sería por IP y toda la oficina compartiría un solo cupo.
        RateLimiter::for('tax-lookup', static function (Request $request): Limit {
            $token = $request->bearerToken();
            $sub   = $token !== null ? app(JwtService::class)->decode($token)['sub'] ?? null : null;

            return Limit::perMinute(20)->by('tax-lookup:'.($sub !== null ? 'u'.$sub : 'ip'.$request->ip()));
        });

        // SCRUM-984 — Biblioteca de manuales. Mismo motivo que `fe-accion`: el guard JWT no define `user()` y
        // ThrottleRequests corre antes de AuthenticateJwt, así que `throttle:N,1` limitaría por IP y 32 usuarios
        // detrás del NAT de la oficina compartirían un solo cupo. Se decodifica el bearer y se limita por usuario
        // (IP solo como respaldo si el token es inválido). nginx llega a PHP por fastcgi, así que la IP de respaldo
        // ya es la del cliente real (no hace falta trustProxies).
        // SCRUM-990 — `zona-libre-lectura` (catálogo Lafayette, ~25.800 filas con precio_5) usa el mismo limitador por usuario.
        // SCRUM-1127 — generar la versión en inglés de una orden de compra llama a la IA (de pago): tope por usuario y minuto, configurable en
        // `config/compras.php`. Mismo patrón por usuario que los limitadores de arriba.
        RateLimiter::for('oc-traduccion', static function (Request $request): Limit {
            $token = $request->bearerToken();
            $sub   = $token !== null ? app(JwtService::class)->decode($token)['sub'] ?? null : null;

            return Limit::perMinute(max(1, (int) config('compras.translation_per_minute', 6)))
                ->by('oc-traduccion:'.($sub !== null ? 'u'.$sub : 'ip'.$request->ip()));
        });

        foreach (['zona-libre-lectura' => 60] as $name => $perMinute) {
            RateLimiter::for($name, static function (Request $request) use ($name, $perMinute): Limit {
                $token = $request->bearerToken();
                $sub   = $token !== null ? app(JwtService::class)->decode($token)['sub'] ?? null : null;

                return Limit::perMinute($perMinute)->by($name.':'.($sub !== null ? 'u'.$sub : 'ip'.$request->ip()));
            });
        }

        Event::listen(ProjectCreated::class, LogProjectCreated::class);
        Event::listen(ProjectStageChanged::class, LogProjectStageChanged::class);
        Event::listen(ActivityAdded::class, LogActivityAdded::class);
        Event::listen(LongWaitDetected::class, NotifyLongQueueWait::class);
        Event::listen(SecurityAnomalyDetected::class, NotifySuperadminsOfAnomaly::class);
        Event::listen(ModelChangeDetected::class, EvaluateNotificationRulesListener::class);
        // SCRUM-1128 — el puente Ventas & Diseño -> Bodega (REQ-308) ya no es un listener: el pedido se
        // genera solo con la acción explícita PipelineService::passToOrder().
        // SCRUM-406 RN2 (ADR docs/adr/ADR-SCRUM406-compras-bodega-stock-bridge.md) — puente Compras -> Bodega.
        Event::listen(PurchaseOrderReceived::class, ReleaseOrdersAwaitingStock::class);
        Event::listen(InvoiceCreated::class, MarkOrderInvoiceReady::class);
        Event::listen(InvoiceCreated::class, CertifyInvoiceOnCreated::class);
        // Fase 4 — Servicios, Grupo D parte 2 (REQ-277, SCRUM-347) — puente Compras -> Servicios
        // (Reserva Servicios). Segundo listener sobre el mismo evento — Laravel despacha ambos.
        Event::listen(PurchaseOrderReceived::class, SyncInsumoReservationOnPurchaseOrderReceived::class);

        // Motor de reglas CRM (SCRUM-49 Fase 2) — Observer registrado solo sobre la
        // whitelist de NotifiableModel::REGISTRY, nunca globalmente (ver docs/specs/
        // notifications.md § "Salvaguarda de alcance").
        foreach (NotifiableModel::REGISTRY as $config) {
            $config['class']::observe(NotificationRuleObserver::class);
        }

        // SCRUM-763 — mantiene legacy.clientes.match_status consistente cuando se borra el
        // SubClient vinculado, sin esperar a la próxima corrida semanal de legacy:sync-clientes.
        SubClient::observe(SubClientObserver::class);

    }
}
