<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Fase 4a (docs/specs/fase4-dataset-demo-generico.md, §3.3, criterio 3) — datos del cliente anterior que no pueden volver al código versionado:
 * RUC/DV, dirección fiscal, razón social, número de cuenta bancaria. Escanea el árbol fuente (sin BD).
 *
 * Excluidos a propósito: las migraciones HISTÓRICAS (inmutables) y `docs/` (reportes históricos de QA/Pre-QA: el historial de git también los conserva;
 * decisión de Luis, 2026-10-25, «solo limpiar desde ahora»).
 */
class ClientDataInRepoTest extends TestCase
{
    /** Valores que ya no pueden aparecer. */
    private const FORBIDDEN = ['074455', 'Times Square Plaza', 'www.atlantic.com.pa', 'Calle 57 Este, Times Square'];

    /**
     * Fase 6 (fiscal Panamá): el RUC/razón social del cliente son el EMISOR registrado en el sandbox del PAC — no se pueden cambiar por un placeholder
     * sin romper esa integración. Se retiran junto con el módulo fiscal por país.
     *
     * @var list<string>
     */
    private const FISCAL_SANDBOX_ISSUER = ['1838853', '711641', 'ATLANTIC USA CORP', 'ATLANTIC U S A'];

    /** Archivos que usan el emisor del sandbox (Fase 6). */
    private const FISCAL_SANDBOX_FILES = [
        'app/Modules/VentasDiseno/Support/SandboxFiscalFixtures.php',
        'infra/scripts/msl-polling-202.php',
        'tests/Feature/AdminCont/AdminContCreditNoteControllerTest.php',
        'tests/Feature/AdminCont/AdminContInvoiceControllerTest.php',
        'tests/Feature/AdminCont/AdminContPartialInvoicingTest.php',
        'tests/Feature/AdminCont/InvoicePdfVariantsTest.php',
    ];

    /** @return list<string> rutas relativas de los archivos de texto a revisar */
    private function files(): array
    {
        $root = dirname(__DIR__, 2);
        $out = [];
        foreach (['app', 'config', 'database/seeders', 'database/factories', 'resources', 'routes', 'tests', 'infra'] as $dir) {
            if (! is_dir("{$root}/{$dir}")) {
                continue;
            }
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator("{$root}/{$dir}", \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if (! $f->isFile() || ! in_array($f->getExtension(), ['php', 'json', 'md', 'sh', 'yml', 'yaml', 'env', 'txt', 'js', 'ts'], true)) {
                    continue;
                }
                $rel = substr($f->getPathname(), strlen($root) + 1);
                if ($rel === 'tests/Unit/ClientDataInRepoTest.php' || str_contains($rel, '/storage/')) {
                    continue;
                }
                $out[] = $rel;
            }
        }
        sort($out);

        return $out;
    }

    /** @return list<string> */
    private function hits(array $needles, array $skipFiles = []): array
    {
        $found = [];
        foreach ($this->files() as $rel) {
            if (in_array($rel, $skipFiles, true)) {
                continue;
            }
            $content = (string) file_get_contents(dirname(__DIR__, 2).'/'.$rel);
            foreach ($needles as $needle) {
                if (str_contains($content, $needle)) {
                    $found[] = "{$rel}: {$needle}";
                }
            }
        }

        return $found;
    }

    public function test_los_datos_bancarios_y_la_direccion_del_cliente_no_estan_en_el_codigo(): void
    {
        $this->assertSame([], $this->hits(self::FORBIDDEN));
    }

    public function test_el_emisor_del_sandbox_fiscal_solo_aparece_en_los_archivos_previstos(): void
    {
        $this->assertSame([], $this->hits(self::FISCAL_SANDBOX_ISSUER, self::FISCAL_SANDBOX_FILES));
    }
}
