<?php

declare(strict_types=1);

namespace Tests\Feature\AdminCont;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Tests\Feature\FeatureTestCase;

/**
 * Fase 15a (docs/specs/fase15a-un-solo-concepto-de-cobros.md) — el concepto nuevo de cobros es el único: no hay bandera, ni registro de cobros ligado a
 * facturas, ni abonos, y un entorno nuevo cobra sin ningún comando previo.
 */
final class UnSoloConceptoDeCobrosTest extends FeatureTestCase
{
    public function test_ya_no_existen_la_ruta_del_registro_anterior_ni_las_de_abonos(): void
    {
        $rutas = collect(Route::getRoutes()->getRoutes());
        $uris = $rutas->map(fn ($r): string => $r->uri().' '.implode('|', $r->methods()))->all();

        $this->assertNotContains('api/admin-contab/payments POST', $uris);
        $this->assertSame([], $rutas->filter(fn ($r): bool => str_starts_with($r->uri(), 'api/admin-contab/abonos'))->all(), 'Sin rutas de abonos.');
        $this->assertContains('api/admin-contab/payments/cobro POST', $uris, 'El registro de cobros del concepto nuevo sigue.');
    }

    public function test_el_comando_de_la_bandera_ya_no_existe(): void
    {
        $this->assertArrayNotHasKey('cobros:v2', Artisan::all());
    }

    public function test_la_configuracion_solo_informa_la_ventana_de_compensaciones_y_el_login_no_trae_la_bandera(): void
    {
        $h = $this->authHeaders($this->loginAs('contabilidad@test.com', 'contabilidad@test.com', 'lider_admin_contab'));

        $this->withHeaders($h)->getJson('/api/admin-contab/payments/config')->assertOk()->assertExactJson(['compensaciones_dias_movimiento' => 30]);
        // Las compensaciones responden sin ningún encendido previo.
        $this->withHeaders($h)->getJson('/api/admin-contab/compensaciones/clientes')->assertOk();

        $flags = $this->postJson('/api/auth/login', ['email' => 'contabilidad@test.com', 'password' => 'contabilidad@test.com'])->json('user.flags') ?? [];
        $this->assertArrayNotHasKey('cobros_v2', $flags);
    }
}
