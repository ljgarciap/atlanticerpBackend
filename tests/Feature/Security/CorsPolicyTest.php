<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use Tests\TestCase;

/** SCRUM-1032 (A-07): la API ya no responde `Access-Control-Allow-Origin: *`. */
class CorsPolicyTest extends TestCase
{
    public function test_origen_ajeno_no_recibe_cabecera_cors(): void
    {
        $r = $this->withHeaders(['Origin' => 'https://evil.example'])->get('/api/health');

        $this->assertNull($r->headers->get('Access-Control-Allow-Origin'));
    }

    public function test_origen_propio_si_la_recibe_y_nunca_comodin(): void
    {
        config(['cors.allowed_origins' => ['https://dev.atlanticerp.ai']]);

        $r = $this->withHeaders(['Origin' => 'https://dev.atlanticerp.ai'])->get('/api/health');

        $this->assertSame('https://dev.atlanticerp.ai', $r->headers->get('Access-Control-Allow-Origin'));
    }

    public function test_la_politica_no_permite_comodin_ni_credenciales(): void
    {
        $this->assertNotContains('*', config('cors.allowed_origins'));
        $this->assertFalse(config('cors.supports_credentials'));
    }
}
