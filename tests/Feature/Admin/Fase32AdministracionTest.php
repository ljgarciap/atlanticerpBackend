<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Shared\Multitenancy\Tenant;
use Tests\Feature\FeatureTestCase;

/** Fase 32 (docs/specs/fase32-cierre-abiertos.md) — administración: contacto de soporte visible sin sesión. */
final class Fase32AdministracionTest extends FeatureTestCase
{
    private function tenant(): void
    {
        Tenant::where('slug', 'atlantic')->firstOrFail()->makeCurrent();
    }

    public function test_el_contacto_de_soporte_se_ve_sin_sesion_y_solo_ese_dato(): void
    {
        // Sin configurar: vacío (la pantalla de ingreso no muestra nada).
        $this->getJson('/api/auth/support-contact')->assertOk()->assertExactJson(['data' => ['support_contact' => '']]);

        $h = $this->authHeaders($this->loginAs());
        $this->tenant();
        $this->withHeaders($h)->putJson('/api/admin/global-settings/support_contact', ['value' => 'soporte@example.com'])->assertOk();

        $this->getJson('/api/auth/support-contact')->assertOk()->assertExactJson(['data' => ['support_contact' => 'soporte@example.com']]);
    }
}
