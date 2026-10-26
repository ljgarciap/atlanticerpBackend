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
        // Memo por petición de los ajustes de CRM (`PipelineCard::isStagnant()` se evalúa por tarjeta; sin esto sería una consulta por tarjeta).
        $this->app->scoped(\App\Modules\CRM\Services\CrmSettingsService::class);
        $this->app->scoped(\App\Modules\Compras\Services\ComprasThresholds::class);

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
        // SCRUM-990 — `zona-libre-lectura` (catálogo externo) usa el mismo limitador por usuario.
        // SCRUM-1127 — generar la versión en inglés de una orden de compra llama a la IA (de pago): tope por usuario y minuto, configurable en
        // `config/compras.php`. Mismo patrón por usuario que los limitadores de arriba.
        RateLimiter::for('oc-traduccion', static function (Request $request): Limit {
            $token = $request->bearerToken();
            $sub   = $token !== null ? app(JwtService::class)->decode($token)['sub'] ?? null : null;

            return Limit::perMinute(max(1, (int) config('compras.translation_per_minute', 6)))
                ->by('oc-traduccion:'.($sub !== null ? 'u'.$sub : 'ip'.$request->ip()));
        });

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
    }
}
