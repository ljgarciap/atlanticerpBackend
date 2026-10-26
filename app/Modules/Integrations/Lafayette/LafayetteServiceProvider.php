<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Lafayette;

use App\Modules\Integrations\Lafayette\Contracts\ProductoExtractorContract as LafayetteProductoExtractorContract;
use App\Modules\Integrations\Lafayette\Extractors\ProductoExtractor as LafayetteProductoExtractor;
use Illuminate\Support\ServiceProvider;

/**
 * Fase 5a — se registra SOLO con `modules.lafayette.enabled` (config/modules.php): extractor del catálogo y comandos `lafayette:*`.
 */
class LafayetteServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // SCRUM-971 (épica SCRUM-969) — mismo criterio: implementación real de producción
        // (conexión lafayette_lighting, MariaDB externa), sobreescrita con un fake en los Feature
        // tests de App\Modules\Integrations\Lafayette\Console\Commands\LafayetteSyncProductosCommand.
        $this->app->bind(LafayetteProductoExtractorContract::class, LafayetteProductoExtractor::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands(array_map(
                static fn (string $file): string => 'App\\Modules\\Integrations\\Lafayette\\Console\\Commands\\'.basename($file, '.php'),
                glob(__DIR__.'/Console/Commands/*Command.php') ?: [],
            ));
        }
    }
}
