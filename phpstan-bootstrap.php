<?php

/**
 * Bootstrap file for PHPStan / Larastan.
 *
 * Larastan calls getTable() on Eloquent models during static analysis to resolve
 * database column types. Our tenant-aware models throw a RuntimeException when
 * no tenant is active. We bypass makeCurrent() (which chains through
 * MakeTenantCurrentAction and needs TasksCollection from the container) and
 * directly bind the mock tenant — matching what bindAsCurrentTenant() does.
 */

use App\Shared\Multitenancy\Tenant;

$tenant = new Tenant([
    'slug'      => 'phpstan',
    'name'      => 'PHPStan',
    'domain'    => 'phpstan.local',
    'is_active' => true,
]);

// Direct container bind — same as bindAsCurrentTenant() without the task chain.
app()->instance(
    config('multitenancy.current_tenant_container_key') ?? 'currentTenant',
    $tenant
);

// Fase 5a — Legacy y Lafayette se registran solo con sus banderas (config/modules.php, apagadas por defecto). El análisis estático los
// necesita registrados para resolver la firma de sus comandos (`option()`/`argument()`), así que se registran aquí explícitamente.
app()->register(App\Modules\Legacy\LegacyServiceProvider::class);
app()->register(App\Modules\Integrations\Lafayette\LafayetteServiceProvider::class);
