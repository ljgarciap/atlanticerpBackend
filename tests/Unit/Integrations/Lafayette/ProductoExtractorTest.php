<?php

declare(strict_types=1);

namespace Tests\Unit\Integrations\Lafayette;

use App\Modules\Integrations\Lafayette\Extractors\ProductoExtractor;
use PHPUnit\Framework\TestCase;

/**
 * SCRUM-971 — tests puros de la capa de mapeo de `ProductoExtractor` (sin DB, sin conexión de
 * red).
 */
final class ProductoExtractorTest extends TestCase
{
    public function test_map_row_castea_campos_basicos(): void
    {
        $row = self::row();

        $producto = ProductoExtractor::mapRow($row);

        $this->assertSame('LNP0000001', $producto->id);
        $this->assertSame('331819AP2-PAB', $producto->referencia);
        $this->assertSame('LAMP COLG DECO 90W', $producto->descripcion);
        $this->assertSame('FLUA', $producto->marca);
        $this->assertSame('LAMPARAS', $producto->categoria);
        $this->assertSame(31, $producto->disponible);
        $this->assertSame(900.80, $producto->precio5);
        $this->assertSame(901.30, $producto->precio6, 'SCRUM-1125: precio_6 se mapea aparte de precio_5');
        $this->assertSame('2026-09-25 16:58:46', $producto->cacheUpdatedAt);
    }

    public function test_map_row_trata_string_vacio_como_null(): void
    {
        $row = self::row(['referencia' => '', 'marca' => '   ']);

        $producto = ProductoExtractor::mapRow($row);

        $this->assertNull($producto->referencia);
        $this->assertNull($producto->marca);
    }

    public function test_map_row_disponible_ausente_es_cero(): void
    {
        $row = self::row(['disponible' => null]);

        $producto = ProductoExtractor::mapRow($row);

        $this->assertSame(0, $producto->disponible);
    }

    public function test_map_row_precio_5_nulo_se_preserva_como_null(): void
    {
        $row = self::row(['precio_5' => null]);

        $producto = ProductoExtractor::mapRow($row);

        $this->assertNull($producto->precio5);
    }

    /** @param array<string, mixed> $overrides */
    private static function row(array $overrides = []): array
    {
        return array_merge([
            'id'               => 'LNP0000001',
            'referencia'       => '331819AP2-PAB',
            'descripcion'      => 'LAMP COLG DECO 90W',
            'marca'            => 'FLUA',
            'url_imagen'       => null,
            'categoria'        => 'LAMPARAS',
            'disponible'       => 31,
            'precio_5'         => '900.8000',
            'precio_6'         => '901.3000',
            'cache_updated_at' => '2026-09-25 16:58:46',
        ], $overrides);
    }
}
