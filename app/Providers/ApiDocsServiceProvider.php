<?php

declare(strict_types=1);

namespace App\Providers;

use App\Shared\Multitenancy\Tenant;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Documentación de la API (OpenAPI) con `dedoc/scramble`, sobre TODA la API (`api/*`, ver `config/scramble.php`).
 *
 * `dedoc/scramble` es dependencia de DESARROLLO (`require-dev`): en dev/test/prod el servidor instala con `--no-dev`, así que este
 * provider no hace nada ahí (`class_exists`). El documento se genera en local o en CI: `php artisan scramble:export`.
 */
class ApiDocsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if (! class_exists(Scramble::class)) {
            return;
        }

        // Toda la API usa el JWT en `Authorization: Bearer` (ver JwtClaimsBuilder). Sin esto el documento no declara seguridad.
        Scramble::configure()->withDocumentTransformers(function (OpenApi $openApi): void {
            $openApi->secure(SecurityScheme::http('bearer', 'JWT'));
        });

        // La página interactiva (/docs/api) solo se sirve en dev (y en local, donde Scramble no pide gate). El permiso de Laravel no sirve
        // para esto: AtlanticERP autentica con JWT en un header que el navegador no manda al abrir una URL. La protección real es la autenticación
        // básica de nginx (infra/nginx/entrypoint.sh, variable API_DOCS_BASIC_AUTH_B64); nginx marca además la petición con un header que
        // pone él mismo (`fastcgi_param`, el cliente no puede fijarlo) y este gate lo exige, así que sin nginx delante no abre nada.
        Gate::define('viewApiDocs', static fn (mixed $user = null): bool => app()->environment('dev')
            && request()->header('X-Api-Docs-Auth') === '1');

        // El analizador de Scramble inspecciona el modelo `User`, que exige un tenant activo (schema-per-tenant): al exportar se activa el
        // tenant por defecto, igual que hace el login. Solo para ese comando; no afecta a ninguna petición.
        Event::listen(CommandStarting::class, function (CommandStarting $event): void {
            if (in_array($event->command, ['scramble:export', 'scramble:cache'], true)) {
                Tenant::where('slug', (string) config('multitenancy.default_tenant'))->first()?->makeCurrent();
            }
        });
    }
}
