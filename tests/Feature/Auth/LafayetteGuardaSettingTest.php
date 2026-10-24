<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Modules\Auth\Models\AuditLog;
use App\Modules\Auth\Models\SystemSetting;
use App\Modules\Integrations\Lafayette\Services\LafayetteSettings;
use App\Shared\Multitenancy\Tenant;
use App\Shared\Services\AuditService;
use Illuminate\Support\Facades\DB;
use Tests\Feature\FeatureTestCase;

/** SCRUM-990 (MEDIO-B1) — `lafayette_guarda_min_ratio` administrable por superadmin vía `PATCH /api/settings/system`. */
final class LafayetteGuardaSettingTest extends FeatureTestCase
{
    private const URL = '/api/settings/system';

    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    private function superadmin(): string
    {
        return $this->loginAs('superadmin@atlantic.test', 'Password123!', 'superadmin');
    }

    public function test_el_get_expone_el_umbral_con_defecto_0_9(): void
    {
        $this->withHeaders($this->authHeaders($this->superadmin()))->getJson(self::URL)->assertOk()->assertJsonPath('lafayette_guarda_min_ratio', 0.9);
    }

    public function test_superadmin_actualiza_el_umbral_y_queda_auditado(): void
    {
        $token = $this->superadmin();

        $this->withHeaders($this->authHeaders($token))->patchJson(self::URL, ['lafayette_guarda_min_ratio' => 0.75])
            ->assertOk()->assertJsonPath('lafayette_guarda_min_ratio', 0.75);

        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $this->assertSame(0.75, app(LafayetteSettings::class)->guardaMinRatio());
        $log = AuditLog::query()->where('action', AuditService::SETTINGS_UPDATED)->where('entity_id', 'lafayette_guarda_min_ratio')->firstOrFail();
        $this->assertSame(0.9, $log->old_values['lafayette_guarda_min_ratio']);
        $this->assertSame(0.75, $log->new_values['lafayette_guarda_min_ratio']);
        $this->assertNotNull(SystemSetting::find('lafayette_guarda_min_ratio')?->updated_by);
    }

    public function test_fuera_del_rango_0_5_a_1_responde_422_y_no_cambia_nada(): void
    {
        $token = $this->superadmin();

        foreach ([0.49, 0, -1, 1.01, 'abc'] as $malo) {
            $this->withHeaders($this->authHeaders($token))->patchJson(self::URL, ['lafayette_guarda_min_ratio' => $malo])
                ->assertStatus(422)->assertJsonValidationErrors(['lafayette_guarda_min_ratio']);
        }
        foreach ([0.5, 1, 1.0] as $bueno) {
            $this->withHeaders($this->authHeaders($token))->patchJson(self::URL, ['lafayette_guarda_min_ratio' => $bueno])->assertOk();
        }
    }

    public function test_quien_tiene_settings_global_sin_ser_superadmin_recibe_403_y_no_lo_cambia(): void
    {
        DB::table('atlantic_auth.users')->where('email', 'designer@atlantic.test')->update(['extra_permissions' => json_encode(['settings.global'])]);
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))->patchJson(self::URL, ['lafayette_guarda_min_ratio' => 0.6])->assertStatus(403);
        // Sigue pudiendo cambiar los demás ajustes.
        $this->withHeaders($this->authHeaders($token))->patchJson(self::URL, ['share_links_expiration_days' => 10])->assertOk();

        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $this->assertSame(0.9, app(LafayetteSettings::class)->guardaMinRatio());
    }

    public function test_sin_settings_global_no_hay_acceso(): void
    {
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))->patchJson(self::URL, ['lafayette_guarda_min_ratio' => 0.6])->assertStatus(403);
    }

    public function test_la_migracion_sube_a_0_9_solo_el_0_5_sembrado_y_no_pisa_un_valor_cambiado_a_mano(): void
    {
        $t = 'atlantic_auth.system_settings';
        $migracion = require database_path('migrations/tenant/2026_10_01_100400_raise_lafayette_guarda_setting_to_0_9.php');

        // 0,5 sembrado (nadie lo editó) => sube a 0,9.
        DB::table($t)->where('key', 'lafayette_guarda_min_ratio')->update(['value' => DB::raw("'0.5'::jsonb"), 'updated_by' => null]);
        $migracion->up();
        $this->assertSame(0.9, app(LafayetteSettings::class)->guardaMinRatio());

        // Valor cambiado a mano (0,7) => intacto.
        DB::table($t)->where('key', 'lafayette_guarda_min_ratio')->update(['value' => DB::raw("'0.7'::jsonb")]);
        $migracion->up();
        $this->assertSame(0.7, app(LafayetteSettings::class)->guardaMinRatio());

        // Un 0,5 puesto adrede desde la pantalla (updated_by informado) tampoco se pisa.
        $uid = (int) DB::table('atlantic_auth.users')->value('id');
        DB::table($t)->where('key', 'lafayette_guarda_min_ratio')->update(['value' => DB::raw("'0.5'::jsonb"), 'updated_by' => $uid]);
        $migracion->up();
        $this->assertSame(0.5, app(LafayetteSettings::class)->guardaMinRatio());
    }
}
