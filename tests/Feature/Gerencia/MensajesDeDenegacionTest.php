<?php

declare(strict_types=1);

namespace Tests\Feature\Gerencia;

use App\Shared\Multitenancy\Tenant;
use App\Shared\Support\PrimaryApprovers;
use Tests\Feature\FeatureTestCase;

/**
 * Fase 12b — el 403 de una aprobación distingue «no eres el aprobador» (identidad, se arregla designándote) de «falta un permiso».
 */
class MensajesDeDenegacionTest extends FeatureTestCase
{
    private const NO_APROBADOR = 'gerencia2@test.com';

    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        PrimaryApprovers::forget();
    }

    public function test_una_gerencia_que_no_es_el_aprobador_recibe_un_mensaje_de_identidad_no_de_permiso(): void
    {
        $token = $this->loginAs(self::NO_APROBADOR, self::NO_APROBADOR, 'management');

        $r = $this->withHeaders($this->authHeaders($token))->postJson('/api/gerencia/aprobaciones/relocation/1/aprobar')->assertStatus(403);

        $this->assertStringContainsString('Solo el aprobador configurado o un suplente', (string) $r->json('message'));
        $this->assertStringContainsString('Configuración global → Aprobadores', (string) $r->json('message'));
        $this->assertStringContainsString('regla de aprobación', (string) $r->json('message'), 'los tipos con regla lo mencionan');
    }

    public function test_orden_de_compra_no_menciona_reglas_y_zona_libre_dice_que_falta_el_permiso(): void
    {
        $token = $this->loginAs(self::NO_APROBADOR, self::NO_APROBADOR, 'management');

        $oc = $this->withHeaders($this->authHeaders($token))->postJson('/api/gerencia/aprobaciones/purchase_order/1/rechazar')->assertStatus(403);
        $this->assertStringNotContainsString('regla de aprobación', (string) $oc->json('message'));
        $this->assertStringContainsString('aprobador configurado', (string) $oc->json('message'));

        $zl = $this->withHeaders($this->authHeaders($token))->postJson('/api/gerencia/aprobaciones/zona_libre/1/aprobar')->assertStatus(403);
        $this->assertStringContainsString('compras.zona-libre.approve', (string) $zl->json('message'));
    }
}
