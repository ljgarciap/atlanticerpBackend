<?php

declare(strict_types=1);

namespace Tests\Feature\Concerns;

use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * docs/specs/demo-mode.md — helpers compartidos por los tests de los 3 endpoints que aplican
 * CatalogProduct::scopeVisibleForDemoMode() (CatalogProductControllerTest,
 * BodegaInventoryControllerTest, ProductLookupControllerTest). Usa DB::table() schema-calificado
 * en vez de los modelos Eloquent (SystemSetting/User) — mismo gotcha ya documentado en
 * DocumentControllerTest.php: fuera de una request activa el tenant puede no estar resuelto.
 */
trait DemoModeTestHelpers
{
    protected function enableDemoModeGlobally(): void
    {
        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');
        DB::table("{$slug}_auth.system_settings")->updateOrInsert(
            ['key' => 'demo_mode_enabled'],
            ['value' => json_encode(true), 'updated_at' => now()],
        );
    }

    protected function setUserDemoModePreference(string $email, bool $enabled): void
    {
        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');
        DB::table("{$slug}_auth.users")->where('email', $email)->update([
            'preferences' => json_encode(['demo_mode_enabled' => $enabled]),
        ]);
    }
}
