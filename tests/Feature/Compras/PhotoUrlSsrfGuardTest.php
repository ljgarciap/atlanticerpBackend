<?php

declare(strict_types=1);

namespace Tests\Feature\Compras;

use App\Modules\Compras\Jobs\CompareSubstituteCandidatesJob;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Shared\Multitenancy\Tenant;
use App\Shared\Rules\SafePhotoUrl;
use App\Shared\Support\SafePhotoUrlGuard;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-1038 (S-01): `photo_url` es texto libre de un usuario y CompareSubstituteCandidatesJob lo descarga.
 * La guarda solo admite https, hosts de `config('catalog.photo_hosts')`, sin credenciales ni puerto, y sin IP interna.
 */
class PhotoUrlSsrfGuardTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        config(['catalog.photo_hosts' => ['cdn.test', 'bucket.s3.us-east-1.amazonaws.com']]);
    }

    /** @param list<string> $ips */
    private function guard(array $ips = ['93.184.216.34']): SafePhotoUrlGuard
    {
        $guard = new SafePhotoUrlGuard(static fn (string $host): array => $ips);
        $this->app->instance(SafePhotoUrlGuard::class, $guard);

        return $guard;
    }

    /** @return array<string, array{string}> */
    public static function urlsRechazadas(): array
    {
        return [
            'http sin TLS'                 => ['http://cdn.test/a.jpg'],
            'host fuera de la lista'       => ['https://evil.example.com/a.jpg'],
            'metadatos de AWS'             => ['http://169.254.169.254/latest/meta-data/'],
            'metadatos por https'          => ['https://169.254.169.254/latest/meta-data/'],
            'loopback'                     => ['https://127.0.0.1/a.jpg'],
            'credenciales en la URL'       => ['https://user:pass@cdn.test/a.jpg'],
            'host permitido como usuario'  => ['https://cdn.test@evil.example.com/a.jpg'],
            'puerto distinto de 443'       => ['https://cdn.test:8443/a.jpg'],
            'ruta relativa'                => ['/public/foto.jpg'],
            'esquema file'                 => ['file:///etc/passwd'],
            'subdominio no listado'        => ['https://x.cdn.test/a.jpg'],
        ];
    }

    /** @dataProvider urlsRechazadas */
    public function test_isAllowed_rechaza_urls_peligrosas(string $url): void
    {
        $this->assertFalse($this->guard()->isAllowed($url));
    }

    public function test_isAllowed_acepta_los_hosts_configurados_en_mayusculas_o_minusculas(): void
    {
        $guard = $this->guard();

        $this->assertTrue($guard->isAllowed('https://cdn.test/a.jpg'));
        $this->assertTrue($guard->isAllowed('https://CDN.test/a.jpg?x=1'));
        $this->assertTrue($guard->isAllowed('https://bucket.s3.us-east-1.amazonaws.com/legacy-photos/x.jpg'));
    }

    /** @return array<string, array{list<string>}> */
    public static function ipsInternas(): array
    {
        return [
            'metadatos'      => [['169.254.169.254']],
            'privada 10/8'   => [['10.0.0.5']],
            'privada 172'    => [['172.16.3.4']],
            'privada 192'    => [['192.168.1.1']],
            'loopback'       => [['127.0.0.1']],
            'ipv6 loopback'  => [['::1']],
            'ipv6 ULA'       => [['fc00::1']],
            'mezcla'         => [['93.184.216.34', '10.0.0.5']],
            'sin resolver'   => [[]],
        ];
    }

    /**
     * @param list<string> $ips
     * @dataProvider ipsInternas
     */
    public function test_resolvesToPublicIps_rechaza_ips_internas_y_hosts_sin_resolver(array $ips): void
    {
        $this->assertFalse($this->guard($ips)->resolvesToPublicIps('https://cdn.test/a.jpg'));
    }

    public function test_resolvesToPublicIps_acepta_ips_publicas(): void
    {
        $this->assertTrue($this->guard(['93.184.216.34', '2606:2800:220:1:248:1893:25c8:1946'])->resolvesToPublicIps('https://cdn.test/a.jpg'));
    }

    public function test_la_regla_de_validacion_usa_la_lista_y_el_mensaje_traducido(): void
    {
        $this->guard();
        $rule = fn (mixed $value) => Validator::make(['photo_url' => $value], ['photo_url' => [new SafePhotoUrl()]]);

        $this->assertFalse($rule('https://cdn.test/a.jpg')->fails());
        $this->assertTrue($rule('http://169.254.169.254/')->fails());
        $this->assertTrue($rule(123)->fails());
        $this->assertStringContainsString('https', (string) $rule('http://x/')->errors()->first('photo_url'));
    }

    /** @return string|null  base64 de la foto o null */
    private function fetch(string $photoUrl): ?string
    {
        $candidate = new CatalogProduct(['photo_url' => $photoUrl]);
        $job       = (new \ReflectionClass(CompareSubstituteCandidatesJob::class))->newInstanceWithoutConstructor();
        $method    = new \ReflectionMethod($job, 'fetchCandidatePhoto');
        $method->setAccessible(true);

        /** @var array{base64: string, mediaType: string}|null $result */
        $result = $method->invoke($job, $candidate);

        return $result['base64'] ?? null;
    }

    public function test_el_job_no_hace_ninguna_peticion_a_urls_no_permitidas(): void
    {
        Http::fake();
        $this->guard();

        foreach (['http://169.254.169.254/latest/meta-data/', 'https://evil.example.com/a.jpg', 'https://127.0.0.1/a.jpg'] as $url) {
            $this->assertNull($this->fetch($url), $url);
        }

        Http::assertNothingSent();
    }

    public function test_el_job_no_hace_peticion_si_el_host_permitido_resuelve_a_ip_interna(): void
    {
        Http::fake();
        $this->guard(['169.254.169.254']);   // rebinding de DNS: el host está en la lista pero apunta a metadatos

        $this->assertNull($this->fetch('https://cdn.test/a.jpg'));
        Http::assertNothingSent();
    }

    public function test_el_job_descarga_la_foto_de_un_host_permitido_y_no_sigue_redirecciones(): void
    {
        Http::fake(['cdn.test/*' => Http::response('img-bytes', 200, ['Content-Type' => 'image/jpeg'])]);
        $this->guard();

        $this->assertSame(base64_encode('img-bytes'), $this->fetch('https://cdn.test/a.jpg'));
        Http::assertSent(fn (Request $r) => $r->url() === 'https://cdn.test/a.jpg');
    }

    public function test_una_redireccion_a_otro_host_no_se_sigue(): void
    {
        Http::fake([
            'cdn.test/*' => Http::response('', 302, ['Location' => 'http://169.254.169.254/latest/meta-data/']),
            '*'          => Http::response('SECRETO', 200, ['Content-Type' => 'image/jpeg']),
        ]);
        $this->guard();

        $this->assertNull($this->fetch('https://cdn.test/a.jpg'));
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '169.254.169.254'));
    }
}
