<?php

use App\Modules\AdminCont\Http\Middleware\EnsureIsPrimaryApprover;
use App\Modules\Auth\Http\Controllers\AuthController;
use App\Modules\Auth\Http\Controllers\ImpersonationController;
use App\Modules\Auth\Http\Controllers\MfaController;
use App\Modules\Auth\Http\Controllers\SecurityLevelController;
use App\Modules\CRM\Http\Controllers\ShareLinkController;
use App\Modules\VentasDiseno\Http\Controllers\CatalogUatPlaceholderPhotoController;
use App\Shared\Http\Controllers\HealthController;
use App\Shared\Http\Middleware\ApplyGerenciaScope;
use App\Shared\Http\Middleware\AssignRequestId;
use App\Shared\Http\Middleware\AttributeDbChangesToUser;
use App\Shared\Http\Middleware\AuthenticateJwt;
use App\Shared\Http\Middleware\BlockWritesWhenImpersonating;
use App\Shared\Http\Middleware\CheckPermission;
use App\Shared\Http\Middleware\DenyWhenImpersonating;
use App\Shared\Http\Middleware\EnforceRole;
use App\Shared\Http\Middleware\SetLocaleFromHeader;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            // Rutas públicas: exentas de AuthenticateJwt con withoutMiddleware('jwt').
            Route::middleware('api')
                ->withoutMiddleware(AuthenticateJwt::class)
                ->prefix('api/auth')
                ->group(base_path('routes/auth.php'));

            // Endpoint de salud: público, sin autenticación.
            Route::middleware('api')
                ->withoutMiddleware(AuthenticateJwt::class)
                ->get('/api/health', HealthController::class);

            // SCRUM-1031 (R-05): chequeo PROFUNDO de dependencias críticas (base y Redis). /up y /api/health no cambian (los usan
            // Docker, nginx y el deploy): este endpoint es el que detecta una caída que el proceso no ve.
            Route::middleware('api')
                ->withoutMiddleware(AuthenticateJwt::class)
                ->get('/api/health/deep', [HealthController::class, 'deep']);

            // Share links (SCRUM — compartir documentos con clientes externos):
            // público, sin autenticación, con throttle para evitar brute-force del token.
            Route::middleware(['api', 'throttle:30,1'])
                ->withoutMiddleware(AuthenticateJwt::class)
                ->get('/public/share/{token}', [ShareLinkController::class, 'publicAccess']);

            // SCRUM-745 — foto placeholder de productos de prueba UAT, generada al vuelo (sin S3
            // de por medio, ver docblock del controller). Público, sin datos sensibles.
            Route::middleware(['api', 'throttle:30,1'])
                ->withoutMiddleware(AuthenticateJwt::class)
                ->get('/public/catalog/uat-placeholder/{reference}', CatalogUatPlaceholderPhotoController::class);

            // Rutas protegidas: AuthenticateJwt se aplica a través del grupo 'api'
            // (appendToGroup en withMiddleware). EnforceRole se aplica por endpoint.
            Route::middleware(['api', 'gerencia.scope'])->prefix('api/crm')->group(base_path('routes/crm.php'));
            Route::middleware(['api', 'gerencia.scope'])->prefix('api/ventas-diseno')->group(base_path('routes/ventas-diseno.php'));
            Route::middleware('api')->prefix('api/compras')->group(base_path('routes/compras.php'));
            Route::middleware('api')->prefix('api/bodega')->group(base_path('routes/bodega.php'));
            Route::middleware('api')->prefix('api/servicios')->group(base_path('routes/servicios.php'));
            Route::middleware('api')->prefix('api/ai')->group(base_path('routes/ai.php'));
            Route::middleware('api')->prefix('api/users')->group(base_path('routes/users.php'));
            Route::middleware('api')->prefix('api/departments')->group(base_path('routes/departments.php'));
            Route::middleware('api')->prefix('api/security-levels')->group(base_path('routes/security-levels.php'));
            Route::middleware('api')->prefix('api/roles')->group(base_path('routes/roles.php'));
            Route::middleware('api')->prefix('api/modules')->group(base_path('routes/modules.php'));
            Route::middleware('api')->prefix('api/settings')->group(base_path('routes/settings.php'));
            Route::middleware('api')->prefix('api/notifications')->group(base_path('routes/notifications.php'));
            Route::middleware('api')->prefix('api/admin-contab')->group(base_path('routes/admin-contab.php'));
            Route::middleware('api')->prefix('api/gerencia')->group(base_path('routes/gerencia.php'));

            // Catálogo de permisos — acceso restringido a superadmin y management
            Route::middleware(['api', 'permission:platform.management'])
                ->get('/api/permission-catalog', [SecurityLevelController::class, 'permissionCatalog']);
            Route::middleware('api')->prefix('api/admin')->group(base_path('routes/admin.php'));

            // Protected auth routes — JWT required via 'api' middleware group
            Route::middleware('api')->prefix('api/auth')->group(function (): void {
                Route::get('/me', [AuthController::class, 'me']);
                Route::put('/password', [AuthController::class, 'changePassword']);
                Route::patch('/profile', [AuthController::class, 'updateProfile']);

                Route::get('/mfa/status', [MfaController::class, 'status']);
                Route::post('/mfa/enable', [MfaController::class, 'enable']);
                Route::post('/mfa/enable/confirm', [MfaController::class, 'confirmEnable']);
                Route::post('/mfa/disable', [MfaController::class, 'disable']);

                // SCRUM-1158 — cualquier token de impersonación puede terminar su propia sesión (única escritura que el modo solo lectura permite).
                Route::post('/impersonation/stop', [ImpersonationController::class, 'stop']);
            });
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // T-17: AuthenticateJwt se agrega al grupo 'api' para proteger
        // automáticamente todas las rutas /api/* que NO usen withoutMiddleware.
        // Las rutas públicas (login, health) se registran con withoutMiddleware.
        //
        // EnforceRole NO se aplica globalmente — la autorización por rol
        // se define por endpoint cuando existan los endpoints de CRM/Installations.
        $middleware->appendToGroup('api', AssignRequestId::class);
        // SCRUM-29 BUG-06/09: fija el locale de trans() según el idioma activo
        // del frontend (header Accept-Language), sin depender de sesión/JWT —
        // necesario para que mensajes como "Credenciales inválidas" en login
        // fallido (sin sesión) respondan en el idioma correcto.
        $middleware->appendToGroup('api', SetLocaleFromHeader::class);
        $middleware->appendToGroup('api', AuthenticateJwt::class);
        // SCRUM-1158 — «Ver como usuario» es de SOLO LECTURA: con el claim `act` toda escritura responde 403. Va justo tras AuthenticateJwt
        // (necesita el JWT ya validado) y antes de cualquier controlador o de la atribución de BD.
        $middleware->appendToGroup('api', BlockWritesWhenImpersonating::class);
        // SCRUM-916 — corre después de AuthenticateJwt (necesita el JWT ya validado en
        // $request->attributes) para atribuir cambios de auditoría a nivel de DB al usuario real.
        $middleware->appendToGroup('api', AttributeDbChangesToUser::class);

        // Alias disponibles para uso puntual en rutas de módulos.
        $middleware->alias([
            'jwt' => AuthenticateJwt::class,
            'role' => EnforceRole::class,
            'permission' => CheckPermission::class,
            'primary_approver_only' => EnsureIsPrimaryApprover::class,
            // SCRUM-1158 (C6) — GET que escriben: se niegan en «Ver como usuario» hasta que pasen a POST.
            'no_impersonation' => DenyWhenImpersonating::class,
            'gerencia.scope' => ApplyGerenciaScope::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
