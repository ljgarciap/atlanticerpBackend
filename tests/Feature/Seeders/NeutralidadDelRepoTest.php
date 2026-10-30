<?php

declare(strict_types=1);

namespace Tests\Feature\Seeders;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\Support\TerminosDelClienteAnterior as T;
use Tests\TestCase;

/**
 * Fase 4b (docs/specs/fase4b-dataset-demo-generico.md §6.1) — contrato de neutralidad del REPOSITORIO COMPLETO: ni organizaciones, marcas, bancos y
 * lugares del cliente anterior ni el vocabulario de su sector en `app/`, `config/`, `routes/`, `resources/`, `database/` (salvo migraciones históricas) y `tests/`.
 *
 * Excepciones, todas explícitas y que SOLO PUEDEN ENCOGERSE:
 * - Permanentes: migraciones históricas (inmutables) y los documentos fechados de `docs/` (se archivan, no se reescriben); no se escanean.
 * - Temporales con dueño: `T::PERMITIDOS` (términos del MODELO) y `T::ARCHIVOS_PENDIENTES_4C` (prompts e identidad). El contrato falla si una excepción
 *   ya no hace falta, para que nadie la deje viva cuando su fase termina.
 *
 * No hereda de `FeatureTestCase`: solo lee archivos, no necesita base de datos.
 */
final class NeutralidadDelRepoTest extends TestCase
{
    private const RAICES = ['app', 'config', 'routes', 'resources', 'database', 'tests'];

    private const EXTENSIONES = ['php', 'json', 'md', 'sql', 'sh', 'yml', 'yaml', 'xml', 'txt'];

    /** @return list<string> rutas relativas a la raíz del backend */
    private function archivos(): array
    {
        $base = base_path();
        $out = [];
        foreach (self::RAICES as $raiz) {
            if (! is_dir("{$base}/{$raiz}")) {
                continue;
            }
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("{$base}/{$raiz}", RecursiveDirectoryIterator::SKIP_DOTS));
            foreach ($it as $f) {
                $rel = ltrim(substr((string) $f->getPathname(), strlen($base)), '/');
                if (! in_array($f->getExtension(), self::EXTENSIONES, true)) {
                    continue;
                }
                // Permanentes: migraciones históricas (y el test de la que retiró Zona Libre, que debe nombrarla). Y este contrato y su lista, que contienen los términos a propósito.
                if (str_starts_with($rel, 'database/migrations/') || str_contains($rel, 'TerminosDelClienteAnterior') || str_ends_with($rel, 'NeutralidadDelRepoTest.php') || str_ends_with($rel, 'RetiroZonaLibreMigracionTest.php')) {
                    continue;
                }
                $out[] = $rel;
            }
        }
        sort($out);

        return $out;
    }

    public function test_ningun_archivo_lleva_nombres_ni_vocabulario_del_cliente_anterior(): void
    {
        $pendientes = array_keys(T::ARCHIVOS_PENDIENTES_4C);
        $hallazgos = [];
        foreach ($this->archivos() as $rel) {
            if (in_array($rel, $pendientes, true)) {
                continue;
            }
            foreach (explode("\n", (string) file_get_contents(base_path($rel))) as $i => $linea) {
                if (($t = T::encuentra($linea)) !== null) {
                    $hallazgos[] = sprintf('%s:%d «%s»', $rel, $i + 1, $t);
                }
            }
        }

        $this->assertSame([], array_slice($hallazgos, 0, 25), sprintf('%d línea(s) con términos del cliente anterior (se muestran 25)', count($hallazgos)));
    }

    /** `docs/`: solo nombres de personas. El vocabulario del sector queda como historia en los documentos fechados. */
    public function test_docs_no_lleva_nombres_de_personas(): void
    {
        $base = base_path('docs');
        $this->assertDirectoryExists($base);
        $hits = [];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->getExtension() !== 'md') {
                continue;
            }
            foreach (explode("\n", (string) file_get_contents($f->getPathname())) as $i => $linea) {
                if (T::nombreDePersona($linea) !== null) {
                    $hits[] = 'docs/'.ltrim(substr((string) $f->getPathname(), strlen($base)), '/').':'.($i + 1);
                }
            }
        }

        $this->assertSame([], array_slice($hits, 0, 25), count($hits).' línea(s) de docs/ con nombres de personas (se muestran 25)');
    }

    public function test_cada_archivo_pendiente_de_la_4c_sigue_necesitando_su_excepcion(): void
    {
        $this->addToAssertionCount(1); // la lista puede estar vacía (4c completa)
        foreach (T::ARCHIVOS_PENDIENTES_4C as $rel => $motivo) {
            $this->assertFileExists(base_path($rel), "«{$rel}» ya no existe: quítalo de ARCHIVOS_PENDIENTES_4C");
            $this->assertNotNull(
                T::encuentra((string) file_get_contents(base_path($rel))),
                "«{$rel}» ({$motivo}) ya está limpio: quítalo de ARCHIVOS_PENDIENTES_4C",
            );
        }
    }

    public function test_cada_dueno_de_excepciones_temporales_sigue_teniendo_al_menos_un_uso(): void
    {
        $this->addToAssertionCount(1); // la lista puede estar vacía (Fase 9: catálogo de productos genérico)
        // Una fase terminada deja de tener usos: entonces su excepción debe borrarse (el contrato solo puede encogerse).
        $contenido = '';
        foreach ($this->archivos() as $rel) {
            $contenido .= (string) file_get_contents(base_path($rel))."\n";
        }
        $usado = [];
        foreach (T::PERMITIDOS as $patron => $dueno) {
            if (preg_match('/'.$patron.'/iu', $contenido) === 1) {
                $usado[$dueno] = true;
            }
        }

        foreach (array_unique(array_values(T::PERMITIDOS)) as $dueno) {
            $this->assertArrayHasKey($dueno, $usado, "ya no queda ningún uso de las excepciones de «{$dueno}»: bórralas de TerminosDelClienteAnterior::PERMITIDOS");
        }
    }
}
