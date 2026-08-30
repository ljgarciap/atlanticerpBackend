<?php

namespace Tests\Feature\Auth;

use Tests\Feature\FeatureTestCase;

class ChangePasswordTest extends FeatureTestCase
{
    public function test_cambio_de_password_valido_retorna_200(): void
    {
        $token = $this->loginAs();

        $this->withHeaders($this->authHeaders($token))
            ->putJson('/api/auth/password', [
                'current_password'          => 'Password123!',
                'new_password'              => 'NuevoPass99!',
                'new_password_confirmation' => 'NuevoPass99!',
            ])
            ->assertStatus(204);
    }

    public function test_despues_del_cambio_el_nuevo_password_funciona(): void
    {
        $token = $this->loginAs();

        $this->withHeaders($this->authHeaders($token))
            ->putJson('/api/auth/password', [
                'current_password'          => 'Password123!',
                'new_password'              => 'NuevoPass99!',
                'new_password_confirmation' => 'NuevoPass99!',
            ])
            ->assertStatus(204);

        // El nuevo password debe permitir login
        $this->postJson('/api/auth/login', [
            'email'    => 'management@illuminations.test',
            'password' => 'NuevoPass99!',
        ])->assertStatus(200);
    }

    public function test_despues_del_cambio_el_password_anterior_es_invalido(): void
    {
        $token = $this->loginAs();

        $this->withHeaders($this->authHeaders($token))
            ->putJson('/api/auth/password', [
                'current_password'          => 'Password123!',
                'new_password'              => 'NuevoPass99!',
                'new_password_confirmation' => 'NuevoPass99!',
            ])
            ->assertStatus(204);

        $this->postJson('/api/auth/login', [
            'email'    => 'management@illuminations.test',
            'password' => 'Password123!',
        ])->assertStatus(401);
    }

    public function test_password_actual_incorrecto_retorna_422(): void
    {
        $token = $this->loginAs();

        $this->withHeaders($this->authHeaders($token))
            ->putJson('/api/auth/password', [
                'current_password'          => 'PasswordIncorrecto!',
                'new_password'              => 'NuevoPass99!',
                'new_password_confirmation' => 'NuevoPass99!',
            ])
            ->assertStatus(422);
    }

    public function test_confirmacion_no_coincide_retorna_422(): void
    {
        $token = $this->loginAs();

        $this->withHeaders($this->authHeaders($token))
            ->putJson('/api/auth/password', [
                'current_password'          => 'Password123!',
                'new_password'              => 'NuevoPass99!',
                'new_password_confirmation' => 'OtroPass99!',
            ])
            ->assertStatus(422);
    }

    public function test_password_muy_corto_retorna_422(): void
    {
        $token = $this->loginAs();

        $this->withHeaders($this->authHeaders($token))
            ->putJson('/api/auth/password', [
                'current_password'          => 'Password123!',
                'new_password'              => 'Abc1!',
                'new_password_confirmation' => 'Abc1!',
            ])
            ->assertStatus(422);
    }

    public function test_cambio_sin_autenticar_retorna_401(): void
    {
        $this->putJson('/api/auth/password', [
            'current_password'          => 'Password123!',
            'new_password'              => 'NuevoPass99!',
            'new_password_confirmation' => 'NuevoPass99!',
        ])->assertStatus(401);
    }
}
