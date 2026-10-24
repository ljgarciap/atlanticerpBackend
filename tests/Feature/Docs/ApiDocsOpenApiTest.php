<?php

declare(strict_types=1);

namespace Tests\Feature\Docs;

use Dedoc\Scramble\Scramble;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use App\Shared\Multitenancy\Tenant;
use Tests\Feature\FeatureTestCase;

/**
 * La documentación OpenAPI (Scramble) cubre TODA la API y se genera sin errores: si alguien agrega una regla de validación que Scramble no
 * puede evaluar (constante privada, `self::` dentro de `Rule::in`…) o rompe el análisis de una ruta, este test lo atrapa en el gate.
 * `dedoc/scramble` es `require-dev`: sin él (servidores con `--no-dev`) el test se omite.
 */
class ApiDocsOpenApiTest extends FeatureTestCase
{
    public function test_el_openapi_se_exporta_sin_errores_y_cubre_toda_la_api(): void
    {
        if (! class_exists(Scramble::class)) {
            $this->markTestSkipped('dedoc/scramble no está instalado (require-dev).');
        }

        // En el CLI real el ApiDocsServiceProvider activa el tenant al arrancar `scramble:export`; `Artisan::call` no dispara ese evento.
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();

        $path = sys_get_temp_dir().'/atlanticerp-openapi-'.uniqid().'.json';

        try {
            // Código de salida 0 = sin errores (los avisos no cuentan).
            $this->assertSame(0, Artisan::call('scramble:export', ['--path' => $path]), Artisan::output());

            /** @var array{paths: array<string, array<string, mixed>>, components: array{securitySchemes?: array<string, mixed>}} $doc */
            $doc = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } finally {
            @unlink($path);
        }

        $apiRoutes = collect(Route::getRoutes()->getRoutes())->filter(fn ($r) => str_starts_with($r->uri(), 'api/'))->count();
        $operations = collect($doc['paths'])->sum(fn (array $item) => count($item));

        $this->assertSame($apiRoutes, $operations, 'Toda ruta api/* debe estar documentada: una operación por ruta+verbo.');
        $this->assertArrayHasKey('http', $doc['components']['securitySchemes'] ?? [], 'El documento debe declarar el bearer JWT.');
    }
}
