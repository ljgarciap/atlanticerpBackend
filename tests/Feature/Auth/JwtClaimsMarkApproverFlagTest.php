<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Modules\AdminCont\Models\AdminContFiscalSettings;
use App\Shared\Auth\JwtClaimsBuilder;
use App\Shared\Multitenancy\Tenant;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-1157 — `flags.mark_approver` es la identidad del aprobador Mark (`primary_approver_user_id`). «Datos de la Empresa» y «Configuración
 * Fiscal» son `primary_approver_only` en el backend; el menú del frontend usa este flag para no ofrecer a nadie más pantallas que responden 403.
 */
class JwtClaimsMarkApproverFlagTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    private function user(string $email): User
    {
        return User::where('email', $email)->firstOrFail();
    }

    private function flagFor(User $user): bool
    {
        return (bool) app(JwtClaimsBuilder::class)->claims($user, 'atlantic')['flags']['mark_approver'];
    }

    public function test_solo_el_aprobador_configurado_lleva_el_flag(): void
    {
        $mark   = $this->user('management@atlantic.test');
        $otra   = $this->user('lideroperaciones@test.com');
        AdminContFiscalSettings::current()->update(['primary_approver_user_id' => $mark->id]);

        $this->assertTrue($this->flagFor($mark));
        $this->assertFalse($this->flagFor($otra));
    }

    public function test_sin_aprobador_configurado_nadie_lleva_el_flag(): void
    {
        AdminContFiscalSettings::current()->update(['primary_approver_user_id' => null]);

        $this->assertFalse($this->flagFor($this->user('management@atlantic.test')));
        $this->assertFalse($this->flagFor($this->user('lideroperaciones@test.com')));
    }

    public function test_el_flag_viaja_en_el_objeto_user_de_las_respuestas_de_auth(): void
    {
        $mark = $this->user('management@atlantic.test');
        AdminContFiscalSettings::current()->update(['primary_approver_user_id' => $mark->id]);

        $payload = app(JwtClaimsBuilder::class)->userPayload($mark, 'es');

        $this->assertTrue($payload['flags']['mark_approver']);
        $this->assertArrayHasKey('approve_large_amounts', $payload['flags'], 'Los flags existentes no cambian.');
        $this->assertArrayHasKey('manage_users', $payload['flags']);
    }
}
