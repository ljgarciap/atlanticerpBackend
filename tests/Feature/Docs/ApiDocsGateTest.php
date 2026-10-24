<?php

declare(strict_types=1);

namespace Tests\Feature\Docs;

use Dedoc\Scramble\Scramble;
use Illuminate\Support\Facades\Gate;
use Tests\Feature\FeatureTestCase;

/**
 * /docs/api (Scramble) solo abre en dev, y solo cuando nginx —que exige usuario y contraseña— marca la petición con
 * `X-Api-Docs-Auth: 1` (`fastcgi_param`, no lo puede fijar el cliente). En cualquier otro entorno el gate niega.
 * `dedoc/scramble` es `require-dev`: donde no está instalado el gate no existe y el test se omite.
 */
class ApiDocsGateTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! class_exists(Scramble::class)) {
            $this->markTestSkipped('dedoc/scramble no está instalado (require-dev).');
        }
    }

    public function test_dev_con_la_marca_de_nginx_puede_ver_la_documentacion(): void
    {
        $this->app['env'] = 'dev';
        request()->headers->set('X-Api-Docs-Auth', '1');

        $this->assertTrue(Gate::allows('viewApiDocs'));
    }

    public function test_dev_sin_la_marca_de_nginx_no_puede_verla(): void
    {
        $this->app['env'] = 'dev';
        request()->headers->remove('X-Api-Docs-Auth');

        $this->assertFalse(Gate::allows('viewApiDocs'));
    }

    public function test_fuera_de_dev_nunca_puede_verla_ni_con_la_marca(): void
    {
        foreach (['qa', 'production'] as $env) {
            $this->app['env'] = $env;
            request()->headers->set('X-Api-Docs-Auth', '1');

            $this->assertFalse(Gate::allows('viewApiDocs'), "No debe abrir en {$env}");
        }
    }
}
