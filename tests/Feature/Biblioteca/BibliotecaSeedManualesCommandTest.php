<?php

declare(strict_types=1);

namespace Tests\Feature\Biblioteca;

use App\Modules\Biblioteca\Contracts\LibraryStore;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\FeatureTestCase;

/** SCRUM-984 (B5) — carga inicial de los 7 PDFs; S3 siempre simulado. */
class BibliotecaSeedManualesCommandTest extends FeatureTestCase
{
    private FakeLibraryStore $store;
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->store = new FakeLibraryStore();
        $this->app->instance(LibraryStore::class, $this->store);

        $this->dir = sys_get_temp_dir() . '/biblioteca-seed-' . uniqid('', true);
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
        parent::tearDown();
    }

    private function makePdf(string $filename, string $content = "%PDF-1.4\nprueba"): void
    {
        file_put_contents($this->dir . '/' . $filename, $content);
    }

    public function test_carga_los_pdfs_en_su_modulo_y_es_idempotente(): void
    {
        $this->makePdf('1. Manual de Usuario AtlanticERP - CRM.pdf');
        $this->makePdf('7. Manual de Usuario AtlanticERP - Gerencia.pdf');

        $this->artisan('biblioteca:seed-manuales', ['directory' => $this->dir])->assertExitCode(0);

        $sidecars = array_filter(array_keys($this->store->objects), static fn (string $k): bool => str_ends_with($k, '.json'));
        $this->assertCount(2, $sidecars);
        $this->assertNotEmpty(array_filter(array_keys($this->store->objects), static fn (string $k): bool => str_starts_with($k, 'crm/pdf/')));
        $this->assertNotEmpty(array_filter(array_keys($this->store->objects), static fn (string $k): bool => str_starts_with($k, 'gerencia/pdf/')));

        $names = array_map(fn (string $k): string => (string) ($this->store->getJson($k)['name'] ?? ''), $sidecars);
        $this->assertContains('Manual de Usuario AtlanticERP - CRM', $names);

        // segunda corrida: no duplica
        $this->artisan('biblioteca:seed-manuales', ['directory' => $this->dir])->assertExitCode(0);
        $this->assertCount(4, $this->store->objects);
    }

    public function test_dry_run_no_escribe_nada(): void
    {
        $this->makePdf('3. Manual de Usuario AtlanticERP - Compras.pdf');

        $this->artisan('biblioteca:seed-manuales', ['directory' => $this->dir, '--dry-run' => true])->assertExitCode(0);

        $this->assertSame([], $this->store->objects);
    }

    public function test_omite_archivos_sin_prefijo_valido_y_rechaza_pdf_corrupto(): void
    {
        $this->makePdf('Manual suelto.pdf');
        $this->makePdf('2. Manual de Usuario AtlanticERP - Ventas.pdf', 'no soy un pdf');

        $this->artisan('biblioteca:seed-manuales', ['directory' => $this->dir])->assertExitCode(1);

        $this->assertSame([], $this->store->objects);
    }

    public function test_rechaza_un_pdf_con_javascript_incrustado(): void
    {
        $this->makePdf('4. Manual de Usuario AtlanticERP - Bodega.pdf', "%PDF-1.4\n1 0 obj << /S /JavaScript /JS (x) >> endobj");

        $this->artisan('biblioteca:seed-manuales', ['directory' => $this->dir])->assertExitCode(1);

        $this->assertSame([], $this->store->objects);
    }

    public function test_sin_bucket_configurado_falla_sin_tocar_nada(): void
    {
        $this->store->configured = false;
        $this->makePdf('1. Manual de Usuario AtlanticERP - CRM.pdf');

        $this->artisan('biblioteca:seed-manuales', ['directory' => $this->dir])->assertExitCode(1);

        $this->assertSame([], $this->store->objects);
    }
}
