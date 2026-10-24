<?php

declare(strict_types=1);

namespace Tests\Unit\Integrations\Lafayette;

use App\Modules\Integrations\Lafayette\Data\ExtractedProductos;
use App\Modules\Integrations\Lafayette\Data\LafayetteProductoRow;
use App\Modules\Integrations\Lafayette\Extractors\ProductoExtractor;
use App\Modules\Integrations\Lafayette\Services\ProductoMapper;
use Carbon\Carbon;
use Tests\TestCase;

/**
 * SCRUM-971 / SCRUM-990 — tests puros de `ProductoMapper` (sin DB). SCRUM-990: el espejo `lafayette_productos` guarda TODO lo
 * que la fuente trae, así que ya no hay exclusiones por falta de `referencia` o `descripcion`.
 */
final class ProductoMapperTest extends TestCase
{
    public function test_mapea_una_fila_completa(): void
    {
        $mapped = ProductoMapper::mapOne(self::row());

        $this->assertSame('LNP0000001', $mapped->lafayetteId);
        $this->assertSame('331819AP2-PAB', $mapped->referencia);
        $this->assertSame('LAMP COLG DECO 90W', $mapped->descripcion);
        $this->assertSame('FLUA', $mapped->marca);
        $this->assertSame('LAMPARAS', $mapped->categoria);
        $this->assertSame(31, $mapped->disponible);
        $this->assertSame(900.80, $mapped->precio5);
        $this->assertSame(901.30, $mapped->precio6);
        $this->assertTrue($mapped->fuenteActualizadoAt?->equalTo(Carbon::parse('2026-09-25 16:58:46')));
    }

    public function test_sin_referencia_ya_no_se_excluye_se_guarda_como_null(): void
    {
        $mapped = ProductoMapper::mapOne(self::row(['referencia' => null]));

        $this->assertSame('LNP0000001', $mapped->lafayetteId);
        $this->assertNull($mapped->referencia);
        $this->assertSame('LAMP COLG DECO 90W', $mapped->descripcion);
    }

    public function test_sin_descripcion_ya_no_se_excluye_se_guarda_como_null(): void
    {
        $mapped = ProductoMapper::mapOne(self::row(['descripcion' => null]));

        $this->assertNull($mapped->descripcion);
        $this->assertSame('331819AP2-PAB', $mapped->referencia);
    }

    public function test_precio_6_nulo_se_preserva_como_null_no_como_cero(): void
    {
        $this->assertNull(ProductoMapper::mapOne(self::row(['precio6' => null]))->precio6);
    }

    public function test_precio_5_nulo_se_preserva_como_null_no_como_cero(): void
    {
        $this->assertNull(ProductoMapper::mapOne(self::row(['precio5' => null]))->precio5);
    }

    public function test_disponible_negativo_se_trunca_a_cero(): void
    {
        $this->assertSame(0, ProductoMapper::mapOne(self::row(['disponible' => -5]))->disponible);
    }

    public function test_fecha_de_la_fuente_ausente_queda_null(): void
    {
        $this->assertNull(ProductoMapper::mapOne(self::row(['cacheUpdatedAt' => null]))->fuenteActualizadoAt);
    }

    public function test_muestra_de_20_filas_pasa_por_el_extractor_real_y_no_pierde_ninguna(): void
    {
        /** @var list<array<string, mixed>> $raw */
        $raw = require __DIR__ . '/../../../Fixtures/Lafayette/vista_productos_lighting_muestra.php';
        $rows = array_map(static fn (array $r): LafayetteProductoRow => ProductoExtractor::mapRow($r), $raw);

        $mapped = (new ProductoMapper())->buildPayloads(new ExtractedProductos($rows));

        $this->assertCount(20, $mapped, 'sin exclusiones: entran las 20 filas, incluidas las de referencia/descripcion nulas');
        $byId = [];
        foreach ($mapped as $m) {
            $byId[$m->lafayetteId][] = $m;
        }
        $this->assertCount(2, $byId['LNP0000001'], 'el duplicado de la vista llega al Loader, que lo deduplica');
        $this->assertNull($byId['LNP0000013'][0]->referencia, 'la cadena de solo espacios se normaliza a null');
        $this->assertNull($byId['LNP0000013'][0]->descripcion);
        $this->assertSame(0, $byId['LNP0000014'][0]->disponible);
        $this->assertSame('LÁMPARA LED BULBO A60 9W 6500K LUZ FRÍA', $byId['LNP0000003'][0]->descripcion);
    }

    /** @param array<string, mixed> $overrides */
    private static function row(array $overrides = []): LafayetteProductoRow
    {
        $m = array_merge([
            'id'             => 'LNP0000001',
            'referencia'     => '331819AP2-PAB',
            'descripcion'    => 'LAMP COLG DECO 90W',
            'marca'          => 'FLUA',
            'urlImagen'      => null,
            'categoria'      => 'LAMPARAS',
            'disponible'     => 31,
            'precio5'        => 900.80,
            'precio6'        => 901.30,
            'cacheUpdatedAt' => '2026-09-25 16:58:46',
        ], $overrides);

        return new LafayetteProductoRow(
            id: $m['id'],
            referencia: $m['referencia'],
            descripcion: $m['descripcion'],
            marca: $m['marca'],
            urlImagen: $m['urlImagen'],
            categoria: $m['categoria'],
            disponible: $m['disponible'],
            precio5: $m['precio5'],
            precio6: $m['precio6'],
            cacheUpdatedAt: $m['cacheUpdatedAt'],
        );
    }
}
