<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Shared\Support\MemoryLimit;
use PHPUnit\Framework\TestCase;

/** Regresión del CI en rojo (2026-10-01): `ini_set` a 512M bajaba el `memory_limit=1G` de la suite. */
class MemoryLimitTest extends TestCase
{
    private string $original;

    protected function setUp(): void
    {
        $this->original = (string) ini_get('memory_limit');
    }

    protected function tearDown(): void
    {
        ini_set('memory_limit', $this->original);
    }

    public function test_to_bytes_entiende_sufijos_y_sin_limite(): void
    {
        $this->assertSame(512 * 1024 ** 2, MemoryLimit::toBytes('512M'));
        $this->assertSame(1024 ** 3, MemoryLimit::toBytes('1G'));
        $this->assertSame(128 * 1024, MemoryLimit::toBytes('128K'));
        $this->assertSame(2048, MemoryLimit::toBytes('2048'));
        $this->assertSame(-1, MemoryLimit::toBytes('-1'));
    }

    public function test_sube_el_limite_cuando_es_menor(): void
    {
        ini_set('memory_limit', '256M');

        MemoryLimit::atLeast('512M');

        $this->assertSame('512M', ini_get('memory_limit'));
    }

    public function test_nunca_baja_un_limite_mayor(): void
    {
        ini_set('memory_limit', '1G');

        MemoryLimit::atLeast('512M');

        $this->assertSame('1G', ini_get('memory_limit'));
    }

    public function test_no_toca_un_proceso_sin_limite(): void
    {
        ini_set('memory_limit', '-1');

        MemoryLimit::atLeast('512M');

        $this->assertSame('-1', ini_get('memory_limit'));
    }
}
