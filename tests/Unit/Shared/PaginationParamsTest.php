<?php

declare(strict_types=1);

namespace Tests\Unit\Shared;

use App\Shared\Http\Pagination;
use App\Shared\Http\QueryParams;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

/**
 * SCRUM-992 (Pre-QA H6/H8) — `Pagination::params()` y `QueryParams::string()` frente a valores
 * absurdos o de tipo inesperado. Los valores válidos no cambian.
 */
class PaginationParamsTest extends TestCase
{
    /** @param array<string, mixed> $query */
    private function params(array $query): array
    {
        return Pagination::params(Request::create('/x', 'GET', $query), ['name'], 'name');
    }

    public function test_page_valida_no_cambia(): void
    {
        $this->assertSame(1, $this->params([])['page']);
        $this->assertSame(1, $this->params(['page' => '0'])['page']);
        $this->assertSame(1, $this->params(['page' => '-7'])['page']);
        $this->assertSame(3, $this->params(['page' => '3'])['page']);
        $this->assertSame(250000, $this->params(['page' => '250000'])['page']);
    }

    public function test_page_gigante_se_acota_al_techo(): void
    {
        // H6: 9223372036854775808 (PHP_INT_MAX + 1) devolvía las filas de la página 1 con
        // current_page = PHP_INT_MAX porque (page - 1) * per_page desbordaba a float.
        foreach (['9223372036854775808', '9223372036854775807', '99999999999', '1000001'] as $huge) {
            $this->assertSame(1_000_000, $this->params(['page' => $huge, 'per_page' => 'all'])['page'], $huge);
        }
    }

    public function test_offset_maximo_cabe_en_un_entero(): void
    {
        $p = $this->params(['page' => '9223372036854775808', 'per_page' => 'all']);

        $offset = ($p['page'] - 1) * $p['per_page'];

        $this->assertIsInt($offset);
        $this->assertSame(999_999_000, $offset);
    }

    public function test_parametros_array_se_tratan_como_ausentes(): void
    {
        // H8: `per_page[]=5` y `sort_dir[]=desc` lanzaban "Array to string conversion".
        $p = $this->params(['per_page' => ['5'], 'sort_dir' => ['desc'], 'page' => ['2'], 'sort_by' => ['name']]);

        $this->assertSame(20, $p['per_page']);
        $this->assertSame('asc', $p['sort_dir']);
        $this->assertSame(1, $p['page']);
        $this->assertSame('name', $p['sort_by']);
    }

    public function test_per_page_all_y_valores_permitidos_no_cambian(): void
    {
        $this->assertSame(1000, $this->params(['per_page' => 'all'])['per_page']);
        $this->assertSame(50, $this->params(['per_page' => '50'])['per_page']);
        $this->assertSame(20, $this->params(['per_page' => '7'])['per_page']);
        $this->assertSame('desc', $this->params(['sort_dir' => 'DESC'])['sort_dir']);
    }

    public function test_query_params_string_convencion(): void
    {
        $r = Request::create('/x', 'GET', ['a' => 'hola', 'n' => 5, 'arr' => ['x'], 'vacio' => '']);

        $this->assertSame('hola', QueryParams::string($r, 'a'));
        $this->assertSame('5', QueryParams::string($r, 'n'));
        $this->assertSame('por-defecto', QueryParams::string($r, 'arr', 'por-defecto'));
        $this->assertSame('', QueryParams::string($r, 'ausente'));
        $this->assertSame('todos', QueryParams::string($r, 'ausente', 'todos'));
        $this->assertSame('', QueryParams::string($r, 'vacio', 'x'));
    }
}
