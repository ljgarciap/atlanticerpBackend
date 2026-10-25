<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Auth\Support\RoleKeys;
use PHPUnit\Framework\TestCase;

/**
 * Fase 3 (docs/specs/fase3-roles-genericos.md, §3.2): la clave de un rol solo puede escribirse como literal en `RoleKeys`. Así renombrar un rol es una
 * línea + una migración de datos, y un literal olvidado no degrada una función en silencio. Sin base de datos: escanea el código fuente.
 */
class RoleKeysUsageTest extends TestCase
{
    /** Claves anteriores a la Fase 3: no deben aparecer en ningún lado salvo en la migración que las renombra, en RoleKeys (docblock) y en este test. */
    private const OLD_KEYS = ['ayudante_general_bodega', 'tecnico_servicios', 'garantias_servicios', 'vendedor_disenador'];

    /** `vendedor` y `tecnico` también son claves de arrays de datos (`'vendedor' => $nombre`): solo se prohíben en contextos de rol. */
    private const AMBIGUOUS = ['vendedor', 'tecnico'];

    /** @var list<string> usos legítimos que NO son la clave de un rol */
    private const ALLOWED = [
        'app/Modules/Legacy/Extractors/ClienteRawExtractor.php',   // 'transporte' = columna del sistema legado
        'app/Modules/Bodega/Support/BodegaRoles.php',              // parámetro público ?role=asistente_bodega de /bodega/team-members
    ];

    /** @return list<string> rutas relativas de los .php de producción */
    private function productionFiles(): array
    {
        $root = dirname(__DIR__, 2);
        $files = [];
        foreach (['app', 'routes', 'config'] as $dir) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator("{$root}/{$dir}", \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if ($f->isFile() && $f->getExtension() === 'php') {
                    $files[] = substr($f->getPathname(), strlen($root) + 1);
                }
            }
        }
        sort($files);

        return $files;
    }

    /** @return list<string> líneas de código (sin comentarios) con su ubicación */
    private function codeLines(string $relative): array
    {
        $out = [];
        foreach (explode("\n", (string) file_get_contents(dirname(__DIR__, 2).'/'.$relative)) as $i => $line) {
            $t = ltrim($line);
            if ($t === '' || str_starts_with($t, '//') || str_starts_with($t, '*') || str_starts_with($t, '/*') || str_starts_with($t, '#')) {
                continue;
            }
            $out[] = ($i + 1).'|'.$line;
        }

        return $out;
    }

    public function test_las_claves_viejas_no_aparecen_en_el_codigo_de_produccion(): void
    {
        $found = [];
        foreach ($this->productionFiles() as $file) {
            if (str_ends_with($file, 'Auth/Support/RoleKeys.php')) {
                continue;
            }
            foreach ($this->codeLines($file) as $entry) {
                foreach (self::OLD_KEYS as $old) {
                    if (preg_match('/\b'.$old.'\b/', $entry) === 1) {
                        $found[] = "{$file}:{$entry}";
                    }
                }
            }
        }
        $this->assertSame([], $found, 'claves de rol anteriores a la Fase 3 en código de producción');
    }

    public function test_ninguna_clave_de_rol_inequivoca_se_escribe_como_literal_fuera_de_rolekeys(): void
    {
        $keys = array_values(array_diff(RoleKeys::all(), self::AMBIGUOUS));
        $regex = '/([\'"])('.implode('|', array_map('preg_quote', $keys)).')\1/';

        $found = [];
        foreach ($this->productionFiles() as $file) {
            if (str_ends_with($file, 'Auth/Support/RoleKeys.php') || in_array($file, self::ALLOWED, true)) {
                continue;
            }
            foreach ($this->codeLines($file) as $entry) {
                if (preg_match($regex, $entry) === 1) {
                    $found[] = "{$file}:{$entry}";
                }
            }
        }
        $this->assertSame([], $found, 'usar RoleKeys::X en vez del literal');
    }

    public function test_vendedor_y_tecnico_no_se_escriben_como_clave_de_rol(): void
    {
        $regex = '/(role|key|roleKey|role_key)[^;\n]{0,60}([\'"])(vendedor|tecnico)\2/i';

        $found = [];
        foreach ($this->productionFiles() as $file) {
            if (str_ends_with($file, 'Auth/Support/RoleKeys.php')) {
                continue;
            }
            foreach ($this->codeLines($file) as $entry) {
                if (preg_match($regex, $entry) === 1) {
                    $found[] = "{$file}:{$entry}";
                }
            }
        }
        $this->assertSame([], $found);
    }

    public function test_rolekeys_all_tiene_14_claves_unicas(): void
    {
        $this->assertCount(14, RoleKeys::all());
        $this->assertSame(RoleKeys::all(), array_values(array_unique(RoleKeys::all())));
    }
}
