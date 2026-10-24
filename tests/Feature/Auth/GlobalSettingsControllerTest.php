<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Modules\Auth\Models\GlobalSetting;
use App\Modules\Auth\Support\GlobalSettingsRegistry;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-944/945 (Pre-QA hallazgo E) — endpoint genérico de GlobalSetting para la vista
 * consolidada de superadmin. Lista blanca única en `GlobalSettingsRegistry`.
 */
class GlobalSettingsControllerTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        foreach (array_keys(GlobalSettingsRegistry::all()) as $key) {
            Cache::forget(GlobalSettingsRegistry::cacheKey($key));
        }
    }

    private function loginAsSuperadmin(): string
    {
        return $this->loginAs('superadmin@atlantic.test', 'Password123!', 'superadmin');
    }

    public function test_index_lista_solo_las_claves_de_la_lista_blanca_con_su_contrato(): void
    {
        GlobalSetting::updateOrCreate(['key' => 'anthropic_api_key'], ['value' => 'sk-ant-secreto-de-prueba-123456', 'updated_at' => now()]);
        $token = $this->loginAsSuperadmin();

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin/global-settings')
            ->assertStatus(200);

        $keys = collect($response->json('data'))->pluck('key')->all();
        $this->assertEqualsCanonicalizing(array_keys(GlobalSettingsRegistry::all()), $keys);
        $this->assertStringNotContainsString('sk-ant', (string) $response->getContent());
        $this->assertStringNotContainsString('anthropic', (string) $response->getContent());

        $row = collect($response->json('data'))->firstWhere('key', 'audit_logs.retention_months');
        $this->assertSame('integer', $row['type']);
        $this->assertSame(12, $row['min']);
        $this->assertSame('audit_logs', $row['group']);
        $this->assertSame('globalSettings.globalTab.labels.audit_logs_retention_months', $row['label_key']);
        $this->assertIsInt($row['value']);
    }

    public function test_index_devuelve_el_default_cuando_la_clave_no_existe_en_bd(): void
    {
        GlobalSetting::query()->where('key', 'notifications.archive_read_after_days')->delete();
        $token = $this->loginAsSuperadmin();

        $row = collect($this->withHeaders($this->authHeaders($token))->getJson('/api/admin/global-settings')->json('data'))
            ->firstWhere('key', 'notifications.archive_read_after_days');

        $this->assertSame(30, $row['value']);
    }

    public function test_update_persiste_invalida_cache_y_deja_audit_log(): void
    {
        $token = $this->loginAsSuperadmin();
        Cache::put(GlobalSettingsRegistry::cacheKey('documents.max_size_mb'), 999, 300);

        $this->withHeaders($this->authHeaders($token))
            ->putJson('/api/admin/global-settings/documents.max_size_mb', ['value' => 250])
            ->assertStatus(200)
            ->assertJsonPath('key', 'documents.max_size_mb')
            ->assertJsonPath('value', 250);

        $this->assertSame('250', GlobalSetting::find('documents.max_size_mb')?->value);
        $this->assertNotNull(GlobalSetting::find('documents.max_size_mb')?->updated_by);
        $this->assertNull(Cache::get(GlobalSettingsRegistry::cacheKey('documents.max_size_mb')));

        $log = DB::table('atlantic_auth.audit_logs')
            ->where('action', 'settings.updated')->where('entity_type', 'global_setting')
            ->where('entity_id', 'documents.max_size_mb')->first();
        $this->assertNotNull($log);
    }

    public function test_update_rechaza_valores_fuera_de_rango_o_no_enteros(): void
    {
        $token = $this->loginAsSuperadmin();
        $before = GlobalSetting::find('audit_logs.retention_months')?->value;

        foreach ([['audit_logs.retention_months', 11], ['audit_logs.retention_months', 121], ['documents.max_size_mb', 501], ['documents.max_size_mb', 0], ['documents.max_size_mb', 'abc'], ['documents.max_size_mb', 12.5]] as [$key, $value]) {
            $this->withHeaders($this->authHeaders($token))
                ->putJson("/api/admin/global-settings/{$key}", ['value' => $value])
                ->assertStatus(422)
                ->assertJsonValidationErrors(['value']);
        }

        $message = (string) $this->withHeaders($this->authHeaders($token))
            ->putJson('/api/admin/global-settings/documents.max_size_mb', ['value' => 12.5])
            ->assertStatus(422)
            ->json('message');
        $this->assertStringNotContainsString('(and ', $message);

        $this->withHeaders($this->authHeaders($token))
            ->putJson('/api/admin/global-settings/documents.max_size_mb', [])
            ->assertStatus(422);

        $this->assertSame($before, GlobalSetting::find('audit_logs.retention_months')?->value);
    }

    public function test_update_de_clave_fuera_de_la_lista_blanca_da_404_incluida_la_api_key_de_ia(): void
    {
        GlobalSetting::updateOrCreate(['key' => 'anthropic_api_key'], ['value' => 'sk-ant-secreto-de-prueba-123456', 'updated_at' => now()]);
        $token = $this->loginAsSuperadmin();

        foreach (['anthropic_api_key', 'no.existe'] as $key) {
            $this->withHeaders($this->authHeaders($token))
                ->putJson("/api/admin/global-settings/{$key}", ['value' => 5])
                ->assertStatus(404);
        }

        $this->assertSame('sk-ant-secreto-de-prueba-123456', GlobalSetting::find('anthropic_api_key')?->value);
    }

    public function test_sin_token_401_y_sin_superadmin_403(): void
    {
        $this->getJson('/api/admin/global-settings')->assertStatus(401);
        $this->putJson('/api/admin/global-settings/documents.max_size_mb', ['value' => 5])->assertStatus(401);

        $designer = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($designer))->getJson('/api/admin/global-settings')->assertStatus(403);
        $this->withHeaders($this->authHeaders($designer))
            ->putJson('/api/admin/global-settings/documents.max_size_mb', ['value' => 5])
            ->assertStatus(403);
    }
}
