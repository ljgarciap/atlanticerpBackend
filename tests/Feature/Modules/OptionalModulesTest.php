<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Fase 5a (docs/specs/fase5-aislar-legacy-lafayette.md §4) — contrato de los módulos fuera del núcleo.
 *
 * Legacy (ICG) y Lafayette se registran solo con `MODULE_LEGACY_ICG` / `MODULE_LAFAYETTE` (config/modules.php, apagados por defecto). Las rutas
 * y los comandos se fijan al arrancar la aplicación, así que cada caso arranca un proceso `artisan` APARTE con las banderas que quiere probar
 * (una variable de entorno real gana sobre `.env`). El resto de la suite corre con ambas encendidas (phpunit.xml) y cubre el comportamiento encendido.
 */
class OptionalModulesTest extends TestCase
{
    /** @return array{routes: list<string>, commands: list<string>, schedule: string} */
    private function boot(bool $legacy, bool $lafayette): array
    {
        $env = ['MODULE_LEGACY_ICG' => $legacy ? 'true' : 'false', 'MODULE_LAFAYETTE' => $lafayette ? 'true' : 'false'];

        $run = static function (array $args) use ($env): string {
            $p = new Process([PHP_BINARY, 'artisan', ...$args], dirname(__DIR__, 3), $env + ['APP_ENV' => 'testing']);
            $p->mustRun();

            return $p->getOutput();
        };

        /** @var list<array{uri: string}> $routes */
        $routes = json_decode($run(['route:list', '--json']), true, flags: JSON_THROW_ON_ERROR);
        /** @var array{commands: list<array{name: string}>} $list */
        $list = json_decode($run(['list', '--format=json']), true, flags: JSON_THROW_ON_ERROR);

        return [
            'routes' => array_column($routes, 'uri'),
            'commands' => array_column($list['commands'], 'name'),
            'schedule' => $run(['schedule:list']),
        ];
    }

    /** @param list<string> $items */
    private function anyContains(array $items, string $needle): bool
    {
        foreach ($items as $i) {
            if (str_contains($i, $needle)) {
                return true;
            }
        }

        return false;
    }

    public function test_apagados_no_hay_rutas_comandos_ni_schedules_de_legacy_ni_lafayette(): void
    {
        $b = $this->boot(false, false);

        $this->assertFalse($this->anyContains($b['routes'], 'api/legacy'), 'rutas /api/legacy registradas con el módulo apagado');
        $this->assertFalse($this->anyContains($b['routes'], 'legacy-history'), 'historial ICG registrado con el módulo apagado');
        $this->assertFalse($this->anyContains($b['routes'], 'zona-libre/productos'), 'catálogo externo registrado con el módulo apagado');
        $this->assertSame([], array_values(array_filter($b['commands'], static fn (string $c): bool => str_starts_with($c, 'legacy:') || str_starts_with($c, 'lafayette:'))));
        $this->assertStringNotContainsString('lafayette:', $b['schedule']);
    }

    public function test_apagados_el_nucleo_de_zona_libre_sigue_disponible(): void
    {
        $b = $this->boot(false, false);

        $this->assertTrue($this->anyContains($b['routes'], 'zona-libre/requests'));
        $this->assertTrue($this->anyContains($b['routes'], 'zona-libre/provider'));
    }

    #[DataProvider('encendidos')]
    public function test_encendidos_se_comportan_como_antes(bool $legacy, bool $lafayette): void
    {
        $b = $this->boot($legacy, $lafayette);

        $this->assertSame($legacy, $this->anyContains($b['routes'], 'api/legacy'));
        $this->assertSame($legacy, $this->anyContains($b['routes'], 'legacy-history'));
        $this->assertSame($legacy, in_array('legacy:sync-productos', $b['commands'], true));
        $this->assertSame($lafayette, $this->anyContains($b['routes'], 'zona-libre/productos'));
        $this->assertSame($lafayette, in_array('lafayette:sync-productos', $b['commands'], true));
    }

    /** @return array<string, array{bool, bool}> */
    public static function encendidos(): array
    {
        return ['solo legacy' => [true, false], 'solo lafayette' => [false, true], 'ambos' => [true, true]];
    }
}
