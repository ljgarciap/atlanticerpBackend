<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Modules\Auth\Mail\AccountActivationMail;
use App\Modules\Auth\Mail\PasswordNoticeMail;
use App\Modules\Auth\Mail\PasswordResetMail;
use App\Modules\Auth\Models\SecurityLevel;
use App\Modules\Auth\Services\AdminPasswordResetService;
use App\Modules\Auth\Services\PasswordResetService;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\FeatureTestCase;

/** S2: aviso por correo al dueño de la cuenta cuando su contraseña cambia (reset administrativo, cambio propio u olvidé mi contraseña). */
class PasswordNoticeMailTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    private function reMakeCurrent(): void
    {
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    /** @param array<string, mixed> $overrides */
    private function makeUser(array $overrides = []): User
    {
        return User::create([
            'first_name' => 'Marta', 'last_name' => 'Prueba', 'email' => uniqid('mail-') . '@atlantic.test',
            'password_hash' => 'ClaveOriginal99!', 'security_level_id' => SecurityLevel::where('level', 3)->firstOrFail()->id,
            'is_active' => true,
            ...$overrides,
        ]);
    }

    /** @return array<string, string> */
    private function superadminHeaders(): array
    {
        return $this->authHeaders($this->loginAs('superadmin@atlantic.test', 'Password123!', 'superadmin'));
    }

    public function test_el_reset_administrativo_por_api_envia_el_enlace_de_activacion_con_la_vigencia_y_sin_contrasenas(): void
    {
        Mail::fake();
        $user = $this->makeUser();

        $this->postJson("/api/users/{$user->id}/reset-password", [], $this->superadminHeaders())->assertStatus(200);

        Mail::assertQueued(AccountActivationMail::class, function (AccountActivationMail $m) use ($user): bool {
            $html = $m->render();

            return $m->hasTo($user->email)
                && str_contains($html, 'Marta')
                && str_contains($html, '168 horas')
                && str_contains($html, '/reset-password#token=')
                && ! str_contains($html, 'ClaveOriginal99!')
                && ! str_contains($html, $user->email . '</');
        });
        Mail::assertQueuedCount(1);
    }

    public function test_los_avisos_van_a_cola_y_no_se_envian_de_forma_sincrona(): void
    {
        Mail::fake();
        $user = $this->makeUser();

        $this->postJson("/api/users/{$user->id}/reset-password", [], $this->superadminHeaders())->assertStatus(200);

        // R3-7: el reset no depende del SMTP síncrono; el aviso queda encolado (el worker lo envía luego).
        Mail::assertQueued(AccountActivationMail::class);
        Mail::assertNotSent(AccountActivationMail::class);
        $this->assertInstanceOf(\Illuminate\Contracts\Queue\ShouldQueue::class, new PasswordNoticeMail(PasswordNoticeMail::TYPE_CHANGED, 'X', 'es', '01/01/2026 10:00'));
    }

    public function test_el_correo_sale_en_el_idioma_de_las_preferencias_del_usuario(): void
    {
        Mail::fake();
        $en = $this->makeUser(['preferences' => ['language' => 'en']]);
        $es = $this->makeUser(['preferences' => ['language' => 'es']]);
        $sin = $this->makeUser();
        $service = app(AdminPasswordResetService::class);

        foreach ([$en, $es, $sin] as $u) {
            $this->reMakeCurrent();
            $service->resetUser($u, 'prueba', 'comando');
        }

        $subjects = [];
        Mail::assertQueued(AccountActivationMail::class, function (AccountActivationMail $m) use (&$subjects): bool {
            $subjects[$m->to[0]['address']] = $m->envelope()->subject;

            return true;
        });
        $this->assertSame('Activate your account — AtlanticERP', $subjects[$en->email]);
        $this->assertSame('Activa tu cuenta — AtlanticERP', $subjects[$es->email]);
        $this->assertSame('Activa tu cuenta — AtlanticERP', $subjects[$sin->email], 'sin preferencia usa el idioma por defecto');
    }

    public function test_el_cambio_propio_de_contrasena_avisa_al_dueno(): void
    {
        Mail::fake();
        $user  = $this->makeUser();
        $token = $this->loginAs($user->email, 'ClaveOriginal99!');

        $this->putJson('/api/auth/password', [
            'current_password' => 'ClaveOriginal99!', 'new_password' => 'NuevaClave99!x', 'new_password_confirmation' => 'NuevaClave99!x',
        ], $this->authHeaders($token))->assertStatus(204);

        Mail::assertQueued(PasswordNoticeMail::class, static fn (PasswordNoticeMail $m): bool => $m->hasTo($user->email) && $m->type === PasswordNoticeMail::TYPE_CHANGED);
        Mail::assertQueuedCount(1);
    }

    public function test_un_cambio_rechazado_no_avisa(): void
    {
        Mail::fake();
        $user  = $this->makeUser();
        $token = $this->loginAs($user->email, 'ClaveOriginal99!');

        $this->putJson('/api/auth/password', [
            'current_password' => 'incorrecta', 'new_password' => 'NuevaClave99!x', 'new_password_confirmation' => 'NuevaClave99!x',
        ], $this->authHeaders($token))->assertStatus(422);

        Mail::assertNothingOutgoing();
    }

    public function test_olvide_mi_contrasena_avisa_al_dueno_cuando_la_cambia(): void
    {
        Mail::fake();
        $user = $this->makeUser();

        app(PasswordResetService::class)->sendResetLink($user->email);
        $url = null;
        Mail::assertQueued(PasswordResetMail::class, static function (PasswordResetMail $m) use (&$url): bool {
            $url = $m->resetUrl;

            return true;
        });
        parse_str((string) parse_url((string) $url, PHP_URL_FRAGMENT), $query);

        $this->postJson('/api/auth/reset-password', [
            'token' => $query['token'], 'password' => 'NuevaClave99!x', 'password_confirmation' => 'NuevaClave99!x',
        ])->assertStatus(200);

        Mail::assertQueued(PasswordNoticeMail::class, static fn (PasswordNoticeMail $m): bool => $m->hasTo($user->email) && $m->type === PasswordNoticeMail::TYPE_CHANGED);
    }

    public function test_el_masivo_envia_el_enlace_a_cada_afectado(): void
    {
        Mail::fake();
        $a = $this->makeUser();
        $b = $this->makeUser();
        $headers = $this->superadminHeaders();
        $count = $this->postJson('/api/users/reset-passwords', ['dry_run' => true], $headers)->json('data.count');

        $this->postJson('/api/users/reset-passwords', ['operator_password' => 'Password123!', 'confirm' => "RESETEAR {$count}"], $headers)->assertStatus(200);

        foreach ([$a, $b] as $u) {
            Mail::assertQueued(AccountActivationMail::class, static fn (AccountActivationMail $m): bool => $m->hasTo($u->email));
        }
        Mail::assertQueuedCount($count);
    }

    public function test_un_fallo_de_envio_no_rompe_el_reset_ni_el_cambio_de_contrasena(): void
    {
        $user = $this->makeUser();
        $headers = $this->superadminHeaders();
        $this->reMakeCurrent();

        Mail::shouldReceive('to')->andThrow(new \RuntimeException('SMTP caido con datos sensibles'));

        $this->postJson("/api/users/{$user->id}/reset-password", [], $headers)->assertStatus(200);
        $this->reMakeCurrent();
        $this->assertTrue($user->fresh()->must_change_password, 'el reset se aplicó aunque el correo falló');

        $otro = $this->makeUser();
        $this->reMakeCurrent();
        $this->putJson('/api/auth/password', [
            'current_password' => 'ClaveOriginal99!', 'new_password' => 'NuevaClave99!x', 'new_password_confirmation' => 'NuevaClave99!x',
        ], $this->authHeaders($this->loginAs($otro->email, 'ClaveOriginal99!')))->assertStatus(204);
    }
}
